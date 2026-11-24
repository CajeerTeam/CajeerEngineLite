# 1.4.9 — Production Release Candidate

Feature freeze: schema 14 and existing CMS behavior remain unchanged except for acceptance-related fixes. No new runtime dependencies. 1.4.9 is not the 1.5.0 production sign-off.

## Reproduce the candidate

Run full CI on the exact candidate commit. On an isolated machine with PHP 8.4.1+, Python 3.11+, OpenSSL and Nginx:

```sh
php tests/core_149.php
python3 tests/rc_staging_149.py
python3 tests/release_engineering_148.py
bash tests/experience_hardening_http.sh
python3 tests/browser_146.py --browser chromium
python3 tests/browser_146.py --browser firefox
python3 tests/browser_146.py --browser webkit
```

The Nginx test launches a private loopback HTTPS proxy, creates a disposable certificate trusted only by its clients, installs an isolated CMS, checks anonymous access, cookies, CSRF, login, page creation, sitemap, multipart media upload, backup download, restore and session revocation, and runs local and live RC checks. It uses the PHP development HTTP backend, not PHP-FPM. Do not expose that backend publicly. The browser tests use actual navigation and form submissions including page creation, file upload, backup and restore, and session invalidation. Install the Playwright browsers first. Network errors, missing browsers and failed assertions are failures, not passes.

Use the exact base and target source exports and independent trusted public key as described in RELEASE.md. The builder verifies the signed package using the base updater, checks a clean installation, then rehearses an upgrade of a populated site. It compares JSON, media index and media bytes across upgrade and rollback and re-verifies the original TAR backup at both stages. Keep the signed receipt and checksums. The test fixture is not a real production copy.

## Actual staging deployment

1. Use a private staging host with production-equivalent PHP-FPM, web server, permissions, TLS, proxy rules and limits. Restore an independent full backup to the staging machine; isolate its storage and replace live credentials with staging credentials. Do not clone active sessions into a publicly reachable host. See INSTALL.md, BACKUP_RECOVERY.md and SECURITY.md.
2. Stop FPM and scheduled writers before installing the pinned signed candidate from the exact base release. Verify, install and record the returned update ID according to UPGRADE.md. Restart FPM, create and verify a CMS backup, run the gate below and exercise the browser flows. Do not install an unsigned ZIP through the update engine.
3. Rehearse rollback with FPM and writers stopped, using the recorded update ID and independent full backup. Restart, verify readiness, login, content/media and provider configuration. Repeat upgrade on a restored clean baseline if the updater refuses a previously applied transition. Record code and data hashes, timestamps and measured outage. A code-only rollback does not reverse later content changes or migrations.
4. Restore the independent off-host backup in a separate environment as a recovery drill. Keep the actual staging output and human reviewer evidence.

## Operator gate

```sh
php bin/cajeer operations backup create
php bin/cajeer release rc-check --json
CAJEER_RC_STAGING_CONFIRM=STAGING php bin/cajeer release rc-check --live --json
```

The gate combines security, compatibility, pending-update, CMS restoration and operational monitoring checks. A missing, stale or invalid backup blocks it. CLI exit 1 means a failure; 2 means automated checks pass but acceptance remains pending. `--live` checks the configured HTTPS origin with ordinary certificate and hostname verification. For detailed failed checks run the corresponding `security audit`, `compatibility check`, `release stable-check`, `monitor check --verify-backup` or `update status` command. Low telemetry samples do not prove performance; retain actual load/soak results. No provider is contacted implicitly.

On staging, explicitly select only configured providers. Repeat `--provider=...` to request multiple checks. OIDC discovery/JWKS and HTTPS, OpenAI model availability, and individual reporting API calls are executed using the same client code as the CMS. Reports are read-only; Clarity probes can consume the provider's limited quota. Unconfigured selected providers fail; unselected ones are `not_run`. No provider data or exception text is included in the RC report.

```sh
CAJEER_RC_STAGING_CONFIRM=STAGING php bin/cajeer release rc-check --live --provider=oidc --provider=openai --provider=ga4
```

Supported report IDs: `ga4`, `metrica`, `matomo`, `clarity`, `gsc`, `yandex_webmaster`. GTM and actual browser tracking/consent delivery must be verified manually in the provider accounts. For the existing three-request paid AI acceptance (text, structured output, SSE with usage accounting), also pass `--paid` with `CAJEER_AI_PAID_PROBE_CONFIRM=I_ACCEPT_API_CHARGES` and a configured cost policy. This may incur real charges; do not use production customer data. Run with no concurrent AI traffic. A live provider probe does not prove real OIDC account sign-in, logout, consent collection or billing.

## Release decision

1.4.9 must be published as a prerelease and not made latest. Stable automatic updates ignore GitHub prereleases. The future stable release needs an explicitly supported transition from the last stable version (1.4.8), or a deliberately supervised upgrade through this candidate; a package built only from 1.4.9 is not installable on 1.4.8. Verify exact artifact hashes, full CI, actual staging TLS/FPM and browser flows, selected provider results and independent backup/rollback evidence. Record failures and their resolution; missing real provider credentials or unavailable browsers remain open. Only after manual review and the 1.5.0 gate can production be accepted. Never treat exit 2 as production approval.
