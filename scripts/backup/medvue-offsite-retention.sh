#!/usr/bin/env bash
# medvue-offsite-retention.sh — which off-site daily sets to DELETE (docs/backup.md §2 ter).
#
# Reads set names "YYYYMMDD_HHMMSS" on stdin (one per line, anything else
# ignored) and prints on stdout those that are no longer kept. Deletes
# nothing itself. Same rule as MedClick's backup-retention.sh: keep the
# newest set of each of the last KEEP_DAILY days that have a set, of each of
# the last KEEP_WEEKLY ISO weeks and of each of the last KEEP_MONTHLY months;
# the newest set of all is always kept.
#
#   KEEP_DAILY (7), KEEP_WEEKLY (4), KEEP_MONTHLY (12)
set -euo pipefail

KEEP_DAILY=${KEEP_DAILY:-7}
KEEP_WEEKLY=${KEEP_WEEKLY:-4}
KEEP_MONTHLY=${KEEP_MONTHLY:-12}

mapfile -t names < <(grep -E '^[0-9]{8}_[0-9]{6}$' | sort -ru || true)
declare -A keep=() day=() week=() month=()

for name in "${names[@]}"; do
  d=${name:0:8}
  w=$(date -u -d "$d" +%G-%V)
  m=${name:0:6}
  if [[ -z "${day[$d]:-}" && "${#day[@]}" -lt "$KEEP_DAILY" ]]; then day[$d]=1; keep[$name]=1; fi
  if [[ -z "${week[$w]:-}" && "${#week[@]}" -lt "$KEEP_WEEKLY" ]]; then week[$w]=1; keep[$name]=1; fi
  if [[ -z "${month[$m]:-}" && "${#month[@]}" -lt "$KEEP_MONTHLY" ]]; then month[$m]=1; keep[$name]=1; fi
done

[[ "${#names[@]}" -gt 0 ]] && keep[${names[0]}]=1
for name in "${names[@]}"; do
  [[ -n "${keep[$name]:-}" ]] || echo "$name"
done
