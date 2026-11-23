#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; }; rm -rf "$tmp"' EXIT
cp -R "$root"/. "$tmp"/
rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_PORT:-19033}; base="http://127.0.0.1:$port"
cd "$tmp"
CAJEER_ADMIN_PASSWORD='BlockEditorPassword133!' php bin/cajeer install "$base" 'Block Editor Integration' >/dev/null
php -S "127.0.0.1:$port" -t public router.php > "$tmp/server.log" 2>&1 & pid=$!
for i in {1..80}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin/editor?new=1") == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin/editor/preview") == 403 ]]
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/login" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -c "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' \
  --data-urlencode 'password=BlockEditorPassword133!' "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin/editor?new=1" > "$tmp/editor"
grep -Fq 'id="cms-editor-form"' "$tmp/editor"
grep -Fq 'src="/assets/block-editor.js"' "$tmp/editor"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/editor" | head -1 | cut -d '"' -f4)
blocks='[{"type":"heading","level":2,"text":"Заголовок <script>evil()</script>"},{"type":"paragraph","text":"Безопасный контент"},{"type":"list","ordered":false,"items":["Пункт 1","Пункт 2"]},{"type":"link","text":"О сайте","url":"/about"}]'
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode 'path=/example' --data-urlencode 'title=Example' \
  --data-urlencode "blocks_json=$blocks" "$base/admin/editor/preview") == 403 ]]
[[ $(curl -s -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'path=/example' \
  --data-urlencode 'title=Example' --data-urlencode 'description=A page' \
  --data-urlencode "blocks_json=$blocks" "$base/admin/editor/preview" -D "$tmp/preview-headers" -o "$tmp/preview") == 200 ]]
grep -Fq 'Content-Security-Policy: sandbox;' "$tmp/preview-headers"
grep -Fq 'X-Frame-Options: SAMEORIGIN' "$tmp/preview-headers"
grep -Fq '&lt;script&gt;evil()&lt;/script&gt;' "$tmp/preview"
! grep -Fq '<script>evil()</script>' "$tmp/preview"
php -r '$d=json_decode(file_get_contents("storage/site.json"),true);if(isset($d["pages"]["/example"]))exit(1);'
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=save_blocks' \
  --data-urlencode 'path=/example' --data-urlencode 'title=Example' --data-urlencode 'description=A page' \
  --data-urlencode 'published=1' --data-urlencode "blocks_json=$blocks" "$base/admin/editor") == 303 ]]
php -r '$d=json_decode(file_get_contents("storage/site.json"),true);if($d["schema"]!==14||!isset($d["pages"]["/example"]["blocks"])||!$d["pages"]["/example"]["published"])exit(1);'
curl -fsS "$base/example" > "$tmp/public-output.html"
grep -Fq '&lt;script&gt;evil()&lt;/script&gt;' "$tmp/public-output.html"
! grep -Fq '<script>evil()</script>' "$tmp/public-output.html"
rev=$(php -r 'require "bootstrap.php";$app=new \CajeerEngine\Lite(getcwd());echo \CajeerEngine\Admin\PagesManagement::revision($app->load()["pages"]["/example"]);')
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" --data-urlencode "csrf=$csrf" \
  --data-urlencode 'action=save_blocks' --data-urlencode 'path=/example' --data-urlencode 'old=/example' \
  --data-urlencode "revision=$rev" --data-urlencode 'title=Changed' \
  --data-urlencode 'published=1' --data-urlencode "blocks_json=$blocks" "$base/admin/editor") == 303 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" --data-urlencode "csrf=$csrf" \
  --data-urlencode 'action=save_blocks' --data-urlencode 'path=/example' --data-urlencode 'old=/example' \
  --data-urlencode "revision=$rev" --data-urlencode 'title=Stale' \
  --data-urlencode 'published=1' --data-urlencode "blocks_json=$blocks" "$base/admin/editor") == 422 ]]
for bad in '[{"type":"link","text":"danger","url":"javascript:evil()"}]' '[{"type":"paragraph","text":"x","html":"<img>"}]' '{bad'; do
  [[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=save_blocks' \
    --data-urlencode 'path=/bad' --data-urlencode 'title=Bad' \
    --data-urlencode "blocks_json=$bad" "$base/admin/editor") == 422 ]]
done
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" --data-urlencode "csrf=$csrf" \
  --data-urlencode 'action=save_blocks' --data-urlencode 'old=/' \
  --data-urlencode "revision=$(php -r 'require "bootstrap.php";$app=new \CajeerEngine\Lite(getcwd());echo \CajeerEngine\Admin\PagesManagement::revision($app->load()["pages"]["/"]);')" \
  --data-urlencode 'path=/' --data-urlencode 'title=Home' \
  --data-urlencode "blocks_json=$blocks" "$base/admin/editor") == 422 ]]
php bin/cajeer doctor >/dev/null
printf 'Enhanced Content Editor 1.3.3 HTTP: auth, CSRF, CSP sandbox, unsaved preview, safe blocks, conflict, publish and schema migration OK\n'
