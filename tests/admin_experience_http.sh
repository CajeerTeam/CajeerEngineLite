#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; }; rm -rf "$tmp"' EXIT
cp -R "$root"/. "$tmp"/
rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_PORT:-18991}; base="http://127.0.0.1:$port"
cd "$tmp"
CAJEER_ADMIN_PASSWORD='AdminLayoutPassword131!' php bin/cajeer install "$base" 'Admin Layout' >/dev/null
php -S "127.0.0.1:$port" -t public router.php > "$tmp/php-server.log" 2>&1 & pid=$!
for i in {1..80}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
curl -fsS "$base/assets/admin.css" > "$tmp/style"
grep -Fq '.admin-metrics' "$tmp/style"
curl -fsS -c "$tmp/cookie" "$base/admin" > "$tmp/login"
! grep -Fq 'Dashboard — обзор сайта' "$tmp/login"
! grep -Fq '/admin?view=security' "$tmp/login"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/login" | head -1 | cut -d '"' -f4)
[[ -n "$csrf" ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookie" -c "$tmp/cookie" \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' \
    --data-urlencode 'password=AdminLayoutPassword131!' "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookie" "$base/admin" > "$tmp/dashboard"
grep -Fq 'Dashboard — обзор сайта' "$tmp/dashboard"
grep -Fq 'id="admin-view-dashboard" class="admin-view" aria-label="Обзор"' "$tmp/dashboard"
grep -Fq 'id="admin-view-security" class="admin-view" hidden' "$tmp/dashboard"
grep -Fq 'Всего страниц' "$tmp/dashboard"
grep -Fq '1 / 5' "$tmp/dashboard" && { echo 'Incorrect integration status' >&2; exit 1; } || true
grep -Fq '0 / 5' "$tmp/dashboard"
grep -Fq 'Серверные сеансы' "$tmp/dashboard"
grep -Fq 'aria-label="Разделы управления"' "$tmp/dashboard"
grep -Fq 'class="admin-mobile-menu"' "$tmp/dashboard"
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookie" "$base/admin?view=%3Cscript%3E") == 400 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookie" "$base/admin?view%5B%5D=pages") == 400 ]]
curl -fsS -b "$tmp/cookie" "$base/admin?view=pages&new=1" > "$tmp/pages"
grep -Fq 'id="admin-view-pages" class="admin-view" aria-label="Страницы"' "$tmp/pages"
grep -Fq 'name="action" value="save"' "$tmp/pages"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/pages" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookie" --data-urlencode 'action=save' \
    --data-urlencode "csrf=$csrf" --data-urlencode 'path=/new' --data-urlencode 'title=New' \
    --data-urlencode 'body=Sample page' --data-urlencode 'published=1' \
    --data-urlencode 'menu=1' "$base/admin") == 303 ]]
location=$(curl -s -D - -o /dev/null -b "$tmp/cookie" \
    --data-urlencode 'action=modules' --data-urlencode "csrf=$csrf" \
    --data-urlencode 'module[navigation]=1' --data-urlencode 'module[robots]=1' \
    --data-urlencode 'module[sitemap]=1' "$base/admin" | tr -d '\r' | sed -n 's/^Location: //Ip')
[[ "$location" == '/admin?view=settings' ]]
curl -fsS -b "$tmp/cookie" "$base/admin?view=pages" > "$tmp/updated"
grep -Fq '/new' "$tmp/updated"
grep -Fq 'Изменения сохранены.' "$tmp/updated"
grep -Fq 'href="/admin?view=pages&amp;edit=%2Fnew"' "$tmp/updated"
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin/ai") == 403 ]]
curl -fsS -b "$tmp/cookie" "$base/admin/ai" > "$tmp/chat"
grep -Fq 'admin-sidebar' "$tmp/chat"
grep -Fq 'href="/admin?view=pages"' "$tmp/chat"
grep -Fq 'href="/admin/ai" aria-current="page"' "$tmp/chat"
curl -fsS -b "$tmp/cookie" "$base/admin?view=security" > "$tmp/security"
grep -Fq 'Активные сеансы и устройства' "$tmp/security"
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookie" -d 'action=settings' "$base/admin") == 403 ]]
php -r '$site=json_decode(file_get_contents("storage/site.json"),true,64,JSON_THROW_ON_ERROR);if($site["schema"]!==14||!isset($site["pages"]["/new"])) exit(1);'
echo 'Admin Experience 1.3.1 authenticated dashboard, responsive navigation, pages, redirects, CSRF, AI navigation and storage OK'
