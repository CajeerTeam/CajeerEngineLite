#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true; rm -rf "$tmp"' EXIT
cp -R "$root"/. "$tmp"/
rm -rf "$tmp/storage/auth" "$tmp/storage/site.json" "$tmp/storage/site.lock" "$tmp/storage/backups"
port=${TEST_AUTH_HARDEN_PORT:-19118}
base="http://127.0.0.1:$port"
cd "$tmp"
CAJEER_ADMIN_PASSWORD=SafePasswordForHardening118 php bin/cajeer install "$base" Hardening >/dev/null
php -S "127.0.0.1:$port" -t public router.php >"$tmp/server.log" 2>&1 & pid=$!
for i in {1..50}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
status(){ curl -sS -o /dev/null -w '%{http_code}' "$@"; }
curl -fsS -c "$tmp/cookie" "$base/admin" >"$tmp/login"
csrf=$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/login" | head -1)
[[ -n "$csrf" ]]
[[ $(status -b "$tmp/cookie" -d 'action=oidc_login&csrf=invalid' "$base/admin") == 403 ]]
[[ $(status -b "$tmp/cookie" -d "action=oidc_login&csrf=$csrf" "$base/admin") == 403 ]]
[[ $(status -b "$tmp/cookie" -d 'action=oidc_cancel&csrf=invalid' "$base/admin") == 403 ]]
[[ $(status -b "$tmp/cookie" -d "action=oidc_cancel&csrf=$csrf" "$base/admin") == 303 ]]
[[ $(status "$base/admin/oidc/login") == 503 ]]
[[ $(status "$base/admin/recovery") == 404 ]]
[[ $(status -b "$tmp/cookie" -c "$tmp/cookie" -d "action=login&csrf=$csrf&password=SafePasswordForHardening118" "$base/admin") == 303 ]]
# Switch canonical origin to HTTPS. Even with spoofed X-Forwarded-Proto,
# an insecure request must not reach session management or login.
php -r '
require "bootstrap.php";
$app=new CajeerEngine\Lite(getcwd());
$app->change(static function(array $site):array {
 $site["settings"]["url"]="https://127.0.0.1:19118";
 return $site;
});'
[[ $(status -H 'X-Forwarded-Proto: https' "$base/admin") == 403 ]]
[[ $(status -H 'X-Forwarded-Proto: https' -b "$tmp/cookie" -d "action=login&csrf=$csrf&password=SafePasswordForHardening118" "$base/admin") == 403 ]]
[[ $(status "$base/admin/oidc/callback?code=attacker&state=attacker") == 403 ]]
echo 'Lite 1.1.8 CSRF-only OIDC initiation, recovery isolation and HTTPS admin guard OK'
