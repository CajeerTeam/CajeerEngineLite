#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d);pid=''
trap '[[ -z "$pid" ]] || { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; }; rm -rf "$tmp"' EXIT
cp -a "$root"/. "$tmp"/
rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_PORT:-19037};base="http://127.0.0.1:$port";cd "$tmp"
CAJEER_ADMIN_PASSWORD='DevTools137Password!' php bin/cajeer install "$base" 'Developer Test' >/dev/null
php -S "127.0.0.1:$port" -t public router.php > "$tmp/server.log" 2>&1 & pid=$!
for i in {1..100}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin/developer") == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin/developer?tab=diagnostics&format=json") == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -d 'action=preview' "$base/admin/developer/preview") == 403 ]]
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/login" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -c "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' \
  --data-urlencode 'password=DevTools137Password!' "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin/developer?tab=diagnostics" > "$tmp/diagnostics"
grep -Fq 'Developer Tools' "$tmp/diagnostics"
grep -Fq 'templates.render' "$tmp/diagnostics"
curl -fsS -b "$tmp/cookies" "$base/admin/developer?tab=diagnostics&format=json" > "$tmp/report.json"
php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if($d["version"]!=="1.3.7"||!isset($d["checks"])||!isset($d["counts"]))exit(1);' "$tmp/report.json"
! grep -Fq 'DevTools137Password!' "$tmp/report.json"
curl -fsS -b "$tmp/cookies" "$base/admin/developer?tab=templates&template=default%2Fpage.cjr" > "$tmp/editor"
grep -Fq 'name="source"' "$tmp/editor"
grep -Fq 'formaction="/admin/developer/preview"' "$tmp/editor"
! grep -Fq 'name="action" value="save"' "$tmp/editor"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/editor" | head -1 | cut -d '"' -f4)
revision=$(grep -o 'name="revision" value="[a-f0-9]*"' "$tmp/editor" | head -1 | cut -d '"' -f4)
[[ ${#revision} == 64 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode 'csrf=bad' --data-urlencode 'template=default/page.cjr' --data-urlencode 'action=save' "$base/admin/developer") == 403 ]]
sed 's/Работает на CajeerEngine Lite/DEVELOPER_HTTP_137/' templates/default/page.cjr > "$tmp/candidate.cjr"
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'template=default/page.cjr' \
  --data-urlencode "revision=$revision" --data-urlencode 'action=save' \
  --data-urlencode "source@$tmp/candidate.cjr" "$base/admin/developer") == 422 ]]
curl -fsS -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'template=default/page.cjr' \
  --data-urlencode 'page=/' --data-urlencode 'action=preview' \
  --data-urlencode "source@$tmp/candidate.cjr" "$base/admin/developer/preview" > "$tmp/preview.html"
grep -Fq 'DEVELOPER_HTTP_137' "$tmp/preview.html"
! grep -Fq 'DEVELOPER_HTTP_137' <(curl -fsS "$base/")
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode 'csrf=bad' --data-urlencode 'template=default/page.cjr' \
  --data-urlencode 'page=/' --data-urlencode 'action=preview' \
  --data-urlencode "source@$tmp/candidate.cjr" "$base/admin/developer/preview") == 403 ]]
# Restart with an explicit, temporary opt-in. Production is read-only by default.
kill "$pid";wait "$pid" 2>/dev/null || true;pid=''
CAJEER_DEVELOPER_TEMPLATE_WRITE=1 php -S "127.0.0.1:$port" -t public router.php > "$tmp/server2.log" 2>&1 & pid=$!
for i in {1..100}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
curl -fsS -b "$tmp/cookies" "$base/admin/developer?tab=templates&template=default%2Fpage.cjr" > "$tmp/enabled"
grep -Fq 'name="action" value="save"' "$tmp/enabled"
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'template=default/page.cjr' \
  --data-urlencode "revision=$revision" --data-urlencode 'action=save' \
  --data-urlencode "source@$tmp/candidate.cjr" "$base/admin/developer") == 303 ]]
grep -Fq 'DEVELOPER_HTTP_137' <(curl -fsS "$base/")
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'template=default/page.cjr' \
  --data-urlencode "revision=$revision" --data-urlencode 'action=save' \
  --data-urlencode "source@$tmp/candidate.cjr" "$base/admin/developer") == 422 ]]
curl -fsS -b "$tmp/cookies" "$base/admin/developer?tab=templates&template=default%2Fpage.cjr" > "$tmp/updated"
new_revision=$(grep -o 'name="revision" value="[a-f0-9]*"' "$tmp/updated" | head -1 | cut -d '"' -f4)
backup=$(grep -o 'name="backup" value="[^"]*"' "$tmp/updated" | head -1 | cut -d '"' -f4)
[[ $new_revision != "$revision" && ${#backup} -gt 20 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'template=default/page.cjr' \
  --data-urlencode "revision=$new_revision" --data-urlencode "backup=$backup" \
  --data-urlencode 'action=restore' "$base/admin/developer") == 303 ]]
! grep -Fq 'DEVELOPER_HTTP_137' <(curl -fsS "$base/")
curl -fsS -b "$tmp/cookies" "$base/admin/developer?tab=events" > "$tmp/events"
grep -Fq 'template.saved' "$tmp/events"
grep -Fq 'template.restored' "$tmp/events"
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'template=../../storage/site.json' \
  --data-urlencode "revision=$new_revision" --data-urlencode 'action=save' \
  --data-urlencode "source@$tmp/candidate.cjr" "$base/admin/developer") == 422 ]]
php bin/cajeer developer check --json > "$tmp/cli.json"
php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if($d["version"]!=="1.3.7"||!$d["ok"])exit(1);' "$tmp/cli.json"
echo 'Developer Tools 1.3.7 HTTP: auth, CSRF, JSON diagnostics, sandboxed preview, read-only policy, write, CAS, rollback, event audit OK'
