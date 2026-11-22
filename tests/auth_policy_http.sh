#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
pid=''
trap '[[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true; rm -rf "$tmp"' EXIT
cp -R "$root"/. "$tmp"/
rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_AUTH_PORT:-19117}; base="http://127.0.0.1:$port"
cd "$tmp"
CAJEER_ADMIN_PASSWORD=SecureLocalPassword123 php bin/cajeer install "$base" 'Auth mode HTTP' >/dev/null
php -S "127.0.0.1:$port" -t public router.php > "$tmp/auth.log" 2>&1 & pid=$!
for i in {1..50}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
http_status() { curl -sS -o /dev/null -w '%{http_code}' "$@"; }
read_csrf() { sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$1"; }
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login.html"
grep -Fq 'Войти по паролю' "$tmp/login.html"
csrf=$(read_csrf "$tmp/login.html")
[[ $(http_status -b "$tmp/cookies" -c "$tmp/cookies" -d "action=login&csrf=$csrf&password=SecureLocalPassword123" "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin" > "$tmp/admin.html"
grep -Fq 'Управление способами входа' "$tmp/admin.html"
grep -Fq 'Локальный пароль и SkiF4er ID' "$tmp/admin.html"
csrf=$(read_csrf "$tmp/admin.html")
[[ $(http_status -b "$tmp/cookies" -d "action=auth_policy&csrf=wrong&auth_mode=oidc_only&confirm_auth_mode=1&auth_password=SecureLocalPassword123" "$base/admin") == 403 ]]
[[ $(http_status -b "$tmp/cookies" -d "action=auth_policy&csrf=$csrf&auth_mode=oidc_only&confirm_auth_mode=1&auth_password=SecureLocalPassword123" "$base/admin") == 422 ]]
[[ $(http_status -b "$tmp/cookies" -d "action=auth_policy&csrf=$csrf&auth_mode=broken&confirm_auth_mode=1&auth_password=SecureLocalPassword123" "$base/admin") == 422 ]]
# Server-admin fixture models the state written by a verified SkiF4er callback.
php -r '
require "bootstrap.php";
$app=new CajeerEngine\Lite(getcwd());
$owner=["iss"=>CajeerEngine\Auth\OidcClient::ISSUER,"sub"=>"test-linked-owner"];
$app->change(fn(array $site):array=>CajeerEngine\Auth\OwnerIdentity::bind($site,$owner));
$site=$app->load();
$app->change(fn(array $data):array=>CajeerEngine\Auth\AuthPolicy::switch($data,
 CajeerEngine\Auth\AuthPolicy::OIDC_ONLY,$site["identity"]["generation"],1,$owner));
'
# The old local session must be revoked and the local login action must be blocked.
curl -fsS -b "$tmp/cookies" -c "$tmp/cookies" "$base/admin" > "$tmp/blocked.html"
grep -Fq 'Вход в админ-панель' "$tmp/blocked.html"
grep -Fq 'Вход по локальному паролю отключён' "$tmp/blocked.html"
if grep -Fq 'name="action" value="login"' "$tmp/blocked.html"; then
  echo 'Local password form is visible in SSO-only mode' >&2; exit 1
fi
csrf=$(read_csrf "$tmp/blocked.html")
[[ $(http_status -b "$tmp/cookies" -c "$tmp/cookies" -d "action=login&csrf=$csrf&password=SecureLocalPassword123" "$base/admin") == 403 ]]
# Server-console recovery unlocks the owner without an HTTP recovery endpoint.
[[ $(http_status "$base/admin/recovery") == 404 || $(http_status "$base/admin/recovery") == 503 ]]
php -r '
require "bootstrap.php";
$app=new CajeerEngine\Lite(getcwd());
$token=(new CajeerEngine\Auth\RecoveryService($app))->issue();
(new CajeerEngine\Auth\RecoveryService($app))->complete($token,"ReinitializedPassword123!");
'
curl -fsS -c "$tmp/newcookies" "$base/admin" > "$tmp/recovered.html"
grep -Fq 'Войти по паролю' "$tmp/recovered.html"
csrf=$(read_csrf "$tmp/recovered.html")
[[ $(http_status -b "$tmp/newcookies" -c "$tmp/newcookies" -d "action=login&csrf=$csrf&password=ReinitializedPassword123%21" "$base/admin") == 303 ]]
curl -fsS -b "$tmp/newcookies" "$base/admin" | grep -Fq 'Управление способами входа'
echo 'Lite 1.1.7 HTTP login modes, CSRF, revoked sessions and recovery OK'
