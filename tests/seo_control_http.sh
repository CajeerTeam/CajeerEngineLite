#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d);pid=''
trap '[[ -z "$pid" ]] || { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; }; rm -rf "$tmp"' EXIT
cp -a "$root"/. "$tmp"/
rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_PORT:-19036};base="http://127.0.0.1:$port";cd "$tmp"
CAJEER_ADMIN_PASSWORD='SeoControl136Password!' php bin/cajeer install "$base" 'SEO HTTP' >/dev/null
php -r 'require "bootstrap.php"; $a=new \CajeerEngine\Lite(getcwd());$a->change(static function(array $s):array {$s["pages"]["/posts"]=\CajeerEngine\Lite::page(["path"=>"/posts","title"=>"SEO <script>alert(1)</script>","description"=>"Before","body"=>"Content","published"=>"1","menu"=>"1"]);return $s;});'
php -S "127.0.0.1:$port" -t public router.php > "$tmp/server.log" 2>&1 & pid=$!
for i in {1..100}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin/seo") == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin/seo" -d 'action=global&indexable=1') == 403 ]]
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/login" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -c "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' --data-urlencode 'password=SeoControl136Password!' "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin/seo?path=/posts" > "$tmp/form"
grep -Fq 'SEO Control Center' "$tmp/form"
grep -Fq 'name="revision"' "$tmp/form"
grep -Fq 'SEO &lt;script&gt;alert(1)&lt;/script&gt;' "$tmp/form"
! grep -Fq '<script>alert(1)</script>' "$tmp/form"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/form" | head -1 | cut -d '"' -f4)
global_revision=$(grep -o 'name="revision" value="[a-f0-9]*"' "$tmp/form" | head -1 | cut -d '"' -f4)
revision=$(grep -o 'name="revision" value="[a-f0-9]*"' "$tmp/form" | sed -n '2p' | cut -d '"' -f4)
[[ ${#revision} == 64 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode 'csrf=bad' --data-urlencode 'action=global' "$base/admin/seo") == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=page' --data-urlencode 'path=/posts' \
  --data-urlencode "revision=$revision" --data-urlencode 'canonical=//attacker.example' \
  --data-urlencode 'changefreq=weekly' --data-urlencode 'priority=5' "$base/admin/seo") == 422 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=page' --data-urlencode 'path=/posts' \
  --data-urlencode "revision=$revision" --data-urlencode 'canonical=/missing' \
  --data-urlencode 'changefreq=weekly' --data-urlencode 'priority=5' "$base/admin/seo") == 422 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=page' --data-urlencode 'path=/posts' \
  --data-urlencode "revision=$revision" --data-urlencode 'canonical=/' --data-urlencode 'follow=1' \
  --data-urlencode 'sitemap=1' --data-urlencode 'og_title=Custom SEO' \
  --data-urlencode 'og_description=Public social description' \
  --data-urlencode 'changefreq=weekly' --data-urlencode 'priority=8' "$base/admin/seo") == 303 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=page' --data-urlencode 'path=/posts' \
  --data-urlencode "revision=$revision" --data-urlencode 'canonical=/' --data-urlencode 'follow=1' \
  --data-urlencode 'changefreq=weekly' --data-urlencode 'priority=8' "$base/admin/seo") == 422 ]]
curl -fsS -D "$tmp/headers" "$base/posts" > "$tmp/page.html"
grep -Fiq 'X-Robots-Tag: noindex, follow' "$tmp/headers"
grep -Fq '<meta name="robots" content="noindex, follow">' "$tmp/page.html"
grep -Fq '<link rel="canonical" href="'"$base"'/">' "$tmp/page.html"
grep -Fq 'content="Custom SEO"' "$tmp/page.html"
grep -Fq 'content="Public social description"' "$tmp/page.html"
! grep -Fq '<script>alert(1)</script>' "$tmp/page.html"
curl -fsS "$base/sitemap.xml" > "$tmp/map"
! grep -Fq "$base/posts" "$tmp/map"
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=global' --data-urlencode "revision=$global_revision" \
  --data-urlencode 'title_suffix=1' "$base/admin/seo") == 303 ]]
# A global update from another tab must not overwrite the newly saved policy.
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=global' \
  --data-urlencode "revision=$global_revision" --data-urlencode 'indexable=1' "$base/admin/seo") == 422 ]]
curl -fsS -b "$tmp/cookies" "$base/admin/seo?path=/posts" > "$tmp/form-latest"
global_revision=$(grep -o 'name="revision" value="[a-f0-9]*"' "$tmp/form-latest" | head -1 | cut -d '"' -f4)
curl -fsS "$base/robots.txt" > "$tmp/robots"
grep -Fxq 'Disallow: /' "$tmp/robots"
! grep -Fq 'Sitemap:' "$tmp/robots"
curl -fsS "$base/sitemap.xml" > "$tmp/map"
! grep -Fq '<url>' "$tmp/map"
curl -fsS "$base/" > "$tmp/home"
grep -Fq 'name="robots" content="noindex, follow"' "$tmp/home"
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=global' --data-urlencode "revision=$global_revision" \
  --data-urlencode 'indexable=1' --data-urlencode 'title_suffix=1' "$base/admin/seo") == 303 ]]
grep -Fq 'Disallow: /admin' <(curl -fsS "$base/robots.txt")
grep -Fq '<loc>' <(curl -fsS "$base/sitemap.xml")
php bin/cajeer doctor >/dev/null
printf 'SEO Control Center 1.3.6 HTTP: authentication, CSRF, page revision, canonical, OG, robots header, sitemap and sitewide flags OK\n'
