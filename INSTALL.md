# Installation — CajeerEngine Lite 1.4.8+

## Requirements

Linux/POSIX local filesystem with atomic rename, hardlinks, `flock` and `fsync`; PHP 8.4.1+ with json, filter, hash, session, openssl and fileinfo; image validation uses the PHP image facilities. Enable `allow_url_fopen` and CA certificates for HTTPS provider calls and release discovery. Use PHP-FPM behind Nginx or Apache, not the PHP development server. PHP 8.5 and the three browser engines are CI matrix candidates, not a promise of certified compatibility. See [COMPATIBILITY.md](COMPATIBILITY.md). No required database, Composer, Redis or Node runtime. Python 3.11+ and OpenSSL CLI are release-build tools only.

## Obtain and verify

Download the full `CajeerEngineLite-<version>.zip`, `SHA256SUMS` and `SHA256SUMS.sig` from an authorized official release. Acquire the publisher's RSA public key independently, then verify before extracting. Never obtain your trust key from the same untrusted package.

```sh
openssl dgst -sha256 -verify /etc/cajeer/update-trusted.pem -signature SHA256SUMS.sig SHA256SUMS
sha256sum --strict -c SHA256SUMS
```

Download all three named payloads (ZIP, `.cjrupdate`, `release-report.json`) for the complete checksum check. A patch ZIP is not a full clean-install archive. Unpack the full ZIP into an empty directory (e.g. `/var/www/cajeer-lite`), inspect ownership and permissions, and do not overwrite another installation.

## Configure the host

Use a dedicated Unix account, with immutable application code readable by the FPM account, and private writable `storage/` owned by that account (0700 directories, 0600 private files). Keep TLS keys, OIDC credentials and provider tokens outside the application and document root; never run the CMS as root. An updater operator needs permission to change the deployed code; do not grant that ability to normal HTTP workers. Adapt `deploy/nginx.conf` or `deploy/apache-vhost.conf`: domain, certificate paths, PHP-FPM socket and absolute `public/` path. Configure HTTPS and a redirect from HTTP at the web server. For Apache, enable the needed SSL, proxy_fcgi, rewrite and headers modules; for Nginx, ensure FastCGI configuration passes only the expected front controller. Validate with `nginx -t` or `apachectl configtest`. See [COMPATIBILITY.md](COMPATIBILITY.md) for exact-peer reverse proxy trust. Do not serve the project root, `storage/`, templates, tests, or backups.

## Initialize

Run from the project root as the storage owner, using a secure terminal. The password prompt is visible; use an environment variable obtained without echoing the password or placing it in shell history. Example Bash:

```sh
read -rsp 'Admin password (12–72 bytes): ' CAJEER_ADMIN_PASSWORD; echo
export CAJEER_ADMIN_PASSWORD
php bin/cajeer install https://example.com 'My site'
unset CAJEER_ADMIN_PASSWORD
php bin/cajeer doctor
php bin/cajeer compatibility check --json
php bin/cajeer security audit --json
php bin/cajeer operations backup create
```

Do not rerun `install` against an existing `site.json`. For legacy data use [UPGRADE.md](UPGRADE.md). Configure PHP-FPM environment explicitly if enabling OIDC, AI or analytics; CLI environment variables do not automatically propagate to FPM. Keep optional integrations disabled until tested, and ensure consent requirements are satisfied.

Start FPM/web server; check `curl --fail https://example.com/health/live`, `curl --fail https://example.com/health/ready`, home page, `/admin` sign-in, public asset loading, and a private URL such as `/storage/site.json` (must not expose data). Verify cookies, TLS, correct domain and content policy. Create and verify an off-host backup; follow [PRODUCTION_ACCEPTANCE.md](PRODUCTION_ACCEPTANCE.md). For a local disposable test only: `php -S 127.0.0.1:8080 -t public router.php` with a separately installed HTTP test site. Do not expose this server publicly.
