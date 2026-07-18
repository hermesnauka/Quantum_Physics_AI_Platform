#!/bin/bash
# Creates the least-privilege application DB user (PLAN.md Phase 2: "MySQL user with
# restricted privileges (no DROP TABLE or GRANT privileges for the web app user)").
#
# We deliberately avoid the mysql image's built-in MYSQL_USER/MYSQL_PASSWORD vars,
# since those grant ALL PRIVILEGES (including DROP) on the database. Instead this
# script runs once, on first container init, and grants only what WordPress core
# and well-behaved plugins need to operate day-to-day.
#
# Trade-off: plugin "uninstall" routines that DROP their own tables will fail
# silently. That's an accepted trade-off per the security requirement above —
# table cleanup after uninstalling a plugin is a manual DBA step.
set -euo pipefail

mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<-EOSQL
    CREATE USER IF NOT EXISTS '${WP_DB_USER}'@'%' IDENTIFIED BY '${WP_DB_PASSWORD}' REQUIRE SSL;
    GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX,
          LOCK TABLES, CREATE TEMPORARY TABLES, REFERENCES
        ON \`${MYSQL_DATABASE}\`.* TO '${WP_DB_USER}'@'%';
    FLUSH PRIVILEGES;
EOSQL
