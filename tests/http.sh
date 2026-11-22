#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d); pid=''
trap '[[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true; rm -rf "$tmp"' EXIT
cp -R "$root"/. "$tmp"/; rm -f "$tmp/storage/site.json" "$tmp/storage/site.lock"
port=${TEST_PORT:-18973}; base=http://127.0.0.1:$port
cd "$tmp"; CAJEER_ADMIN_PASSWORD=TestPassword123 php bin/cajeer install "$base" Test
php -S "127.0.0.1:$port" -t public router.php > "$tmp/server.log" 2>&1 & pid=$!
for i in {1..30}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
get(){ local result; result=$(curl -fsS "$base$1") || return; printf '%s\n' "$result"; }
status(){ curl -sS -o /dev/null -w '%{http_code}' "$@"; }
get / | grep -q 'Добро пожаловать'; get /robots.txt | grep -q Sitemap; get /sitemap.xml | grep -q "$base/"; get /assets/style.css | grep -q ':root'
[[ $(status "$base/storage/site.json") == 404 ]]; [[ $(status "$base/missing") == 404 ]]; [[ $(status -X POST "$base/admin") == 403 ]]
curl -fsS -c "$tmp/cookies" "$base/admin" > "$tmp/login"; csrf=$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/login")
[[ $(status -b "$tmp/cookies" -c "$tmp/cookies" -d "action=login&csrf=$csrf&password=TestPassword123" "$base/admin") == 303 ]]
curl -fsS -b "$tmp/cookies" "$base/admin?new=1" > "$tmp/admin"; csrf=$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$tmp/admin")
[[ $(status -b "$tmp/cookies" --data-urlencode 'body=<script>alert(1)</script>' -d "action=save&csrf=$csrf&path=/about&title=About&published=1&menu=1" "$base/admin") == 303 ]]
get /about | grep -q '&lt;script&gt;'; get /sitemap.xml | grep -q '/about'; get / | grep -q '/about'
[[ $(status -b "$tmp/cookies" -d "action=save&csrf=$csrf&path=/draft&title=Draft" "$base/admin") == 303 ]]; [[ $(status "$base/draft") == 404 ]]
# Runtime module configuration from authenticated admin UI.
[[ $(status -b "$tmp/cookies" -d "action=modules&csrf=$csrf&module[robots]=1" "$base/admin") == 303 ]]
[[ $(status "$base/sitemap.xml") == 404 ]]
get /robots.txt | grep -vq 'Sitemap:'
if get / | grep -q '<nav'; then echo 'Disabled navigation still shown' >&2; exit 1; fi
[[ $(status -b "$tmp/cookies" -d "action=modules&csrf=$csrf&module[robots]=0" "$base/admin") == 422 ]]
[[ $(status -b "$tmp/cookies" -d "action=modules&csrf=$csrf&module[robots]=1&module[sitemap]=1&module[navigation]=1" "$base/admin") == 303 ]]
get /sitemap.xml | grep -q "$base/"
get / | grep -q '<nav'
php bin/cajeer doctor | grep -q 'schema 4'
[[ "$(php bin/cajeer migrate)" == 'Already at schema 4.' ]]
php bin/cajeer module disable robots | grep -q disabled
[[ $(status "$base/robots.txt") == 404 ]]
php bin/cajeer module enable robots | grep -q enabled
backup_file=$(php bin/cajeer backup create | sed -n 's/^Backup created: //p')
[[ -n "$backup_file" ]]
php bin/cajeer backup verify "$backup_file" | grep -q 'verified'
php bin/cajeer module disable sitemap | grep -q disabled
[[ $(status "$base/sitemap.xml") == 404 ]]
if CAJEER_RESTORE_CONFIRM=wrong php bin/cajeer backup restore "$backup_file" >/dev/null 2>&1; then
    echo 'Restore missing confirmation succeeded' >&2; exit 1
fi
CAJEER_RESTORE_CONFIRM="$backup_file" php bin/cajeer backup restore "$backup_file" | grep -q 'Restored:'
get /sitemap.xml | grep -q "$base/"
# Cookie Consent: public-only JavaScript, strict admin CSP, runtime config, consent expiry.
get / | grep -q 'data-consent-action="accept"'
get / | grep -q 'src="/assets/consent.js?v=103"'
get /assets/consent.js | grep -q 'cajeer:consentchange'
curl -fsSI "$base/" | grep -qi "Content-Security-Policy:.*script-src 'self'"
curl -fsSI "$base/admin" | grep -qi "Content-Security-Policy:.*script-src 'none'"
[[ $(status -b "$tmp/cookies" -d "action=consent&csrf=$csrf&enabled=1&days=12&title=Cookie+choices&description=Visitors+may+choose&privacy_url=https://invalid.example" "$base/admin") == 422 ]]
[[ $(status -b "$tmp/cookies" -d "action=consent&csrf=$csrf&enabled=1&days=12&title=Cookie+choices&description=Visitors+may+choose&privacy_url=" "$base/admin") == 303 ]]
get / | grep -q 'Cookie choices'
get / | grep -q 'data-revision="2"'
now=$(date +%s)
# Old policy revision is rejected; current revision is honored by server-rendered markup.
curl -fsS -H "Cookie: cajeer_lite_consent=v1.1.$now.7" "$base/" | grep -q 'data-mask="0"'
curl -fsS -H "Cookie: cajeer_lite_consent=v1.2.$now.5" "$base/" | grep -q 'data-mask="5"  hidden'
# GA4 configuration through authenticated admin; every script blocked by default.
get / | grep -vq '/assets/ga4.js'
[[ $(status -b "$tmp/cookies" -d "action=ga4&csrf=$csrf&enabled=1&measurement_id=G-<script>" "$base/admin") == 422 ]]
[[ $(status -b "$tmp/cookies" -d "action=ga4&csrf=$csrf&enabled=1&measurement_id=G-1234567890&page_views=1&contact_clicks=1&debug_mode=1" "$base/admin") == 303 ]]
get / | grep -q 'data-ga4-id="G-1234567890"'
get /assets/ga4.js | grep -q 'analytics_storage'
curl -fsSI "$base/" | grep -qi 'script-src.*https://www.googletagmanager.com'
curl -fsSI "$base/admin" | grep -qi "script-src 'none'"
[[ $(status -b "$tmp/cookies" -d "action=ga4&csrf=wrong&enabled=1&measurement_id=G-1234567890" "$base/admin") == 403 ]]
# If the consent UI is disabled, configured analytics must fail closed.
[[ $(status -b "$tmp/cookies" -d "action=consent&csrf=$csrf&days=12&title=Cookie+choices&description=Visitors+may+choose&privacy_url=" "$base/admin") == 303 ]]
get / | grep -vq '/assets/ga4.js'
if curl -fsSI "$base/" | grep -qi 'googletagmanager.com'; then echo 'GA4 CSP allowed without Cookie Consent' >&2; exit 1; fi
[[ $(status -b "$tmp/cookies" -d "action=consent&csrf=$csrf&enabled=1&days=12&title=Cookie+choices&description=Visitors+may+choose&privacy_url=" "$base/admin") == 303 ]]
get / | grep -q '/assets/ga4.js'
[[ $(status -b "$tmp/cookies" -d "action=logout&csrf=$csrf" "$base/admin") == 303 ]]; [[ $(status -b "$tmp/cookies" -d "action=save&csrf=$csrf&path=/bad&title=Bad" "$base/admin") == 403 ]]
echo 'HTTP smoke OK' 
