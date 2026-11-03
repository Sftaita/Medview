#!/usr/bin/env bash
# medvue-offsite-cron.test.sh — behaviour of medvue-offsite-cron.sh on a throwaway tree.
# medvue-offsite.sh, rclone and docker are stand-ins: no network, no container, nothing outside its temporary directory.
#
# Usage: medvue-offsite-cron.test.sh       Exit 0 only if every check passes.
set -uo pipefail

here=$(cd "$(dirname "$0")" && pwd)
cron="$here/../medvue-offsite-cron.sh"
root=$(mktemp -d)
trap 'rm -rf -- "$root"' EXIT
B="$root/backups" R="$root/remote" BIN="$root/bin"
mkdir -p "$B/postgres" "$B/status" "$BIN"

# Stand-in of medvue-offsite.sh: creates the remote set unless told to fail, and counts its runs.
cat >"$BIN/offsite" <<EOF
#!/usr/bin/env bash
echo run >>"$root/offsite.runs"
[[ -f "$root/offsite.fail" ]] && exit 3
[[ -f "$root/offsite.lazy" ]] && exit 0
mkdir -p "$R/daily/\$STAMP"
touch "$R/daily/\$STAMP/medvue_\$STAMP.dump.gpg"
EOF
cat >"$BIN/rclone" <<EOF
#!/usr/bin/env bash
[[ "\$1" == lsf ]] && ls "$R/\${2#remote:}" 2>/dev/null
EOF
# Stand-in of docker: records every mailer call; "psql" lists one administrator.
cat >"$BIN/docker" <<EOF
#!/usr/bin/env bash
case "\$*" in
  *mailer:test*) echo "\$*" >>"$root/mails"; [[ -f "$root/smtp.fail" ]] && exit 1; exit 0 ;;
  *psql*) echo admin@example.invalid ;;
  *printenv*) echo support@example.invalid ;;
esac
EOF
chmod +x "$BIN/offsite" "$BIN/rclone" "$BIN/docker"

export STAMP=20261104_034501
touch "$B/postgres/medvue_20261103_034501.dump" "$B/postgres/medvue_$STAMP.dump"

fails=0
check() { if eval "$2"; then echo "ok   $1"; else echo "FAIL $1"; fails=$((fails + 1)); fi; }

status() { # status RESULT FAILURES AGE_SECONDS
  printf '{"finishedAt":"%s","result":"%s","failures":%s}\n' "$(date -u -d "-$3 seconds" '+%Y-%m-%dT%H:%M:%SZ')" "$1" "$2" >"$B/status/backup.json"
}
reset() { rm -f "$root/offsite.runs" "$root/mails" "$root/offsite.fail" "$root/offsite.lazy" "$root/smtp.fail" "$B/status/offsite.json"; rm -rf "$R"; mkdir -p "$R"; status success 0 600; }
run() {
  MEDVUE_BACKUP_ROOT="$B" MEDVUE_OFFSITE_SCRIPT="$BIN/offsite" MEDVUE_OFFSITE_REMOTE="remote:" MEDVUE_OFFSITE_RCLONE="$BIN/rclone" MEDVUE_DOCKER="$BIN/docker" \
    bash "$cron" >"$root/out" 2>&1
  rc=$?
}
runs() { [[ -f "$root/offsite.runs" ]] && wc -l <"$root/offsite.runs" || echo 0; }
mails() { [[ -f "$root/mails" ]] && wc -l <"$root/mails" || echo 0; }

reset; run
check "success: exit 0" '[[ $rc -eq 0 ]]'
check "success: the validated script ran once" '[[ $(runs) -eq 1 ]]'
check "success: no alert" '[[ $(mails) -eq 0 ]]'
check "success: status records the newest set" 'grep -q "\"result\":\"success\",\"set\":\"$STAMP\"" "$B/status/offsite.json"'
check "success: logged" 'grep -q "done: set $STAMP on the remote" "$B/backup.log"'

reset; status failure 1 600; run
check "failed backup: exit 1" '[[ $rc -eq 1 ]]'
check "failed backup: nothing sent" '[[ $(runs) -eq 0 ]]'
check "failed backup: alert" '[[ $(mails) -eq 1 ]]'
check "failed backup: alert goes to the administrator" 'grep -q "admin@example.invalid" "$root/mails"'
check "failed backup: alert comes from the MedVue address" 'grep -q -- "--from=support@example.invalid" "$root/mails"'
check "failed backup: status failure" 'grep -q "\"result\":\"failure\"" "$B/status/offsite.json"'

reset; status success 2 600; run
check "backup with failures: nothing sent" '[[ $(runs) -eq 0 && $rc -eq 1 && $(mails) -eq 1 ]]'

reset; status success 0 90000; run
check "backup too old: nothing sent" '[[ $(runs) -eq 0 && $rc -eq 1 && $(mails) -eq 1 ]]'

reset; rm -f "$B/status/backup.json"; run
check "no backup status: nothing sent" '[[ $(runs) -eq 0 && $rc -eq 1 && $(mails) -eq 1 ]]'

reset; touch "$root/offsite.fail"; run
check "upload failure: exit 1 and alert" '[[ $rc -eq 1 && $(mails) -eq 1 ]]'
check "upload failure: exit status in the log" 'grep -q "exited with status 3" "$B/backup.log"'

reset; touch "$root/offsite.lazy"; run
check "script says ok but set absent: exit 1 and alert" '[[ $rc -eq 1 && $(mails) -eq 1 ]]'

reset; touch "$root/smtp.fail" "$root/offsite.fail"; run
check "alert not delivered: said in the log, still exit 1" '[[ $rc -eq 1 ]] && grep -q "ALERT NOT DELIVERED" "$B/backup.log"'

reset; exec 9>"$B/.offsite-cron.lock"; flock -n 9; run; flock -u 9; exec 9>&-
check "concurrent run: refused without sending" '[[ $rc -eq 1 && $(runs) -eq 0 && $(mails) -eq 1 ]]'

reset; run
check "after the lock is released: runs again" '[[ $rc -eq 0 && $(runs) -eq 1 ]]'

check "never prints or reads a secret file" '! grep -nE "\.env|passphrase" "$cron" | grep -vE "^[0-9]+:#" | grep -q .'

if [[ "$fails" -eq 0 ]]; then echo "RESULT: PASS"; else echo "RESULT: FAIL ($fails)"; exit 1; fi
