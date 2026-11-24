#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; }; rm -rf "$tmp"' EXIT
cp -a "$root"/{core,public,templates,bin,bootstrap.php,router.php,VERSION} "$tmp/"
port=$(python -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')
base="http://127.0.0.1:$port"; cd "$tmp"
CAJEER_ADMIN_PASSWORD='MonitorSecret147Password!' php bin/cajeer install "$base" 'Monitor test' >/dev/null
CAJEER_MONITORING=1 php -S "127.0.0.1:$port" -t public router.php >"$tmp/server.log" 2>&1 & pid=$!
for i in {1..100}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
status() { curl --max-time 5 -sS -o /dev/null -w '%{http_code}' "$@"; }
[[ $(status "$base/admin/developer?tab=monitoring") == 403 ]]
[[ $(status "$base/?secret=MonitorPrivateQuery147") == 200 ]]
[[ $(status "$base/MonitorPrivatePath147") == 404 ]]
[[ $(status "$base/health/ready") == 200 ]]
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/login" | head -1 | cut -d '"' -f4)
[[ $(status -b "$tmp/cookies" -c "$tmp/cookies" --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' --data-urlencode 'password=MonitorSecret147Password!' "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin/developer?tab=monitoring" > "$tmp/monitor"
grep -Fq 'Мониторинг эксплуатации' "$tmp/monitor"
grep -Fq 'http.p95_ms' "$tmp/monitor"
cp storage/site.json "$tmp/site.good"
printf '{broken' >storage/site.json
[[ $(status "$base/health/ready") == 503 ]]
[[ $(status "$base/") == 500 ]]
cp "$tmp/site.good" storage/site.json
[[ $(status "$base/health/ready") == 200 ]]
php bin/cajeer monitor status > "$tmp/status.json"
php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if($r["all"]["errors_5xx"]<2||$r["all"]["errors_4xx"]<2||$r["groups"]["admin"]["requests"]<3||$r["invalid_records"]!==0)exit(1);' "$tmp/status.json"
! grep -E 'MonitorPrivate(Query|Path)147|MonitorSecret147Password' storage/app/monitoring/requests.jsonl
# Static files are not handled by the instrumented PHP entrypoint.
[[ $(status "$base/storage/app/monitoring/requests.jsonl") == 404 ]]
kill "$pid"; wait "$pid" 2>/dev/null || true; pid=''
CAJEER_MONITORING=0 php -S "127.0.0.1:$port" -t public router.php >"$tmp/server.log" 2>&1 & pid=$!
for i in {1..100}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
before=$(sha256sum storage/app/monitoring/requests.jsonl)
[[ $(status "$base/") == 200 ]]
[[ $(sha256sum storage/app/monitoring/requests.jsonl) == "$before" ]]
echo 'Operational HTTP 1.4.7: real request statuses, private admin report, 500/503 telemetry, no request secrets, disabled telemetry OK'
