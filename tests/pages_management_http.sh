#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; }; rm -rf "$tmp"' EXIT
cp -R "$root"/. "$tmp"/
rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_PORT:-18992}; base="http://127.0.0.1:$port"
cd "$tmp"
CAJEER_ADMIN_PASSWORD='PagesManagementPassword132!' php bin/cajeer install "$base" 'Pages Test' >/dev/null
php -S "127.0.0.1:$port" -t public router.php > "$tmp/server.log" 2>&1 & pid=$!
for i in {1..80}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"
! grep -Fq 'Управление страницами' "$tmp/login"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/login" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -c "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' --data-urlencode 'password=PagesManagementPassword132!' "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin?view=pages" > "$tmp/pages"
grep -Fq 'Управление страницами' "$tmp/pages"
grep -Fq 'name="action" value="pages_bulk"' "$tmp/pages"
grep -Fq 'admin-pages-table' "$tmp/pages"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/pages" | head -1 | cut -d '"' -f4)
for page in alpha beta charlie; do
  [[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=save' \
    --data-urlencode "path=/$page" --data-urlencode "title=Test $page" \
    --data-urlencode "description=Description $page" --data-urlencode "body=Body $page" \
    --data-urlencode 'menu=1' "$base/admin") == 303 ]]
done
curl -fsS -b "$tmp/cookies" "$base/admin?view=pages&status=draft&q=ALPHA" > "$tmp/filtered"
grep -Fq 'Найдено страниц: 1' "$tmp/filtered"
grep -Fq 'Test alpha' "$tmp/filtered"
! grep -Fq 'Test beta</strong>' "$tmp/filtered"
curl -fsS -b "$tmp/cookies" "$base/admin?view=pages&status=published" > "$tmp/filtered"
grep -Fq 'Найдено страниц: 1' "$tmp/filtered"
for url in 'status=other' 'q%5B%5D=x' 'page=0' 'per_page=99' 'sort=bad' 'dir=bad'; do
  [[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" "$base/admin?view=pages&$url") == 400 ]]
done
rev_for() { php -r 'require "bootstrap.php"; $p=(new \CajeerEngine\Lite(getcwd()))->load()["pages"][$argv[1]]; echo \CajeerEngine\Admin\PagesManagement::revision($p);' "$1"; }
rev_alpha=$(rev_for /alpha); rev_beta=$(rev_for /beta)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=page_status' \
  --data-urlencode 'old=/alpha' --data-urlencode "revision=$rev_alpha" \
  --data-urlencode 'published=1' "$base/admin") == 303 ]]
php -r '$d=json_decode(file_get_contents("storage/site.json"),true);if(!$d["pages"]["/alpha"]["published"] || $d["pages"]["/beta"]["published"]) exit(1);'
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=page_status' \
  --data-urlencode 'old=/alpha' --data-urlencode "revision=$rev_alpha" \
  --data-urlencode 'published=0' "$base/admin") == 422 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=page_duplicate' \
  --data-urlencode 'old=/beta' --data-urlencode "revision=$rev_beta" "$base/admin") == 303 ]]
php -r '$d=json_decode(file_get_contents("storage/site.json"),true);if(!isset($d["pages"]["/beta-copy"]) || $d["pages"]["/beta-copy"]["published"] || $d["pages"]["/beta-copy"]["menu"]) exit(1);'
rev_alpha=$(rev_for /alpha)
key_alpha=$(printf '/alpha' | sha256sum | cut -d ' ' -f1)
key_beta=$(printf '/beta' | sha256sum | cut -d ' ' -f1)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=pages_bulk' \
  --data-urlencode 'operation=publish' --data-urlencode 'selected[]=/alpha' \
  --data-urlencode 'selected[]=/beta' \
  --data-urlencode "revisions[$key_alpha]=$rev_alpha" \
  --data-urlencode "revisions[$key_beta]=$rev_beta" "$base/admin") == 303 ]]
php -r '$d=json_decode(file_get_contents("storage/site.json"),true);if(!$d["pages"]["/alpha"]["published"] || !$d["pages"]["/beta"]["published"]) exit(1);'
# A stale revision rejects the whole batch, without modifying any requested page.
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=pages_bulk' \
  --data-urlencode 'operation=draft' --data-urlencode 'selected[]=/alpha' \
  --data-urlencode 'selected[]=/beta' \
  --data-urlencode "revisions[$key_alpha]=$rev_alpha" \
  --data-urlencode "revisions[$key_beta]=$rev_beta" "$base/admin") == 422 ]]
php -r '$d=json_decode(file_get_contents("storage/site.json"),true);if(!$d["pages"]["/alpha"]["published"] || !$d["pages"]["/beta"]["published"]) exit(1);'
rev_home=$(rev_for /)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=page_status' \
  --data-urlencode 'old=/' --data-urlencode "revision=$rev_home" \
  --data-urlencode 'published=0' "$base/admin") == 422 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" \
  --data-urlencode 'action=page_duplicate' --data-urlencode 'old=/alpha' "$base/admin") == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=pages_bulk' --data-urlencode 'operation=draft' "$base/admin") == 403 ]]
curl -fsS -b "$tmp/cookies" "$base/admin?view=pages&sort=path&dir=desc&per_page=10" > "$tmp/pages"
grep -Fq 'revisions[' "$tmp/pages"
grep -Fq 'name="operation" value="publish"' "$tmp/pages"
php bin/cajeer doctor >/dev/null
php -r '$d=json_decode(file_get_contents("storage/site.json"),true,512,JSON_THROW_ON_ERROR);if($d["schema"]!==14)exit(1);'
echo 'Pages Management 1.3.2 real HTTP: authenticated filters, publishing, duplication, atomic bulk, conflict, CSRF and storage OK'
