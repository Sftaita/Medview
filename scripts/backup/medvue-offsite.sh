#!/usr/bin/env bash
# medvue-offsite.sh — encrypted off-site copy of the MedVue backups to Google
# Drive (docs/backup.md §2 ter, docs/decisions.md D209).
#
# Every file is encrypted ON THIS SERVER before it leaves it (gpg, AES-256,
# with MedVue's own passphrase file — never JWT_PASSPHRASE), decrypted again
# and compared with the original's sha256, then sent with
# `rclone copy --immutable` and checked with `rclone check`. Never
# `rclone sync`: a local rotation never deletes anything remote.
#
#   <remote>/daily/<stamp>/          a set of postgres/ + jwt-keys/ (same stamp)
#   <remote>/reserved/<stamp>/       a set of reserved/ — never deleted by any script
#   <remote>/reserved/_index/<hash>/ SHA256SUMS, INDEX.tsv, README.txt of reserved/
#
# In each set: <file>.gpg, <file>.sha256 (digest of the plaintext) and
# SHA256SUMS (digests of the .gpg files). Retention applies to daily/ only
# (medvue-offsite-retention.sh: 7 daily, 4 weekly, 12 monthly), and only
# when every upload of the run was verified. A set that retention would drop
# at once is never uploaded.
#
# The passphrase is only ever read by gpg from its file (600): it is never
# printed, logged, or passed on a command line. Without a copy of it kept
# off the server, these files cannot be decrypted.
#
# Usage: medvue-offsite.sh [--dry-run]
# Environment overrides (all optional):
#   MEDVUE_BACKUP_ROOT        default /home/deploy/backups/medvue
#   MEDVUE_OFFSITE_REMOTE     default gdrive:INFORMATIQUE/Base de donnée/medvue
#   MEDVUE_OFFSITE_PASSFILE   default $HOME/.medvue-backup-passphrase
#   MEDVUE_OFFSITE_RCLONE     default rclone
#   KEEP_DAILY / KEEP_WEEKLY / KEEP_MONTHLY (see medvue-offsite-retention.sh)
set -euo pipefail
export PATH="$HOME/bin:$PATH"

BACKUP_ROOT="${MEDVUE_BACKUP_ROOT:-/home/deploy/backups/medvue}"
REMOTE="${MEDVUE_OFFSITE_REMOTE:-gdrive:INFORMATIQUE/Base de donnée/medvue}"
PASSFILE="${MEDVUE_OFFSITE_PASSFILE:-$HOME/.medvue-backup-passphrase}"
RCLONE="${MEDVUE_OFFSITE_RCLONE:-rclone}"
RETENTION="$(dirname "$(readlink -f "$0")")/medvue-offsite-retention.sh"
LOG="$BACKUP_ROOT/backup.log"
DRY_RUN=0
[[ "${1:-}" == "--dry-run" ]] && DRY_RUN=1

umask 077
log() { printf '[%s] [offsite] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG"; }

exec 8>"$BACKUP_ROOT/.offsite.lock"
if ! flock -n 8; then
  log "ERROR: another off-site run is in progress"
  exit 1
fi
if [[ ! -f "$PASSFILE" || ! -s "$PASSFILE" || "$(stat -c %a "$PASSFILE")" != "600" ]]; then
  log "ERROR: passphrase file missing, empty or not 600 ($PASSFILE)"
  exit 1
fi
if [[ ! -x "$RETENTION" ]]; then
  log "ERROR: $RETENTION missing or not executable"
  exit 1
fi

work=$(mktemp -d "$BACKUP_ROOT/.offsite.XXXXXX")
trap 'rm -rf -- "$work"' EXIT
# Throwaway gpg home: never reads or writes ~/.gnupg.
export GNUPGHOME="$work/gnupg"
mkdir -m 700 "$GNUPGHOME"
gpg_args=(--batch --quiet --yes --no-tty --pinentry-mode loopback --passphrase-file "$PASSFILE")

failures=0
STAMP_RE='^[0-9]{8}_[0-9]{6}$'

# Directories under a remote path; a path that does not exist yet (code 3) is empty.
remote_dirs() {
  local code=0
  "$RCLONE" lsf "$1" --dirs-only >"$work/lsf.out" 2>"$work/lsf.err" || code=$?
  if [[ "$code" -eq 3 ]]; then return 0; fi
  if [[ "$code" -ne 0 ]]; then
    log "ERROR: cannot list $1: $(sed -n 1p "$work/lsf.err")"
    return 1
  fi
  sed 's:/$::' "$work/lsf.out"
}

# encrypt_set SRC_DIR DEST_DIR FILE... — encrypt each file, prove the .gpg
# decrypts back to the original digest, write SHA256SUMS of the .gpg files.
encrypt_set() {
  local src="$1" dest="$2" f want got
  shift 2
  mkdir -m 700 "$dest" || return 1
  for f in "$@"; do
    if [[ -f "$src/$f.sha256" ]]; then
      (cd "$src" && sha256sum -c --quiet "$f.sha256") || { log "ERROR: $f does not match its .sha256"; return 1; }
      cp -- "$src/$f.sha256" "$dest/$f.sha256" || return 1
    else
      (cd "$src" && sha256sum -- "$f") >"$dest/$f.sha256" || return 1
    fi
    want=$(cut -d' ' -f1 "$dest/$f.sha256")
    gpg "${gpg_args[@]}" --symmetric --cipher-algo AES256 -o "$dest/$f.gpg" -- "$src/$f" || { log "ERROR: cannot encrypt $f"; return 1; }
    got=$(gpg "${gpg_args[@]}" -d -- "$dest/$f.gpg" | sha256sum | cut -d' ' -f1)
    [[ "$got" == "$want" ]] || { log "ERROR: $f.gpg does not decrypt back to the original"; return 1; }
  done
  (cd "$dest" && sha256sum -- *.gpg >SHA256SUMS)
}

# upload_set LOCAL_DIR REMOTE_DIR — immutable copy, then a one-way check.
upload_set() {
  if [[ "$DRY_RUN" -eq 1 ]]; then
    log "dry-run: would upload $(basename "$1") -> $2"
    return 0
  fi
  "$RCLONE" copy "$1" "$2" --immutable --log-file "$LOG" --log-level NOTICE &&
    "$RCLONE" check "$1" "$2" --one-way --log-file "$LOG" --log-level NOTICE
}

# Functions called in an `if` run without errexit: every step above returns explicitly.
send() { # send KIND STAMP SRC_DIR FILE...
  local kind="$1" stamp="$2" src="$3" dest
  shift 3
  dest="$work/set-${kind//\//_}-$stamp"
  if encrypt_set "$src" "$dest" "$@" && upload_set "$dest" "$REMOTE/$kind/$stamp"; then
    [[ "$DRY_RUN" -eq 1 ]] || log "OK $kind/$stamp ($# file(s), encrypted, verified)"
  else
    log "ERROR: $kind/$stamp not sent"
    failures=$((failures + 1))
  fi
  rm -rf -- "${dest:?}"
}

log "start (remote: $REMOTE$([[ "$DRY_RUN" -eq 1 ]] && echo ', dry-run'))"

# --- daily: complete pairs of the normal rotation, top level only ----------
remote_dirs "$REMOTE/daily" >"$work/remote_daily" || exit 1
grep -E "$STAMP_RE" "$work/remote_daily" | sort -u >"$work/remote_daily.ok" || true
: >"$work/local_daily"
while IFS= read -r dump; do
  s=${dump#medvue_}; s=${s%.dump}
  [[ "$s" =~ $STAMP_RE ]] || continue
  [[ -f "$BACKUP_ROOT/postgres/$dump.sha256" && -f "$BACKUP_ROOT/jwt-keys/jwt-keys_$s.tar.gz" && -f "$BACKUP_ROOT/jwt-keys/jwt-keys_$s.tar.gz.sha256" ]] || continue
  echo "$s" >>"$work/local_daily"
done < <(find "$BACKUP_ROOT/postgres" -maxdepth 1 -type f -name 'medvue_*.dump' -printf '%f\n')
sort -u -o "$work/local_daily" "$work/local_daily"

sort -u "$work/remote_daily.ok" "$work/local_daily" | "$RETENTION" | sort -u >"$work/drop"
comm -23 "$work/local_daily" "$work/remote_daily.ok" | comm -23 - "$work/drop" >"$work/todo_daily"
while IFS= read -r s; do
  tmp="$work/src-$s"
  mkdir -m 700 "$tmp"
  ln -s "$BACKUP_ROOT/postgres/medvue_$s.dump" "$tmp/medvue_$s.dump"
  ln -s "$BACKUP_ROOT/postgres/medvue_$s.dump.sha256" "$tmp/medvue_$s.dump.sha256"
  ln -s "$BACKUP_ROOT/jwt-keys/jwt-keys_$s.tar.gz" "$tmp/jwt-keys_$s.tar.gz"
  ln -s "$BACKUP_ROOT/jwt-keys/jwt-keys_$s.tar.gz.sha256" "$tmp/jwt-keys_$s.tar.gz.sha256"
  send daily "$s" "$tmp" "medvue_$s.dump" "jwt-keys_$s.tar.gz"
  rm -rf -- "${tmp:?}"
done <"$work/todo_daily"

# --- reserved: every set, kept forever --------------------------------------
if [[ -d "$BACKUP_ROOT/reserved" ]]; then
  remote_dirs "$REMOTE/reserved" >"$work/remote_reserved" || exit 1
  while IFS= read -r s; do
    grep -qxF "$s" "$work/remote_reserved" && continue
    mapfile -t files < <(find "$BACKUP_ROOT/reserved/$s" -maxdepth 1 -type f ! -name '*.sha256' -printf '%f\n' | sort)
    if [[ "${#files[@]}" -eq 0 ]]; then
      log "ERROR: reserved/$s is empty"
      failures=$((failures + 1))
      continue
    fi
    send reserved "$s" "$BACKUP_ROOT/reserved/$s" "${files[@]}"
  done < <(find "$BACKUP_ROOT/reserved" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | grep -E "$STAMP_RE" | sort)

  # Index of reserved/, one version per content.
  index_files=()
  for f in SHA256SUMS INDEX.tsv README.txt; do [[ -f "$BACKUP_ROOT/reserved/$f" ]] && index_files+=("$f"); done
  if [[ "${#index_files[@]}" -gt 0 ]]; then
    h=$(cd "$BACKUP_ROOT/reserved" && cat -- "${index_files[@]}" | sha256sum | cut -c1-16)
    remote_dirs "$REMOTE/reserved/_index" >"$work/remote_index" || exit 1
    grep -qxF "$h" "$work/remote_index" || send reserved/_index "$h" "$BACKUP_ROOT/reserved" "${index_files[@]}"
  fi
fi

# --- retention of daily/, only after a fully verified run -------------------
if [[ "$failures" -eq 0 ]]; then
  while IFS= read -r s; do
    [[ "$s" =~ $STAMP_RE ]] || continue
    grep -qxF "$s" "$work/remote_daily.ok" || continue
    if [[ "$DRY_RUN" -eq 1 ]]; then
      log "dry-run: would delete daily/$s (retention)"
    elif "$RCLONE" purge "$REMOTE/daily/$s" --log-file "$LOG" --log-level NOTICE; then
      log "[retention] deleted daily/$s"
    else
      log "ERROR: could not delete daily/$s"
      failures=$((failures + 1))
    fi
  done <"$work/drop"
else
  log "retention skipped: ${failures} failure(s) in this run"
fi

if [[ "$failures" -eq 0 ]]; then
  log "done: all OK"
else
  log "done: ${failures} failure(s)"
  exit 1
fi
