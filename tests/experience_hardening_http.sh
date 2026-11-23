#!/usr/bin/env bash
set -euo pipefail
source_root=$(cd "$(dirname "$0")/.." && pwd)
work=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; }; rm -rf "$work"' EXIT
cp -a "$source_root"/. "$work"/
rm -f "$work/storage/site.json" "$work/storage/site.lock"
port=${TEST_PORT:-19039}; base="http://127.0.0.1:$port"
cd "$work"
CAJEER_ADMIN_PASSWORD='Integration139Password!' php bin/cajeer install "$base" 'CMS Hardening' >/dev/null
php -S "127.0.0.1:$port" -t public router.php > "$work/php.log" 2>&1 & pid=$!
for i in {1..80}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep 0.1; done
status() { curl --max-time 10 -s -o /dev/null -w '%{http_code}' "$@"; }

# Both unencrypted staging and HTTPS production have a canonical admin host.
[[ $(status -H 'Host: attacker.test' "$base/admin") == 421 ]]
[[ $(status -H 'Host: evil@127.0.0.1:'"$port" "$base/admin") == 421 ]]
[[ $(status -H 'Host: 127.0.0.1:9999' "$base/admin/seo") == 421 ]]
[[ $(status -H 'Host: attacker.test' "$base/admin/operations") == 421 ]]
[[ $(status "$base/admin/operations") == 403 ]]
[[ $(status "$base/admin/developer") == 403 ]]
[[ $(status "$base/storage/site.json") == 404 ]]
[[ $(status "$base/storage/auth/sessions.json") == 404 ]]

curl -fsS -c "$work/cookies" "$base/admin" > "$work/login.html"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$work/login.html" | head -1 | cut -d '"' -f4)
[[ ${#csrf} == 64 ]]
[[ $(status -b "$work/cookies" -c "$work/cookies" -H 'Origin: http://attacker.test' \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' --data-urlencode 'password=Integration139Password!' "$base/admin") == 403 ]]
[[ $(status -b "$work/cookies" -c "$work/cookies" -H 'Referer: https://attacker.test/path' \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' --data-urlencode 'password=Integration139Password!' "$base/admin") == 403 ]]
[[ $(status -b "$work/cookies" -c "$work/cookies" -H 'Sec-Fetch-Site: cross-site' \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' --data-urlencode 'password=Integration139Password!' "$base/admin") == 403 ]]
[[ $(status -b "$work/cookies" -c "$work/cookies" -H "Origin: $base" -H 'Sec-Fetch-Site: same-origin' \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' --data-urlencode 'password=Integration139Password!' "$base/admin") == 303 ]]
curl -fsS -b "$work/cookies" -D "$work/admin_headers" -o "$work/admin.html" "$base/admin"
grep -qi '^X-Frame-Options: DENY' "$work/admin_headers"
grep -qi '^Cache-Control: no-store' "$work/admin_headers"
grep -qi "script-src 'none'" "$work/admin_headers"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$work/admin.html" | head -1 | cut -d '"' -f4)

# Oversized form body is rejected before application-level parsing/mutation.
python - <<'PY'
from pathlib import Path
Path('too_big.txt').write_text('action=save&body='+'A'*350000)
PY
[[ $(status -b "$work/cookies" -H 'Content-Type: application/x-www-form-urlencoded' --data-binary @too_big.txt "$base/admin") == 413 ]]

# A real public page is saved using the authenticated administration endpoint.
[[ $(status -b "$work/cookies" -H "Origin: $base" \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=save' \
    --data-urlencode 'path=/launch' --data-urlencode 'title=Launch & <script>alert(1)</script>' \
    --data-urlencode 'description=Launch description' --data-urlencode 'body=Visible original body' \
    --data-urlencode 'published=1' --data-urlencode 'menu=1' "$base/admin") == 303 ]]
curl -fsS "$base/launch" > "$work/launch.html"
grep -Fq 'Visible original body' "$work/launch.html"
! grep -Fq '<script>alert(1)</script>' "$work/launch.html"

# The actual multipart upload is served by the private media storage path.
php -r 'file_put_contents($argv[1],base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGOUT9rBwMDAxMDAwMDAAAAOPQE9xgjZwQAAAABJRU5ErkJggg=="));' "$work/logo.png"
[[ $(status -b "$work/cookies" -H "Origin: $base" \
    -F 'action=upload' -F "csrf=$csrf" -F "media_file=@$work/logo.png;filename=logo.png" "$base/admin/media") == 303 ]]
media_id=$(php -r '$d=json_decode(file_get_contents("storage/media/index.json"),true,512,JSON_THROW_ON_ERROR);echo array_key_first($d["files"]);')
[[ $media_id =~ ^[a-f0-9]{32}$ ]]
curl -fsS "$base/media/$media_id" > "$work/served.png"
cmp "$work/served.png" "$work/logo.png"

# Global + per-page SEO settings make real changes to the rendered HTML and sitemap.
curl -fsS -b "$work/cookies" "$base/admin/seo?path=/launch" > "$work/seo.html"
revision=$(grep -o 'name="revision" value="[a-f0-9]*"' "$work/seo.html" | head -1 | cut -d '"' -f4)
[[ $(status -b "$work/cookies" -H "Origin: $base" \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=global' --data-urlencode "revision=$revision" \
    --data-urlencode 'indexable=1' --data-urlencode 'title_suffix=1' --data-urlencode "default_image=$media_id" \
    "$base/admin/seo") == 303 ]]
curl -fsS -b "$work/cookies" "$base/admin/seo?path=/launch" > "$work/seo.html"
page_revision=$(grep -o 'name="revision" value="[a-f0-9]*"' "$work/seo.html" | sed -n '2p' | cut -d '"' -f4)
[[ $(status -b "$work/cookies" -H "Origin: $base" \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=page' --data-urlencode 'path=/launch' \
    --data-urlencode "revision=$page_revision" --data-urlencode 'index=1' --data-urlencode 'follow=1' \
    --data-urlencode 'sitemap=1' --data-urlencode 'og_title=Social launch' \
    --data-urlencode 'changefreq=weekly' --data-urlencode 'priority=7' "$base/admin/seo") == 303 ]]
curl -fsS "$base/launch" > "$work/launch.html"
grep -Fq 'Social launch' "$work/launch.html"
grep -Fq "$base/media/$media_id" "$work/launch.html"
curl -fsS "$base/sitemap.xml" > "$work/sitemap.xml"
grep -Fq "$base/launch" "$work/sitemap.xml"

# Full private TAR backup includes the media+SEO state, then survives mutation.
curl -fsS -b "$work/cookies" -H "Origin: $base" --data-urlencode "csrf=$csrf" \
    --data-urlencode 'action=create' "$base/admin/operations" > "$work/created.html"
backup=$(grep -o 'cms-[0-9A-Z]*-[a-f0-9]*\.tar' "$work/created.html" | head -1)
[[ $backup =~ ^cms-.*\.tar$ ]]
curl -fsS -b "$work/cookies" --data-urlencode "csrf=$csrf" --data-urlencode 'action=download' \
    --data-urlencode "name=$backup" "$base/admin/operations" -o "$work/backup.tar"
php -r 'if(substr(file_get_contents($argv[1]),257,5)!=="ustar")exit(1);' "$work/backup.tar"

# A failed archive import must not mutate the live site or media.
cp "$work/backup.tar" "$work/bad.tar"
php -r '$f=$argv[1];$b=file_get_contents($f);$pos=strpos($b,"Visible original body");if($pos===false)exit(2);$b[$pos]="X";file_put_contents($f,$b);' "$work/bad.tar"
[[ $(status -b "$work/cookies" -H "Origin: $base" -F "csrf=$csrf" -F 'action=import' \
    -F 'confirm=IMPORT' -F "archive=@$work/bad.tar;filename=bad.tar" "$base/admin/operations") == 422 ]]
[[ $(status -b "$work/cookies" -H "Origin: $base" --data-urlencode "csrf=$csrf" \
    --data-urlencode 'action=save' --data-urlencode 'old=' --data-urlencode 'path=/after-backup' \
    --data-urlencode 'title=New page' --data-urlencode 'description=New' --data-urlencode 'body=After backup' \
    --data-urlencode 'published=1' --data-urlencode 'menu=1' "$base/admin") == 303 ]]
[[ $(status "$base/after-backup") == 200 ]]
[[ $(status -b "$work/cookies" -H "Origin: $base" --data-urlencode "csrf=$csrf" \
    --data-urlencode 'action=restore' --data-urlencode "name=$backup" --data-urlencode 'confirm=RESTORE' \
    "$base/admin/operations") == 303 ]]
[[ $(status "$base/after-backup") == 404 ]]
[[ $(status -b "$work/cookies" "$base/admin/seo") == 403 ]]
curl -fsS "$base/launch" > "$work/restored.html"
grep -Fq 'Social launch' "$work/restored.html"
grep -Fq "$base/media/$media_id" "$work/restored.html"
curl -fsS "$base/media/$media_id" > "$work/restored.png"
cmp "$work/restored.png" "$work/logo.png"

# Local credentials were preserved, even when content was replaced by a backup.
curl -fsS -c "$work/newcookies" "$base/admin" > "$work/relogin.html"
newcsrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$work/relogin.html" | head -1 | cut -d '"' -f4)
[[ $(status -b "$work/newcookies" -c "$work/newcookies" --data-urlencode "csrf=$newcsrf" \
    --data-urlencode 'action=login' --data-urlencode 'password=Integration139Password!' "$base/admin") == 303 ]]
php bin/cajeer doctor >/dev/null
php -r '$s=json_decode(file_get_contents("storage/site.json"),true,512,JSON_THROW_ON_ERROR);if($s["schema"]!==14 || count($s["pages"])!==2)exit(1);'
echo 'CMS Experience Hardening 1.3.9 HTTP: origin/host, CSRF, limits, page XSS escaping, upload, SEO, backup/download, tamper, restore, sessions, credentials OK'
