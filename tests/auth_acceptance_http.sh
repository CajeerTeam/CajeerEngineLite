#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
pid=''
trap '[[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true; rm -rf "$tmp"' EXIT
cp -R "$root"/. "$tmp"/
rm -rf "$tmp/storage/auth" "$tmp/storage/site.json" "$tmp/storage/site.lock" "$tmp/storage/backups"
port=${TEST_AUTH_ACCEPT_PORT:-19119}
base="http://127.0.0.1:$port"
cd "$tmp"
CAJEER_ADMIN_PASSWORD=AcceptancePasswordHttp119 php bin/cajeer install "$base" 'Auth HTTP acceptance' >/dev/null
php -S "127.0.0.1:$port" -t public router.php >"$tmp/server.log" 2>&1 & pid=$!
for i in {1..60}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
http_status(){ curl -sS -o /dev/null -w '%{http_code}' "$@"; }
csrf_from(){ sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$1" | head -1; }
# Distinct real PHP sessions, real cookies and authorized POST routes.
for device in a b; do
    curl -fsS -A "Acceptance-Device-$device" -c "$tmp/$device.cookie" "$base/admin" >"$tmp/$device.login"
    csrf=$(csrf_from "$tmp/$device.login")
    [[ -n "$csrf" ]]
    [[ $(http_status -A "Acceptance-Device-$device" -b "$tmp/$device.cookie" -c "$tmp/$device.cookie" \
        -d "action=login&csrf=$csrf&password=AcceptancePasswordHttp119" "$base/admin") == 303 ]]
done
curl -fsS -b "$tmp/a.cookie" "$base/admin" >"$tmp/admin.html"
grep -Fq 'Acceptance-Device-b' "$tmp/admin.html"
csrf=$(csrf_from "$tmp/admin.html")
id_b=$(php -r '$data=json_decode(file_get_contents("storage/auth/sessions.json"),true,32,JSON_THROW_ON_ERROR);
foreach($data["items"] as $id=>$entry) if($entry["agent"]==="Acceptance-Device-b") {echo $id;exit; }')
[[ "$id_b" =~ ^[a-f0-9]{64}$ ]]
[[ $(http_status -b "$tmp/a.cookie" -d "action=session_revoke&csrf=wrong&session_id=$id_b" "$base/admin") == 403 ]]
[[ $(http_status -b "$tmp/a.cookie" -d "action=session_revoke&csrf=$csrf&session_id=$id_b" "$base/admin") == 303 ]]
[[ $(http_status -b "$tmp/b.cookie" -d "action=modules&csrf=$csrf" "$base/admin") == 403 ]]
curl -fsS -b "$tmp/b.cookie" "$base/admin" >"$tmp/revoked.html"
grep -Fq 'Вход в админ-панель' "$tmp/revoked.html"
curl -fsS -b "$tmp/a.cookie" "$base/admin" >"$tmp/active.html"
grep -Fq 'Управление способами входа' "$tmp/active.html"
php bin/cajeer auth check --json > "$tmp/auth.json"
php -r '$r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
if(!$r["ok"] || $r["sessions"]["active"]!==1 || !isset($r["manual"][0])) exit(1);' "$tmp/auth.json"
# Current security state must survive content restoration. Old cookies remain revoked.
backup=$(php bin/cajeer backup create | sed -n 's/^Backup created: //p')
[[ -n "$backup" ]]
php -r 'require "bootstrap.php";
$app=new CajeerEngine\Lite(getcwd());
$owner=["iss"=>CajeerEngine\Auth\OidcClient::ISSUER,"sub"=>"http-acceptance-119"];
$app->change(fn($state)=>CajeerEngine\Auth\OwnerIdentity::bind($state,$owner));
$app->change(fn($state)=>CajeerEngine\Auth\AuthPolicy::switch($state,
CajeerEngine\Auth\AuthPolicy::OIDC_ONLY,$state["identity"]["generation"],$state["auth_policy"]["revision"],$owner));'
if php bin/cajeer auth check --json > "$tmp/unready.json"; then
  echo 'SSO-only without credentials incorrectly passed auth acceptance' >&2; exit 1
fi
php -r '$r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
if($r["ok"]) exit(1);' "$tmp/unready.json"
[[ $(http_status -b "$tmp/a.cookie" -d "action=save&csrf=$csrf&path=/unsafe&title=Unsafe" "$base/admin") == 403 ]]
CAJEER_RESTORE_CONFIRM="$backup" php bin/cajeer backup restore "$backup" >/dev/null
php -r 'require "bootstrap.php"; $s=(new CajeerEngine\Lite(getcwd()))->load();
if($s["auth_policy"]["mode"]!=="oidc_only" || $s["identity"]["owner"]["sub"]!=="http-acceptance-119") exit(1);'
# Real console recovery, not an HTTP recovery endpoint.
[[ $(http_status "$base/admin/recovery") == 404 ]]
php -r 'require "bootstrap.php";
$r=new CajeerEngine\Auth\RecoveryService(new CajeerEngine\Lite(getcwd()));
$t=$r->issue();$r->complete($t,"NewPasswordForHTTP119!");'
php bin/cajeer auth check --json > "$tmp/recovered.json"
php -r '$r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
if(!$r["ok"])exit(1);' "$tmp/recovered.json"
curl -fsS -c "$tmp/fresh.cookie" "$base/admin" > "$tmp/fresh.login"
csrf=$(csrf_from "$tmp/fresh.login")
[[ $(http_status -b "$tmp/fresh.cookie" -c "$tmp/fresh.cookie" \
  -d "action=login&csrf=$csrf&password=NewPasswordForHTTP119%21" "$base/admin") == 303 ]]
[[ $(http_status -b "$tmp/a.cookie" -d "action=modules&csrf=$csrf" "$base/admin") == 403 ]]
echo 'Lite 1.2.0 HTTP two-device revocation, SSO-only restore safety, acceptance CLI and recovery OK'
