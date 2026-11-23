#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d);pid=''
trap '[[ -z "$pid" ]] || { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; }; rm -rf "$tmp"' EXIT
cp -a "$root"/. "$tmp"/
rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_PORT:-19035};base="http://127.0.0.1:$port";cd "$tmp"
CAJEER_ADMIN_PASSWORD='MediaContent135Password!' php bin/cajeer install "$base" 'Media content' >/dev/null
php -S "127.0.0.1:$port" -t public router.php > "$tmp/server.log" 2>&1 & pid=$!
for i in {1..100}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin/editor?new=1") == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin/media") == 403 ]]
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/login" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -c "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' --data-urlencode 'password=MediaContent135Password!' "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin/media" > "$tmp/media"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/media" | head -1 | cut -d '"' -f4)
# 2x2 PNG with a valid footer, plus a minimal PDF.
base64 -d > "$tmp/test.png" <<'B64'
iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGOUT9rBwMDAxMDAwMDAAAAOPQE9xgjZwQAAAABJRU5ErkJggg==
B64
printf '%%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%%%EOF\n' > "$tmp/test.pdf"
for f in "$tmp/test.png" "$tmp/test.pdf"; do
  [[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
    -F "csrf=$csrf" -F 'action=upload' -F "media_file=@$f" "$base/admin/media") == 303 ]]
done
image=$(php -r 'require "bootstrap.php";$l=new \CajeerEngine\Media\MediaLibrary(getcwd());foreach($l->listing() as $row)if($row["mime"]==="image/png")echo $row["id"];')
pdf=$(php -r 'require "bootstrap.php";$l=new \CajeerEngine\Media\MediaLibrary(getcwd());foreach($l->listing() as $row)if($row["mime"]==="application/pdf")echo $row["id"];')
[[ ${#image} == 32 && ${#pdf} == 32 ]]
curl -fsS -b "$tmp/cookies" "$base/admin/editor?new=1" > "$tmp/editor"
grep -Fq 'id="cms-editor-media-json"' "$tmp/editor"
grep -Fq "$image" "$tmp/editor"
grep -Fq "$pdf" "$tmp/editor"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/editor" | head -1 | cut -d '"' -f4)
blocks=$(php -r '$image=$argv[1];$pdf=$argv[2];echo json_encode([["type"=>"image","media_id"=>$image,"alt"=>"Alt <script>evil()</script>","caption"=>"Caption & <b>bold</b>","size"=>"full"],["type"=>"file","media_id"=>$pdf,"label"=>"Download <script>evil()</script>"]],JSON_UNESCAPED_UNICODE);' "$image" "$pdf")
[[ $(curl -s -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'title=Media page' --data-urlencode 'path=/media-page' \
  --data-urlencode "blocks_json=$blocks" "$base/admin/editor/preview" -o "$tmp/preview") == 200 ]]
grep -Fq "src=\"/media/$image\"" "$tmp/preview"
grep -Fq '&lt;script&gt;evil()&lt;/script&gt;' "$tmp/preview"
! grep -Fq '<script>evil()</script>' "$tmp/preview"
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=save_blocks' --data-urlencode 'title=Media page' \
  --data-urlencode 'path=/media-page' --data-urlencode 'published=1' --data-urlencode "blocks_json=$blocks" "$base/admin/editor") == 303 ]]
curl -fsS "$base/media-page" > "$tmp/page"
grep -Fq "src=\"/media/$image\"" "$tmp/page"
grep -Fq "href=\"/media/$pdf?download=1\"" "$tmp/page"
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/media/$image") == 200 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/media/$pdf?download=1") == 200 ]]
# Both files are referenced; deletion is rejected even when the browser bypasses a disabled button.
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=delete' --data-urlencode "id=$image" "$base/admin/media") == 422 ]]
curl -fsS -b "$tmp/cookies" "$base/admin/media" > "$tmp/gallery"
grep -Fq 'Используется на 1 страницах' "$tmp/gallery"
grep -Fq 'disabled title="Файл используется на страницах"' "$tmp/gallery"
# Test attempted forged reference and wrong file type; neither persists.
invalid=$(php -r 'echo json_encode([["type"=>"image","media_id"=>$argv[1],"alt"=>"","caption"=>"","size"=>"full"]]);' "$pdf")
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=save_blocks' --data-urlencode 'title=Invalid' \
  --data-urlencode 'path=/invalid' --data-urlencode "blocks_json=$invalid" "$base/admin/editor") == 422 ]]
rev=$(php -r 'require "bootstrap.php";$a=new \CajeerEngine\Lite(getcwd());echo \CajeerEngine\Admin\PagesManagement::revision($a->load()["pages"]["/media-page"]);')
plain='[{"type":"paragraph","text":"Without media"}]'
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=save_blocks' --data-urlencode 'path=/media-page' \
  --data-urlencode 'old=/media-page' --data-urlencode "revision=$rev" --data-urlencode 'title=Media page' \
  --data-urlencode "blocks_json=$plain" "$base/admin/editor") == 303 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=delete' --data-urlencode "id=$image" "$base/admin/media") == 303 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/media/$image") == 404 ]]
php bin/cajeer doctor >/dev/null
printf 'Media Content Integration 1.3.5 HTTP: upload, picker, preview, publish, media download, XSS, referenced-file delete, MIME and revisions OK\n'
