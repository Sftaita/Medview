#!/usr/bin/env bash
# medvue-offsite.test.sh — behaviour of medvue-offsite.sh, medvue-offsite-fetch.sh
# and medvue-offsite-retention.sh on a throwaway tree (docs/backup.md §2 ter).
#
# The "remote" is a local directory: with the real rclone when it is installed
# (production server), otherwise with tests/fake-rclone.sh (CI). Needs gpg.
# Never reads or writes outside its own temporary directory.
#
# Usage: medvue-offsite.test.sh            Exit 0 only if every check passes.
#        TEST_RCLONE=/path/to/rclone medvue-offsite.test.sh
set -euo pipefail
# The checks below report each failure and carry on: no errexit past this point.
set +e

here=$(cd "$(dirname "$0")" && pwd)
scripts=$(cd "$here/.." && pwd)
if [[ -n "${TEST_RCLONE:-}" ]]; then rclone_bin="$TEST_RCLONE"
elif command -v rclone >/dev/null 2>&1; then rclone_bin=$(command -v rclone)
elif [[ -x "$HOME/bin/rclone" ]]; then rclone_bin="$HOME/bin/rclone"
else rclone_bin="$here/fake-rclone.sh"
fi
echo "rclone: $rclone_bin"

root=$(mktemp -d)
trap 'rm -rf -- "$root"' EXIT
B="$root/backups" R="$root/remote" P="$root/passphrase"
mkdir -p "$B/postgres" "$B/jwt-keys" "$B/reserved" "$R"
head -c 48 /dev/urandom | base64 >"$P"
chmod 600 "$P"
secret=$(cat "$P")

fails=0
ok() { if eval "$2"; then echo "ok   $1"; else echo "FAIL $1"; fails=$((fails + 1)); fi; }

mkset() { # mkset STAMP — a complete daily pair, like medvue-backup.sh writes it
  printf 'PLAINTEXT-MARKER dump %s\n' "$1" >"$B/postgres/medvue_$1.dump"
  printf 'PLAINTEXT-MARKER keys %s\n' "$1" >"$B/jwt-keys/jwt-keys_$1.tar.gz"
  (cd "$B/postgres" && sha256sum "medvue_$1.dump" >"medvue_$1.dump.sha256")
  (cd "$B/jwt-keys" && sha256sum "jwt-keys_$1.tar.gz" >"jwt-keys_$1.tar.gz.sha256")
}
mkreserved() {
  mkdir -p "$B/reserved/$1"
  printf 'PLAINTEXT-MARKER reserved %s\n' "$1" >"$B/reserved/$1/medvue_$1.dump"
  printf 'PLAINTEXT-MARKER reserved keys %s\n' "$1" >"$B/reserved/$1/jwt-keys_$1.tar.gz"
  (cd "$B/reserved/$1" && sha256sum "medvue_$1.dump" >"medvue_$1.dump.sha256" && sha256sum "jwt-keys_$1.tar.gz" >"jwt-keys_$1.tar.gz.sha256")
}

offsite() {
  MEDVUE_BACKUP_ROOT="$B" MEDVUE_OFFSITE_REMOTE="$R" MEDVUE_OFFSITE_PASSFILE="$P" MEDVUE_OFFSITE_RCLONE="$rclone_bin" \
    bash "$scripts/medvue-offsite.sh" "$@" >>"$root/stdout" 2>&1
}
fetch() {
  MEDVUE_OFFSITE_REMOTE="$R" MEDVUE_OFFSITE_PASSFILE="$P" MEDVUE_OFFSITE_RCLONE="$rclone_bin" \
    bash "$scripts/medvue-offsite-fetch.sh" "$@" >>"$root/stdout" 2>&1
}
remote_daily() { find "$R/daily" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null | sort; }
local_tree() { (cd "$B" && find postgres jwt-keys reserved -type f -print0 | sort -z | xargs -0 sha256sum); }

# --- retention rule alone -------------------------------------------------
r=$(printf '%s\n' 20261023_034500 20261023_051123 20261022_034500 junk 20261001_034500 20260915_034500 \
  | KEEP_DAILY=2 KEEP_WEEKLY=1 KEEP_MONTHLY=2 bash "$scripts/medvue-offsite-retention.sh" | sort | tr '\n' ' ')
ok "retention: newest of each kept day/week/month, others dropped, junk ignored" '[[ "$r" == "20261001_034500 20261023_034500 " ]]'
ok "retention: empty input deletes nothing" '[[ -z "$(printf "" | bash "$scripts/medvue-offsite-retention.sh")" ]]'

# --- fixture: daily sets over several months, reserved sets, an index -------
for s in 20260601_034500 20260701_034500 20260801_034500 20260901_034500; do mkset "$s"; done
for d in $(seq -w 1 23); do mkset "202610${d}_034500"; done
mkset 20261023_051123                                  # manual backup, same day
printf 'PLAINTEXT-MARKER orphan\n' >"$B/postgres/medvue_20261020_120000.dump"   # dump without keys: never a set
(cd "$B/postgres" && sha256sum medvue_20261020_120000.dump >medvue_20261020_120000.dump.sha256)
mkreserved 20260925_125642
mkreserved 20261021_115609
printf 'INDEX\n' >"$B/reserved/INDEX.tsv"
printf 'README\n' >"$B/reserved/README.txt"
(cd "$B/reserved" && find . -mindepth 2 -type f ! -name '*.sha256' | sort | xargs sha256sum >SHA256SUMS)
before=$(local_tree)

# --- refusals ----------------------------------------------------------------
chmod 644 "$P"; offsite; code=$?; chmod 600 "$P"
ok "passphrase file not 600: refused, nothing sent" '[[ $code -ne 0 && -z "$(ls -A "$R")" ]]'

offsite --dry-run; code=$?
ok "dry-run: exit 0, nothing sent, plan logged" '[[ $code -eq 0 && -z "$(ls -A "$R")" ]] && grep -q "dry-run: would upload" "$B/backup.log"'

# --- first real run -------------------------------------------------------------
offsite; code=$?
ok "first run exits 0" '[[ $code -eq 0 ]]'
expected=$( (cd "$B/postgres" && ls medvue_*.dump) | sed 's/medvue_//; s/\.dump$//' | grep -v 20261020_120000 \
  | sort >"$root/local"; bash "$scripts/medvue-offsite-retention.sh" <"$root/local" | sort | comm -23 "$root/local" -)
ok "daily/ holds exactly the sets retention keeps (none uploaded to be dropped)" '[[ "$(remote_daily)" == "$expected" ]]'
ok "incomplete pair (dump without keys) never sent" '[[ ! -e "$R/daily/20261020_120000" ]]'
ok "reserved sets sent" '[[ -d "$R/reserved/20260925_125642" && -d "$R/reserved/20261021_115609" ]]'
ok "one reserved index version (3 files)" '[[ $(find "$R/reserved/_index" -name "*.gpg" | wc -l) -eq 3 ]]'
ok "no plaintext anywhere remote" '! grep -rq "PLAINTEXT-MARKER" "$R"'
ok "every remote set matches its SHA256SUMS" 'for d in $(find "$R" -name SHA256SUMS -printf "%h\n"); do (cd "$d" && sha256sum -c --quiet SHA256SUMS) || exit 1; done'
ok "passphrase never written to the log or the output" '! grep -qF "$secret" "$B/backup.log" "$root/stdout"'
ok "local backups untouched" '[[ "$(local_tree)" == "$before" ]]'

# --- idempotent -------------------------------------------------------------------
snapshot=$(cd "$R" && find . -type f | sort | xargs sha256sum)
offsite; code=$?
ok "second run: exit 0, remote unchanged" '[[ $code -eq 0 && "$(cd "$R" && find . -type f | sort | xargs sha256sum)" == "$snapshot" ]]'

# --- retention as days pass; reserved never pruned -------------------------------
for d in 01 02 03 04 05 06 07 08 09 10; do mkset "202611${d}_034500"; done
offsite; code=$?
expected=$( (cd "$B/postgres" && ls medvue_*.dump) | sed 's/medvue_//; s/\.dump$//' | grep -v 20261020_120000 \
  | sort >"$root/local"; bash "$scripts/medvue-offsite-retention.sh" <"$root/local" | sort | comm -23 "$root/local" -)
ok "later run: daily/ pruned to the retention set" '[[ $code -eq 0 && "$(remote_daily)" == "$expected" ]]'
ok "later run: reserved untouched" '[[ $(find "$R/reserved" -name "*.gpg" | wc -l) -eq 7 ]]'

# --- a failed set blocks retention -----------------------------------------------
before_fail=$(remote_daily)
for d in 11 12 13; do mkset "202611${d}_034500"; done
printf 'tampered\n' >>"$B/postgres/medvue_20261113_034500.dump"
offsite; code=$?
ok "corrupted dump: run fails, set not sent" '[[ $code -ne 0 && ! -e "$R/daily/20261113_034500" ]]'
ok "corrupted dump: no deletion in that run (only additions)" '[[ -z "$(comm -23 <(echo "$before_fail") <(remote_daily))" ]]'
rm -f "$B/postgres/medvue_20261113_034500.dump"* "$B/jwt-keys/jwt-keys_20261113_034500.tar.gz"*

# --- fetch and decrypt --------------------------------------------------------------
fetch daily 20261023_051123 "$root/out1"; code=$?
ok "fetch daily: exit 0, files identical to the originals" '[[ $code -eq 0 ]] && cmp -s "$root/out1/medvue_20261023_051123.dump" "$B/postgres/medvue_20261023_051123.dump" && cmp -s "$root/out1/jwt-keys_20261023_051123.tar.gz" "$B/jwt-keys/jwt-keys_20261023_051123.tar.gz"'
fetch reserved 20260925_125642 "$root/out2"; code=$?
ok "fetch reserved: exit 0, identical" '[[ $code -eq 0 ]] && cmp -s "$root/out2/medvue_20260925_125642.dump" "$B/reserved/20260925_125642/medvue_20260925_125642.dump"'
fetch daily 20261023_051123 "$root/out1"; code=$?
ok "fetch refuses an existing directory" '[[ $code -eq 2 ]]'
printf 'x' >>"$R/daily/20261023_051123/medvue_20261023_051123.dump.gpg"
fetch daily 20261023_051123 "$root/out3"; code=$?
ok "fetch rejects a tampered encrypted file" '[[ $code -ne 0 && ! -e "$root/out3/medvue_20261023_051123.dump" ]]'
printf 'wrong-passphrase\n' >"$root/wrong"; chmod 600 "$root/wrong"
MEDVUE_OFFSITE_REMOTE="$R" MEDVUE_OFFSITE_PASSFILE="$root/wrong" MEDVUE_OFFSITE_RCLONE="$rclone_bin" \
  bash "$scripts/medvue-offsite-fetch.sh" reserved 20261021_115609 "$root/out4" >>"$root/stdout" 2>&1; code=$?
ok "fetch with another passphrase fails" '[[ $code -ne 0 && ! -s "$root/out4/medvue_20261021_115609.dump" ]]'

echo "RESULT: $([[ $fails -eq 0 ]] && echo PASS || echo "FAIL ($fails)")"
[[ $fails -eq 0 ]]
