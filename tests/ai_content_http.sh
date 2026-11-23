#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true; rm -rf "$tmp"' EXIT
cp -R "$root"/. "$tmp"/; rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_PORT_CONTENT:-19977}; base=http://127.0.0.1:$port
cd "$tmp"
CAJEER_ADMIN_PASSWORD=TestContentPassword123 php bin/cajeer install "$base" 'Test Content'
CAJEER_OPENAI_API_KEY='' php -S "127.0.0.1:$port" -t public router.php > "$tmp/server.log" 2>&1 & pid=$!
for i in {1..60}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin/ai/content") == 403 ]]
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"
csrf=$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/login")
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -c "$tmp/cookies" -d "action=login&csrf=$csrf&password=TestContentPassword123" "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin/ai/content" > "$tmp/content"
grep -Fq 'Новая генерация' "$tmp/content"
grep -Fq 'name="mode"' "$tmp/content"
grep -Fq 'name="target"' "$tmp/content"
grep -Fq 'name="action" value="generate"' "$tmp/content"
csrf=$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/content" | head -1)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d 'action=generate' "$base/admin/ai/content") == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d "csrf=$csrf&action=generate&mode=page&path=/test&topic=hello&style=neutral&length=short" "$base/admin/ai/content") == 503 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d "csrf=$csrf&action=apply&id=garbage" "$base/admin/ai/content") == 409 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d "csrf=$csrf&action=discard" "$base/admin/ai/content") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin" > "$tmp/admin"
grep -Fq '/admin/ai/content' "$tmp/admin"
php -r '$s=json_decode(file_get_contents("storage/site.json"),true);if($s["schema"]!==14 || count($s["pages"])!==1) exit(1);'
php bin/cajeer ai status > "$tmp/usage"
php -r '$x=json_decode(file_get_contents($argv[1]),true);if($x["usage"]["requests"]!==0)exit(1);' "$tmp/usage"
echo 'Lite 1.2.3 authenticated content generation forms, CSRF, no provider leakage and unchanged pages OK'
