#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
PID=""
cleanup() { [[ -z "$PID" ]] || { kill "$PID" 2>/dev/null || :; wait "$PID" 2>/dev/null || :; }; rm -rf "$TMP"; }
trap cleanup EXIT
mkdir -p "$TMP/site"
cp -a "$ROOT/core" "$ROOT/public" "$ROOT/templates" "$ROOT/bootstrap.php" "$ROOT/router.php" "$ROOT/VERSION" "$TMP/site/"
mkdir -p "$TMP/site/bin"
cp "$ROOT/bin/cajeer" "$TMP/site/bin/cajeer"
CAJEER_ADMIN_PASSWORD='TestProxyPassword146!' php "$TMP/site/bin/cajeer" install https://test.example.org 'Reverse proxy test' >/dev/null
PORT="$(python - <<'PYPORT'
import socket
s=socket.socket();s.bind(('127.0.0.1',0));print(s.getsockname()[1]);s.close()
PYPORT
)"
(cd "$TMP/site" && CAJEER_TRUSTED_PROXY_IPS=127.0.0.1 php -S "127.0.0.1:$PORT" router.php >"$TMP/php.log" 2>&1) & PID=$!
for i in $(seq 1 80); do
  if curl -sS --max-time 1 "http://127.0.0.1:$PORT/health/live" >"$TMP/health" 2>/dev/null; then break; fi
  sleep .1
done
[[ "$(cat "$TMP/health")" == '{"status":"ok"}' ]]
url="http://127.0.0.1:$PORT/admin"
status() { curl --max-time 8 -sS -o "$TMP/body" -w '%{http_code}' "$@" "$url"; }
[[ "$(status -H 'Host: backend.internal' -H 'X-Forwarded-Host: test.example.org' -H 'X-Forwarded-Proto: https')" == '200' ]]
grep -q 'Войти по паролю' "$TMP/body"
[[ "$(status -H 'Host: backend.internal' -H 'X-Forwarded-Host: test.example.org.evil.test' -H 'X-Forwarded-Proto: https')" == '421' ]]
[[ "$(status -H 'Host: backend.internal' -H 'X-Forwarded-Host: test.example.org' -H 'X-Forwarded-Proto: http')" == '421' ]]
[[ "$(status -H 'Host: backend.internal' -H 'X-Forwarded-Host: test.example.org,evil.test' -H 'X-Forwarded-Proto: https')" == '421' ]]
[[ "$(status -H 'Host: test.example.org')" == '403' ]]
[[ "$(status -H 'Host: test.example.org' -H 'X-Forwarded-Host: test.example.org' -H 'X-Forwarded-Proto: https')" == '200' ]]
# Real HTTP response must issue Secure/HttpOnly cookies even behind the TLS offloader.
curl -sS -D "$TMP/headers" -o /dev/null -H 'Host: test.example.org' -H 'X-Forwarded-Host: test.example.org' -H 'X-Forwarded-Proto: https' "$url"
grep -qi 'set-cookie: CajeerLite=' "$TMP/headers"
grep -qi 'secure;.*httponly' "$TMP/headers"
echo 'Compatibility HTTP 1.4.6: canonical trusted proxy, TLS, HTTP cookies, injected Host and downgrade refusals OK'
