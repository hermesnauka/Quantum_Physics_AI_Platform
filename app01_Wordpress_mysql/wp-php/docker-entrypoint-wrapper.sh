#!/usr/bin/env bash
# PLAN.md Phase 5 "File Permissions: ... wp-config.php secured at 400 or
# 440, owned by the web user". The stock wordpress:php8.3-apache image
# creates wp-config.php world-readable (644) — confirmed by inspecting a
# running container, not assumed. This wraps the image's own
# /usr/local/bin/docker-entrypoint.sh rather than replacing or vendoring
# it, so it stays correct across image updates as long as that script's
# own "exec \"$@\" at the end" shape holds (a very stable, minimal
# contract for an official Docker entrypoint script).
#
# Two-pass trick: docker-entrypoint.sh's own final line is `exec "$@"`,
# which replaces the *current* process — so a plain wrapper can't run
# code "after" it in the same invocation. Calling it once with a harmless
# command (`true`) lets all of its setup logic run (copy WordPress core if
# missing, generate wp-config.php from WORDPRESS_* env vars if missing)
# and then exit cleanly, handing control back to THIS script. Only then do
# we chmod wp-config.php, and only then do we call docker-entrypoint.sh a
# second time with the real command (apache2-foreground) to actually start
# the server. That second call is cheap: WordPress core and wp-config.php
# both already exist by that point, so its own "copy/generate" branches
# are no-ops (their guard conditions are already false) — verified by
# reading /usr/local/bin/docker-entrypoint.sh in the actual running image.
set -Eeuo pipefail

/usr/local/bin/docker-entrypoint.sh true

if [ -f /var/www/html/wp-config.php ]; then
    chown www-data:www-data /var/www/html/wp-config.php
    chmod 440 /var/www/html/wp-config.php
fi

exec /usr/local/bin/docker-entrypoint.sh "$@"
