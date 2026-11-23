#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true; rm -rf "$tmp"' EXIT
cp -R "$root"/. "$tmp"/; rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_PORT_AI_ANALYTICS:-19988};base="http://127.0.0.1:$port"
cd "$tmp"
CAJEER_ADMIN_PASSWORD=RealAIAnalyticsHttpPassword126 php bin/cajeer install "$base" 'AI Analytics HTTP'
CAJEER_OPENAI_API_KEY='' php -S "127.0.0.1:$port" -t public router.php > "$tmp/server.log" 2>&1 & pid=$!
for i in {1..80};do curl -fsS "$base/health/live" >/dev/null 2>&1 && break;sleep .1;done
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/admin/ai/analytics") == 403 ]]
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/login" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" -c "$tmp/cookies" \
 --data-urlencode "csrf=$csrf" --data-urlencode 'action=login' \
 --data-urlencode 'password=RealAIAnalyticsHttpPassword126' "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin/ai/analytics" > "$tmp/analytics"
grep -Fq 'AI Analytics Insights' "$tmp/analytics"
grep -Fq 'Отчёт за выбранный период отсутствует' "$tmp/analytics"
curl -fsS -b "$tmp/cookies" "$base/admin" > "$tmp/admin-csrf"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$tmp/admin-csrf" | head -1 | cut -d '"' -f4)
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" --data-urlencode 'action=generate' --data-urlencode 'days=7' "$base/admin/ai/analytics") == 403 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" --data-urlencode "csrf=$csrf" --data-urlencode 'action=generate' --data-urlencode 'days=7' "$base/admin/ai/analytics") == 409 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" "$base/admin/ai/analytics?days=500") == 400 ]]
# Insert a real private snapshot into the same deployment. All fields come from the local report service shape.
php -r 'require "bootstrap.php"; $a=new CajeerEngine\Lite(getcwd()); $s=$a->load();$to=gmdate("Y-m-d",time()-86400);$from=(new DateTimeImmutable($to))->modify("-6 days")->format("Y-m-d");$r=["days"=>7,"from"=>$from,"to"=>$to,"generated_at"=>gmdate("c"),"providers"=>["ga4"=>["status"=>"ok","summary"=>["sessions"=>71,"conversions"=>4]],"metrica"=>["status"=>"unconfigured"]]];(new CajeerEngine\Analytics\ReportCache(getcwd()))->save($s,7,$r);'
curl -fsS -b "$tmp/cookies" "$base/admin/ai/analytics?days=7" > "$tmp/with-report"
grep -Fq '71' "$tmp/with-report"
grep -Fq 'name="action" value="generate"' "$tmp/with-report"
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" --data-urlencode "csrf=$csrf" \
 --data-urlencode 'action=generate' --data-urlencode 'days=7' "$base/admin/ai/analytics") == 503 ]]
[[ $(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/cookies" --data-urlencode "csrf=$csrf" \
 --data-urlencode 'action=unknown' --data-urlencode 'days=7' "$base/admin/ai/analytics") == 400 ]]
curl -fsS -b "$tmp/cookies" "$base/admin" > "$tmp/admin"
grep -Fq 'AI Analytics Insights — анализ отчётов и конверсий' "$tmp/admin"
php -r '$s=json_decode(file_get_contents("storage/site.json"),true);if($s["schema"]!==14||count($s["pages"])!==1)exit(1);'
echo 'Lite 1.2.6 HTTP AI analytics: private reports, authenticated read-only insights, CSRF, no provider or model calls without configured key OK'
