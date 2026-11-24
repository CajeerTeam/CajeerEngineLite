# Automatic Health & Rollback — 1.5.6

This release extends the optional, independently installed Linux/systemd maintenance supervisor. The signed Update Engine remains the sole code installer and rollback engine. The root supervisor runs no PHP as root. The public HTTP 503 gate remains present through installation, restart, health checks and any rollback. It is not sufficient to update the CMS files alone: the operator must replace the separately installed root-owned supervisor and configure the local TLS probe once.

## Prerequisites and upgrade from 1.5.5

Follow `MAINTENANCE_ORCHESTRATION.md` for the dedicated service/account, code read-only mounts, worker/timer list, root-owned trust key, backup, systemd startup guards and public vhost rules. Keep a separate verified backup of code, storage, keys and server configuration. No shared FPM, independent cron/CLI installers or unlisted background processes. A CDN must respect maintenance and not cache readiness. Configure all paths for your actual site.

1. Stop/disable the existing maintenance timer and other update jobs. Ensure the old supervisor is not running and that neither `/var/lib/cajeer-maintenance/active` nor the Update Engine journal is present. If a 1.5.5 maintenance cycle is interrupted, recover it using the **old** supervisor before replacing it. A v1 gate is intentionally rejected by the new supervisor.
2. Apply the verified 1.5.6 CMS update (signed exact-base package or manual ZIP under actual quiescence). Keep automatic installation disabled while configuring the supervisor.
3. From a trusted, reviewed, read-only release source, install the new root-owned script outside the PHP-writable tree:

```sh
sudo install -o root -g root -m 0755 deploy/cajeer-maintenance.sh /usr/local/sbin/cajeer-maintenance
sudoedit /etc/cajeer/maintenance.env
```

Add these settings, adapting the example host/port to your installation:

```ini
CAJEER_MAINTENANCE_HEALTH_HOST=example.com
CAJEER_MAINTENANCE_HEALTH_PORT=8443
CAJEER_MAINTENANCE_HEALTH_CA_FILE=
```

Use the canonical TLS DNS name (or certificate IP identity). With a private CA, set the absolute path of a root-owned CA bundle in a root-controlled directory (not the CMS tree). Use normal system trust for a publicly trusted certificate. Do not disable certificate or hostname verification. The host and port are root operator configuration; the supervisor connects only to 127.0.0.1, while sending the canonical hostname and TLS SNI. Requires `/usr/bin/curl` and `/usr/bin/python3` in addition to the existing Linux requirements. PHP has no new extension dependencies.

4. Install **one** reviewed loopback-only listener: `deploy/nginx-maintenance-health.conf` (in Nginx http context) or `deploy/apache-maintenance-health.conf` (Apache with SSL, rewrite, proxy_fcgi, authz_host). Use exactly the same certificate, application root, PHP-FPM service/socket, environment and site identity as the public vhost. The listener must bind only 127.0.0.1 and expose only GET/HEAD `/health/ready`; it must not be reachable through a proxy/CDN or public port. Do not exempt a header or query parameter from the public gate. Disable any caching in the probe. Test config with `nginx -t` or `apachectl configtest`, reload the web server and confirm the probe works with the public gate still active during a disposable staging rehearsal. The root supervisor performs normal verified TLS, never follows redirects and never sends cookies or credentials.

```sh
curl --noproxy '*' --connect-to example.com:443:127.0.0.1:8443 \
  --include https://example.com/health/ready
```

Expected: HTTP 200, `X-Cajeer-Release: 1.5.6`, `Cache-Control: no-store`, JSON `{"status":"ready"}`. For private CA add `--cacert /etc/cajeer/health-ca.pem`. The public route must still return 503 while the maintenance gate is present. The probe is not a public TLS/load-balancer/CDN test; external uptime monitoring and the production acceptance CLI remain necessary.

5. Rehearse a healthy and deliberately unhealthy **signed test release in staging** with the real FPM service. Verify data preservation, gate, rollback, hold, service state, reboot recovery, and external monitoring. Then enable the reviewed systemd unit/timer and opt-in installation. Never run the script from a PHP-writable path. Signed updates may change deployment examples but cannot overwrite the actual root-installed script, configuration or trust files.

## Runtime protocol

- Discovery-only ticks do not stop services. Before an eligible install, the supervisor reads the installed version and last transaction ID through the non-root CLI and requires valid baseline HTTPS readiness. If the baseline is unhealthy, no installation or downtime is started.
- The root-owned, fsync-published `active` journal records baseline version, prior transaction ID, phase (`check`/`rollback`) and original unit states. It is also the public HTTP gate. It is replaced atomically when the rollback phase begins; public access is never opened between versions.
- After draining services and signed installation, the supervisor recovers any unfinished engine transaction. A fresh CLI verifies version/schema/site JSON and checks the latest installed transition's file hashes against its recovery plan under the exclusive operations lock. `snapshot_verified: false` means a manual installation or an older receipt, not proof of signed runtime integrity. This verification covers files affected by that signed transition; use the production release integrity check for the complete release tree.
- Start only FPM, check TLS readiness (up to five attempts, two seconds between attempts, max five seconds per HTTP request), and require exactly the expected release header, JSON status, HTTP 200 and no-store. Readiness already renders the published home page and validates site data. Start the previously active background units only after that probe, and probe again before removing the public gate.
- If the new version fails offline verification, service startup or HTTPS readiness, durably record rollback intent and create the root-owned `hold`. Stop the managed units again. Identify the **new** transaction by its distinct ID and exact from/to versions. Invoke `update rollback <id>` with the existing offline rollback confirmation. If an engine journal is present, recover it first. A prior unrelated transaction is never rolled back merely because readiness fails.
- Reverify and restart the old version and repeat the TLS probes. Only a healthy original service set opens the gate. A successfully restored rollback returns exit code 3 (systemd records failure deliberately). Other failures return nonzero and retain the gate; normal completed updates return 0. Journal recovery takes priority over the hold and never schedules a new install. Repeated recovery is idempotent, including a crash after the engine already rolled back. The runtime guard permission does not survive reboot.
- If CLI/bootstrap is too broken to recover, an affected file was independently edited, the snapshot is damaged, a service cannot stop or start, or the restored baseline is itself unhealthy, the supervisor fails closed. Do not remove the gate to force traffic through. Restore the independent backup under actual offline quiescence with operator assistance.

## Operator review after rollback

The persistent `/var/lib/cajeer-maintenance/hold` prevents all supervisor update ticks, even after reboot or rollback to PHP code without this feature. The normal CMS remains available after a successful rollback. This hold is enforced by the privileged supervisor, not a PHP UI setting. Do not bypass it with another CLI or scheduler. Journald records the exact rolled-back transaction and outcomes; the existing Control Center records the installation step, not the independent supervisor's final health verdict.

```sh
journalctl -u cajeer-maintenance.service -n 150
sudo cat /var/lib/cajeer-maintenance/hold
php bin/cajeer update status
php bin/cajeer update control status
```

To resume: stop the timer, confirm the supervisor is idle and no active gate/engine journal exists, investigate the release and logs, disable automatic installation and cancel any pending install in `/admin/updates`, check backups and test a corrected release. Only then explicitly remove the hold as root and restart the timer. Do not clear it during an unfinished recovery. Keep the install opt-in off until a fresh verified package is approved.

## Local verification

```sh
bash tests/maintenance_orchestration_155.sh
bash tests/maintenance_cli_155.sh
python3 tests/automatic_health_156.py
bash tests/webservers_146.sh
bash tests/maintenance_units_155.sh
php tests/core_154.php
```

The HTTPS integration test uses a disposable PHP HTTP backend and real Nginx TLS listener, a genuine RSA-signed intentionally unhealthy update and the real rollback engine. Systemd service transitions are simulated; it does not attest actual FPM, machine reboot, external GitHub or production infrastructure. Production sign-off remains separate. Storage schema 14 remains unchanged; update packages are code-only and do not roll back independently edited site content.
