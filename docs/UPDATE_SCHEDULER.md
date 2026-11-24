# Update Scheduler (1.5.4)

For unattended service orchestration in 1.5.5, follow [MAINTENANCE_ORCHESTRATION.md](MAINTENANCE_ORCHESTRATION.md). The existing timer remains discovery-only; do not enable both timers.

The scheduler drives the existing private Update Control Center mailbox using one bounded tick per process. No HTTP request starts a process, no daemon is embedded in PHP, and no scheduler code stops or restarts services. Schema 14 is unchanged.

## CLI and policy

```sh
php bin/cajeer update scheduler status
CAJEER_UPDATE_CONTROL_WORKER=1 php bin/cajeer update scheduler tick
```

Configure `CAJEER_AUTO_UPDATE_GITHUB_REPO` and `CAJEER_UPDATE_TRUSTED_KEY_FILE` exactly as in [UPDATE_CONTROL_CENTER.md](UPDATE_CONTROL_CENTER.md). Use the official repository and an independently trusted public key. The scheduler requires explicit worker opt-in; the CLI status command does not. In `/admin/updates` enable periodic checking (1–168 hours) if desired. Manual queue requests take priority. A checked package can be installed on a later tick only with the CMS opt-in (or an explicit queued install), the operator's install permissions, both UTC maintenance windows, a fresh exact-digest verification and actual worker quiescence. Each tick executes at most one job. After a scheduled successful installation the next tick discovers the next step using newly loaded code.

Check jobs that fail during execution retry at most twice, no earlier than 5 and 15 minutes after the respective failures; the same job ID is preserved. GitHub's own persistent rate-limit cooldown still applies. A failed check invalidates the previous verification. Delayed jobs can be cancelled in the UI, and jobs expire after 24 hours. Failed or interrupted installation jobs are **not** automatically replayed; the scheduler turns off CMS auto-install and requires renewed operator review. An interrupted check is recorded as interrupted rather than replayed blindly. If an installer journal remains, the tick returns `recovery_required` (exit 2); follow the offline `update recover` procedure before resuming. A concurrent scheduler tick returns `busy` without changing heartbeat or queue. The legacy `update control work` command remains available, but should not be scheduled in parallel; legacy `update auto run` must not be scheduled alongside the console scheduler.

## systemd (Linux)

The supplied units are examples for a dedicated single-site account `cajeer`, project `/srv/cajeer` and PHP CLI `/usr/bin/php`. Replace those values for your deployment. Use the same Unix identity as the site's private mailbox, with a read-only code mount or sandbox for HTTP. Do not make private files group/world writable. The timer template is shipped as `.timer.conf` to remain installable by the 1.5.3 signed update allowlist; copy it to the actual `.timer` filename. Unit installation is an explicit administrator action outside the CMS. Create `/etc/cajeer/update-scheduler.env` owned by root, readable only by root, with:

```ini
CAJEER_AUTO_UPDATE_GITHUB_REPO=CajeerTeam/CajeerEngineLite
CAJEER_UPDATE_TRUSTED_KEY_FILE=/etc/cajeer/update-trusted.pem
```

The trusted public key must be readable by the service identity and not writable by it. Keep signing keys off the server. The service removes install and quiescence variables and mounts code read-only: unattended discovery is enabled, not unattended PHP-FPM shutdown/installation. Do not relax this boundary just to make installation work. With workers genuinely stopped and verified backups available, invoke `update scheduler tick` manually as the deployment identity using the separate installation permissions in the control center runbook. Automated maintenance lifecycle is available via the separate 1.5.5 supervisor; do not enable both timers.

```sh
# From the deployed project, as an administrator after reviewing paths/identity:
sudo install -m 0644 deploy/cajeer-update-scheduler.service /etc/systemd/system/cajeer-update-scheduler.service
sudo install -m 0644 deploy/cajeer-update-scheduler.timer.conf /etc/systemd/system/cajeer-update-scheduler.timer
sudo systemctl daemon-reload
sudo systemctl enable --now cajeer-update-scheduler.timer
sudo systemctl start cajeer-update-scheduler.service
systemctl list-timers cajeer-update-scheduler.timer
journalctl -u cajeer-update-scheduler.service -n 50
```

The timer wakes once per minute with a small jitter and persistent missed-calendar activation. The configured CMS interval controls actual GitHub checks. The service has a 10-minute timeout; interrupted jobs and installer journals are handled on the next tick. For cron-only hosts use `deploy/cajeer-update-scheduler.cron.conf` after editing its identity, paths and repository; install as `/etc/cron.d/cajeer-update-scheduler` (root-owned 0644, trailing newline). The example bounds the CLI execution to 10 minutes using GNU timeout. Configure cron mail/log capture. Do not enable both mechanisms.

## Monitoring and recovery

`update scheduler status` returns private heartbeat, age, stale flag (over 15 minutes or missing), queue, policy and nominal next periodic check. The console displays heartbeat and retry time. A recent heartbeat proves a tick ran, not that GitHub or installation succeeded. Inspect `action` and `pending`. `tick` exits 0 for normal idle/deferred/retry/checked/installed/busy outcomes, 2 if recovery is required, 1 on unexpected or final job failure. `scheduler.json` is atomic and private; corrupt or unsafe state fails closed. `scheduler.lock` and existing worker/update locks prevent overlap. No credentials or exception messages are persisted in scheduler state. The scheduler cannot repair a damaged journal or restart FPM; stop the timer before manual recovery and restart it only after the site is verified.

## Verification

```sh
php tests/core_154.php
bash tests/update_scheduler_cli_154.sh
php tests/core_153.php
bash tests/update_control_http_153.sh
```

Tests use disposable files and keys, controlled release responses, actual signed installation, retries and persisted locks. On a real server validate the unit with `systemd-analyze verify`, check the timer and journal, and verify the configured official GitHub release. Production unattended installation requires the separately configured maintenance supervisor.
