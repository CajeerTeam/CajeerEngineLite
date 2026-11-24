# CajeerEngine Lite 1.4.3 — security operations

## Deployment prerequisites

- Configure the web root as `<project>/public` only. Never expose the project root or `storage/`, templates, backup archives, environment files, or the PHP session directory.
- On HTTPS installs, configure the web server to pass the *actual* TLS state as `HTTPS=on` to PHP-FPM. The CMS does not trust arbitrary `X-Forwarded-*` headers to decide whether administrative access is secure.
- Protect `storage/` and PHP session files with private filesystem permissions and use the PHP `files` session handler. Existing sessions may require reauthentication after a security-policy change.
- Run PHP 8.4.1+ with OpenSSL for OIDC and required JSON/filter/hash support. Keep all enabled extensions and your operating system patched.
- Keep `CAJEER_DEVELOPER_TEMPLATE_WRITE` disabled except during supervised maintenance. Take a verified backup before template changes; disable the toggle afterwards.

## Security audit

```bash
php bin/cajeer security audit
php bin/cajeer security audit --json
php tests/core_143.php
bash tests/security_hardening_http_143.sh
```

The offline audit checks the *current files*: the front controller, public executable/private-file exposure, symlinks, runtime prerequisites, and third-party package/lockfile inventory. A nonzero exit signals a detected violation. **It does not query CVE databases, assess dependency advisories or prove security against unknown vulnerabilities.** Use independently maintained advisory scanners for dependency CVEs when dependencies are present. The standard Lite archive has no Composer/npm manifest or vendored dependencies.

## Changed trust boundaries

- Admin POSTs permit only same-origin Fetch Metadata (or explicit browser `none`), suitable form content types, and valid CSRF tokens; OIDC back-channel logout remains exempt from browser-origin checks but demands a valid signed logout token.
- OIDC signature keys must be RS256 verification keys with a strong RSA modulus and a canonical odd exponent of at least 3. Remote key URLs and redirect-based key retrieval remain disabled.
- Templates are read through a checked file descriptor; symlinked paths and hardlinked files are not rendered or edited. Template expressions do not grant PHP execution. Reserved loop variables cannot override template engine internals.
- Imported TAR members must match the CMS archive format, have canonical nonprivileged permission/owner metadata, pass checksums, and only contain allowlisted file names. TAR imports are not generic filesystem extraction.
- Administrative CSP rejects inline handler attributes and worker execution. Public CSP maintains narrowly scoped provider permissions based on configured integrations.

## Backup / rollback

Upgrade with an independent, offline backup of both source code and storage. CMS Operations TAR archives include CMS content and media **but not PHP source files, templates or server secrets**. For restore failures, follow `STORAGE_RECOVERY.md` and keep the undo journal intact until successful recovery. CLI audit success does not imply live TLS, IdP, reverse proxy or production sign-off.
