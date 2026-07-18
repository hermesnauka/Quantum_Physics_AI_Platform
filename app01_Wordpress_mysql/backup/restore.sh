#!/bin/sh
# PLAN.md Phase 6 restore helper — operator-invoked, NEVER run automatically
# by the `backup` compose service or any schedule. Decrypts and restores a
# single backup artifact produced by run-backup.sh.
#
# Reuses the `backup` service (rather than a separate one) so it inherits
# network access to the `internal`-only `db` service without publishing
# MySQL's port — invoke with the entrypoint overridden, e.g.:
#
#   docker compose --profile backup run --rm \
#     -v /secure/path/to/backup-key.txt:/keys/backup-key.txt:ro \
#     --entrypoint /bin/sh \
#     backup /backup/restore.sh /backups/db-20260718-030000.sql.gz.age /keys/backup-key.txt
#
# Uses RESTORE_DB_USER/RESTORE_DB_PASSWORD (db/init/03-create-restore-user.sh)
# by default, NOT WP_DB_USER/BACKUP_DB_USER — see that script's doc comment
# for exactly why reusing either of those doesn't work here (in short:
# WP_DB_USER's and root's caching_sha2_password auth can't be used by the
# mariadb-client this container has, and WP_DB_USER also lacks the DROP
# privilege a full-dump restore needs; BACKUP_DB_USER is deliberately
# read-only). Override with MYSQL_RESTORE_USER/MYSQL_RESTORE_PASSWORD only
# if you have a specific reason to restore as a different user.
set -eu

BACKUP_DIR="/backups"
BACKUP_FILE="${1:?usage: restore.sh <backup-file> <age-key-file>}"
AGE_KEY_FILE="${2:?usage: restore.sh <backup-file> <age-key-file>}"

if [ ! -f "$BACKUP_FILE" ]; then
    echo "[restore] Backup file not found: $BACKUP_FILE" >&2
    exit 1
fi
if [ ! -f "$AGE_KEY_FILE" ]; then
    echo "[restore] Age private key file not found: $AGE_KEY_FILE" >&2
    exit 1
fi

apk add --no-cache --quiet mariadb-client age gzip tar

BASENAME="$(basename "$BACKUP_FILE")"

case "$BASENAME" in
    db-*.sql.gz.age)
        WORK_DIR="$(mktemp -d)"
        trap 'rm -rf "$WORK_DIR"' EXIT

        MYSQL_RESTORE_HOST="${MYSQL_RESTORE_HOST:-db}"
        MYSQL_RESTORE_DATABASE="${MYSQL_RESTORE_DATABASE:-$MYSQL_DATABASE}"
        MYSQL_RESTORE_USER="${MYSQL_RESTORE_USER:-$RESTORE_DB_USER}"
        MYSQL_RESTORE_PASSWORD="${MYSQL_RESTORE_PASSWORD:-$RESTORE_DB_PASSWORD}"

        echo "[restore] Decrypting $BASENAME..."
        age -d -i "$AGE_KEY_FILE" -o "$WORK_DIR/db.sql.gz" "$BACKUP_FILE"
        gunzip "$WORK_DIR/db.sql.gz"

        echo "[restore] Importing into $MYSQL_RESTORE_HOST/$MYSQL_RESTORE_DATABASE as $MYSQL_RESTORE_USER..."
        echo "[restore] This OVERWRITES existing data in that database — make sure that's intended."
        # --ssl, not MySQL 8's --ssl-mode=REQUIRED: same mariadb-client
        # incompatibility as run-backup.sh's mysqldump call — confirmed by
        # actually running this, not assumed. See that script's comment
        # for the full explanation.
        mysql \
            --host="$MYSQL_RESTORE_HOST" \
            --user="$MYSQL_RESTORE_USER" \
            --password="$MYSQL_RESTORE_PASSWORD" \
            --ssl \
            "$MYSQL_RESTORE_DATABASE" < "$WORK_DIR/db.sql"

        echo "[restore] Database restore complete. Decrypted dump removed on exit (never left on disk)."
        ;;

    uploads-*.tar.gz.age)
        # Extracted under /backups (bind-mounted to ./backups on the HOST —
        # docker-compose.yml), not a container-local mktemp -d: this
        # container normally runs with `--rm`, so anything written to its
        # own /tmp is gone the instant the script exits — before an
        # operator could ever act on a "docker cp" command printed below
        # referencing it. Confirmed by actually running this: the first
        # version of this script used mktemp -d, printed a docker cp
        # command, and the container (and every file in it) had already
        # been removed by the time that command could be read, let alone
        # run. Writing here instead means the extracted files genuinely
        # persist on the host after this container exits.
        SCRATCH_NAME="restore-scratch-$(date +%Y%m%d-%H%M%S)"
        WORK_DIR="$BACKUP_DIR/$SCRATCH_NAME"
        mkdir -p "$WORK_DIR"
        # Deliberately NOT cleaned up on exit — the operator still needs to
        # `docker cp` these files out (see below), and auto-deleting the
        # scratch dir before that happens would just force a second
        # decrypt. Left as plaintext under ./backups/ on the host until the
        # operator removes it themselves (see the printed reminder below) —
        # unlike the DB restore path above, this does linger on disk in the
        # meantime, since there's no way to hand files to `docker cp`
        # without a real path that outlives this container.

        echo "[restore] Decrypting $BASENAME..."
        age -d -i "$AGE_KEY_FILE" -o "$WORK_DIR/uploads.tar.gz" "$BACKUP_FILE"

        echo "[restore] Extracting..."
        tar -xzf "$WORK_DIR/uploads.tar.gz" -C "$WORK_DIR"
        rm -f "$WORK_DIR/uploads.tar.gz"

        echo "[restore] Extracted to ./backups/$SCRATCH_NAME/uploads (on your HOST,"
        echo "[restore] not inside this container, which is about to exit). Run this"
        echo "[restore] from your host shell in app01_Wordpress_mysql/ (not inside a"
        echo "[restore] container) to copy it into the running wordpress container:"
        echo "[restore]"
        echo "[restore]   docker cp backups/$SCRATCH_NAME/uploads/. quantumai-wp-wordpress-1:/var/www/html/wp-content/uploads/"
        echo "[restore]"
        echo "[restore] Then delete backups/$SCRATCH_NAME yourself — it is left in"
        echo "[restore] place on purpose (plaintext, on your host) and will NOT be"
        echo "[restore] cleaned up automatically."
        ;;

    *)
        echo "[restore] Unrecognized backup filename: $BASENAME" >&2
        echo "[restore] Expected db-*.sql.gz.age or uploads-*.tar.gz.age" >&2
        exit 1
        ;;
esac
