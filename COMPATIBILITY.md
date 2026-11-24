# CajeerEngine Lite 1.4.6 — Compatibility Assurance

## Supported configurations

- **PHP:** 8.4.1+ with `json`, `hash`, `filter`, `session`, `openssl` and durable `fsync` support. The local suite validates PHP 8.4; PHP 8.5 is included in CI as an additional candidate and should not be considered certified until its pipeline passes. PHP 8.3 and below are unsupported.
- **Persistence:** one writable local POSIX-style filesystem (file locks, atomic rename, hardlinks, `fsync`); no NFS, SMB, multi-node or distributed locks. Site schemas 1–14 can be read; schema 14 is written after migration.
- **Web servers:** Nginx + PHP-FPM (`deploy/nginx.conf`), Apache 2.4 + PHP-FPM (`deploy/apache-vhost.conf`), or an isolated reverse proxy via `deploy/nginx-reverse-proxy.conf`. In every case the real document root must be `public/`.
- **Browsers:** Chromium, Firefox and WebKit have **real HTTP navigation** integration checks in `tests/browser_146.py`. Run all three in CI. Existing Chromium `set_content` screenshot/layout tests remain independent.

## Read-only environment and data checks

```sh
CAJEER_COMPAT_DOCUMENT_ROOT=/var/www/cajeer-lite/public php bin/cajeer compatibility check --json
php bin/cajeer compatibility migration preview
```

`check` validates the running PHP extensions, the installed version, filesystem separation, configured document root, trusted-proxy IP list and JSON data. The document-root check is explicitly marked manual if the environment does not provide it. `preview` loads the current site file, validates it, computes the complete 1–13 → 14 migration **in memory**, and reports source/target hashes and page count without touching site data or creating a backup.

## Applying a legacy migration

1. Make a verified independent backup of **code, templates, storage and server secrets** and stop PHP-FPM, cron and update workers.
2. Run `php bin/cajeer compatibility migration preview`; review the schema and checksum.
3. Run `CAJEER_COMPATIBILITY_CONFIRM=MIGRATE php bin/cajeer compatibility migration apply`.
4. The existing `SiteRepository::migrate` acquires the exclusive writer lock, creates a checksummed `migration` snapshot in private `storage/backups`, and atomically commits schema 14. It preserves page content, credentials and origin.
5. Run `php bin/cajeer compatibility check --json`, `php bin/cajeer doctor` and your HTTP/browser acceptance. Keep the independent backup available for recovery.

Do not enable unattended updates on old schemas. The **signed Update Engine remains code-only** and requires schema 14; it does not run untrusted migration scripts from release packages. For upgrading from a pre-1.4.4 release, use the documented manual code upgrade and this explicit migration path before enabling automatic updates. Do not claim arbitrary direct version jumps are tested: base-file hashes must match the signed release's exact `from` version.

## Reverse proxy trust

By default, forwarded headers are ignored and `AdminRequestGuard` requires a canonical `Host` and an authenticated `HTTPS=on` signal from the web server. For TLS offloading, set `CAJEER_TRUSTED_PROXY_IPS` to at most 16 **exact** IPv4 or IPv6 peer addresses (not CIDR ranges), e.g. `127.0.0.1`. The PHP request must be received directly from one of those peers. If both `X-Forwarded-Host` and `X-Forwarded-Proto` arrive from a trusted peer, they must identify the exact configured canonical origin. Malformed, multiple, contradictory or wrong-origin values are rejected. Client-supplied `X-Forwarded-For` is never used for authentication or rate limiting.

The front proxy **must overwrite, not forward or append, client-provided** `Host`, `X-Forwarded-Host` and `X-Forwarded-Proto`. Block direct public access to the origin. The supplied origin config binds loopback; adjust only after restricting source IPs with firewall rules. An env list alone is NOT sufficient to secure a publicly reachable origin. Avoid passing `HTTPS=on` unconditionally from an HTTP-only backend: the trusted peer check must establish the actual external HTTPS connection first.

## Integration testing

```sh
php tests/core_146.php
bash tests/compatibility_http_146.sh
python tests/browser_146.py --browser chromium
python tests/browser_146.py --browser firefox
python tests/browser_146.py --browser webkit
```

Run the native Nginx/Apache configuration lint with deployed certificate/socket paths, then test real TLS and real reverse proxy behavior in staging. The sample config files are not automatically installed and must be adapted to your environment. Unverified matrix entries must not be reported as production certified.
