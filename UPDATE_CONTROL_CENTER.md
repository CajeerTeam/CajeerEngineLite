# Update Control Center (1.5.3)

The authenticated `/admin/updates` console manages GitHub releases. It is a private mailbox, not a privileged web installer. HTTP does not contact GitHub, download packages, launch subprocesses, set process environment or install code. The worker executes one job per invocation; system scheduling and FPM/service orchestration are separate deployment steps.

## Operator setup

The GitHub repository and trusted RSA public key remain deployment-owned, outside the HTTP application's writable paths. Configure the following in the CLI worker environment (replace repository/key with the actual official values):

```sh
export CAJEER_UPDATE_CONTROL_WORKER=1
export CAJEER_AUTO_UPDATE_GITHUB_REPO=CajeerTeam/CajeerEngineLite
export CAJEER_UPDATE_TRUSTED_KEY_FILE=/etc/cajeer/update-trusted.pem
php bin/cajeer update control status
php bin/cajeer update control work
```

A check job downloads and verifies the actual exact-base package against the trusted key and installed files, including schema and PHP compatibility. Release notes are bounded, untrusted plain text. Only the first transition is verified; the remaining route is discovery metadata, not verified packages. There is no installation on a check. Requests expire after 24 hours; a changed base, channel, target, digest or failed check requires a fresh verification. The worker re-fetches and re-verifies before installing, and the installer checks the digest of the bytes it actually stages.

To allow an install, take independent verified backups of code and storage, stop PHP-FPM and other writers, and supply the additional operator-controlled permissions for that invocation only:

```sh
export CAJEER_AUTO_UPDATE_ENABLED=1
export CAJEER_AUTO_UPDATE_INSTALL=1
export CAJEER_AUTO_UPDATE_WINDOW_UTC=02:00-04:00
export CAJEER_AUTO_UPDATE_WORKERS_QUIESCED=1
php bin/cajeer update control work
```

Do not set `WORKERS_QUIESCED=1` while PHP-FPM/jobs are active. The operator window and the CMS window must both be open. Preview installation additionally requires `CAJEER_AUTO_UPDATE_ALLOW_PREVIEW_INSTALL=1`. The manual UI installation request requires typing the verified target version; it does not bypass the operator's settings. After installation restart services, check `/health/ready` and public/admin routes. For failed checks inspect CLI output. Do not run this worker as root or give the web process sudo, signing keys or writable application code. The existing offline installer requires writable managed code in its execution context. Private files use 0600 and directories 0700: use a service identity with access to the mailbox and updater storage, with the HTTP service restricted to a read-only code mount / OS sandbox; do not loosen state permissions to make a different Unix user work. Dedicated cross-UID worker deployment and automated service lifecycle are future roadmap stages.

## Policy and history

Stable is the default. Preview discovery is explicitly selected. Periodic checks and auto-install are both off by default. The configured interval (1–168 hours) is evaluated on each worker invocation. Auto-install requires periodic checks and both the CMS opt-in and operator permissions. A successful check can queue one exact transition on a subsequent invocation. Without periodic invocations there is no scheduler. Manual queue jobs have priority. Pending jobs can be cancelled, running jobs cannot. Only one job can exist at a time. A short state lock and revision check prevent lost edits; a separate exclusive worker lease prevents simultaneous processing. No network activity occurs under the state lock. A worker interrupted after claiming a job is marked interrupted on the next invocation and not automatically replayed. If an installer journal is pending, recover it using the existing operator recovery command before new work:

```sh
php bin/cajeer update status
php bin/cajeer update recover
# For a completed install, use its rollback ID and follow the offline rollback runbook.
```

Consult [docs/UPDATE_ENGINE.md](docs/UPDATE_ENGINE.md) for confirmation and rollback requirements. The console records the last 40 job outcomes in `storage/app/update-control/state.json`; no API credentials, URLs, or raw error strings are stored there. Backups of site content do not include this operational state. Direct CLI `update auto run` is a separate legacy interface with its own environment policy; do not schedule both workers against the same installation. This console does not automatically stop services or roll back a failed live health check yet. The existing installer still rolls back failed file operations and has crash recovery. No new mandatory runtime extensions; JSON schema remains 14.

## Verification

```sh
php tests/core_153.php
bash tests/update_control_http_153.sh
php tests/core_151.php
php tests/core_152.php
php bin/cajeer update control status
```

Tests use temporary RSA keys and controlled GitHub transport fixtures, real file installation and real local authenticated HTTP. They do not authorize production deployment or verify the official GitHub repository's release availability. Never install an unsigned GitHub source ZIP through the updater.
