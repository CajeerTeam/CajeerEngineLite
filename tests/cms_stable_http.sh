#!/usr/bin/env bash
set -euo pipefail
source_root=$(cd "$(dirname "$0")/.." && pwd)
work=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; }; rm -rf "$work"' EXIT
cp -a "$source_root"/. "$work"/
rm -f "$work/storage/site.json" "$work/storage/site.lock"
port=${TEST_PORT:-19140}; base="http://127.0.0.1:$port"
cd "$work"
php -S "127.0.0.1:$port" -t public router.php > "$work/php.log" 2>&1 & pid=$!
for i in {1..100}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .08; done
status() { curl --max-time 15 -s -o /dev/null -w '%{http_code}' "$@"; }
[[ $(status "$base/health/live") == 200 ]]
[[ $(status "$base/health/ready") == 503 ]]
[[ $(status -X POST "$base/health/ready") == 405 ]]
CAJEER_ADMIN_PASSWORD='Release140Password!' php bin/cajeer install "$base" 'Ready Check' >/dev/null
[[ $(status "$base/health/ready") == 200 ]]
[[ $(curl -fsS "$base/health/ready") == '{"status":"ready"}' ]]
[[ $(curl --max-time 15 -s -I -o /dev/null -w '%{http_code}' "$base/health/ready") == 200 ]]
[[ $(status -H 'Host: unexpected.test' "$base/admin") == 421 ]]
[[ $(status "$base/") == 200 ]]
curl -fsS "$base/admin" > "$work/login.html"
grep -q 'Вход в админ-панель' "$work/login.html"
# Invalid state blocks readiness, but liveness remains healthy. Neither response discloses JSON.
cp "$work/storage/site.json" "$work/original.json"
chmod 0600 "$work/original.json"
printf '{invalid\n' > "$work/storage/site.json"
[[ $(status "$base/health/ready") == 503 ]]
[[ $(curl -fsS "$base/health/live") == '{"status":"ok"}' ]]
[[ $(curl -s "$base/health/ready") == '{"status":"unavailable"}' ]]
cp "$work/original.json" "$work/storage/site.json"
chmod 0600 "$work/storage/site.json"
[[ $(status "$base/health/ready") == 200 ]]
# Full release gate on a production-style HTTPS configuration (no fake HTTPS network).
php -r 'require "bootstrap.php"; $a=new \CajeerEngine\Lite(getcwd());$a->change(static function(array $s):array {$s["settings"]["url"]="https://release.test"; return $s;});'
php bin/cajeer operations backup create >/dev/null
set +e
php bin/cajeer release stable-check --json > "$work/release.json"
result=$?
set -e
[[ $result == 2 ]]
php -r '$s=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if(($s["version"]??"")!=="1.4.0"||($s["automated_ok"]??false)!==true||($s["status"]??"")!=="pending_live"||($s["production_accepted"]??true)!==false) exit(1);' "$work/release.json"
echo 'CMS Experience Stable 1.4.0 HTTP: readiness, liveness, safe failure, host guard and CLI release gate OK'
