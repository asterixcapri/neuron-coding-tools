#!/usr/bin/env bash
# Removes the sandboxes drive.php registered (and only those). Evidence in
# .verify/evidence/ is kept; pass --evidence to delete it too.
set -u
repo="$(cd "$(dirname "$0")/../../../.." && pwd)"
registry="$repo/.verify/sandboxes"
for entry in "$registry"/*; do
  [ -f "$entry" ] || continue
  sandbox="$(cat "$entry")"
  case "$sandbox" in
    */ncto-verify-*) rm -rf -- "$sandbox" && echo "removed $sandbox" ;;
    *) echo "skipped unexpected registry entry: $sandbox" ;;
  esac
  rm -f -- "$entry"
done
if [ "${1:-}" = "--evidence" ]; then rm -rf -- "$repo/.verify/evidence" && echo "removed evidence"; fi
[ "${1:-}" = "--evidence" ] || echo "evidence kept in $repo/.verify/evidence"
