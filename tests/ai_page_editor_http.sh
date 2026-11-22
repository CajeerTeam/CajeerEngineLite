#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true; rm -rf "$tmp"' EXIT
cp -R "$root"/. "$tmp"/; rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_PORT_EDITOR:-19988}; base=http://127.0.0.1:$port
cd "$tmp"
CAJEER_ADMIN_PASSWORD=ValidEditorPassword124 php bin/cajeer install "$base" 'Editor integration'
CAJEER_OPENAI_API_KEY='' php -S "127.0.0.1:$port" -t public router.php > "$tmp/server.log" 2>&1 & pid=$!
for i in {1..70}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin/ai/editor") == 403 ]]
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/login" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -c "$tmp/cookies" \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' \
    --data-urlencode 'password=ValidEditorPassword124' "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin/ai/editor" > "$tmp/editor"
grep -Fq 'Редактура существующей страницы' "$tmp/editor"
grep -Fq 'name="operation"' "$tmp/editor"
grep -Fq 'name="scope"' "$tmp/editor"
grep -Fq 'name="action" value="generate"' "$tmp/editor"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/editor" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
    --data-urlencode 'action=generate' --data-urlencode 'path=/' "$base/admin/ai/editor") == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=generate' --data-urlencode 'path=/' \
    --data-urlencode 'scope=body' --data-urlencode 'operation=improve' "$base/admin/ai/editor") == 503 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=apply' --data-urlencode 'path=/' \
    --data-urlencode 'id=bad' "$base/admin/ai/editor") == 409 ]]
# Create a legitimate revision on the server; HTTP restore must use the real current revision.
php -r 'require "bootstrap.php"; $a=new \CajeerEngine\Lite(getcwd()); $s=$a->load(); $h=new \CajeerEngine\AI\PageHistory(getcwd(),$s["identity"]["generation"]); $a->change(static function(array $state) use($h):array {$h->save($state["pages"]["/"]); $state["pages"]["/"]["body"]="Edited from backend";return $state;});'
curl -fsS -b "$tmp/cookies" "$base/admin/ai/editor?path=%2F" > "$tmp/editor2"
grep -Fq 'История изменений AI-редактора' "$tmp/editor2"
grep -Fq 'Восстановить версию' "$tmp/editor2"
snapshot=$(grep -o 'name="snapshot_id" value="[a-f0-9]*"' "$tmp/editor2" | head -1 | cut -d '"' -f4)
revision=$(grep -o 'name="revision" value="[a-f0-9]*"' "$tmp/editor2" | head -1 | cut -d '"' -f4)
[[ ${#snapshot} == 32 && ${#revision} == 64 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=restore' --data-urlencode 'path=/' \
    --data-urlencode "snapshot_id=$snapshot" --data-urlencode 'revision=bad' \
    --data-urlencode 'confirm_restore=1' "$base/admin/ai/editor") == 422 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=restore' --data-urlencode 'path=/' \
    --data-urlencode "snapshot_id=$snapshot" --data-urlencode "revision=$revision" \
    --data-urlencode 'confirm_restore=1' "$base/admin/ai/editor") == 303 ]]
php -r '$s=json_decode(file_get_contents("storage/site.json"),true);if($s["schema"]!==13 || $s["pages"]["/"]["body"]==="Edited from backend") exit(1);'
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=restore' --data-urlencode 'path=/' \
    --data-urlencode "snapshot_id=$snapshot" --data-urlencode "revision=$revision" \
    --data-urlencode 'confirm_restore=1' "$base/admin/ai/editor") == 422 ]]
curl -fsS -b "$tmp/cookies" "$base/admin" > "$tmp/admin"
grep -Fq 'AI Page Editor' "$tmp/admin"
php -r '$a=json_decode(file_get_contents("storage/site.json"),true);if(count($a["pages"])!==1)exit(1);'
echo 'Lite 1.2.4 HTTP CSRF, access control, real revision restoration and stale snapshot blocking OK'
