# Automatic Updates Release Candidate — 1.5.9

1.5.9 is a **Preview** release; it is not a production sign-off. The PHP runtime and schema 14 remain compatible. Stable discovery ignores GitHub prereleases. The workflow already publishes 1.5.9 with `--prerelease --latest=false`; check the release flags after publication. Keep unattended production installation disabled until the actual deployment is accepted.

## Upgrade and supervisor bootstrap

Back up the full code tree, private `storage/`, secrets and independent root-owned deployment files. Stop the update timer and managed workers before a manual patch. A signed update requires an exact base; distribution supports a non-adjacent primary base when intervening tags were unpublished or Preview (full exact upgrade/rollback rehearsal is mandatory). Publish a package from the actual preceding stable tag, and extra exact-base packages as needed (including 1.5.7 if not upgraded to 1.5.8). To upgrade a Preview installation to 1.6.0, include its exact base in the final stable distribution. Do not assume that a primary package for the previous Stable release applies to a Preview installation.

The root-owned supervisor is deliberately excluded from automatic replacement outside the application tree. After verifying the official release, manually deploy `deploy/cajeer-maintenance.sh` to `/usr/local/sbin/cajeer-maintenance` as root:root 0755, using the independent verified source, not an HTTP-writable directory. A 1.5.9 supervisor writes a `failed` phase in the v2 maintenance journal. Finish/recover any existing active maintenance before changing the supervisor; do not downgrade a supervisor with an active journal. Recheck the dedicated service, guard and loopback HTTPS vhost according to [MAINTENANCE_ORCHESTRATION.md](MAINTENANCE_ORCHESTRATION.md), [AUTOMATIC_HEALTH_ROLLBACK.md](AUTOMATIC_HEALTH_ROLLBACK.md) and [UPDATE_SECURITY_HARDENING.md](UPDATE_SECURITY_HARDENING.md).

If a scheduler fails after creating an installation receipt, the supervisor records the failure in the persistent gate and rolls back the exact candidate before reopening HTTP. If there is no new candidate it may reopen the healthy baseline and return the worker failure. A failed recovery retains the maintenance gate. The rollback hold remains until an operator reviews the failure. During engine recovery, clearing a receipt precedes clearing `active.json`; a damaged receipt blocks completion, even if files have already been restored. Do not manually delete the journal to make readiness pass. Investigate and restore valid private transaction metadata from the independent backup; retry recovery.

## Reproducible automated checks (disposable test environment)

PHP 8.4.1+, Python 3, OpenSSL, Nginx, curl and bash are required for these tests. The managed-policy fixture requires root ownership; use a disposable CI runner, not the production server. The test never calls real systemctl or changes the host's service configuration. It starts a temporary PHP server and Nginx with an ephemeral CA certificate. The release feed and asset response bytes are controlled by the test, but the real downloader, signature verifier, scheduler, mailbox, installer, rollback engine and TLS probes are exercised. The fixture `tests/rc_transport_159.php` is test-only; never expose `tests/` through the web server.

```sh
php tests/core_151.php
php tests/core_152.php
php tests/core_153.php
php tests/core_154.php
sudo -n "$(command -v php)" tests/core_157.php
php tests/core_158.php
bash tests/maintenance_cli_155.sh
bash tests/maintenance_orchestration_155.sh
sudo -n env PATH="$PATH" "$(command -v python3)" tests/automatic_health_156.py --queue --healthy
sudo -n env PATH="$PATH" "$(command -v python3)" tests/automatic_health_156.py --queue
```

CI also runs historical core/HTTP/browser tests and signed release rehearsals. The exact-base release build must verify clean installation, update and rollback, unchanged site JSON/media/backup, and all package signatures. For release publication, follow [RELEASE.md](RELEASE.md) and [RELEASE_AUTOMATION_MONITORING.md](RELEASE_AUTOMATION_MONITORING.md). Never use a disposable test signing key in an official release.

## Actual GitHub and staging acceptance (operator)

1. Provision an isolated staging host with a dedicated PHP-FPM service, systemd supervisor, guarded background units, Nginx or Apache TLS gate and loopback readiness listener. Use an independent non-production site dataset and a separate backup. Configure the official or dedicated staging GitHub repository, root-owned trust policy and a verified baseline. Install the reviewed root-owned supervisor separately. Confirm that the public document root is `public/`.
2. Publish the protected tag and signed Preview distribution. Verify the assets and `prerelease` flag in GitHub. On the staging host, with the timer disabled, run the following as the update user with the same operator-provisioned environment as the supervisor. `verify` downloads and verifies a compatible package but does not install it or enqueue work; a null `verified` means no available compatible package, not a passed live update test.

```sh
php bin/cajeer update trust status
CAJEER_AUTO_UPDATE_CHANNEL=stable php bin/cajeer update auto verify
CAJEER_AUTO_UPDATE_CHANNEL=preview php bin/cajeer update auto verify
php bin/cajeer update control status
```

Record the candidate SHA-256, source and target versions. The Stable channel must not select 1.5.9; the Preview channel must show the exact signed package. A network failure is a failed check, not permission to use stale results. The CLI requires no install permission for verification. It uses the configured trust policy and HTTPS only; it does not bypass package validation.

3. In `/admin/updates`, select Preview, request verification, explicitly enable installation or queue the exact target. Configure system permissions (`CAJEER_AUTO_UPDATE_ENABLED=1`, `CAJEER_AUTO_UPDATE_INSTALL=1`, `CAJEER_AUTO_UPDATE_ALLOW_PREVIEW_INSTALL=1`, a valid UTC window). On staging only, start the system service or enable the timer and inspect `journalctl -u cajeer-maintenance.service`. Check that the public gate returns 503 during the code update, PHP-FPM PID changes, the HTTPS readiness header has the expected target version and the public homepage and administrative login work after reopening. Verify media and backups and record `php bin/cajeer update monitor status` and `php bin/cajeer update status`. No secrets in evidence.
4. In a separate disposable staging installation, use a separately signed staging-only package with an intentionally failing readiness response; do not publish this fixture as an official release. Verify automatic rollback, old version TLS readiness, durable hold, correct journal behavior and data integrity. Exercise a terminated supervisor during maintenance and a staging reboot; confirm startup guard and resumed recovery. Repeat with unavailable GitHub and a corrupted or revoked package; neither may change installed code. Run the load script on isolated fixtures and sustained monitoring on actual staging; choose latency/error thresholds for the target host.
5. Only close the RC gate when the actual service logs, GitHub verification, full CI/browser matrix, network and recovery rehearsal, sustained monitoring, and operator review have passed. Keep the timer disabled or installation opt-out if any gate fails. Record the actual source/tag, target, package hash, server versions, test results and reviewer. The 1.5.0 production sign-off mechanism is separate; neither a local test nor the RC tag is evidence of production acceptance.
