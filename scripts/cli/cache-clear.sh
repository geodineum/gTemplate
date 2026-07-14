#!/bin/bash
# geodineum cache clear <site_id>
#
# Purge all gTemplate page-cache entries for a site from ValKey.
# Cache keys live at {<site_id>}:cache:page:<md5(path)> (see
# inc/sync/full-page-cache.php).
#
# Reasons to invoke:
#   - After a content/theme change that the cache layer didn't auto-invalidate
#   - After fixing a cache-correctness bug (e.g. the gcore_viewkey poisoning)
#   - During debugging of "why am I seeing stale content"

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GEODINEUM_ROOT="${GEODINEUM_ROOT:-/opt/geodineum}"

COMMON="${GEODINEUM_ROOT}/Geodineum/lib/common.sh"
[[ -f "$COMMON" ]] && source "$COMMON"

SITE_ID="${1:-}"

if [[ -z "$SITE_ID" ]] || [[ "$SITE_ID" == "--help" ]] || [[ "$SITE_ID" == "-h" ]]; then
    cat <<EOF
Usage: geodineum cache clear <site_id>

Purge all gTemplate page-cache entries for a site.
Cache key pattern: {<site_id>}:cache:page:*

Example:
  sudo geodineum cache clear example_site
EOF
    exit 2
fi

cred_dir="${GEODINEUM_CREDENTIALS_DIR:-/etc/geodineum/credentials}"
pass_file="${cred_dir}/valkey.password"
port="${VALKEY_PORT:-47445}"

if [[ ! -r "$pass_file" ]]; then
    echo "ERROR: cannot read $pass_file (need sudo)" >&2
    exit 1
fi

pass=$(tr -d '\n' < "$pass_file")
pattern="{${SITE_ID}}:cache:page:*"

R() { REDISCLI_AUTH="$pass" valkey-cli -p "$port" --no-auth-warning "$@"; }

pong=$(R PING 2>&1)
if [[ "$pong" != "PONG" ]]; then
    echo "ERROR: ValKey auth failed on port $port: $pong" >&2
    exit 1
fi

echo "  pattern: $pattern"
keys=$(R --scan --pattern "$pattern" 2>/dev/null)
count=$(printf '%s\n' "$keys" | grep -c . || true)
echo "  found:   $count cached page entries"

if [[ "$count" -gt 0 ]]; then
    deleted=$(printf '%s\n' "$keys" \
              | xargs -r -n50 redis-cli --no-auth-warning -p "$port" -a "$pass" DEL 2>/dev/null \
              | awk '{s+=$1} END {print s+0}')
    echo "  purged:  $deleted entries for ${SITE_ID}"
else
    echo "  (nothing to purge)"
fi
