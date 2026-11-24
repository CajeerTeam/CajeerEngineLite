#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
cp -a "$ROOT"/. "$WORK"/ || { echo 'Cannot create CLI fixture' >&2; exit 1; }
REPORT="$(php "$WORK/bin/cajeer" update auto status)"
printf '%s' "$REPORT" | php -r '$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);if($d["installed_version"]!=="1.4.5"||!array_key_exists("pending",$d))exit(1);'
if CAJEER_AUTO_UPDATE_ENABLED=0 php "$WORK/bin/cajeer" update auto run >"$WORK/disabled.out" 2>"$WORK/disabled.err"; then
  echo 'Disabled auto-update unexpectedly installed' >&2; exit 1
fi
grep -q 'Auto updates disabled' "$WORK/disabled.err"
if php "$WORK/bin/cajeer" update auto invalid >"$WORK/invalid.out" 2>"$WORK/invalid.err"; then
  echo 'Unknown updater action unexpectedly accepted' >&2; exit 1
fi
if CAJEER_AUTO_UPDATE_ENABLED=1 CAJEER_AUTO_UPDATE_INSTALL=1 \
    CAJEER_AUTO_UPDATE_WORKERS_QUIESCED=1 CAJEER_AUTO_UPDATE_WINDOW_UTC=99:99-88:88 \
    php "$WORK/bin/cajeer" update auto run >"$WORK/window.out" 2>"$WORK/window.err"; then
  echo 'Invalid window unexpectedly accepted' >&2; exit 1
fi
grep -q 'CAJEER_AUTO_UPDATE_WINDOW_UTC' "$WORK/window.err"
test "$(cat "$WORK/VERSION")" = '1.4.5'
echo 'Automatic Updates 1.4.5 CLI: status, opt-in, window validation and command routing OK'
