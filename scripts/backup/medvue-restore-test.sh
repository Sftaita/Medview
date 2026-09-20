#!/usr/bin/env bash
# medvue-restore-test.sh — proves a MedVue backup is restorable, in a
# throwaway target, without ever touching production.
#
# docs/backup.md, docs/decisions.md D109. The restore target is a disposable
# PostgreSQL container: no network, RAM-backed data directory, no published
# port, removed on exit (even on failure). It is created with local-socket
# "trust" authentication precisely because it is unreachable — so no
# password exists anywhere in this script.
#
# Usage:
#   medvue-restore-test.sh [--dump FILE] [--keys FILE] [--compare-live]
#     --dump FILE      dump to restore (default: newest in the backup root)
#     --keys FILE      JWT archive to check (default: newest in the backup root)
#     --compare-live   also compare the restored database (tables, row counts,
#                      constraints, indexes) and the JWT key files with the
#                      live ones. Only meaningful right after a backup.
#
# Environment overrides: MEDVUE_BACKUP_ROOT, MEDVUE_DB_CONTAINER,
# MEDVUE_JWT_VOLUME, MEDVUE_BACKUP_UTILITY_IMAGE, MEDVUE_RESTORE_IMAGE
# (default postgres:16-alpine, must already be local).
# Exit code 0 only if every check passed.
set -euo pipefail

BACKUP_ROOT="${MEDVUE_BACKUP_ROOT:-/home/deploy/backups/medvue}"
DB_CONTAINER="${MEDVUE_DB_CONTAINER:-medvue-database}"
JWT_VOLUME="${MEDVUE_JWT_VOLUME:-medvue_jwt_keys}"
UTILITY_IMAGE="${MEDVUE_BACKUP_UTILITY_IMAGE:-alpine}"
RESTORE_IMAGE="${MEDVUE_RESTORE_IMAGE:-postgres:16-alpine}"

dump="" keys="" compare_live=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --dump) dump="$2"; shift 2 ;;
    --keys) keys="$2"; shift 2 ;;
    --compare-live) compare_live=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

newest() { find "$1" -maxdepth 1 -type f -name "$2" -printf '%T@ %p\n' | sort -rn | head -1 | cut -d' ' -f2-; }
[[ -n "$dump" ]] || dump=$(newest "$BACKUP_ROOT/postgres" 'medvue_*.dump')
[[ -n "$keys" ]] || keys=$(newest "$BACKUP_ROOT/jwt-keys" 'jwt-keys_*.tar.gz')
[[ -f "$dump" ]] || { echo "FAIL: no dump found" >&2; exit 1; }
[[ -f "$keys" ]] || { echo "FAIL: no JWT archive found" >&2; exit 1; }

umask 077
name="mvrt-$$-$(date +%s)"
work=$(mktemp -d)
failures=0
cleanup() {
  docker rm -f "$name" >/dev/null 2>&1 || true
  rm -rf -- "$work"
}
trap cleanup EXIT

pass() { echo "  [PASS] $*"; }
fail() { echo "  [FAIL] $*"; failures=$((failures + 1)); }

echo "Dump : $(basename "$dump") ($(du -h "$dump" | cut -f1))"
echo "Keys : $(basename "$keys")"

echo "== 1. Integrity =="
for f in "$dump" "$keys"; do
  if [[ -f "$f.sha256" ]] && (cd "$(dirname "$f")" && sha256sum -c "$(basename "$f").sha256" >/dev/null 2>&1); then
    pass "sha256 of $(basename "$f")"
  else
    fail "sha256 of $(basename "$f") missing or wrong"
  fi
done

echo "== 2. PostgreSQL restore into a throwaway container =="
user=$(docker exec "$DB_CONTAINER" printenv POSTGRES_USER)
db=$(docker exec "$DB_CONTAINER" printenv POSTGRES_DB)
docker run -d --name "$name" --pull never --network none \
  --tmpfs /var/lib/postgresql/data:rw,size=512m \
  -e POSTGRES_HOST_AUTH_METHOD=trust -e POSTGRES_USER="$user" -e POSTGRES_DB="$db" \
  "$RESTORE_IMAGE" >/dev/null

# The official image starts a temporary server during init: wait for the real one.
ready=0
for _ in $(seq 1 60); do
  # Read the log into a variable first: `docker logs | grep -q` makes docker
  # die of SIGPIPE and, under pipefail, reports a false negative.
  logs=$(docker logs "$name" 2>&1 || true)
  if grep -q 'PostgreSQL init process complete' <<<"$logs" \
    && docker exec "$name" pg_isready -U "$user" -d "$db" >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 1
done
[[ "$ready" -eq 1 ]] || { fail "throwaway PostgreSQL did not become ready"; exit 1; }
pass "throwaway PostgreSQL ready (container $name, no network)"

docker cp "$dump" "$name:/tmp/restore.dump" >/dev/null
if docker exec "$name" pg_restore -U "$user" -d "$db" --no-owner --no-acl --exit-on-error /tmp/restore.dump >"$work/restore.out" 2>&1; then
  pass "pg_restore completed with --exit-on-error"
else
  fail "pg_restore failed: $(head -c 400 "$work/restore.out")"
  exit 1
fi

# Same read-only fingerprint on any database: table list with exact row
# counts, constraint counts by type, index count, migrations.
fingerprint() { # $1 = "docker exec <container>" target
  local target="$1"
  # shellcheck disable=SC2086
  $target psql -U "$user" -d "$db" -At <<'SQL'
select 'table ' || table_name || ' rows=' ||
  (xpath('/row/c/text()', query_to_xml(format('select count(*) as c from %I.%I', table_schema, table_name), false, true, '')))[1]::text
from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE' order by 1;
select 'constraint ' || contype || ' = ' || count(*) from pg_constraint c
  join pg_namespace n on n.oid = c.connamespace where n.nspname = 'public' group by contype order by contype;
select 'indexes = ' || count(*) from pg_indexes where schemaname = 'public';
select 'latest migration = ' || coalesce(max(version), 'none') from doctrine_migration_versions;
SQL
}
fingerprint "docker exec -i $name" >"$work/restored.txt"

tables=$(grep -c '^table ' "$work/restored.txt" || true)
if [[ "$tables" -gt 0 ]]; then pass "${tables} tables restored"; else fail "no table restored"; fi
if grep -q '^table doctrine_migration_versions rows=[1-9]' "$work/restored.txt"; then
  pass "migration history restored ($(grep '^latest migration' "$work/restored.txt"))"
else
  fail "migration history empty"
fi
echo "  data-bearing tables: $(grep '^table ' "$work/restored.txt" | grep -v 'rows=0$' | sed 's/^table //' | tr '\n' ' ')"
echo "  $(grep '^constraint' "$work/restored.txt" | sed 's/^constraint //' | tr '\n' ';') $(grep '^indexes' "$work/restored.txt")"

if [[ "$compare_live" -eq 1 ]]; then
  fingerprint "docker exec -i $DB_CONTAINER" >"$work/live.txt"
  if diff -q "$work/live.txt" "$work/restored.txt" >/dev/null; then
    pass "restored database == live database (tables, row counts, constraints, indexes, migrations)"
  else
    fail "restored database differs from live:"
    diff "$work/live.txt" "$work/restored.txt" | head -20
  fi
fi

echo "== 3. JWT key archive =="
mkdir "$work/keys"
if tar xzf "$keys" -C "$work/keys"; then
  pass "archive extracts"
else
  fail "archive does not extract"
fi
for f in private.pem public.pem; do
  if [[ -s "$work/keys/$f" ]]; then pass "$f present ($(wc -c <"$work/keys/$f") bytes)"; else fail "$f missing or empty"; fi
done
if [[ "$(head -1 "$work/keys/public.pem")" == *'BEGIN PUBLIC KEY'* ]]; then pass "public.pem is a PEM public key"; else fail "public.pem header unexpected"; fi
if grep -q 'ENCRYPTED' "$work/keys/private.pem"; then pass "private.pem is passphrase-encrypted"; else fail "private.pem is NOT encrypted"; fi

if [[ "$compare_live" -eq 1 ]]; then
  live=$(docker run --rm --pull never --network none -v "${JWT_VOLUME}:/data:ro" "$UTILITY_IMAGE" sha256sum /data/private.pem /data/public.pem | awk '{print $1}')
  restored=$(cd "$work/keys" && sha256sum private.pem public.pem | awk '{print $1}')
  if [[ "$live" == "$restored" ]]; then
    pass "restored key files == live key files (sha256 ${restored:0:16}...)"
  else
    fail "restored key files differ from the live volume"
  fi
fi

echo "== Result =="
if [[ "$failures" -eq 0 ]]; then
  echo "RESTORE TEST: PASS"
else
  echo "RESTORE TEST: FAIL (${failures})"
  exit 1
fi
