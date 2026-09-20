#!/usr/bin/env bash
# medvue-backup.sh — MedVue backup: PostgreSQL dump + JWT key volume.
#
# docs/backup.md, docs/decisions.md D109. Runs unattended from cron as the
# `deploy` user; touches nothing outside MedVue (own containers, own volume,
# own backup directory, own log — never the SurgicalHub/MySQL backups).
#
# Secrets: none appear here. pg_dump runs INSIDE the database container over
# its local socket, so no password is ever read, passed or logged; the JWT
# archive contains the passphrase-encrypted private key only — the
# passphrase (.env) is deliberately NOT backed up alongside it.
#
# Environment overrides (all optional):
#   MEDVUE_BACKUP_ROOT            default /home/deploy/backups/medvue
#   MEDVUE_BACKUP_RETENTION_DAYS  default 30
#   MEDVUE_BACKUP_MIN_KEEP        default 7  (newest archives always kept)
#   MEDVUE_DB_CONTAINER           default medvue-database
#   MEDVUE_JWT_VOLUME             default medvue_jwt_keys
#   MEDVUE_BACKUP_UTILITY_IMAGE   default alpine (must already be local)
set -euo pipefail

BACKUP_ROOT="${MEDVUE_BACKUP_ROOT:-/home/deploy/backups/medvue}"
RETENTION_DAYS="${MEDVUE_BACKUP_RETENTION_DAYS:-30}"
MIN_KEEP="${MEDVUE_BACKUP_MIN_KEEP:-7}"
DB_CONTAINER="${MEDVUE_DB_CONTAINER:-medvue-database}"
JWT_VOLUME="${MEDVUE_JWT_VOLUME:-medvue_jwt_keys}"
UTILITY_IMAGE="${MEDVUE_BACKUP_UTILITY_IMAGE:-alpine}"

# Backups hold user data: owner-only from the first byte.
umask 077
PG_DIR="$BACKUP_ROOT/postgres"
JWT_DIR="$BACKUP_ROOT/jwt-keys"
LOG="$BACKUP_ROOT/backup.log"
mkdir -p "$PG_DIR" "$JWT_DIR"

log() { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG"; }

# One run at a time (cron overlap, manual run during a scheduled one).
exec 9>"$BACKUP_ROOT/.lock"
if ! flock -n 9; then
  log "[backup] ERROR: another backup is already running"
  exit 1
fi

# Keep the newest MIN_KEEP files of a kind whatever their age, delete the
# older ones only when they exceed RETENTION_DAYS. Called only after the
# backup of that kind succeeded, so a failing backup never empties the set.
rotate() {
  local dir="$1" pattern="$2" rank=0 file
  while IFS= read -r file; do
    rank=$((rank + 1))
    [[ "$rank" -le "$MIN_KEEP" ]] && continue
    if [[ -n "$(find "$file" -mtime +"$RETENTION_DAYS" -print -quit)" ]]; then
      rm -f -- "$file" "$file.sha256"
      log "[rotate] removed $(basename "$file")"
    fi
  done < <(find "$dir" -maxdepth 1 -type f -name "$pattern" -printf '%T@ %p\n' | sort -rn | cut -d' ' -f2-)
}

running() { [[ "$(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null || true)" == "true" ]]; }

backup_postgres() {
  local stamp="$1" out tmp err db user
  out="$PG_DIR/medvue_${stamp}.dump"
  tmp="$PG_DIR/.medvue_${stamp}.dump.partial"
  err="$PG_DIR/.medvue_${stamp}.err"

  if ! running "$DB_CONTAINER"; then
    log "[postgres] ERROR: container $DB_CONTAINER is not running"
    return 1
  fi
  db=$(docker exec "$DB_CONTAINER" printenv POSTGRES_DB)
  user=$(docker exec "$DB_CONTAINER" printenv POSTGRES_USER)

  # Custom format: compressed, restorable selectively. --no-owner/--no-acl
  # keep it restorable under any role name. One consistent snapshot.
  if ! docker exec "$DB_CONTAINER" pg_dump -U "$user" -d "$db" --format=custom --no-owner --no-acl >"$tmp" 2>"$err"; then
    log "[postgres] ERROR: pg_dump failed: $(head -c 300 "$err")"
    rm -f -- "$tmp" "$err"
    return 1
  fi
  rm -f -- "$err"

  # A dump that cannot be listed is not a backup.
  if ! docker exec -i "$DB_CONTAINER" pg_restore --list <"$tmp" >/dev/null 2>&1; then
    log "[postgres] ERROR: the dump is not a readable archive; discarded"
    rm -f -- "$tmp"
    return 1
  fi

  mv -- "$tmp" "$out"
  (cd "$PG_DIR" && sha256sum "$(basename "$out")" >"$(basename "$out").sha256")

  local version
  version=$(docker exec "$DB_CONTAINER" psql -U "$user" -d "$db" -Atc \
    "select coalesce(max(version), 'none') from doctrine_migration_versions" 2>/dev/null || echo unknown)
  log "[postgres] OK $(basename "$out") ($(du -h "$out" | cut -f1)), latest migration: ${version}"
  rotate "$PG_DIR" 'medvue_*.dump'
}

backup_jwt() {
  local stamp="$1" out tmp err
  out="$JWT_DIR/jwt-keys_${stamp}.tar.gz"
  tmp="$JWT_DIR/.jwt-keys_${stamp}.tar.gz.partial"
  err="$JWT_DIR/.jwt-keys_${stamp}.err"

  if ! docker volume inspect "$JWT_VOLUME" >/dev/null 2>&1; then
    log "[jwt] ERROR: volume $JWT_VOLUME does not exist"
    return 1
  fi

  # No network, volume mounted read-only, image never pulled implicitly.
  if ! docker run --rm --pull never --network none -v "${JWT_VOLUME}:/data:ro" "$UTILITY_IMAGE" \
    tar czf - -C /data . >"$tmp" 2>"$err"; then
    log "[jwt] ERROR: archive failed: $(head -c 300 "$err")"
    rm -f -- "$tmp" "$err"
    return 1
  fi
  rm -f -- "$err"

  local listing
  listing=$(tar tzf "$tmp" 2>/dev/null || true)
  if ! grep -q 'private\.pem$' <<<"$listing" || ! grep -q 'public\.pem$' <<<"$listing"; then
    log "[jwt] ERROR: archive does not contain both private.pem and public.pem; discarded"
    rm -f -- "$tmp"
    return 1
  fi

  mv -- "$tmp" "$out"
  (cd "$JWT_DIR" && sha256sum "$(basename "$out")" >"$(basename "$out").sha256")
  log "[jwt] OK $(basename "$out") ($(du -h "$out" | cut -f1))"
  rotate "$JWT_DIR" 'jwt-keys_*.tar.gz'
}

stamp=$(date +%Y%m%d_%H%M%S)
log "[backup] start (retention ${RETENTION_DAYS}d, always keeping the newest ${MIN_KEEP})"

failures=0
backup_postgres "$stamp" || failures=$((failures + 1))
backup_jwt "$stamp" || failures=$((failures + 1))

if [[ "$failures" -eq 0 ]]; then
  log "[backup] done: all OK"
else
  log "[backup] done: ${failures} failure(s)"
  exit 1
fi
