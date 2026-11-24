#!/bin/bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
mkdir "$tmp/base"
cp -a "$root"/{core,bin,bootstrap.php,VERSION} "$tmp/base/"
cd "$tmp/base"
if php bin/cajeer update maintenance plan >/dev/null 2>&1; then exit 1; fi
export CAJEER_UPDATE_CONTROL_WORKER=1 CAJEER_AUTO_UPDATE_WORKERS_QUIESCED=0
[[ $(php bin/cajeer update maintenance plan) == tick ]]
if php bin/cajeer update maintenance recover >/dev/null 2>&1; then exit 1; fi
export CAJEER_AUTO_UPDATE_WORKERS_QUIESCED=1
if php bin/cajeer update maintenance verify >/dev/null 2>&1; then exit 1; fi
CAJEER_ADMIN_PASSWORD=MaintenanceTestPass155 php bin/cajeer install https://example.test Test >/dev/null
before=$(sha256sum storage/site.json)
php bin/cajeer update maintenance verify | grep -q ready_to_start
php bin/cajeer update maintenance recover | grep -q clean
flock -x storage/app/update-control/scheduler.lock sh -c '! php bin/cajeer update maintenance recover >/dev/null 2>&1'
flock -x storage/app/update-control/worker.lock sh -c '! php bin/cajeer update maintenance recover >/dev/null 2>&1'
flock -x storage/app/operations.lock sh -c '! php bin/cajeer update maintenance verify >/dev/null 2>&1'
mkdir "$tmp/next"; cp -a core bin bootstrap.php VERSION "$tmp/next/"
current=$(cat VERSION)
IFS=. read -r major minor patch <<< "$current"
next="$major.$minor.$((patch+1))"
printf '%s\n' "$next" > "$tmp/next/VERSION"
sed -i "s/VERSION = '$current'/VERSION = '$next'/" "$tmp/next/core/Lite.php"
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$tmp/private.pem" 2>/dev/null
chmod 600 "$tmp/private.pem"; openssl pkey -in "$tmp/private.pem" -pubout -out "$tmp/public.pem"
export CAJEER_UPDATE_SIGNING_KEY_FILE="$tmp/private.pem" CAJEER_UPDATE_TRUSTED_KEY_FILE="$tmp/public.pem"
php bin/cajeer update package "$tmp/next" "$tmp/package" >/dev/null
CAJEER_UPDATE_CONFIRM=INSTALL php bin/cajeer update install "$tmp/package" > "$tmp/installed.json"
php bin/cajeer update maintenance verify | grep -Fq "$next"
php bin/cajeer update maintenance verify | grep -q '"snapshot_verified": true'
cp core/Lite.php "$tmp/clean-lite"
printf '\n' >> core/Lite.php
if php bin/cajeer update maintenance verify >/dev/null 2>&1; then exit 1; fi
cp "$tmp/clean-lite" core/Lite.php
php -r 'require "bootstrap.php";$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);CajeerEngine\Update\OperationFiles::json(getcwd()."/storage/app/updates/active.json",["id"=>$r["id"],"mode"=>"install"]);' "$tmp/installed.json"
[[ $(php bin/cajeer update maintenance plan) == blocked ]]
php bin/cajeer update maintenance recover | grep -q recovered
php bin/cajeer update maintenance verify | grep -Fq "$current"
[[ $(sha256sum storage/site.json) == "$before" ]]
echo 'Maintenance CLI: opt-in, real signed installation, interrupted update recovery, fresh-process verification, leases and site integrity OK'
