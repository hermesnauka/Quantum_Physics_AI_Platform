#!/bin/bash
# Creates a dedicated DB user for restoring a backup/run-backup.sh dump
# (PLAN.md Phase 6), separate from WP_DB_USER, BACKUP_DB_USER, and root.
#
# Restoring a full mysqldump needs write privileges the read-only
# BACKUP_DB_USER deliberately doesn't have — but WP_DB_USER can't be reused
# for this either, for two independent reasons discovered by actually
# running backup/restore.sh against this stack (not assumed up front):
#
#  1. WP_DB_USER (and root) both authenticate with MySQL 8's default
#     caching_sha2_password plugin. Alpine's mariadb-client package — the
#     only mysqldump/mysql-equivalent installable via apk, see
#     backup/run-backup.sh — cannot load that plugin's client-side shared
#     object at all ("Plugin caching_sha2_password could not be loaded").
#     There's no separate apk package that provides it either. This is a
#     genuine ecosystem limitation, not something fixable with a flag.
#  2. mysqldump's default dump output includes `DROP TABLE IF EXISTS`
#     before each `CREATE TABLE` — but WP_DB_USER deliberately has no DROP
#     privilege (01-create-app-user.sh), by design, so it can't restore a
#     full dump even setting the auth problem aside.
#
# Rather than change WP_DB_USER's or root's auth plugin — which would touch
# the live application's own DB access and deserves its own explicit
# decision, not a side effect of adding backups — this is a fourth,
# purpose-built user (root ≠ app user ≠ backup user ≠ restore user),
# mysql_native_password like BACKUP_DB_USER, with the write privileges an
# import actually needs, only used interactively via backup/restore.sh —
# never by the running application.
#
# Like the other init scripts, this only runs against a fresh db_data
# volume — an existing volume needs this CREATE USER/GRANT run manually.
set -euo pipefail

mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<-EOSQL
    CREATE USER IF NOT EXISTS '${RESTORE_DB_USER}'@'%' IDENTIFIED WITH mysql_native_password BY '${RESTORE_DB_PASSWORD}' REQUIRE SSL;
    GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX,
          LOCK TABLES, CREATE TEMPORARY TABLES, REFERENCES
        ON \`${MYSQL_DATABASE}\`.* TO '${RESTORE_DB_USER}'@'%';
    FLUSH PRIVILEGES;
EOSQL
