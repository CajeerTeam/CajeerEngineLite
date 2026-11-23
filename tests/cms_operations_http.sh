#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d);pid=''
trap '[[ -z "$pid" ]] || { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; }; rm -rf "$tmp"' EXIT
cp -a "$root"/. "$tmp"/
rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_PORT:-19138};base="http://127.0.0.1:$port";cd "$tmp"
CAJEER_ADMIN_PASSWORD='Operations138Password!' php bin/cajeer install "$base" 'Operations Test' >/dev/null
php -S "127.0.0.1:$port" -t public router.php > "$tmp/server.log" 2>&1 & pid=$!
for i in {1..100};do curl -fsS "$base/health/live" >/dev/null 2>&1 && break;sleep .1;done
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin/operations") == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -d 'action=create' "$base/admin/operations") == 403 ]]
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/login" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -c "$tmp/cookies" --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' --data-urlencode 'password=Operations138Password!' "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin/operations" > "$tmp/panel"
grep -Fq 'CMS Operations' "$tmp/panel"
grep -Fq 'Переносимый экспорт и импорт' "$tmp/panel"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/panel" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d 'action=create&csrf=invalid' "$base/admin/operations") == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" --data-urlencode "csrf=$csrf" --data-urlencode 'action=restore' --data-urlencode 'name=../../etc/passwd' --data-urlencode 'confirm=RESTORE' "$base/admin/operations") == 422 ]]
curl -fsS -b "$tmp/cookies" --data-urlencode "csrf=$csrf" --data-urlencode 'action=create' "$base/admin/operations" > "$tmp/created"
archive=$(grep -o 'cms-[0-9A-Z]*-[a-f0-9]*\.tar' "$tmp/created" | head -1)
[[ $archive =~ ^cms-.*\.tar$ ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" --data-urlencode "csrf=$csrf" --data-urlencode 'action=verify' --data-urlencode "name=$archive" "$base/admin/operations") == 200 ]]
curl -fsS -b "$tmp/cookies" --data-urlencode "csrf=$csrf" --data-urlencode 'action=download' --data-urlencode "name=$archive" "$base/admin/operations" -o "$tmp/download.tar"
php -r '$f=$argv[1];if(substr(file_get_contents($f),257,5)!=="ustar")exit(1);' "$tmp/download.tar"
curl -fsS -b "$tmp/cookies" --data-urlencode "csrf=$csrf" --data-urlencode 'action=export' "$base/admin/operations" -o "$tmp/export.tar"
php -r '$f=$argv[1];if(substr(file_get_contents($f),257,5)!=="ustar")exit(1);' "$tmp/export.tar"
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -F "csrf=$csrf" -F 'action=import' -F 'confirm=IMPORT' -F "archive=@$tmp/export.tar;filename=portable.tar" "$base/admin/operations") == 303 ]]
# Import revokes all PHP sessions; an old cookie can no longer access operations.
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" "$base/admin/operations") == 403 ]]
# Log back in and verify cache management, retention and traversal controls.
curl -fsS -c "$tmp/newcookies" "$base/admin" > "$tmp/newlogin"
csrf2=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/newlogin" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/newcookies" -c "$tmp/newcookies" --data-urlencode "csrf=$csrf2" --data-urlencode 'action=login' --data-urlencode 'password=Operations138Password!' "$base/admin") == 303 ]]
curl -fsS -b "$tmp/newcookies" "$base/admin/operations" > "$tmp/newpanel"
csrf2=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/newpanel" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/newcookies" --data-urlencode "csrf=$csrf2" --data-urlencode 'action=cache' "$base/admin/operations") == 200 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/newcookies" --data-urlencode "csrf=$csrf2" --data-urlencode 'action=prune' --data-urlencode 'confirm=PRUNE' --data-urlencode 'keep=1' "$base/admin/operations") == 200 ]]
php bin/cajeer operations check > "$tmp/cli.json"
php -r '$report=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if(version_compare($report["version"],"1.3.8","<")||$report["backups"]!==1)exit(1);' "$tmp/cli.json"
echo 'CMS Operations 1.3.8 HTTP: authorization, CSRF, complete TAR backup/download/export, import, session revocation, cache, retention OK'
