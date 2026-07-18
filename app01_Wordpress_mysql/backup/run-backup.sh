#!/bin/sh
# PLAN.md Phase 6 "Daily ... full database/file backups securely stored in
# an off-site, immutable storage (S3 with object lock)". Invoked via the
# `backup` compose service (profile "backup", so it never runs on a plain
# `docker compose up`): `docker compose --profile backup run --rm backup`.
#
# Scope, deliberately: full backups only, daily — no true incremental
# (binlog-based) backup. Real incremental needs binary logging enabled on
# `db` (it isn't) plus binlog-position tracking tooling; out of scope for
# this local dev/educational compose stack. See README "Backups" for the
# full list of known gaps, including that the S3 upload leg below has never
# been run against a real bucket from this environment.
set -eu

BACKUP_DIR="/backups"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

mkdir -p "$BACKUP_DIR"

echo "[backup] Installing mariadb-client, age, rclone, and gzip/tar (busybox)..."
apk add --no-cache --quiet mariadb-client age rclone gzip tar

# ---------------------------------------------------------------
# Database dump.
# ---------------------------------------------------------------

DB_SQL="$WORK_DIR/db.sql"
DB_OUT="$BACKUP_DIR/db-$TIMESTAMP.sql.gz.age"

echo "[backup] Dumping database $MYSQL_DATABASE..."

# --single-transaction: InnoDB-consistent snapshot via one REPEATABLE READ
# transaction, no global lock needed. --routines/--triggers/--events is
# exactly why BACKUP_DB_USER (db/init/02-create-backup-user.sh) is granted
# SHOW VIEW/EVENT/TRIGGER beyond plain SELECT — the schema doesn't use any
# of those today, but a dump that silently omitted them the day it did
# would be a bad surprise at restore time.
#
# Confirmed live against this exact mysql:8.0 server, using Alpine's
# mariadb-client package (the only mysqldump-equivalent available via apk):
# its dump tool doesn't understand MySQL 8's --ssl-mode or --set-gtid-purged
# flags at all (mariadb-dump --help has no such options — verified by
# running it, not assumed). --ssl is what it has instead, and it's already
# the client's own default (TRUE), so it's passed explicitly below for
# documentation, not because it's load-bearing; `db` requiring
# --require-secure-transport=ON means a plaintext connection is rejected by
# the server regardless. --set-gtid-purged has no MariaDB-client equivalent
# and no need for one here: this stack doesn't run with GTID enabled, and
# mariadb-dump doesn't emit MySQL's GTID_PURGED statement in the first
# place, so there's nothing to suppress. --no-tablespaces: also confirmed
# by running it — without this, mysqldump tries to dump tablespace
# metadata first, which needs the PROCESS privilege BACKUP_DB_USER
# deliberately doesn't have, and emits a scary-looking (but non-fatal)
# "Access denied ... PROCESS privilege" error on every single run. This
# stack's own tables are all InnoDB in the default/only tablespace anyway,
# so there's nothing worth dumping there.
mysqldump \
    --host=db \
    --user="$BACKUP_DB_USER" \
    --password="$BACKUP_DB_PASSWORD" \
    --ssl \
    --single-transaction \
    --quick \
    --no-tablespaces \
    --routines --triggers --events \
    --default-character-set=utf8mb4 \
    "$MYSQL_DATABASE" > "$DB_SQL"

gzip -c "$DB_SQL" | age -r "$BACKUP_AGE_RECIPIENT" -o "$DB_OUT"
echo "[backup] Wrote $DB_OUT"

# ---------------------------------------------------------------
# Uploads archive.
# ---------------------------------------------------------------
#
# wp-content/uploads is the only non-reproducible content inside the
# wp_data volume — WordPress core itself is reproducible from the
# wordpress:php8.3-apache image tag, and the theme/mu-plugins are
# bind-mounted read-only from this git repo already (docker-compose.yml).

UPLOADS_TAR="$WORK_DIR/uploads.tar.gz"
UPLOADS_OUT="$BACKUP_DIR/uploads-$TIMESTAMP.tar.gz.age"

if [ -d /var/www/html/wp-content/uploads ]; then
    echo "[backup] Archiving wp-content/uploads..."
    tar -czf "$UPLOADS_TAR" -C /var/www/html/wp-content uploads
    age -r "$BACKUP_AGE_RECIPIENT" -o "$UPLOADS_OUT" "$UPLOADS_TAR"
    echo "[backup] Wrote $UPLOADS_OUT"
else
    echo "[backup] No wp-content/uploads directory yet - nothing to archive." >&2
fi

# ---------------------------------------------------------------
# Local retention.
# ---------------------------------------------------------------
#
# Mirrors the audit-log mu-plugin's own "prune anything past N days"
# pattern (mu-plugins/audit-log/class-quantumai-audit-log.php). Purely
# local — S3 lifecycle/Object Lock retention (if configured) is a separate,
# independent window at the bucket level, not driven by this script.

RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
echo "[backup] Pruning local backups older than $RETENTION_DAYS days..."
find "$BACKUP_DIR" -name '*.age' -mtime "+$RETENTION_DAYS" -delete

# ---------------------------------------------------------------
# Optional off-site upload (S3, via rclone). Best-effort: skipped
# gracefully, never fails the run, if unconfigured.
# ---------------------------------------------------------------

if [ -n "${AWS_ACCESS_KEY_ID:-}" ] && [ -n "${BACKUP_S3_BUCKET:-}" ]; then
    echo "[backup] Uploading to s3://$BACKUP_S3_BUCKET/$BACKUP_S3_PREFIX ..."
    for f in "$DB_OUT" "$UPLOADS_OUT"; do
        [ -f "$f" ] || continue
        rclone copyto "$f" \
            ":s3,provider=AWS,env_auth=true,region=${AWS_DEFAULT_REGION:-us-east-1}:${BACKUP_S3_BUCKET}/${BACKUP_S3_PREFIX}/$(basename "$f")"
    done
    echo "[backup] Upload complete."
else
    echo "[backup] AWS_ACCESS_KEY_ID / BACKUP_S3_BUCKET not set - skipping off-site upload." >&2
    echo "[backup] Backups remain local-only in $BACKUP_DIR. See README 'Backups' section" >&2
    echo "[backup] for S3 (with Object Lock) setup." >&2
fi

echo "[backup] Done."
