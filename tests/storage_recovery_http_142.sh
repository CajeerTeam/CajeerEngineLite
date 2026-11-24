#!/usr/bin/env bash
set -euo pipefail
source_root=$(cd "$(dirname "$0")/.." && pwd)
work=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; }; rm -rf "$work"' EXIT
cp -a "$source_root"/. "$work"/
rm -f "$work/storage/site.json" "$work/storage/site.lock"
port=${TEST_PORT:-19142};base="http://127.0.0.1:$port"
cd "$work"
CAJEER_ADMIN_PASSWORD='Recovery142Password!' php bin/cajeer install "$base" 'Recovery HTTP' >/dev/null
cp storage/site.json "$work/expected.json"
# Create a real durable undo journal, then corrupt the live site as if a worker
# stopped immediately after publishing the changed JSON file.
php -r '
require "bootstrap.php";
$root=getcwd();$repo=new \CajeerEngine\Storage\SiteRepository($root);
$media=new \CajeerEngine\Media\MediaLibrary($root);
$journal=new \CajeerEngine\Operations\RecoveryJournal($root);
$guard=\CajeerEngine\Update\OperationLock::acquire($root,true);
$media->guard(function()use($repo,$journal):void {
    $repo->exclusive(function()use($repo,$journal):void {
        $journal->begin($repo->raw(),null,[]);
    });
});
\CajeerEngine\Update\OperationFiles::write($repo->file(),"{corrupt-json",0600);
'
[[ -f storage/app/cms-operations/recovery/pending.json ]]
php -S "127.0.0.1:$port" -t public router.php > "$work/server.log" 2>&1 & pid=$!
for i in {1..100};do curl -fsS "$base/health/live" >/dev/null 2>&1 && break;sleep .1;done
[[ $(curl -fsS "$base/health/live") == '{"status":"ok"}' ]]
# Liveness must not clear the recovery journal.
[[ -f storage/app/cms-operations/recovery/pending.json ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/health/ready") == 200 ]]
[[ ! -f storage/app/cms-operations/recovery/pending.json ]]
cmp "$work/expected.json" storage/site.json
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/") == 200 ]]
# A damaged undo record must fail closed, not expose a half-imported site.
php -r '
require "bootstrap.php";
$root=getcwd();$repo=new \CajeerEngine\Storage\SiteRepository($root);
$media=new \CajeerEngine\Media\MediaLibrary($root);
$journal=new \CajeerEngine\Operations\RecoveryJournal($root);
$guard=\CajeerEngine\Update\OperationLock::acquire($root,true);
$media->guard(function()use($repo,$journal):void {
    $repo->exclusive(function()use($repo,$journal):void {$journal->begin($repo->raw(),null,[]);});
});
'
printf 'corrupted-recovery-snapshot' > storage/app/cms-operations/recovery/site.before
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/health/ready") == 503 ]]
[[ $(curl -fsS "$base/health/live") == '{"status":"ok"}' ]]
[[ -f storage/app/cms-operations/recovery/pending.json ]]
# Operator reconstructs the original snapshot from a verified offline copy.
cp "$work/expected.json" storage/app/cms-operations/recovery/site.before
chmod 0600 storage/app/cms-operations/recovery/site.before
php bin/cajeer operations recover | grep -Fq 'rolled back'
[[ ! -f storage/app/cms-operations/recovery/pending.json ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/health/ready") == 200 ]]
php bin/cajeer operations audit | grep -Fq '"orphan_files": 0'
echo 'Storage & Recovery 1.4.2 HTTP: restart undo, readiness isolation, corrupted journal fails closed, operator recovery OK'
