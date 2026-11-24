#!/usr/bin/env bash
set -euo pipefail
source_root=$(cd "$(dirname "$0")/.." && pwd)
work=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; }; rm -rf "$work"' EXIT
cp -a "$source_root"/. "$work"/
rm -f "$work/storage/site.json" "$work/storage/site.lock"
port=${TEST_PORT:-19143};base="http://127.0.0.1:$port"
cd "$work"
CAJEER_ADMIN_PASSWORD='Harden143ExamplePassword!' php bin/cajeer install "$base" 'Security Acceptance 143' >/dev/null
php -S "127.0.0.1:$port" -t public router.php > "$work/php.log" 2>&1 & pid=$!
for i in {1..80}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
status() { curl --max-time 8 -s -o /dev/null -w '%{http_code}' "$@"; }
[[ $(status "$base/admin") == 200 ]]
[[ $(status -H 'Host: sub.attacker.test' "$base/admin") == 421 ]]
[[ $(status "$base/admin/developer") == 403 ]]
[[ $(status "$base/admin/operations") == 403 ]]
[[ $(status "$base/storage/site.json") == 404 ]]
curl -fsS -c "$work/cookie" "$base/admin" > "$work/login.html"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$work/login.html" | head -1 | cut -d '"' -f4)
[[ ${#csrf} == 64 ]]
[[ $(status -b "$work/cookie" -c "$work/cookie" -H 'Sec-Fetch-Site: same-site' \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' --data-urlencode 'password=Harden143ExamplePassword!' "$base/admin") == 403 ]]
[[ $(status -b "$work/cookie" -c "$work/cookie" -H 'Content-Type: text/plain' \
    --data-binary "action=login&csrf=$csrf" "$base/admin") == 415 ]]
[[ $(status -b "$work/cookie" -c "$work/cookie" -H "Origin: $base" -H 'Sec-Fetch-Site: same-origin' \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' --data-urlencode 'password=Harden143ExamplePassword!' "$base/admin") == 303 ]]
[[ $(status -b "$work/cookie" "$base/admin/developer") == 200 ]]
[[ $(status -b "$work/cookie" "$base/admin/operations") == 200 ]]
curl -fsS -b "$work/cookie" -D "$work/admin.headers" -o /dev/null "$base/admin"
grep -Fqi "script-src-attr 'none'" "$work/admin.headers"
grep -Fqi "worker-src 'none'" "$work/admin.headers"
php bin/cajeer security audit --json > "$work/security.json"
php -r '$r=json_decode(file_get_contents($argv[1]),true,128,JSON_THROW_ON_ERROR);if(!$r["ok"]||count($r["checks"])<5)exit(1);' "$work/security.json"
echo 'Security Hardening 1.4.3 HTTP: authority, fetch metadata, CSRF, content type, private routes, session, CSP, audit OK'
