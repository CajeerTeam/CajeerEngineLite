#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
cp -a "$root"/{core,bin,bootstrap.php,VERSION} "$tmp/"
cd "$tmp"
php bin/cajeer update scheduler status > initial.json
php -r '$s=json_decode(file_get_contents("initial.json"),true,512,JSON_THROW_ON_ERROR);if(!$s["stale"]||$s["heartbeat"]!==null)exit(1);'
if CAJEER_UPDATE_CONTROL_WORKER=0 php bin/cajeer update scheduler tick >/dev/null 2>&1; then exit 1; fi
export CAJEER_UPDATE_CONTROL_WORKER=1 CAJEER_AUTO_UPDATE_GITHUB_REPO=invalid
php bin/cajeer update scheduler tick > idle.json
php -r '$s=json_decode(file_get_contents("idle.json"),true,512,JSON_THROW_ON_ERROR);if($s["action"]!=="idle")exit(1);'
php -r 'require "bootstrap.php";$c=new CajeerEngine\Update\ControlCenter(getcwd());$c->submit(["action"=>"check","revision"=>$c->status()["revision"]]);'
php bin/cajeer update scheduler tick > retry.json
php bin/cajeer update scheduler tick > wait.json
php bin/cajeer update scheduler status > state.json
php -r '$r=json_decode(file_get_contents("retry.json"),true,512,JSON_THROW_ON_ERROR);$w=json_decode(file_get_contents("wait.json"),true,512,JSON_THROW_ON_ERROR);$s=json_decode(file_get_contents("state.json"),true,512,JSON_THROW_ON_ERROR);if($r["action"]!=="retry_scheduled"||$w["action"]!=="retry_wait"||$s["job"]["id"]!==$r["id"]||$s["heartbeat"]["action"]!=="retry_wait"||$s["stale"])exit(1);'
# Independent OS process owns the same lease; scheduler must return without rewriting heartbeat.
cp storage/app/update-control/scheduler.json before.json
flock -x storage/app/update-control/scheduler.lock php bin/cajeer update scheduler tick > busy.json
cmp before.json storage/app/update-control/scheduler.json
php -r '$s=json_decode(file_get_contents("busy.json"),true,512,JSON_THROW_ON_ERROR);if($s["action"]!=="busy")exit(1);'
# Reconstruct an existing queued job with an already elapsed backoff (no sleeps/network).
for i in 2 3; do
    php -r 'require "bootstrap.php";$p="storage/app/update-control/state.json";$s=CajeerEngine\Update\OperationFiles::read($p);$s["job"]["not_before"]=time()-1;CajeerEngine\Update\OperationFiles::json($p,$s);'
    if [[ $i == 2 ]]; then php bin/cajeer update scheduler tick >/dev/null; else
        if php bin/cajeer update scheduler tick >/dev/null 2>&1; then exit 1; fi
    fi
done
php bin/cajeer update scheduler status > state.json
php -r '$s=json_decode(file_get_contents("state.json"),true,512,JSON_THROW_ON_ERROR);if($s["heartbeat"]["action"]!=="failed"||$s["job"]!==null)exit(1);'
php -r 'require "bootstrap.php";CajeerEngine\Update\OperationFiles::json(getcwd()."/storage/app/updates/active.json",["id"=>str_repeat("a",24),"mode"=>"install"]);'
set +e
php bin/cajeer update scheduler tick > recovery.json
rc=$?
set -e
[[ "$rc" == 2 ]]
php -r '$s=json_decode(file_get_contents("recovery.json"),true,512,JSON_THROW_ON_ERROR);if($s["action"]!=="recovery_required")exit(1);'
echo 'Scheduler CLI: opt-in, persisted retry across processes, delayed retry, exclusive lease and final error exit OK'
