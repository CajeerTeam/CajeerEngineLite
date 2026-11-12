#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true; rm -rf "$tmp"' EXIT
cp -R "$root"/. "$tmp"/; rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_PORT:-18973}; base=http://127.0.0.1:$port
cd "$tmp"; CAJEER_ADMIN_PASSWORD=TestPassword123 php bin/cajeer install "$base" Test
php -S "127.0.0.1:$port" -t public router.php > "$tmp/server.log" 2>&1 & pid=$!
for i in {1..30}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
get(){ curl -fsS "$base$1"; }
status(){ curl -sS -o /dev/null -w '%{http_code}' "$@"; }
get / | grep -q 'Добро пожаловать'; get /robots.txt | grep -q Sitemap; get /sitemap.xml | grep -q "$base/"; get /assets/style.css | grep -q ':root'
[[ $(status "$base/storage/site.json") == 404 ]]; [[ $(status "$base/missing") == 404 ]]; [[ $(status -X POST "$base/admin") == 403 ]]
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"; csrf=$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/login")
[[ $(status -b "$tmp/cookies" -c "$tmp/cookies" -d "action=login&csrf=$csrf&password=TestPassword123" "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin?new=1" > "$tmp/admin"; csrf=$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/admin")
[[ $(status -b "$tmp/cookies" --data-urlencode 'body=<script>alert(1)</script>' -d "action=save&csrf=$csrf&path=/about&title=About&published=1&menu=1" "$base/admin") == 303 ]]
get /about | grep -q '&lt;script&gt;'; get /sitemap.xml | grep -q '/about'; get / | grep -q '/about'
[[ $(status -b "$tmp/cookies" -d "action=save&csrf=$csrf&path=/draft&title=Draft" "$base/admin") == 303 ]]; [[ $(status "$base/draft") == 404 ]]
[[ $(status -b "$tmp/cookies" -d "action=logout&csrf=$csrf" "$base/admin") == 303 ]]; [[ $(status -b "$tmp/cookies" -d "action=save&csrf=$csrf&path=/bad&title=Bad" "$base/admin") == 403 ]]
echo 'HTTP smoke OK'
