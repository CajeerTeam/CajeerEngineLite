#!/usr/bin/env bash
set -euo pipefail
source_root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
pid=''
trap '[[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true; rm -rf "$tmp"' EXIT
cp -R "$source_root"/. "$tmp"/
rm -rf "$tmp/storage/auth" "$tmp/storage/site.json" "$tmp/storage/site.lock" "$tmp/storage/backups"
port=${TEST_AUTH_RELEASE_PORT:-19120}
base="http://127.0.0.1:$port"
cd "$tmp"
CAJEER_ADMIN_PASSWORD=ReleaseAcceptancePassword120 php bin/cajeer install "$base" 'Release HTTP 1.2.0' >/dev/null
php -S "127.0.0.1:$port" -t public router.php >"$tmp/server.log" 2>&1 & pid=$!
for i in {1..60}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep 0.1; done
curl -fsS -c "$tmp/cookies" "$base/admin?auth_acceptance=1" >"$tmp/login.html"
! grep -Fq 'Сетевую проверку Discovery' "$tmp/login.html"
csrf=$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/login.html" | head -1)
[[ -n "$csrf" ]]
status=$(curl -sS -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -c "$tmp/cookies" \
    -d "action=login&csrf=$csrf&password=ReleaseAcceptancePassword120" "$base/admin")
[[ "$status" == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin?auth_acceptance=1" >"$tmp/admin.html"
grep -Fq 'SkiF4er ID — приёмка 1.2.0' "$tmp/admin.html"
grep -Fq 'Проверить локальную готовность' "$tmp/admin.html"
grep -Fq 'Сетевую проверку Discovery' "$tmp/admin.html"
! grep -Fq 'CAJEER_OIDC_CLIENT_SECRET=' "$tmp/admin.html"
# Configure HTTPS and a known owner, as would be done after a genuine linkage
# on a staging site; the acceptance gate must still refuse to certify E2E login.
php -r '
require "bootstrap.php";
$a=new CajeerEngine\Lite(getcwd());
$a->change(static function(array $state):array {
 $state["settings"]["url"]="https://lite.example.test";
 return CajeerEngine\Auth\OwnerIdentity::bind($state,
   ["iss"=>CajeerEngine\Auth\OidcClient::ISSUER,"sub"=>"http-release-120"]);
});'
export CAJEER_OIDC_CLIENT_ID=release-cli-120
export CAJEER_OIDC_CLIENT_SECRET=secret-not-for-browser-120
set +e
php bin/cajeer auth release-check --json >"$tmp/release.json"
code=$?
set -e
[[ "$code" == 2 ]]
php -r '$r=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
if(!$r["automated_ok"] || $r["live_checked"] || $r["production_accepted"] ||
    $r["status"]!=="pending_live" || count($r["manual"])<5) exit(1);' "$tmp/release.json"
! grep -Fq "$CAJEER_OIDC_CLIENT_SECRET" "$tmp/release.json"
[[ $(php bin/cajeer auth check --json | php -r '$r=json_decode(stream_get_contents(STDIN),true);echo $r["ok"]?"pass":"fail";') == pass ]]
set +e
php bin/cajeer auth release-check --live --unsafe >"$tmp/invalid.log" 2>&1
code=$?
set -e
[[ "$code" == 1 ]]
grep -Fq 'Usage: auth release-check' "$tmp/invalid.log"
echo 'Lite 1.2.0 HTTP authenticated release diagnostics and CLI pending-manual gate OK'
