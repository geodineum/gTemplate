#!/bin/bash
# geodineum cache stats [<site_id>]
#
# Show gTemplate page-cache key counts. Without args, shows per-site totals.
# With <site_id>, shows just that site's count.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GEODINEUM_ROOT="${GEODINEUM_ROOT:-/opt/geodineum}"

COMMON="${GEODINEUM_ROOT}/Geodineum/lib/common.sh"
[[ -f "$COMMON" ]] && source "$COMMON"

SITE_ID="${1:-}"

cred_dir="${GEODINEUM_CREDENTIALS_DIR:-/etc/geodineum/credentials}"
pass_file="${cred_dir}/valkey.password"
port="${VALKEY_PORT:-47445}"

if [[ ! -r "$pass_file" ]]; then
    echo "ERROR: cannot read $pass_file (need sudo)" >&2
    exit 1
fi

pass=$(tr -d '\n' < "$pass_file")
R() { REDISCLI_AUTH="$pass" valkey-cli -p "$port" --no-auth-warning "$@"; }

pong=$(R PING 2>&1)
if [[ "$pong" != "PONG" ]]; then
    echo "ERROR: ValKey auth failed on port $port: $pong" >&2
    exit 1
fi

if [[ -n "$SITE_ID" ]]; then
    pattern="{${SITE_ID}}:cache:page:*"
    count=$(R --scan --pattern "$pattern" 2>/dev/null | grep -c . || true)
    echo "  Site:    $SITE_ID"
    echo "  Pattern: $pattern"
    echo "  Cached:  $count page entries"
else
    pattern='*:cache:page:*'
    echo "  Pattern: $pattern"
    echo "  Per-site counts:"
    R --scan --pattern "$pattern" 2>/dev/null \
        | sed -E 's/^\{([^}]+)\}:cache:page:.*/\1/' \
        | sort \
        | uniq -c \
        | sort -rn \
        | awk '{printf "    %-30s %s\n", $2, $1}'
fi
