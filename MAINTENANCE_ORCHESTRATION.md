# Maintenance Orchestration (1.5.5)

The optional Linux/systemd supervisor replaces the discovery-only scheduler service when automated offline installation is wanted. It is a **real service controller**: it asks the current unprivileged CLI whether installation is eligible, publishes a durable HTTP gate, stops configured timer/services, confirms their stopped state, runs the existing signed update worker, recovers any outstanding engine journal, checks version/schema in a fresh PHP process, starts previously active services and releases the gate. A complete FPM stop/start discards that instance's in-memory OPcache and old loaded code. Disable persistent OPcache file-cache for this deployment (or manage it independently during offline maintenance). No install permission is derived from the web request. Checks run without maintenance downtime.

## Required deployment boundary

- Single-site Linux installation, local disk with fsync/rename/flock support, systemd with `ExecCondition`, Bash, GNU coreutils/util-linux, PHP CLI and a **dedicated** FPM service. No shared multi-site FPM service or unlisted FPM socket activation, cron jobs, daemon processes or externally started application jobs. All background service/timer pairs must be listed. Use long-running background services or timers whose oneshot service is inactive between jobs; transitional/failed units defer a new maintenance cycle.
- The root supervisor and its unit, guard drop-ins, timer and environment are administrator-installed, **outside the writable CMS tree**, root-owned and not writable by the site user. Review them from a trusted release before installation. Do not run the copy in `deploy/` as root, install a sudo rule permitting arbitrary CMS commands, or let updates automatically replace the privileged installed copy. The supervisor never runs PHP as root. Use a dedicated unprivileged deployment/site account (no interactive login, sudo or independent cron). The existing private queue requires the same storage identity as FPM. The managed HTTP and background services MUST mount the code read-only using systemd `ReadOnlyPaths` with only `storage/` writable and `NoNewPrivileges=yes`; the separate CLI process can write the installed code. This is a service namespace boundary, not independent Unix identities; a process outside the guarded units with that UID can still write code. Separate update identities and stronger privilege isolation are future hardening work.
- The trusted RSA public key must be independently provisioned, readable by the site user but not writable by it. Keep the signing key off the server. Do not enable installations until HTTPS, backup, staging and recovery checks are complete.
- Root-owned marker `/var/lib/cajeer-maintenance/active` is a durable journal and the actual HTTP gate. Configure every route (including static assets and health endpoints) at the web server before any automatic installation. Nginx samples check the marker at server scope. Apache sample uses a vhost-level rewrite. Reverse proxy/CDN must forward the 503 rather than serve cached pages during maintenance. Disable open-file caching for the gate check; web server needs traversal/read access to this root-owned directory. If using SELinux/AppArmor, authorize the read. Verify the marker using a disposable staging installation before enabling the supervisor. The gate is intentionally fixed for one installation per host.
- Never leave a permanent `CAJEER_AUTO_UPDATE_WORKERS_QUIESCED=1` in cron/systemd/FPM. The supervisor supplies that flag only to the gated CLI after service stop verification. Existing exact digest, schema, signature, opt-in and both UTC windows are still enforced by the worker. Direct CLI installers, other schedulers and manual service changes must not run concurrently with the supervisor.

## Initial installation (operator, once)

Adapt the examples to the **canonical** application root, FPM service and storage owner; the shipped paths are examples, not autodetection. The site account must own the writable code tree and private storage; normal FPM and background units must see the code read-only. Configure a graceful FPM stop (e.g. `KillSignal=SIGQUIT`, sufficient `TimeoutStopSec`, `KillMode=control-group` or `mixed`, `SendSIGKILL=yes`). Configure systemd service dependencies so no unit outside the managed set starts or stops FPM. Guard all listed service units and include their timers in the environment list. Any timer must trigger exactly one listed service. Do not use a shared FPM service.

First disable the previous update scheduler and any cron entries invoking `update auto run`, `update control work` or `update scheduler tick`:

```sh
sudo systemctl disable --now cajeer-update-scheduler.timer
sudo systemctl stop cajeer-update-scheduler.service
# Remove/disable the corresponding cron entry separately, if present.
```

Install the reviewed files (copy from a trusted read-only source, not a live writable application checkout):

```sh
sudo install -o root -g root -m 0755 deploy/cajeer-maintenance.sh /usr/local/sbin/cajeer-maintenance
sudo install -o root -g root -m 0644 deploy/cajeer-maintenance.service /etc/systemd/system/cajeer-maintenance.service
sudo install -o root -g root -m 0644 deploy/cajeer-maintenance.timer.conf /etc/systemd/system/cajeer-maintenance.timer
sudo install -d -o root -g root -m 0755 /etc/cajeer
sudo install -o root -g root -m 0600 deploy/cajeer-maintenance.env.conf /etc/cajeer/maintenance.env
sudoedit /etc/cajeer/maintenance.env
sudo install -d -o root -g root -m 0755 /etc/systemd/system/cajeer-php-fpm.service.d
sudo install -o root -g root -m 0644 deploy/cajeer-maintenance-guard.conf \
  /etc/systemd/system/cajeer-php-fpm.service.d/90-cajeer-maintenance.conf
```

Repeat the last two commands for **each** listed background `.service`, adapting the directory. If the root differs from `/srv/cajeer`, edit the `ReadWritePaths` in the supervisor unit and both path directives in each guard drop-in. Keep the root-owned supervisor outside that tree. `ReadOnlyPaths=/srv/cajeer` and `ReadWritePaths=/srv/cajeer/storage` deliberately allow nested storage while keeping code immutable to service processes. Check other drop-ins and external writable aliases; do not allow an alternate path to writable code. Review other FPM/socket/session paths as needed. Restart the managed services once after applying the guard and sandbox, during a manually controlled quiet period. The supervisor checks loaded `ExecCondition`, `KillMode`, `SendSIGKILL`, `NoNewPrivileges` and read-only/writable path settings before shutting down services. It will not change those settings itself.

Install the corresponding vhost maintenance rule from `deploy/nginx.conf`, `deploy/nginx-reverse-proxy.conf` or `deploy/apache-vhost.conf` into the real server configuration. Run `nginx -t`/`apachectl configtest` and reload the web server. On staging verify 503 for `/`, `/admin`, `/health/ready` and a static CSS URL while the marker exists, then 200 after its removal. Do not manually fabricate a marker on a live site: the marker contains the prior service state and the supervisor will refuse a malformed one.

```sh
sudo systemctl daemon-reload
sudo systemd-analyze verify /etc/systemd/system/cajeer-maintenance.service /etc/systemd/system/cajeer-maintenance.timer
sudo systemctl enable --now cajeer-maintenance.timer
sudo systemctl start cajeer-maintenance.service
systemctl list-timers cajeer-maintenance.timer
journalctl -u cajeer-maintenance.service -n 100
```

Default environment disables installation. Configure repository and key, enable periodic checks in `/admin/updates`, and verify that the checks complete. After backup and staging rehearsal, set `CAJEER_AUTO_UPDATE_ENABLED=1` and `CAJEER_AUTO_UPDATE_INSTALL=1` in `/etc/cajeer/maintenance.env` and enable CMS auto-install (or queue a specific checked package). Both maintenance windows must overlap. Optional `CAJEER_AUTO_UPDATE_ALLOW_PREVIEW_INSTALL=1` permits preview installation; leave it off for production. The timer evaluates the queue each minute; checks never take down FPM. The supervisor has a 20-minute timeout and its systemd cgroup is stopped on timeout.

## Failure and restart behavior

The journal records the exact prior `active`/`inactive` state of each managed unit before any stop. Background timers stop first, background services next, then FPM. Active services start in reverse order; inactive services stay stopped. A startup guard prevents systemd from starting application services while the durable gate is present, except for the supervisor's transient root-owned `/run/cajeer-maintenance/start-permitted` marker during safe resume. That permission is lost on reboot. The supervisor uses an exclusive root-owned flock; the worker retains the scheduler, worker, update and operations leases. It does not replay a failed or interrupted installation. It invokes the existing engine's rollback recovery only for its own gated maintenance lifecycle; an orphaned engine/CMS maintenance journal outside that lifecycle requires an operator.

If stopping, engine recovery, offline verification or service start fails, the gate remains and the next timer invocation tries to finish the interrupted maintenance. No new installation is attempted in this recovery branch. If a signed install itself fails but its rollback and service restoration succeed, the gate is removed and the supervisor still exits with the failing status. `journalctl` and the existing Control Center history/heartbeat expose outcomes. After a power loss, guarded services cannot start into partially updated code; the persistent timer recovers the journal and restores the prior set. If an engine journal or application PHP cannot be read, or service configuration changes during the interruption, the supervisor fails closed. Stop the timer and inspect logs and `update status`; repair the configuration or restore the independent backup under actual quiescence, then restart the supervisor. Do not remove the root gate until recovery and service restart are confirmed. Do not run a rollback with live workers.

The new CLI subcommands are internal operator tools (run as the site account, not root):

```sh
CAJEER_UPDATE_CONTROL_WORKER=1 php bin/cajeer update maintenance plan
# recover and verify require actual offline quiescence plus the worker flag:
CAJEER_UPDATE_CONTROL_WORKER=1 CAJEER_AUTO_UPDATE_WORKERS_QUIESCED=1 php bin/cajeer update maintenance recover
CAJEER_UPDATE_CONTROL_WORKER=1 CAJEER_AUTO_UPDATE_WORKERS_QUIESCED=1 php bin/cajeer update maintenance verify
```

`plan` is a hint only (`tick`, `install`, `blocked`); it cannot authorize a package. The worker repeats all existing package/window checks. `recover` serializes against scheduler and worker, then uses the engine's operation lease. `verify` is read-only, checks `VERSION`, installed PHP constant, schema 14, valid site JSON and unresolved maintenance journals in a fresh process. The existing installer retains code snapshots; make an independent backup of code, data and secrets before enabling automation. Version 1.5.5 verifies offline consistency and actual systemd active state, **not** live HTTP health after restart; automatic live health/rollback is the next milestone 1.5.6. Keep external uptime monitoring enabled. The cron-only scheduler remains discovery-only.

## Tests

```sh
bash tests/maintenance_orchestration_155.sh  # actual supervisor state machine with simulated systemctl/worker boundary
bash tests/maintenance_cli_155.sh            # real CLI signed install and journal rollback
php tests/core_154.php                     # actual signed queue install, windows and plan checks
bash tests/webservers_146.sh                # native config syntax
bash tests/maintenance_units_155.sh        # systemd analyzer, unit/timer/guard
python3 tests/maintenance_gate_155.py        # real loopback Nginx HTTP gate
```

Live systemd/PHP-FPM service control, host reboot, all external integrations and the official GitHub release need staging/production acceptance on the target server. Local fixtures are not that acceptance. Schema stays 14. Upgrade from 1.5.4 by signed code package or the manual ZIP patch; privileged bootstrap remains a separate one-time operator operation.
