#!/usr/bin/env bash
# medvue-offsite-cron.sh — the daily cron entry of the off-site copy (docs/backup.md §2 ter).
#
# Adds around medvue-offsite.sh, which it runs unchanged (encryption, immutable
# upload, check and retention stay there) what a cron needs and it does not do:
#   - nothing is sent unless tonight's local backup succeeded (status/backup.json);
#   - the newest local set must then be on the remote;
#   - status/offsite.json records the result;
#   - any failure sends an alert by email through the MedVue mailer;
#   - two runs never overlap.
# Never reads .env or the passphrase. The email says what failed, never a secret.
#
# Environment overrides (all optional):
#   MEDVUE_BACKUP_ROOT           default /home/deploy/backups/medvue
#   MEDVUE_OFFSITE_SCRIPT        default medvue-offsite.sh next to the real path of this script,
#                                or /opt/stack/apps/medvue/scripts/backup/medvue-offsite.sh
#   MEDVUE_OFFSITE_REMOTE        default gdrive:INFORMATIQUE/Base de donnée/medvue
#   MEDVUE_OFFSITE_RCLONE        default rclone
#   MEDVUE_OFFSITE_BACKUP_MAX_AGE_HOURS  default 6: how recent the local backup must be
#   MEDVUE_OFFSITE_ALERT_TO      default: the platform administrators, else SUPPORT_EMAIL
#   MEDVUE_DOCKER                default docker
set -euo pipefail
export PATH="$HOME/bin:$PATH"

BACKUP_ROOT="${MEDVUE_BACKUP_ROOT:-/home/deploy/backups/medvue}"
REMOTE="${MEDVUE_OFFSITE_REMOTE:-gdrive:INFORMATIQUE/Base de donnée/medvue}"
RCLONE="${MEDVUE_OFFSITE_RCLONE:-rclone}"
MAX_AGE_HOURS="${MEDVUE_OFFSITE_BACKUP_MAX_AGE_HOURS:-6}"
DOCKER="${MEDVUE_DOCKER:-docker}"
here="$(dirname "$(readlink -f "$0")")"
OFFSITE="${MEDVUE_OFFSITE_SCRIPT:-}"
if [[ -z "$OFFSITE" ]]; then
  OFFSITE="$here/medvue-offsite.sh"
  [[ -x "$OFFSITE" ]] || OFFSITE=/opt/stack/apps/medvue/scripts/backup/medvue-offsite.sh
fi
LOG="$BACKUP_ROOT/backup.log"
STATUS_DIR="$BACKUP_ROOT/status"

umask 077
log() { printf '[%s] [offsite-cron] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG" || true; } # a journal that cannot be written must never stop the alert

write_status() { # write_status RESULT STAMP
  [[ -d "$STATUS_DIR" ]] || return 0
  printf '{"finishedAt":"%s","result":"%s","set":"%s"}\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$1" "$2" \
    >"$STATUS_DIR/.offsite.json.partial" && mv -f -- "$STATUS_DIR/.offsite.json.partial" "$STATUS_DIR/offsite.json" \
    && chmod 644 "$STATUS_DIR/offsite.json"
}

alert_recipients() {
  if [[ -n "${MEDVUE_OFFSITE_ALERT_TO:-}" ]]; then
    printf '%s\n' "$MEDVUE_OFFSITE_ALERT_TO"
    return 0
  fi
  # shellcheck disable=SC2016 # expanded inside the database container
  local admins
  admins=$("$DOCKER" exec medvue-database sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -At -c "SELECT email FROM users WHERE platform_admin"' 2>/dev/null) || admins=""
  if [[ -n "$admins" ]]; then
    printf '%s\n' "$admins"
  else
    "$DOCKER" exec medvue-backend printenv SUPPORT_EMAIL 2>/dev/null
  fi
}

fail() { # fail REASON — logs, records, alerts, and always exits 1
  local reason="$1" sent=0 to from
  log "ERROR: $reason"
  write_status failure "${newest:-}" || true
  from=$("$DOCKER" exec medvue-backend printenv SUPPORT_EMAIL 2>/dev/null) || from=""
  while IFS= read -r to; do
    [[ -n "$to" ]] || continue
    if "$DOCKER" exec medvue-backend php bin/console mailer:test "$to" ${from:+--from="$from"} \
      --subject="[MedVue] Copie hors site en échec" \
      --body="La copie hors site chiffrée de MedVue a échoué : $reason. Voir $LOG sur le serveur (docs/backup.md, §2 ter)." \
      >/dev/null 2>&1; then
      sent=$((sent + 1))
    fi
  done < <(alert_recipients)
  if [[ "$sent" -gt 0 ]]; then
    log "alert sent to $sent recipient(s)"
  else
    log "ERROR: ALERT NOT DELIVERED"
  fi
  exit 1
}

newest=""

exec 7>"$BACKUP_ROOT/.offsite-cron.lock" || fail "lock file not writable"
flock -n 7 || fail "another off-site run is in progress"

log "start"

# --- 1. tonight's local backup succeeded, or nothing is sent ------------------
status_file="$STATUS_DIR/backup.json"
[[ -r "$status_file" ]] || fail "no local backup status ($status_file)"
status=$(cat "$status_file")
result=$(printf '%s' "$status" | sed -nE 's/.*"result":"([^"]*)".*/\1/p')
failures_count=$(printf '%s' "$status" | sed -nE 's/.*"failures":([0-9]+).*/\1/p')
finished=$(printf '%s' "$status" | sed -nE 's/.*"finishedAt":"([^"]*)".*/\1/p')
[[ "$result" == "success" && "$failures_count" == "0" ]] || fail "local backup not successful (result=$result, failures=$failures_count), nothing sent"
finished_at=$(date -u -d "$finished" +%s 2>/dev/null) || fail "unreadable date in the local backup status"
age=$(($(date -u +%s) - finished_at))
[[ "$age" -le $((MAX_AGE_HOURS * 3600)) ]] || fail "local backup older than ${MAX_AGE_HOURS} h ($finished), nothing sent"

newest_dump=$(find "$BACKUP_ROOT/postgres" -maxdepth 1 -type f -name 'medvue_*.dump' -printf '%f\n' | sort | tail -n 1) || newest_dump=""
newest=${newest_dump#medvue_}
newest=${newest%.dump}
[[ -n "$newest" ]] || fail "no local dump found"

# --- 2. the validated script, unchanged ---------------------------------------
"$OFFSITE" || fail "medvue-offsite.sh exited with status $?"

# --- 3. the newest set is on the remote ---------------------------------------
# Listed first: `lsf | grep -q` under pipefail could fail on a SIGPIPE although the set is there.
listing=$("$RCLONE" lsf "$REMOTE/daily/$newest" 2>/dev/null) || listing=""
grep -q '\.gpg$' <<<"$listing" || fail "set $newest not found on the remote after the run"

write_status success "$newest" || fail "cannot record the result in $STATUS_DIR"
log "done: set $newest on the remote"
