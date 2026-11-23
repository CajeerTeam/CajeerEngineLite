#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
pid=''
trap '[[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true; rm -rf "$tmp"' EXIT
mkdir -p "$tmp/project"
cp -R "$root"/{bootstrap.php,bin,core,public,templates,router.php,VERSION} "$tmp/project/"
cd "$tmp/project"
port=${TEST_PORT_AI_ACCEPTANCE:-19989}
base=http://127.0.0.1:$port
CAJEER_ADMIN_PASSWORD='AcceptancePassword129!' php bin/cajeer install "$base" 'AI Release Check' >/dev/null
php -r 'require "bootstrap.php"; $a=new CajeerEngine\Lite(__DIR__);$a->change(static function($s){$s["ai"]["enabled"]=true;return $s;});'
CAJEER_OPENAI_API_KEY='' php -S "127.0.0.1:$port" -t public router.php >"$tmp/http.log" 2>&1 & pid=$!
for i in {1..60}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
for path in /admin/ai /admin/ai/content /admin/ai/editor /admin/ai/seo /admin/ai/analytics /admin/ai/support; do
  code=$(curl --silent -o /dev/null -w '%{http_code}' "$base$path")
  [[ "$code" == 403 ]] || { echo "Unprotected AI screen $path: HTTP $code" >&2; exit 1; }
done
[[ $(curl -s -o /dev/null -w '%{http_code}' -d 'action=generate&topic=test' "$base/admin/ai/content") == 403 ]]
kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; pid=''
# Upgrade the origin to the canonical HTTPS production URL after the HTTP access checks.
php -r 'require "bootstrap.php"; $a=new CajeerEngine\Lite(__DIR__);$a->change(static function($s){$s["settings"]["url"]="https://staging.example.test";return $s;});'
set +e
CAJEER_OPENAI_API_KEY='' php bin/cajeer ai acceptance --json >"$tmp/missing.json" 2>"$tmp/missing.err"
missing=$?
CAJEER_OPENAI_API_KEY='sk-FakeAcceptanceKey123456789' php bin/cajeer ai acceptance --json >"$tmp/ready.json" 2>"$tmp/ready.err"
ready=$?
set -e
[[ "$missing" == 1 && "$ready" == 2 ]] || { cat "$tmp/missing.err" "$tmp/ready.err"; echo "Wrong acceptance gates: $missing $ready" >&2; exit 1; }
php -r '$s=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);if($s["automated_ok"]||$s["production_accepted"]||!in_array("credentials",array_column(array_filter($s["checks"],fn($c)=>$c["status"]==="fail"),"id"),true))exit(1);' "$tmp/missing.json"
php -r '$s=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);if(!$s["automated_ok"]||$s["production_accepted"]||count($s["manual"])<4)exit(1);' "$tmp/ready.json"
! grep -Fq 'sk-FakeAcceptanceKey123456789' "$tmp/ready.json"
# A malicious symlink in the private AI storage turns the same release gate red.
ln -s /etc/passwd storage/ai/evil.txt
set +e
CAJEER_OPENAI_API_KEY='sk-FakeAcceptanceKey123456789' php bin/cajeer ai acceptance --json >"$tmp/unsafe.json"
unsafe=$?
set -e
[[ "$unsafe" == 1 ]]
php -r '$s=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);if($s["automated_ok"]||!in_array("ai_files",array_column(array_filter($s["checks"],fn($c)=>$c["status"]==="fail"),"id"),true))exit(1);' "$tmp/unsafe.json"
php -r '$s=json_decode(file_get_contents("storage/site.json"),true,64,JSON_THROW_ON_ERROR);if($s["schema"]!==14||!$s["ai"]["enabled"])exit(1);'
echo 'Lite 1.2.9 HTTP protected AI routes, actual CLI release gate, privacy, manual outstanding status and unsafe storage rejection OK'
