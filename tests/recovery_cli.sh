#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
stage=$(mktemp -d "${TMPDIR:-/tmp}/cajeer-recovery-cli-XXXXXXXX")
trap 'rm -rf "$stage"' EXIT
mkdir "$stage/project"
cp -R bootstrap.php core bin public templates VERSION "$stage/project/"
mkdir -p "$stage/project/storage"
cd "$stage/project"
CAJEER_ADMIN_PASSWORD='BeforeRecoverySecure123!' php bin/cajeer install https://recovery.test 'Recovery CLI' >/dev/null
old=$(php bin/cajeer recovery issue)
token=${old##*$'\n'}
[[ "$token" =~ ^[0-9a-f]{64}$ ]] || { echo 'CLI did not return a 256-bit token' >&2; exit 1; }
printf '%s\n' "$token" > "$stage/token"
printf '%s\n' 'AfterRecoverySecure456!' > "$stage/password"
chmod 600 "$stage/token" "$stage/password"
php bin/cajeer recovery complete "$stage/token" "$stage/password" | grep -F 'All sessions revoked'
php bin/cajeer doctor | grep -F 'Lite 1.2.1'
if php bin/cajeer recovery complete "$stage/token" "$stage/password" >/dev/null 2>&1; then
  echo 'Replay of consumed token accepted' >&2; exit 1
fi
php -r 'require "bootstrap.php"; $app=new CajeerEngine\Lite(__DIR__); if(!$app->login("AfterRecoverySecure456!") || $app->login("BeforeRecoverySecure123!")) exit(1);'
echo 'Recovery CLI end-to-end OK'
