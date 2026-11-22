#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true; rm -rf "$tmp"' EXIT
cp -R "$root"/. "$tmp"/; rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_PORT_SEO:-19989}; base=http://127.0.0.1:$port
cd "$tmp"
CAJEER_ADMIN_PASSWORD=ValidSeoHttpPassword125 php bin/cajeer install "$base" 'SEO HTTP test'
CAJEER_OPENAI_API_KEY='' php -S "127.0.0.1:$port" -t public router.php > "$tmp/server.log" 2>&1 & pid=$!
for i in {1..80}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin/ai/seo") == 403 ]]
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/login" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -c "$tmp/cookies" \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' \
    --data-urlencode 'password=ValidSeoHttpPassword125' "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin/ai/seo" > "$tmp/seo"
grep -Fq 'SEO-аудит сайта' "$tmp/seo"
grep -Fq 'name="scope"' "$tmp/seo"
grep -Fq 'name="action" value="generate"' "$tmp/seo"
grep -Fq 'отключён' "$tmp/seo" || grep -Fq 'OpenAI' "$tmp/seo"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/seo" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode 'action=generate' --data-urlencode 'path=/' --data-urlencode 'scope=metadata' "$base/admin/ai/seo") == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=generate' --data-urlencode 'path=/' \
  --data-urlencode 'scope=metadata' "$base/admin/ai/seo") == 503 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=apply' --data-urlencode 'path=/' \
  --data-urlencode 'id=bad' "$base/admin/ai/seo") == 409 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=discard' --data-urlencode 'path=/' "$base/admin/ai/seo") == 303 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" "$base/admin/ai/seo?path=%2Funknown") == 404 ]]
curl -fsS -b "$tmp/cookies" "$base/admin" > "$tmp/admin"
grep -Fq 'AI SEO Assistant' "$tmp/admin"
php -r '$s=json_decode(file_get_contents("storage/site.json"),true);if($s["schema"]!==13||count($s["pages"])!==1)exit(1);'
echo 'Lite 1.2.5 HTTP SEO audit UI, login, CSRF, forbidden generation, stale preview, no unwanted writes OK'
