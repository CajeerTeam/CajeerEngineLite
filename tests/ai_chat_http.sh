#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true; rm -rf "$tmp"' EXIT
cp -R "$root"/. "$tmp"/; rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_PORT_CHAT:-19976};base=http://127.0.0.1:$port
cd "$tmp"
CAJEER_ADMIN_PASSWORD=ChatPassword12345 php bin/cajeer install "$base" Test
CAJEER_OPENAI_API_KEY='' php -S "127.0.0.1:$port" -t public router.php > "$tmp/server.log" 2>&1 & pid=$!
for i in {1..50}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin/ai") == 403 ]]
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"
csrf=$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/login")
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -c "$tmp/cookies" -d "action=login&csrf=$csrf&password=ChatPassword12345" "$base/admin") == 303 ]]
curl -fsS -D "$tmp/headers" -b "$tmp/cookies" "$base/admin/ai" > "$tmp/chat"
grep -q 'Cajeer AI Assistant' "$tmp/chat"
grep -q 'ai-chat.js' "$tmp/chat"
grep -q 'script-src '\''self'\''' "$tmp/headers"
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d 'action=create' "$base/admin/ai") == 403 ]]
csrf=$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/chat" | head -1)
location=$(curl -s -D - -o /dev/null -b "$tmp/cookies" -d "action=create&csrf=$csrf" "$base/admin/ai" | tr -d '\r' | sed -n 's/^Location: //Ip')
[[ "$location" =~ ^/admin/ai\?id=[a-f0-9]{32}$ ]]
id=${location##*=}
curl -fsS -b "$tmp/cookies" "$base$location" > "$tmp/chat2"
grep -q 'ai-chat-send' "$tmp/chat2"
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d "action=send&csrf=$csrf&id=$id&message=Hello" "$base/admin/ai") == 503 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d "action=send&csrf=wrong&id=$id&message=Hello" "$base/admin/ai") == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d "action=delete&csrf=$csrf&id=$id" "$base/admin/ai") == 303 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" "$base$location") == 404 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d "action=clear&csrf=$csrf" "$base/admin/ai") == 303 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/assets/ai-chat.js") == 200 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -d "action=send&csrf=$csrf&id=../../bad&message=Hello" "$base/admin/ai") == 503 ]]
php -r '$s=json_decode(file_get_contents("storage/site.json"),true);if($s["schema"]!==14||$s["ai"]["enabled"]!==false)exit(1);'
echo 'Lite 1.2.2 authenticated AI chat, private CRUD, CSP, CSRF and unconfigured-provider HTTP OK'
