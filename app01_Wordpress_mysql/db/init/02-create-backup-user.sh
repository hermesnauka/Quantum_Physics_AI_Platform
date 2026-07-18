#!/bin/bash
# Creates a dedicated, read-only backup DB user (PLAN.md Phase 6: "Daily ...
# full database ... backups"), separate from both the MySQL root user and
# the WP_DB_USER app user in 01-create-app-user.sh.
#
# WP_DB_USER deliberately lacks SHOW VIEW/EVENT/TRIGGER (see that script's
# own doc comment), which `mysqldump --routines --triggers --events` needs
# if the schema ever grows those objects (it doesn't today). Rather than
# widen the app user — which the running application itself should never
# need — this grants a second, strictly read-only user solely for the
# `backup` compose service (docker-compose.yml, profile "backup") to
# connect with.
#
# No INSERT/UPDATE/DELETE/CREATE/ALTER/DROP/GRANT: this user cannot modify
# or destroy data even if the backup container were fully compromised.
#
# Like 01-create-app-user.sh, this only runs against a fresh db_data volume
# (docker-entrypoint-initdb.d scripts are a first-boot-only mechanism) — an
# existing volume needs this CREATE USER/GRANT run manually.
#
# IDENTIFIED WITH mysql_native_password (rather than MySQL 8's own default,
# caching_sha2_password): confirmed by actually running the backup script
# against this exact server that Alpine's mariadb-client package — the only
# mysqldump-equivalent installable via apk, see backup/run-backup.sh — can't
# load caching_sha2_password's plugin at all ("Plugin caching_sha2_password
# could not be loaded ... No such file or directory"). mysql_native_password
# is a built-in MySQL 8 plugin (confirmed ACTIVE via `SHOW PLUGINS`) that
# both sides actually support. Scoped to this one low-privilege, read-only
# user only — WP_DB_USER is untouched, since WordPress's own PHP mysqli/PDO
# drivers already handle caching_sha2_password fine.
set -euo pipefail

mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<-EOSQL
    CREATE USER IF NOT EXISTS '${BACKUP_DB_USER}'@'%' IDENTIFIED WITH mysql_native_password BY '${BACKUP_DB_PASSWORD}' REQUIRE SSL;
    GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER
        ON \`${MYSQL_DATABASE}\`.* TO '${BACKUP_DB_USER}'@'%';
    FLUSH PRIVILEGES;
EOSQL
