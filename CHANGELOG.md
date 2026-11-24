# Changelog

## 1.5.6 — Automatic Health & Rollback

- Mandatory baseline and post-install TLS readiness/version validation via loopback-only vhost; public HTTP maintenance gate remains active until the original service set is healthy.
- Offline verification of all files covered by the latest installed update plan, under exclusive operations lease.
- Exact-transaction automatic rollback, durable rollback intent, prior-release re-probe and persistent operator hold; failed recovery retains the gate.
- Separate privileged bootstrap upgrade, native web-server checks and real TLS/signed rollback integration tests. Schema 14 unchanged.

## 1.5.5 — Maintenance Orchestration

- Root-owned external systemd supervisor, persistent HTTP 503 gate, startup guard and service state journal. Actual FPM and background service stop/start around the existing non-root signed installer.
- Fresh-process offline compatibility verification and interrupted maintenance recovery, preserving prior service states. Discovery does not require downtime. New deployment prerequisites are opt-in; schema 14 unchanged.

## 1.5.4 — Update Scheduler

- Durable scheduler tick with non-overlapping execution, heartbeat, delayed check retries, expiry and failed-install auto-install pause.
- systemd timer/service and cron deployment examples; installation remains gated by operator quiescence. Schema 14 unchanged.

## 1.5.3 — Update Control Center

- Authenticated update console with CSRF, revision checks, private queue, channel, maintenance policy and history.
- Worker verifies downloaded packages and pins the exact digest through staging; release notes are untrusted escaped text.
- Separate operator opt-in, maintenance windows and worker quiescence still required. No automatic service management. Schema 14 unchanged.

## 1.5.2 — Update Package Distribution

- Signed distribution index, multiple exact-base packages, verified update route and one-step installation.
- Release workflow rehearses each base independently; primary artifact remains backwards compatible.
- Schema 14 unchanged.

## 1.5.1 — GitHub Release Discovery

- Bounded GitHub pagination, per-page conditional requests with private ETag cache, nearest/latest selection and strict official asset URLs.
- Transient HTTP/network retries, persistent rate-limit cooldown, no stale or partial installation selection, stable and opt-in preview channels.
- Regression coverage of HTTP decoding, discovery, signed installation and version-independent release acceptance; no data schema change.

## 1.5.0 — Production Stable

- Signed runtime inventory and verification of the complete artifact set, installed executable/template bytes, compatibility, live TLS/version and fresh monitoring.
- Explicit operator sign-off with a fresh live gate, evidence hashes, private atomic audit record and no automatic approval.
- Official stable update from 1.4.8; exact 1.4.9 upgrade remains supported by separate signed rehearsal. Schema 14 unchanged.
- Disposable HTTPS acceptance and tamper regression; real deployment acceptance remains operator-specific.

## 1.4.9 — Production Release Candidate

- Freeze features and schema 14. Reject unsupported direct OIDC login before checking optional provider configuration, preventing false live readiness failure.
- Operator-only RC acceptance gate with backup freshness, verified TLS and opt-in selected live provider probes; no automatic production sign-off.
- Signed exact upgrade/rollback rehearsal on populated data with media hashes and verified backups; TLS/Nginx staging and real browser form regression.
- Publish RC as prerelease, never in the stable auto-update channel.

## 1.4.8 — Documentation & Release Engineering

- Executable signed release build, independent source/manifest comparison, reproducible full ZIP, signed SHA-256 list, actual clean install, upgrade and rollback rehearsal, tampering rejection.
- Protected GitHub release workflow (full CI, disposable-key rehearsal, protected signing, verified draft publication); no embedded signing keys or live provider secrets.
- Consolidated installation, upgrade, backup/recovery, security, operations, compatibility and release runbooks. Schema 14 unchanged; signed upgrades from exact 1.4.7 sources are supported for managed paths. Manual live sign-off remains required.

## 1.4 series

- **1.4.7:** bounded private request telemetry, resource/backup monitoring, load tests.
- **1.4.6:** environment compatibility, legacy schema migration preview/apply, trusted reverse proxy, browser/web-server test matrix.
- **1.4.5:** opt-in GitHub signed auto-updates, UTC windows and recovery.
- **1.4.4:** signed code-only CLI update engine with transaction snapshots and rollback; first transition from 1.4.3 is manual.
- **1.4.3:** session, CSRF, OIDC, CSP, file/template/TAR hardening and offline dependency audit.
- **1.4.2:** storage recovery journal, media consistency and verified atomic backups.
- **1.4.1:** core transaction stability, concurrency and rollback fixes.
- **1.4.0:** CMS Experience Stable automated acceptance and readiness/liveness.

## 1.3 series

CMS experience: page management, blocks, media, SEO control, developer tools, operations, and integration hardening. See the version-specific sections of [README.md](README.md) for the complete retained history including 1.0, 1.1 and 1.2 releases, feature details and historical upgrade notes. Historical commands may refer to old versions; use current runbooks for new deployments.
