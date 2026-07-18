#!/bin/sh
# AS-03 "automate weekly DAST scans (WPScan)". Invoked via the `wpscan`
# compose service (profile "scan", so it never runs on a plain
# `docker compose up`): `docker compose --profile scan run --rm wpscan`.
set -eu

TARGET_URL="${WPSCAN_TARGET_URL:-https://nginx/}"
REPORT_DIR="/report"
REPORT_FILE="$REPORT_DIR/wpscan-$(date +%Y%m%d-%H%M%S).json"

mkdir -p "$REPORT_DIR"

set -- --url "$TARGET_URL" --disable-tls-checks --enumerate vp,vt,u --format json --output "$REPORT_FILE"

if [ -n "${WPSCAN_API_TOKEN:-}" ]; then
    set -- "$@" --api-token "$WPSCAN_API_TOKEN"
else
    # Confirmed by actually running this against the stack: wpscan CLI 4.x
    # calls home to the WPScan API to even start a scan, not just for CVE
    # detail like older versions — with no token this aborts immediately
    # with a 401 (Scan Aborted, no report content). Fail loudly here rather
    # than let that surprise show up buried in a report file nobody reads
    # until the scan they thought ran turns out to have done nothing.
    echo "[wpscan] WPSCAN_API_TOKEN is not set - wpscan 4.x requires one to run" >&2
    echo "[wpscan] at all (not just for extra CVE detail). Get a free one at" >&2
    echo "[wpscan] https://wpscan.com/api/ and set WPSCAN_API_TOKEN in .env." >&2
    exit 1
fi

# --disable-tls-checks because the target is our own self-signed dev cert
# (SR-03) — this scan runs inside the same docker network as nginx, not over
# the public internet, so skipping cert validation here doesn't weaken
# anything; a production run against a real cert wouldn't need the flag but
# leaving it doesn't hurt.
wpscan "$@"

echo "[wpscan] Report written to $REPORT_FILE"
