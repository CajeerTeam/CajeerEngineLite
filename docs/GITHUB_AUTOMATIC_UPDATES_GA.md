# GitHub Automatic Updates GA — 1.6.0

This release implements Stable unattended updates. Local tests are not production approval. JSON schema 14 and the signed `.cjrupdate` format are unchanged. The root-owned supervisor is independent of application updates.

## Publish

Follow [RELEASE.md](RELEASE.md), [UPDATE_DISTRIBUTION.md](UPDATE_DISTRIBUTION.md) and [RELEASE_AUTOMATION_MONITORING.md](RELEASE_AUTOMATION_MONITORING.md). Configure protected version tags, signing/publishing environments with required reviewers, official RSA keys and repository permissions. Never commit private keys. Create `v1.6.0` with VERSION 1.6.0. The workflow selects the previous published Stable release, requires the exact 1.5.9 source tag even if Preview/unpublished, and includes 1.5.7/1.5.8 tags if available. All bases must be exact ancestors. Manual dispatch accepts additional reviewed `extra_base_tags`; the RC package is still mandatory. Never falsify tags. Customized source trees may need a reviewed manual upgrade.

The public release must not be draft or prerelease. It contains primary and additional `.cjrupdate` packages, `update-index.json`, full ZIP, `release-report.json`, `SHA256SUMS` and `SHA256SUMS.sig`. The workflow verifies the downloaded draft before publishing. Keep an independently verified distribution outside the application directory; no extra files in that directory. The manual changed-files ZIP is not an official signed distribution.

## Bootstrap

1. Follow [docs/INSTALL.md](docs/INSTALL.md). Use PHP 8.4.1+, local storage, dedicated non-root update account, PHP-FPM, systemd, bash, curl and Python 3. Serve only `public/`.
2. Stop timers, workers and FPM, resolve any active transaction and create independent backups of code, storage, secrets, keys and deployment configuration. Apply the verified release over 1.5.9. Never run a partial overlay while PHP is serving requests.
3. Follow [MAINTENANCE_ORCHESTRATION.md](MAINTENANCE_ORCHESTRATION.md), [AUTOMATIC_HEALTH_ROLLBACK.md](AUTOMATIC_HEALTH_ROLLBACK.md) and [UPDATE_SECURITY_HARDENING.md](UPDATE_SECURITY_HARDENING.md). Install the independently verified supervisor `deploy/cajeer-maintenance.sh` as `/usr/local/sbin/cajeer-maintenance`, root:root 0755. Configure service, timer, startup guards, private loopback HTTPS listener and public HTTP 503 gate. Do not replace the supervisor during active maintenance; older supervisors cannot read the new `installing` journal phase.
4. Install root-owned 0600 `/etc/cajeer/maintenance.env` with canonical site root, dedicated FPM unit, site user, background units, official repository, managed trust policy, health host/port and optional trusted CA. Keep application code and transaction storage read-only to HTTP workers. Disable legacy cron/scheduler services. Set `CAJEER_AUTO_UPDATE_ENABLED=0`, `CAJEER_AUTO_UPDATE_INSTALL=0`, `CAJEER_AUTO_UPDATE_ALLOW_PREVIEW_INSTALL=0` initially. Configure matching valid UTC windows in system environment and `/admin/updates`. Enable Stable checks and verify a candidate before opting in.
5. After staging approval set both system opt-in flags to `1` and enable automatic installation in `/admin/updates` (Stable). Leave Preview permission off. The system supervisor, not HTTP, controls services. Never acknowledge `WORKERS_QUIESCED=1` while workers are running.

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now cajeer-maintenance.timer
sudo systemctl start cajeer-maintenance.service
systemctl list-timers cajeer-maintenance.timer
journalctl -u cajeer-maintenance.service -n 100
```

## Actual deployment verification

Run the root preflight with the same environment as the service. It checks actual systemd units/guards, active FPM and enabled timer, installed supervisor copy, managed trust, loopback TLS and monitoring. It holds the supervisor lock and does not install or stop services. Retry if another supervisor is running.

```sh
sudo systemd-run --wait --pipe --collect \
  --property=EnvironmentFile=/etc/cajeer/maintenance.env \
  /usr/local/sbin/cajeer-maintenance preflight
```

Run as the update user with the same operator-provisioned trust/GitHub environment. The local gate verifies signed runtime and index including exact RC package; update configuration deficiencies are warnings until live mode. Live requires all configured providers via `--provider=NAME` and explicit consent (`--paid` for paid AI checks). See [docs/PRODUCTION_STABLE.md](docs/PRODUCTION_STABLE.md).

```sh
php bin/cajeer release ga-check --artifacts=/srv/releases/1.6.0 --json
CAJEER_PRODUCTION_LIVE_CONFIRM=LIVE php bin/cajeer release ga-check \
  --artifacts=/srv/releases/1.6.0 --live --json
```

Live GA performs a fresh, uncached exact-tag GitHub request, rejects drafts/Preview, extra/missing assets, wrong URLs and changed digests, streams every published asset and verifies bytes against the independently verified distribution. It checks managed trust, stable opt-in, valid UTC window, recent completed scheduler heartbeat, successful recent Stable check, no pending work/hold and no monitoring warnings/errors. A network failure fails the gate, not permission to install stale data. Downloads are removed and no install or job is triggered. The total download budget is 256 MB. A successful check with no newer release is legitimate but does not prove an upgrade rehearsal. Allow current scheduler work to finish before live acceptance.

## Recovery and production evidence

On isolated staging, exercise actual GitHub upgrade from previous Stable and 1.5.9 with real systemd/FPM/TLS; record source, target, package hash, HTTP 503 gate, PID change, health, queue, media and backup integrity. Test a staging-only signed bad package, revoked key, corrupted package, network failure, interrupted supervisor and reboot. Never publish a deliberately bad fixture as official. Rehearse off-host restore, full CI/browser matrix, external integrations, production observation and sustained load.

The `installing` phase is persisted before the worker starts. An interrupted committed candidate is rolled back even if healthy; an unchanged baseline is restored without rolling back an unrelated receipt. If rollback or baseline health fails, retain the public gate. Do not delete active journals. Review and resolve the failure before clearing the rollback hold.

Use the approval format in [docs/PRODUCTION_STABLE.md](docs/PRODUCTION_STABLE.md). Add `github_update_e2e.txt`, `systemd_reboot_recovery.txt`, `supervisor_preflight.txt` containing actual reviewed evidence; include all eleven evidence hashes in `approval.json`. Also bind `distribution_index_sha256` and `publication_sha256` to the live GA report (`updates_ga.distribution_index_sha256`, `updates_ga.publication.publication_sha256`). Sign-off re-runs live acceptance, rejects changed control revision/trust/site/runtime and writes a private record. Evidence and reviewer are operator-attested, not independently authenticated. CMS-only `production-check`/`sign-off` do not attest updates GA.

```sh
CAJEER_PRODUCTION_LIVE_CONFIRM=LIVE \
CAJEER_PRODUCTION_SIGNOFF_CONFIRM=I_REVIEWED_PRODUCTION_EVIDENCE \
php bin/cajeer release ga-sign-off --artifacts=/srv/releases/1.6.0 \
  --live --evidence=/secure/ga-review --json
```

Exit 0: operator attestation recorded in `storage/app/release-signoff/`; 1: failed gate; 2: automated checks passed but live/approval pending. Continue monitoring (`update monitor check`, `monitor check --verify-backup`). Opt out if safety checks fail.

## Disposable tests

```sh
python3 tests/release_ga_160.py
bash tests/maintenance_orchestration_155.sh
bash tests/maintenance_units_155.sh
php tests/core_157.php
php tests/core_158.php
```

Use `release_ga_160.py --base /clean/stable --rc /clean/1.5.9` for exact source rehearsals. Tests generate disposable keys, perform actual install and rollback, and exercise the actual streaming transport with controlled release metadata and asset bytes. Existing CI tests exercise queued updates with real local TLS/Nginx and simulated GitHub/systemd. No test publishes official releases or issues production sign-off.
