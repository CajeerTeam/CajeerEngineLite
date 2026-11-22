#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true; rm -rf "$tmp"' EXIT
cp -R "$root"/. "$tmp"/; rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_PORT_AI_SUPPORT:-19989};base="http://127.0.0.1:$port";cd "$tmp"
CAJEER_ADMIN_PASSWORD=RealSupportHttpPassword127 php bin/cajeer install "$base" 'AI Support HTTP'
CAJEER_OPENAI_API_KEY='' php -S "127.0.0.1:$port" -t public router.php > "$tmp/server.log" 2>&1 & pid=$!
for i in {1..80};do curl -fsS "$base/health/live" >/dev/null 2>&1 && break;sleep .1;done
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin/ai/support") == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin/ai/support?format=json") == 403 ]]
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/login" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -c "$tmp/cookies" \
 --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' \
 --data-urlencode 'password=RealSupportHttpPassword127' "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin/ai/support" > "$tmp/support"
grep -Fq 'AI Technical Support' "$tmp/support"
grep -Fq 'name="action" value="generate"' "$tmp/support"
grep -Fq 'Общее состояние CMS' "$tmp/support"
curl -fsS -b "$tmp/cookies" "$base/admin/ai/support?category=auth&format=json" > "$tmp/report.json"
php -r '$s=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);if($s["category"]!=="auth" || !isset($s["snapshot"]["checks"]["auth.state"]) || str_contains(file_get_contents($argv[1]),"RealSupportHttpPassword127"))exit(1);' "$tmp/report.json"
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" "$base/admin/ai/support?category=../../etc/passwd") == 400 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" "$base/admin/ai/support?format=html") == 400 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" --data-urlencode 'action=generate' "$base/admin/ai/support") == 403 ]]
curl -fsS -b "$tmp/cookies" "$base/admin" > "$tmp/admin"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/admin" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" --data-urlencode "csrf=$csrf" --data-urlencode 'action=generate' "$base/admin/ai/support") == 503 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" --data-urlencode "csrf=$csrf" --data-urlencode 'action=unknown' "$base/admin/ai/support") == 400 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" --data-urlencode "csrf=$csrf" --data-urlencode 'action=delete' "$base/admin/ai/support") == 303 ]]
grep -Fq 'AI Technical Support — диагностика и поддержка CMS' "$tmp/admin"
php -r '$s=json_decode(file_get_contents("storage/site.json"),true);if($s["schema"]!==13||count($s["pages"])!==1)exit(1);'
echo 'Lite 1.2.7 HTTP AI support: real diagnostics, export, authenticated access, CSRF, offline guidance, no paid requests without key OK'
