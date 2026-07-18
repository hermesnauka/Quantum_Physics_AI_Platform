#!/bin/sh
# SR-03: runs as part of the stock nginx:alpine entrypoint (anything
# executable in /docker-entrypoint.d/ runs before nginx starts), after
# 20-envsubst-on-templates.sh has already rendered our template.
set -eu

# We used to bind-mount our own file straight over /etc/nginx/conf.d/, which
# hid the image's stock default.conf entirely. Now that conf.d is populated
# by the envsubst step from nginx/templates/ instead, that stock file is back
# on disk and would fight our catch-all `server_name _;` for the same ports.
rm -f /etc/nginx/conf.d/default.conf

CERT_DIR=/etc/nginx/certs
CERT_FILE="$CERT_DIR/wordpress.crt"
KEY_FILE="$CERT_DIR/wordpress.key"

if [ -f "$CERT_FILE" ] && [ -f "$KEY_FILE" ]; then
    exit 0
fi

echo "[tls] No certificate found in $CERT_DIR - generating a self-signed one for local/dev use."
echo "[tls] Replace $CERT_FILE / $KEY_FILE with a CA-issued certificate before this stack is reachable from the public internet."

mkdir -p "$CERT_DIR"

if ! command -v openssl >/dev/null 2>&1; then
    apk add --no-cache openssl
fi

openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
    -keyout "$KEY_FILE" -out "$CERT_FILE" \
    -subj "/CN=localhost" \
    -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" \
    >/dev/null 2>&1

echo "[tls] Self-signed certificate generated (valid 825 days)."
