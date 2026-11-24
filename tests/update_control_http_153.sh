#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; }; rm -rf "$tmp"' EXIT
cp -a "$root"/{core,public,templates,bin,bootstrap.php,router.php,VERSION} "$tmp/"
port=$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')
base="http://127.0.0.1:$port"; cd "$tmp"
CAJEER_ADMIN_PASSWORD='ControlSecret153Password!' php bin/cajeer install "$base" 'Update control test' >/dev/null
php -S "127.0.0.1:$port" -t public router.php >"$tmp/server.log" 2>&1 & pid=$!
for i in {1..100}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
status() { curl --max-time 5 -sS -o /dev/null -w '%{http_code}' "$@"; }
[[ $(status "$base/admin/updates") == 403 ]]
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/login" | head -1 | cut -d '"' -f4)
[[ $(status -b "$tmp/cookies" -c "$tmp/cookies" --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' --data-urlencode 'password=ControlSecret153Password!' "$base/admin") == 303 ]]
refresh() { curl -fsS -b "$tmp/cookies" "$base/admin/updates" > "$tmp/control"; csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/control" | head -1 | cut -d '"' -f4); rev=$(grep -o 'name="revision" value="[a-f0-9]*"' "$tmp/control" | head -1 | cut -d '"' -f4); }
post() { status -b "$tmp/cookies" --data-urlencode "csrf=$csrf" --data-urlencode "revision=$rev" "$@" "$base/admin/updates"; }
refresh
grep -Fq 'Update Control Center' "$tmp/control"; grep -Fq "$(cat VERSION)" "$tmp/control"; grep -Fq 'История пуста' "$tmp/control"
[[ $(status -b "$tmp/cookies" -d 'action=check' "$base/admin/updates") == 403 ]]
[[ $(post -H 'Origin: https://evil.example' -d 'action=check') == 403 ]]
[[ $(post -d 'action=settings&channel=stable&interval=0&window=02:00-04:00') == 422 ]]
[[ $(post -d 'action=install&confirm=1.5.4') == 422 ]]
[[ $(post -d 'action=settings&channel=preview&interval=8&window=22:00-04:00&checks=1') == 303 ]]
[[ $(post -d 'action=check') == 422 ]]
refresh; grep -Fq 'value="preview" selected' "$tmp/control"
[[ $(post -d 'action=check') == 303 ]]
refresh; grep -Fq 'в очереди' "$tmp/control"
[[ $(post -d 'action=check') == 422 ]]
[[ $(status "$base/storage/app/update-control/state.json") == 404 ]]
php bin/cajeer update control status > "$tmp/state"
php -r '$s=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if($s["job"]["type"]!=="check"||$s["settings"]["channel"]!=="preview")exit(1);' "$tmp/state"
if php bin/cajeer update control work > /dev/null 2>&1; then exit 1; fi
[[ $(post -d 'action=cancel') == 303 ]]
refresh; grep -Fq 'Отменено' "$tmp/control"
# No network: a configured worker with invalid repository records a failed check.
[[ $(post -d 'action=check') == 303 ]]
if CAJEER_UPDATE_CONTROL_WORKER=1 CAJEER_AUTO_UPDATE_GITHUB_REPO=invalid php bin/cajeer update control work > /dev/null 2>&1; then exit 1; fi
refresh; grep -Fq 'Ошибка (см. CLI)' "$tmp/control"
# The scheduled worker records a retry across processes and exposes the heartbeat only to the administrator.
[[ $(post -d 'action=check') == 303 ]]
CAJEER_UPDATE_CONTROL_WORKER=1 CAJEER_AUTO_UPDATE_GITHUB_REPO=invalid php bin/cajeer update scheduler tick > "$tmp/retry"
refresh; grep -Fq 'retry_scheduled' "$tmp/control"; grep -Fq 'Следующая попытка:' "$tmp/control"
[[ $(status "$base/storage/app/update-control/scheduler.json") == 404 ]]
[[ $(post -d 'action=cancel') == 303 ]]
[[ $(status "$base/health/ready") == 200 ]]
echo 'Update Control HTTP: authentication, origin/CSRF, validation, settings, queue/cancel, real CLI failure and private history OK'
