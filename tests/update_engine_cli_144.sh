#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/live" "$WORK/next"
# A real installable copy, so CLI uses the same bootstrap and PHP runtime as production.
cp -a "$ROOT"/. "$WORK/live/"
cp -a "$ROOT"/. "$WORK/next/"
CURRENT_VERSION="$(cat "$WORK/live/VERSION")"
NEXT_VERSION="$(php -r '$v=explode(".",$argv[1]);$v[2]=(int)$v[2]+1;echo implode(".",$v);' "$CURRENT_VERSION")"
printf '%s\n' "$NEXT_VERSION" > "$WORK/next/VERSION"
php -r '$p=$argv[1];$s=file_get_contents($p);$old="public const VERSION = ".chr(39).$argv[2].chr(39);$new="public const VERSION = ".chr(39).$argv[3].chr(39);if(!str_contains($s,$old))exit(2);file_put_contents($p,str_replace($old,$new,$s));' "$WORK/next/core/Lite.php" "$CURRENT_VERSION" "$NEXT_VERSION"
printf '%s\n' '<?php declare(strict_types=1); namespace CajeerEngine\Update; final class Probe144 {}' > "$WORK/next/core/Update/Probe144.php"
php -r '
$key=openssl_pkey_new(["private_key_type"=>OPENSSL_KEYTYPE_RSA,"private_key_bits"=>2048]);
if(!$key||!openssl_pkey_export($key,$secret))exit(2);
file_put_contents($argv[1],$secret);chmod($argv[1],0600);
file_put_contents($argv[2],openssl_pkey_get_details($key)["key"]);chmod($argv[2],0600);
' "$WORK/private.pem" "$WORK/trusted.pem"
export CAJEER_UPDATE_TRUSTED_KEY_FILE="$WORK/trusted.pem"
export CAJEER_UPDATE_SIGNING_KEY_FILE="$WORK/private.pem"
php "$WORK/live/bin/cajeer" update package "$WORK/next" "$WORK/release.cjrupdate" > "$WORK/build.json"
php "$WORK/live/bin/cajeer" update verify "$WORK/release.cjrupdate" > "$WORK/verify.json"
php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if($r["to"]!==$argv[2] || $r["files"]<3)exit(1);' "$WORK/verify.json" "$NEXT_VERSION"
export CAJEER_UPDATE_CONFIRM=INSTALL
php "$WORK/live/bin/cajeer" update install "$WORK/release.cjrupdate" > "$WORK/installed.json"
php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if($r["to"]!==$argv[2]||!preg_match("/^[a-f0-9]{24}$/",$r["id"]))exit(1);' "$WORK/installed.json" "$NEXT_VERSION"
test "$(cat "$WORK/live/VERSION")" = "$NEXT_VERSION"
test -f "$WORK/live/core/Update/Probe144.php"
php "$WORK/live/bin/cajeer" update status > "$WORK/status.json"
php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if($r["installed_version"]!==$argv[2])exit(1);' "$WORK/status.json" "$NEXT_VERSION"
# A left-behind active journal must make real HTTP PHP requests unavailable.
ID="$(php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);echo $r["id"];' "$WORK/installed.json" "$NEXT_VERSION")"
php -r 'file_put_contents($argv[1],json_encode(["id"=>$argv[2],"mode"=>"install"]));chmod($argv[1],0600);' "$WORK/live/storage/app/updates/active.json" "$ID"
HTTP_BODY="$(php -r '$_SERVER["REQUEST_URI"]="/health/ready";$_SERVER["REQUEST_METHOD"]="GET";include $argv[1];' "$WORK/live/public/index.php")"
[[ "$HTTP_BODY" == *'CMS update in progress'* ]] || { echo 'HTTP maintenance guard failed' >&2; exit 1; }
php "$WORK/live/bin/cajeer" update recover > "$WORK/recovered.json"
test "$(cat "$WORK/live/VERSION")" = "$CURRENT_VERSION"
test ! -f "$WORK/live/core/Update/Probe144.php"
# Re-install and roll back via the real CLI command parser.
php "$WORK/live/bin/cajeer" update install "$WORK/release.cjrupdate" > "$WORK/installed2.json"
ID="$(php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);echo $r["id"];' "$WORK/installed2.json")"
export CAJEER_UPDATE_CONFIRM=ROLLBACK
php "$WORK/live/bin/cajeer" update rollback "$ID" > "$WORK/rolled.json"
test "$(cat "$WORK/live/VERSION")" = "$CURRENT_VERSION"
test ! -f "$WORK/live/core/Update/Probe144.php"
echo 'Update Engine 1.4.4 CLI: signed build, verify, install, status, HTTP maintenance guard, recovery and rollback OK'
