#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d);pid=''
trap '[[ -z "$pid" ]] || { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; }; rm -rf "$tmp"' EXIT
cp -R "$root"/. "$tmp"/
rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_PORT:-19034}; base="http://127.0.0.1:$port"
cd "$tmp"
CAJEER_ADMIN_PASSWORD='MediaLibraryPassword134!' php bin/cajeer install "$base" 'Media Library Integration' >/dev/null
php -S "127.0.0.1:$port" -t public router.php > "$tmp/server.log" 2>&1 & pid=$!
for i in {1..80}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin/media") == 403 ]]
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/login" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -c "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' \
  --data-urlencode 'password=MediaLibraryPassword134!' "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin/media" > "$tmp/gallery"
grep -Fq 'Медиатека' "$tmp/gallery"
grep -Fq 'enctype="multipart/form-data"' "$tmp/gallery"
grep -Fq 'action="/admin/media"' "$tmp/gallery"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/gallery" | head -1 | cut -d '"' -f4)
php -r 'file_put_contents($argv[1],base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGOUT9rBwMDAxMDAwMDAAAAOPQE9xgjZwQAAAABJRU5ErkJggg=="));' "$tmp/real.png"
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  -F 'action=upload' -F 'csrf=bad' -F "media_file=@$tmp/real.png;filename=valid.png" \
  "$base/admin/media") == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  -F 'action=upload' -F "csrf=$csrf" -F "media_file=@$tmp/real.png;filename=valid.png" \
  "$base/admin/media") == 303 ]]
id=$(php -r '$d=json_decode(file_get_contents("storage/media/index.json"),true);echo array_key_first($d["files"]);')
[[ "$id" =~ ^[a-f0-9]{32}$ ]]
php -r '$d=json_decode(file_get_contents("storage/media/index.json"),true);$row=array_values($d["files"])[0];if($row["mime"]!=="image/png"||$row["width"]!==2||$row["height"]!==2)exit(1);'
[[ $(curl -s -o "$tmp/downloaded" -w '%{http_code}' "$base/media/$id") == 200 ]]
cmp -s "$tmp/real.png" "$tmp/downloaded"
curl -fsS -D "$tmp/headers" -o /dev/null "$base/media/$id"
grep -qi '^Content-Type: image/png' "$tmp/headers"
grep -qi '^X-Content-Type-Options: nosniff' "$tmp/headers"
grep -qi '^Content-Security-Policy: sandbox' "$tmp/headers"
[[ $(curl -s -o /dev/null -w '%{http_code}' -I "$base/media/$id") == 200 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -H 'If-None-Match: '"\"$(sha256sum "$tmp/real.png" | cut -d ' ' -f1)\"" "$base/media/$id") == 304 ]]
curl -fsS -b "$tmp/cookies" "$base/admin/media?q=valid&type=image" > "$tmp/filtered"
grep -Fq 'valid.png' "$tmp/filtered"
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=rename' --data-urlencode "id=$id" \
  --data-urlencode 'name=renamed.png' "$base/admin/media") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin/media?q=renamed&type=image" > "$tmp/renamed"
grep -Fq 'renamed.png' "$tmp/renamed"
printf '<?php echo "execution";' > "$tmp/evil.php"
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  -F 'action=upload' -F "csrf=$csrf" -F "media_file=@$tmp/evil.php;filename=evil.php" \
  "$base/admin/media") == 422 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  -F 'action=upload' -F "csrf=$csrf" -F "media_file=@$tmp/real.png;filename=evil.php" \
  "$base/admin/media") == 422 ]]
printf '%%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%%%EOF\n' > "$tmp/doc.pdf"
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  -F 'action=upload' -F "csrf=$csrf" -F "media_file=@$tmp/doc.pdf;filename=doc.pdf" \
  "$base/admin/media") == 303 ]]
pdfid=$(php -r '$d=json_decode(file_get_contents("storage/media/index.json"),true);foreach($d["files"] as $id=>$row)if($row["mime"]==="application/pdf"){echo $id;exit;}exit(1);')
curl -fsS -D "$tmp/pdf-headers" -o "$tmp/pdf-bytes" "$base/media/$pdfid"
grep -qi '^Content-Disposition: attachment;' "$tmp/pdf-headers"
grep -qi '^Content-Type: application/pdf' "$tmp/pdf-headers"
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/storage/media/index.json") == 404 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=delete' --data-urlencode "id=$id" \
  "$base/admin/media") == 303 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/media/$id") == 404 ]]
php -r '$d=json_decode(file_get_contents("storage/media/index.json"),true);if(count($d["files"])!==1)exit(1);'
php bin/cajeer doctor >/dev/null
printf 'Media Library 1.3.4 HTTP: authenticated multipart, CSRF, real bytes, MIME, ETag, PDF download, filters, rename, deletion, private storage and executable rejection OK\n'
