#!/usr/bin/env bash
# Verifies that SYMFONY_TRUSTED_PROXIES (docs/decisions.md D108) is Traefik's
# CURRENT address on the shared `proxy` network, both in the server `.env` and
# in the running backend container. Prints only IP addresses, never secrets.
# Exit 0 = consistent, 1 = mismatch/misconfiguration.
set -euo pipefail

APP_DIR="${MEDVUE_APP_DIR:-/opt/stack/apps/medvue}"
TRAEFIK_CONTAINER="${TRAEFIK_CONTAINER:-traefik}"
BACKEND_CONTAINER="${MEDVUE_BACKEND_CONTAINER:-medvue-backend}"
NETWORK="${PROXY_NETWORK:-proxy}"
IPV4='^([0-9]{1,3}\.){3}[0-9]{1,3}$'

fail() { echo "FAIL: $*" >&2; exit 1; }

actual=$(docker inspect "$TRAEFIK_CONTAINER" --format "{{(index .NetworkSettings.Networks \"$NETWORK\").IPAddress}}" 2>/dev/null) \
  || fail "cannot read $TRAEFIK_CONTAINER's address on network $NETWORK"
[[ "$actual" =~ $IPV4 ]] || fail "unexpected Traefik address '$actual'"

env_file="$APP_DIR/.env"
[[ -r "$env_file" ]] || fail "$env_file not readable"
configured=$(grep -E '^SYMFONY_TRUSTED_PROXIES=' "$env_file" | head -1 | cut -d= -f2- | tr -d "\"' \r" || true)
[[ -n "$configured" ]] || fail "SYMFONY_TRUSTED_PROXIES is not set in $env_file"
[[ "$configured" =~ $IPV4 ]] || fail "SYMFONY_TRUSTED_PROXIES must be ONE exact IPv4 address (got '$configured'; no subnet, no placeholder)"

echo "Traefik on '$NETWORK': $actual | .env: $configured"
[[ "$configured" == "$actual" ]] || fail ".env does not match Traefik's current address (update it, then recreate the backend)"

running=$(docker exec "$BACKEND_CONTAINER" printenv SYMFONY_TRUSTED_PROXIES 2>/dev/null || true)
echo "running $BACKEND_CONTAINER: ${running:-<unset>}"
[[ "$running" == "$actual" ]] || fail "the running backend does not carry the value (env_file is read at container creation: recreate it)"

echo "OK: trusted proxy = Traefik's exact current address"
