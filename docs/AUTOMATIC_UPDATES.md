# CajeerEngine Lite 1.4.5 — Automatic Updates

## Discovery behavior since 1.5.1

See [GITHUB_RELEASE_DISCOVERY.md](GITHUB_RELEASE_DISCOVERY.md) for pagination, conditional caching, retry/cooldown behavior, stable/preview channels and exact discovery limits. The installation safeguards below are unchanged. Preview installs additionally require `CAJEER_AUTO_UPDATE_ALLOW_PREVIEW_INSTALL=1`.

## What is actually automated

`php bin/cajeer update auto check` reads **public GitHub Releases** over verified HTTPS and picks the **lowest eligible numeric SemVer release higher** than the installed version that contains an asset named exactly `CajeerEngineLite-X.Y.Z.cjrupdate`. Drafts, prereleases in the default stable channel, missing/ambiguous assets, invalid tag names, invalid sizes, and untrusted URLs are skipped or rejected. This check reads release metadata only: it does **not** imply that the release file has been authenticated.

`php bin/cajeer update auto run` performs the same selection, downloads the asset to a private 0600 file with size caps, optionally checks GitHub's `sha256:` digest, then **always** verifies the custom package's RSA-SHA256 signature with the independently pinned `CAJEER_UPDATE_TRUSTED_KEY_FILE`, the signed manifest, all file hashes, the exact installed base version, PHP and site schema, and PHP syntax before any file installation. It invokes the real 1.4.4 transactional installer, including backups, per-file atomic replacement, journaling, rollback and crash recovery. Only code-only packages compatible with JSON schema 14 are supported.

The update process is triggered from a scheduled CLI invocation on the host. It does not add a web installer, modify application content or database files, or run scripts from downloaded releases. It does not require PHP cURL; it requires TLS-capable PHP streams, `openssl`, network access to GitHub and a trusted system CA store. Redirects are HTTPS-only and restricted to GitHub's release delivery hosts; no HTTP downgrade or arbitrary redirect is allowed. The default request timeout is 20 seconds per HTTP connection, the JSON response is limited to 512 KB, and package size is limited to 96 MB. Only public GitHub repositories are supported.

## Explicit production opt-in

The following environment values are read by the **CLI process** (not browser requests):

```sh
CAJEER_AUTO_UPDATE_GITHUB_REPO=CajeerTeam/CajeerEngineLite
CAJEER_UPDATE_TRUSTED_KEY_FILE=/etc/cajeer/update-trusted.pem
CAJEER_AUTO_UPDATE_ENABLED=1
CAJEER_AUTO_UPDATE_INSTALL=1
CAJEER_AUTO_UPDATE_WINDOW_UTC=02:00-03:00
CAJEER_AUTO_UPDATE_WORKERS_QUIESCED=1
# Optional, only after reviewed/rehearsed recovery:
CAJEER_AUTO_UPDATE_RECOVER=1
```

- **Enabled** and **install** must both be `1`. Checking does not modify installed code or site data; it writes private discovery/cache status and does not require either flag.
- The time window is explicitly specified in **UTC** (`HH:MM-HH:MM`), end-exclusive; overnight windows such as `23:30-00:30` work. Invalid or zero-length windows fail closed. No download or install occurs outside the window.
- **WORKERS_QUIESCED is an operator attestation, not a command that stops workers.** Configure the scheduler/service manager to drain HTTP traffic, stop PHP-FPM workers/cron/queues that may execute the CMS, verify quiescence, then run `update auto run`, restart/invalidate PHP OPcache and check `/health/ready`. Do **not** set it globally while serving live traffic. An in-process shared file lock and the active-update 503 guard cannot guarantee quiescence of pre-existing workers or statically served files.
- The trusted public key must be provisioned out of band and pinned to the real release owner's private signing key. Do not place the private signing key in the deployed CMS, GitHub repository, or the released package. Do not enable automatic install using unsigned, third-party, or unknown-key releases.
- GitHub release asset names must be exactly `CajeerEngineLite-X.Y.Z.cjrupdate`, with `X.Y.Z` matching the version tag `vX.Y.Z` (or `X.Y.Z`). Each release's *signed manifest* must target the exact currently installed version; incremental releases can require multiple maintenance cycles. GitHub metadata alone is never an authorization to install.

**Example** for a supervised cron/systemd maintenance wrapper:

1. Drain ingress and stop all workers and other CMS writers; verify no active processes remain.
2. Load the private environment configuration (including the trusted public key path and explicit opt-ins); set `CAJEER_AUTO_UPDATE_WORKERS_QUIESCED=1` only in this wrapper.
3. Invoke `php /srv/cajeer/bin/cajeer update auto run` during the UTC window. It returns JSON and a nonzero exit on failure. Preserve stderr in your secured operator logs; do not store secrets in logs.
4. If installed, restart PHP-FPM, clear OPcache and validate `php bin/cajeer doctor`, `/health/ready`, local login and configured OIDC integrations. Roll back or recover before reopening ingress if necessary.
5. Reopen ingress and monitor. Repeat from a host scheduler (for example nightly). The CMS does not install or register a privileged cron job automatically.

## CLI and recovery

```sh
php bin/cajeer update auto status      # installed version, configuration flags, last run, pending journal
php bin/cajeer update auto check       # read public GitHub release metadata (no installation)
php bin/cajeer update auto run         # opt-in check/download/verify/install/recover
php bin/cajeer update status           # transactional updater status
php bin/cajeer update recover          # manual interrupted-update recovery
```

`auto run` uses a separate private `flock` for cron executions, while the underlying installer uses the shared operations lock. If another update/backup is running, the run fails without publishing release files. The current release download is removed from private staging after each run, including verification/install failures; the installed update's private recovery snapshot is retained by the underlying engine. Summary status (without tokens or response bodies) lives at `storage/app/updates/auto/state.json`. Status `failed` is not a completion claim.

If a previous install left `storage/app/updates/active.json`, auto-run refuses to install another release. With `CAJEER_AUTO_UPDATE_RECOVER=1` **and all the normal opt-ins and maintenance safeguards**, it performs `UpdateEngine::recover()` and exits without installing anything else. If recovery fails, the journal is retained and the site stays in maintenance mode until the administrator repairs the issue and verifies the rollback.

Rollback of a successfully installed update remains an explicit operator action (while workers are stopped): `CAJEER_UPDATE_CONFIRM=ROLLBACK php bin/cajeer update rollback <update-id>`. Automatic post-update health acceptance, PHP-FPM orchestration, DNS/network availability, and key management are the operator's responsibility. A valid code signature does not replace staging and full production acceptance.

## Verification and tests

`php tests/core_145.php` uses locally generated RSA keys and a real `.cjrupdate` package to validate discovery, failure-closed opt-ins, UTC windows, cross-process locks, corruption rejection, **actual file replacement** and crash recovery. `bash tests/automatic_updates_cli_145.sh` tests the real CLI parser and default-deny behavior. These tests use a deterministic release transport fixture; **live GitHub connectivity, signed official release availability, PHP-FPM orchestration and real production TLS are not validated by the fixture**.

To deploy the 1.4.5 ZIP patch from 1.4.4, make an independent off-host backup of code, templates, `storage/` and secrets, stop PHP-FPM and background jobs, overlay the changed files, run the tests/doctor/security audit, then restart workers. Do not enable auto install until the real signed-release pipeline, trusted public key, traffic drain, and external staging acceptance exist.
