#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true; rm -rf "$tmp"' EXIT
mkdir -p "$tmp/project"
cp -R "$root"/{bootstrap.php,bin,core,public,templates,router.php,VERSION} "$tmp/project/"
cd "$tmp/project"
port=${TEST_PORT_AI_STABLE:-19991};base="http://127.0.0.1:$port"
CAJEER_ADMIN_PASSWORD='StableHttpPassword130!' php bin/cajeer install "$base" 'Stable HTTP Test' >/dev/null
CAJEER_OPENAI_API_KEY='' php -S "127.0.0.1:$port" -t public router.php > "$tmp/http.log" 2>&1 & pid=$!
for i in {1..80};do curl -fsS "$base/health/live" >/dev/null 2>&1 && break;sleep .1;done
curl -fsS -c "$tmp/cookies" "$base/admin?ai_release=1" > "$tmp/login"
! grep -q 'Cajeer AI Assistant 1.3.0 — проверка готовности' "$tmp/login"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/login" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -c "$tmp/cookies" \
 --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' \
 --data-urlencode 'password=StableHttpPassword130!' "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin?ai_release=1" > "$tmp/ready"
grep -Fq 'Cajeer AI Assistant 1.3.0 — проверка готовности' "$tmp/ready"
grep -Fq 'Платный запуск:' "$tmp/ready"
! grep -Fq 'sk-' "$tmp/ready"
kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true;pid=''
php -r 'require "bootstrap.php";$app=new CajeerEngine\Lite(__DIR__);$app->change(static function($s){$s["settings"]["url"]="https://lite.example.test";$s["ai"]["enabled"]=true;return $s;});'
set +e
CAJEER_OPENAI_API_KEY='' php bin/cajeer ai release-check --json > "$tmp/missing.json" 2> "$tmp/missing.err"; missing=$?
CAJEER_OPENAI_API_KEY='sk-SafeFakeReleaseKey12345' php bin/cajeer ai release-check --json > "$tmp/configured.json" 2> "$tmp/configured.err"; configured=$?
CAJEER_OPENAI_API_KEY='sk-SafeFakeReleaseKey12345' php bin/cajeer ai release-check --paid --json > "$tmp/no-consent.json" 2> "$tmp/no-consent.err"; no_consent=$?
set -e
[[ $missing == 1 && $configured == 2 && $no_consent == 1 ]] || {
 cat "$tmp/missing.err" "$tmp/configured.err" "$tmp/no-consent.err";echo 'Unexpected release gate exit codes' >&2;exit 1;
}
php -r '
$m=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
$c=json_decode(file_get_contents($argv[2]),true,64,JSON_THROW_ON_ERROR);
$n=json_decode(file_get_contents($argv[3]),true,64,JSON_THROW_ON_ERROR);
if($m["automated_ok"]||!$c["automated_ok"]||$c["production_accepted"]||
   $c["paid_smoke_passed"]||$n["automated_ok"]||$n["paid_smoke_passed"]||
   !in_array("paid_api_smoke",array_column(array_filter($n["checks"],fn($x)=>$x["status"]==="fail"),"id"),true)) exit(1);
' "$tmp/missing.json" "$tmp/configured.json" "$tmp/no-consent.json"
! grep -Fq 'sk-SafeFakeReleaseKey12345' "$tmp/configured.json"
php -r '$v=json_decode(file_get_contents("storage/site.json"),true,64,JSON_THROW_ON_ERROR);if($v["schema"]!==13||count($v["pages"])!==1)exit(1);'
[[ ! -f storage/ai/usage-$(date -u +%F).json ]]
echo 'Lite 1.3.0 authenticated release UI, read-only CLI, explicit paid consent, no leaked credentials or paid calls OK'
