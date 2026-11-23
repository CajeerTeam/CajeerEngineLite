#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d);pid=''
trap '[[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true; rm -rf "$tmp"' EXIT
cp -R "$root"/. "$tmp"/;rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_PORT_AI_COST:-19990};base="http://127.0.0.1:$port";cd "$tmp"
CAJEER_ADMIN_PASSWORD=CostControlHttp128 php bin/cajeer install "$base" 'AI Cost HTTP'
CAJEER_OPENAI_API_KEY='' php -S "127.0.0.1:$port" -t public router.php > "$tmp/server.log" 2>&1 & pid=$!
for i in {1..70};do curl -fsS "$base/health/live" >/dev/null 2>&1 && break;sleep .1;done
[[ $(curl -s -o /dev/null -w '%{http_code}' -d 'action=ai_cost_settings' "$base/admin") == 403 ]]
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/login" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -c "$tmp/cookies" --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' --data-urlencode 'password=CostControlHttp128' "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin" > "$tmp/admin"
grep -Fq 'AI Security &amp; Cost Control' "$tmp/admin" || grep -Fq 'AI Security & Cost Control' "$tmp/admin"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/admin" | head -1 | cut -d '"' -f4)
post="action=ai_cost_settings&cost_enabled=1&cost_daily_cents=10&cost_monthly_cents=100&cost_input_cents_per_million=100&cost_output_cents_per_million=300&cost_max_concurrent=2"
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d "$post" "$base/admin") == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d "$post&csrf=$csrf&cost_max_concurrent=999" "$base/admin") == 422 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d "$post&csrf=$csrf" "$base/admin") == 303 ]]
php -r '$v=json_decode(file_get_contents("storage/ai/cost-policy.json"),true,32,JSON_THROW_ON_ERROR);if(!$v["enabled"] || $v["monthly_cents"]!==100 || $v["max_concurrent"]!==2)exit(1);'
[[ $(stat -c '%a' storage/ai/cost-policy.json) == 600 ]]
php bin/cajeer ai status > "$tmp/usage.json"
php -r '$v=json_decode(file_get_contents($argv[1]),true);if($v["usage"]["cost_policy"]["monthly_cents"]!==100 || $v["usage"]["monthly_spent_microcents"]!==0)exit(1);' "$tmp/usage.json"
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin?ai=1") == 200 ]]
if curl -fsS "$base/admin?ai=1" | grep -q 'Цена входных 1 млн'; then echo 'Unauthenticated budget UI exposed' >&2; exit 1; fi
php -r '$v=json_decode(file_get_contents("storage/site.json"),true);if($v["schema"]!==14)exit(1);'
echo 'Lite 1.2.8 authenticated budget configuration, CSRF, file permissions, quota status and unchanged site schema OK'
