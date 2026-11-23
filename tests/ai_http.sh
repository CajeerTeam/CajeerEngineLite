#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true; rm -rf "$tmp"' EXIT
cp -R "$root"/. "$tmp"/; rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_PORT_AI:-19975};base=http://127.0.0.1:$port
cd "$tmp"; CAJEER_ADMIN_PASSWORD=TestPassword12345 php bin/cajeer install "$base" Test
CAJEER_OPENAI_API_KEY='' php -S "127.0.0.1:$port" -t public router.php > "$tmp/server.log" 2>&1 & pid=$!
for i in {1..40}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin") == 200 ]]
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"
csrf=$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/login")
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -c "$tmp/cookies" -d "action=login&csrf=$csrf&password=TestPassword12345" "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin" > "$tmp/admin"
grep -q 'OpenAI — серверная интеграция' "$tmp/admin"
csrf=$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/admin")
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d "action=ai_settings&ai_enabled=1&ai_model=gpt-5-mini&ai_max_input_chars=3000&ai_max_output_tokens=256&ai_daily_requests=3&ai_daily_tokens=5000" "$base/admin") == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d "action=ai_settings&csrf=$csrf&ai_enabled=1&ai_model=../../bad&ai_max_input_chars=3000&ai_max_output_tokens=256&ai_daily_requests=3&ai_daily_tokens=5000" "$base/admin") == 422 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d "action=ai_settings&csrf=$csrf&ai_enabled=1&ai_model=gpt-5-mini&ai_max_input_chars=3000&ai_max_output_tokens=256&ai_daily_requests=3&ai_daily_tokens=5000" "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin?ai=1" > "$tmp/admin2"
grep -q 'gpt-5-mini' "$tmp/admin2"
[[ $(php -r '$x=json_decode(file_get_contents("storage/site.json"),true); echo $x["schema"];') == 14 ]]
[[ $(php -r '$x=json_decode(file_get_contents("storage/site.json"),true); echo $x["ai"]["daily_requests"];') == 3 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d "action=ai_test&csrf=$csrf&ai_prompt=Hello" "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin?ai=1" > "$tmp/admin3"
grep -q 'OpenAI API key is not configured' "$tmp/admin3"
# No billable call or usage reservation is made if the secret is missing.
php bin/cajeer ai status > "$tmp/status.json"
php -r '$x=json_decode(file_get_contents($argv[1]),true); if($x["usage"]["requests"]!==0||$x["key_configured"]!==false) exit(1);' "$tmp/status.json"
if grep -r -q 'sk-testingA123456789012' storage;then exit 1;fi
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin?ai=1") == 200 ]]
if curl -fsS "$base/admin?ai=1" | grep -q 'Тестовый запрос'; then echo 'Unauthenticated AI UI exposed' >&2; exit 1; fi
php bin/cajeer doctor | grep -q 'schema 14'
echo 'Lite 1.2.1 authenticated OpenAI settings and HTTP access OK'
