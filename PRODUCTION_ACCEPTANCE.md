# CajeerEngine Lite: production release checklist

For 1.5.0 use the additional required [signed production gate and operator sign-off](PRODUCTION_STABLE.md). The legacy stable-check and RC gate alone never approve a deployment.

The executable gate is `php bin/cajeer release stable-check --json [--live]`.
Exit 1 means a failed check. Exit 2 means automated checks passed but manual release acceptance is still pending; it is **not** a deployment success code. The command never authenticates real users, sends a paid AI request or checks a real analytics account.

## Before upgrade

1. Take an off-host backup of the **entire** code tree (including custom `.cjr` templates), `storage/`, PHP-FPM/web-server configuration and secrets. CMS Operations TAR only includes site JSON and registered media, not the full server.
2. Confirm the live PHP-FPM user has read access to immutable code and exclusive access to `storage/` (0700/0600); verify the web `document_root` is `public/`, never the project root.
3. Record current version and schema, PHP runtime, canonical `settings.url` and deployed web-server configuration. Confirm a documented rollback and time to restore.
4. Create an offline-verified CMS archive with `php bin/cajeer operations backup create` and `php bin/cajeer operations backup verify <name>`.

## Isolated staging acceptance

1. Restore the **off-host** copy of code, templates and `storage/` into a private staging host; use separate environment credentials and domain as appropriate.
2. Apply the verified release following UPGRADE.md with PHP-FPM and scheduled jobs stopped. Do **not** alter `storage/site.json` manually or rename it. JSON schema remains 14.
3. Run `php bin/cajeer doctor`, `php bin/cajeer developer check` and `php bin/cajeer release stable-check --json`. Fix failures; note that exit 2 denotes pending human sign-off.
4. Run all CI PHP, HTTP and Chromium tests. The Chromium 1.4.0 smoke obtains real HTTP HTML via an authenticated client but uses `set_content` in restricted sandboxes; manually test **real browser navigation** on staging.
5. With a valid TLS certificate and correct `Host`, verify `GET /health/live` = 200, `GET /health/ready` = 200 `{"status":"ready"}`, `POST /health/ready` = 405, and `/admin` has HSTS, cache-control, CSP, Secure/HttpOnly/SameSite cookie. Verify wrong Host is rejected.
6. Run `php bin/cajeer release stable-check --json --live` where the canonical HTTPS URL is reachable with normal certificate verification (no insecure skip). This is a read-only public probe.
7. Test page creation, media upload/read/delete, SEO output, protected admin POST, template preview/save/restore only in an authorized maintenance window, backup/export/import, stale session revocation, and invalid TAR refusal on staging.
8. Exercise OIDC login, account mismatch, revocation, and backchannel logout with real SkiF4er ID accounts **if enabled**; test recovery with CLI and session revocation.
9. Check real AI/analytics provider delivery only if configured, respecting consent and cost limits.
10. Verify backup restore from the full off-host copy on an isolated host and rehearse rollback. Ensure temporary export TARs and logs are not exposed through `public/`.

## Deployment/rollback

Stop writers and schedulers, take a fresh off-host snapshot, deploy only the changed patch files with retained paths and production ownership, restart PHP-FPM, check `health/ready` and critical user flows. Monitor logs, error rate and disk utilization. If any check fails, stop writes and restore **both code and storage** from the same pre-upgrade backup, then rerun readiness and authentication checks. Do not use an imported CMS TAR as a substitute for a full server rollback.

Sign-off requires a human to record staging environment, date, test evidence, external dependency outcomes and rollback validation. No automatic tool can truthfully certify unobserved production traffic, credentials, providers or server configuration.

## 1.4.1 Core Stability acceptance

- Check that the project root `VERSION` agrees exactly with `Lite::VERSION` before a release is considered healthy; partial deployments are rejected.
- Run `php tests/core_141.php` and `php tests/site_concurrency_141.php` after a full independent backup. These tests use isolated local directories; do not run fault injection against production data.
- Exercise real site-editor submissions, page history, CMS import and JSON backup restoration on staging, including deliberate failures and recovery. Verify that password and OIDC authorization remain usable after a failed restore.
- Complete the existing TLS/proxy, real-browser and external integration sign-off; successful PHP regression does not complete production acceptance.

## 1.4.8 artifact acceptance

Verify the signed SHA256SUMS with the independently pinned public key. Run `python3 tests/release_engineering_148.py` and the exact-tag build rehearsal described in RELEASE.md. Review `release-report.json`; it records actual disposable installation and rollback, never live acceptance. Capture successful CI and staging logs, real TLS/FPM, browser and provider outcomes, off-host restore evidence, date and reviewer before production sign-off.

## 1.4.9 release candidate

Follow [RELEASE_CANDIDATE.md](RELEASE_CANDIDATE.md). `release rc-check` combines existing executable checks and requires a current verified CMS backup. On staging, explicitly opt into live HTTPS and selected provider probes. Exit 2 still means pending manual acceptance; `not_run` is not a successful provider check. The release workflow must mark 1.4.9 as a prerelease. No live account, production deployment or human sign-off is inferred from local TLS or synthetic test fixtures.
