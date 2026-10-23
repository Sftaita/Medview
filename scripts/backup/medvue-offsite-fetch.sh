#!/usr/bin/env bash
# medvue-offsite-fetch.sh — bring back one off-site set and decrypt it
# (docs/backup.md §2 ter, docs/decisions.md D209).
#
# Downloads <remote>/<daily|reserved>/<stamp>/ into a NEW directory, checks
# the encrypted files against their SHA256SUMS, decrypts them and checks each
# plaintext against its .sha256. Writes nothing anywhere else and restores
# nothing: prove the set with medvue-restore-test.sh, then follow
# docs/backup.md §4.
#
# Usage: medvue-offsite-fetch.sh daily|reserved STAMP OUT_DIR
# Environment overrides: MEDVUE_OFFSITE_REMOTE, MEDVUE_OFFSITE_PASSFILE,
# MEDVUE_OFFSITE_RCLONE (same defaults as medvue-offsite.sh).
set -euo pipefail
export PATH="$HOME/bin:$PATH"

REMOTE="${MEDVUE_OFFSITE_REMOTE:-gdrive:INFORMATIQUE/Base de donnée/medvue}"
PASSFILE="${MEDVUE_OFFSITE_PASSFILE:-$HOME/.medvue-backup-passphrase}"
RCLONE="${MEDVUE_OFFSITE_RCLONE:-rclone}"

kind="${1:-}" stamp="${2:-}" out="${3:-}"
[[ "$kind" == daily || "$kind" == reserved ]] || { echo "usage: $0 daily|reserved STAMP OUT_DIR" >&2; exit 2; }
[[ "$stamp" =~ ^[0-9]{8}_[0-9]{6}$ ]] || { echo "STAMP must be YYYYMMDD_HHMMSS" >&2; exit 2; }
[[ -n "$out" && ! -e "$out" ]] || { echo "OUT_DIR must be a new path" >&2; exit 2; }
[[ -f "$PASSFILE" && -s "$PASSFILE" && "$(stat -c %a "$PASSFILE")" == "600" ]] || { echo "FAIL: passphrase file missing, empty or not 600 ($PASSFILE)" >&2; exit 1; }

umask 077
mkdir -m 700 -p "$out/encrypted"
gnupg=$(mktemp -d)
trap 'rm -rf -- "$gnupg"' EXIT
export GNUPGHOME="$gnupg"

"$RCLONE" copy "$REMOTE/$kind/$stamp" "$out/encrypted"
[[ -f "$out/encrypted/SHA256SUMS" ]] || { echo "FAIL: $kind/$stamp not found or incomplete" >&2; exit 1; }
(cd "$out/encrypted" && sha256sum -c --quiet SHA256SUMS) || { echo "FAIL: encrypted files do not match SHA256SUMS" >&2; exit 1; }

for enc in "$out"/encrypted/*.gpg; do
  name=$(basename "$enc" .gpg)
  gpg --batch --quiet --yes --no-tty --pinentry-mode loopback --passphrase-file "$PASSFILE" -d -o "$out/$name" -- "$enc"
  cp -- "$out/encrypted/$name.sha256" "$out/$name.sha256"
  (cd "$out" && sha256sum -c --quiet "$name.sha256") || { echo "FAIL: $name does not match its digest after decryption" >&2; exit 1; }
  echo "OK $out/$name"
done
echo "Next: medvue-restore-test.sh --dump $out/medvue_$stamp.dump --keys $out/jwt-keys_$stamp.tar.gz"
