#!/usr/bin/env bash
# Native Apache/Nginx configuration syntax validation; no live FPM/TLS assertion.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if ! command -v apache2 >/dev/null || ! command -v nginx >/dev/null || ! command -v openssl >/dev/null; then
  echo 'Server syntax testing unavailable (requires apache2, nginx, openssl)' >&2
  exit 2
fi
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/ssl" "$TMP/www" "$TMP/logs"
cp /etc/nginx/fastcgi_params "$TMP/fastcgi_params"
openssl req -newkey rsa:2048 -nodes -keyout "$TMP/ssl/key.pem" -x509 -days 1 \
  -out "$TMP/ssl/cert.pem" -subj '/CN=example.com' >/dev/null 2>&1
for name in nginx.conf nginx-reverse-proxy.conf nginx-maintenance-health.conf apache-vhost.conf apache-maintenance-health.conf; do
  sed -e "s#/etc/ssl/example.com/fullchain.pem#$TMP/ssl/cert.pem#g" \
      -e "s#/etc/ssl/example.com/privkey.pem#$TMP/ssl/key.pem#g" \
      -e "s#/var/www/cajeer-lite/public#$TMP/www#g" \
      "$ROOT/deploy/$name" > "$TMP/$name"
done
printf 'events {}\nhttp { include /etc/nginx/mime.types; include %s/nginx.conf; include %s/nginx-reverse-proxy.conf; include %s/nginx-maintenance-health.conf; }\n' \
  "$TMP" "$TMP" "$TMP" > "$TMP/nginx-root.conf"
nginx -t -p "$TMP" -c "$TMP/nginx-root.conf" >"$TMP/nginx-log" 2>&1 || { cat "$TMP/nginx-log";exit 1; }
cat > "$TMP/apache.conf" <<APACHE
ServerRoot "/etc/apache2"
ServerName example.com
PidFile "$TMP/apache.pid"
ErrorLog "$TMP/apache.log"
Listen 443
LoadModule mpm_event_module /usr/lib/apache2/modules/mod_mpm_event.so
LoadModule authz_core_module /usr/lib/apache2/modules/mod_authz_core.so
LoadModule authz_host_module /usr/lib/apache2/modules/mod_authz_host.so
LoadModule ssl_module /usr/lib/apache2/modules/mod_ssl.so
LoadModule socache_shmcb_module /usr/lib/apache2/modules/mod_socache_shmcb.so
LoadModule rewrite_module /usr/lib/apache2/modules/mod_rewrite.so
LoadModule headers_module /usr/lib/apache2/modules/mod_headers.so
LoadModule proxy_module /usr/lib/apache2/modules/mod_proxy.so
LoadModule proxy_fcgi_module /usr/lib/apache2/modules/mod_proxy_fcgi.so
LoadModule dir_module /usr/lib/apache2/modules/mod_dir.so
LoadModule mime_module /usr/lib/apache2/modules/mod_mime.so
Include "$TMP/apache-vhost.conf"
Include "$TMP/apache-maintenance-health.conf"
APACHE
APACHE_LOG_DIR="$TMP/logs" apache2 -t -f "$TMP/apache.conf" >"$TMP/apache-log" 2>&1 || { cat "$TMP/apache-log";exit 1; }
echo 'Compatibility webserver 1.4.6: Apache 2.4 and Nginx native syntax tests OK'
