# Changelog

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
