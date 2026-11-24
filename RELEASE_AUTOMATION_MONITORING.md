# Release Automation & Monitoring (1.5.8)

## Official publication

Only reviewed commits and protected numeric tags (`v1.5.8`, etc.) may enter the release workflow. Restrict tag creation and update to release maintainers, and configure `release-signing` and `release-publish` environments with required reviewers, no self-approval, protected deployment tag rules, the official `CAJEER_RELEASE_PRIVATE_KEY` secret (signing only), and `CAJEER_RELEASE_PUBLIC_KEY` variable. Do not approve jobs from unreviewed tags. Never put private keys in the repository or CMS. The signing job executes the reviewed source and base updater, so review both before approval.

Creating a protected `vX.Y.Z` tag now triggers `Signed Lite release`. The read-only validation job queries all pages of published GitHub releases, excludes drafts and prereleases, selects the highest earlier stable tag, verifies exact versions and ancestry, then runs CI and the existing signed upgrade/rollback rehearsal. No published stable base, a duplicate version, an existing release at or above the target, or an incompatible base stops the automatic release. For initial publication, an explicit base or additional supported bases, use manual dispatch on the target tag with `base_tag` and optional `extra_base_tags`. The primary base must satisfy the builder's adjacency rules; do not bypass these checks. 1.5.9 is a prerelease, so 1.6.0 can use stable 1.5.8 as the primary base, with the RC as an optional base.

The protected signing and publication jobs remain separate. The draft contains the signed `.cjrupdate` packages, full ZIP, signed distribution index, signed primary checksums and release report. After upload, the publication job downloads every asset into a new private temporary directory, verifies package signatures, exact inventory, hashes, versions and the byte-for-byte match to the approved artifacts; it rechecks the target and base tags and draft state before publishing. A failed download or verification leaves the draft unpublished. Inspect a failed draft manually; the workflow never overwrites a release. Restrict other writers and edits during publication; enable immutable releases where supported. Production sign-off remains separate from release publication.

The workflow and tests are repository tooling; the privileged maintenance supervisor is not replaced by this release. Use the installed 1.5.7 supervisor, the managed trust policy, and the same update worker configuration. Full installation/release docs: `RELEASE.md`, `UPDATE_SECURITY_HARDENING.md`, `UPDATE_SCHEDULER.md`, `AUTOMATIC_HEALTH_ROLLBACK.md`.

## Operator monitoring

`/admin/updates` displays live derived alerts from the validated mailbox, including available verified updates, scheduler heartbeat missing/stale, failed checks/installs, expired jobs, overdue periodic checks, recovery required and the presence of the host-wide maintenance supervisor hold. Errors remain visible until their relevant job type succeeds or the operator resolves the problem; notifications are in-app, not email or push. No provider URLs, secrets or raw error messages are included. A scheduler disabled in CMS with no pending jobs does not generate a missing-heartbeat alert. The supervisor hold detection uses the standard single-site `/var/lib/cajeer-maintenance` deployment. Its presence is reported, not its content. Monitor systemd separately for the definitive root-side status.

```sh
php bin/cajeer update monitor status
php bin/cajeer update monitor check
php bin/cajeer monitor check --json
```

`update monitor check` returns exit 1 on error alerts or invalid state; informational and warning alerts do not fail the command. `monitor check` includes the same update alert codes. Invoke with the site account from existing monitoring (for example a once-per-minute systemd or cron check) and route nonzero results to your alerting service. No outbound notification service or credentials are required by CMS. During maintenance a temporary failure is expected; configure alert suppression in the external monitoring system. Inspect the scheduler and supervisor with `journalctl -u cajeer-update-scheduler.service -u cajeer-maintenance.service`. Do not clear a rollback hold until its cause is reviewed.

## Snapshot retention

The signed updater's `storage/app/updates/history` contains code rollback snapshots and private site JSON copies; it is **not** a replacement for a full independent site/media/server backup. Snapshot status reads and validates inventory, the recovery plan and checksums of original files. It does not delete anything. The default policy keeps at least the three newest valid snapshots, all snapshots younger than seven days, and the snapshot referenced by `last.json` regardless of age. Only the latest installed transition can be rolled back through the engine. Old snapshots may be needed for forensic review; retain independent backups.

```sh
php bin/cajeer update snapshots status --keep=3 --days=7
CAJEER_UPDATE_RETENTION_CONFIRM=PRUNE php bin/cajeer update snapshots prune --keep=3 --days=7
```

The second command is destructive and explicitly opt-in. Run as the update worker account, **never as the web request or root**, preferably during a quiet window. The exclusive application lease prevents concurrent CMS operations, install, restore and rollback; an active transaction, supervisor maintenance/hold, corrupted or incomplete snapshot, or interrupted cleanup refuses pruning. A snapshot is atomically retired before deleting its private files; interrupted `.pruned-*` directories are reported and require offline operator inspection/cleanup before pruning resumes. No arbitrary paths or symlinks are accepted. `--keep` is 1–100; `--days` is 1–3650. `status` returns exit 1 for damaged snapshots. `prune` records structured audit events. A scheduled retention task is optional: run the same explicit confirmed command as the site worker from a daily timer/cron after independently backing up, inspect exit codes and do not run a second updater. No automatic pruning on web requests or by default.

## Acceptance

```sh
php tests/core_158.php
bash tests/update_control_http_153.sh
python3 tests/distribution_release_test_152.py
php bin/cajeer doctor
```

The tests use disposable signing keys and local source trees. GitHub API responses and uploaded/downloaded draft bytes are simulated locally; configure and execute the real protected GitHub workflow to confirm publication. Actual systemd/PHP-FPM deployment, external alerts and production acceptance require the operator. Schema 14 is unchanged.
