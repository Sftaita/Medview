#!/usr/bin/env bash
# fake-rclone.sh — stand-in for the few rclone commands used by the off-site
# scripts, on local directories only, for tests where rclone is not installed
# (CI). Same exit codes as rclone where the scripts rely on them: 3 for a
# missing directory, non-zero when --immutable meets a different file.
set -euo pipefail

cmd="$1"
shift
args=()
immutable=0 dirs=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --immutable) immutable=1 ;;
    --one-way | --files-only) ;;
    --dirs-only) dirs=1 ;;
    --log-file | --log-level) shift ;;
    --*) echo "fake-rclone: unsupported option $1" >&2; exit 2 ;;
    *) args+=("$1") ;;
  esac
  shift
done

case "$cmd" in
  lsf)
    [[ -d "${args[0]}" ]] || { echo "directory not found" >&2; exit 3; }
    if [[ "$dirs" -eq 1 ]]; then
      find "${args[0]}" -mindepth 1 -maxdepth 1 -type d -printf '%f/\n' | sort
    else
      find "${args[0]}" -mindepth 1 -maxdepth 1 -type f -printf '%f\n' | sort
    fi
    ;;
  copy)
    [[ -d "${args[0]}" ]] || { echo "directory not found" >&2; exit 3; }
    mkdir -p "${args[1]}"
    for f in "${args[0]}"/*; do
      [[ -f "$f" ]] || continue
      n=$(basename "$f")
      if [[ -e "${args[1]}/$n" ]]; then
        cmp -s "$f" "${args[1]}/$n" && continue
        [[ "$immutable" -eq 1 ]] && { echo "immutable file modified: $n" >&2; exit 1; }
      fi
      cp -L -- "$f" "${args[1]}/$n"
    done
    ;;
  check)
    for f in "${args[0]}"/*; do
      [[ -f "$f" ]] || continue
      cmp -s "$f" "${args[1]}/$(basename "$f")" || { echo "differs: $(basename "$f")" >&2; exit 1; }
    done
    ;;
  purge)
    rm -rf -- "${args[0]:?}"
    ;;
  *)
    echo "fake-rclone: unsupported command $cmd" >&2
    exit 2
    ;;
esac
