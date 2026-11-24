# Operations — 1.4.8+

## Daily checks

From the deployed project root, as the appropriate dedicated account:

```sh
php bin/cajeer doctor
php bin/cajeer monitor status --minutes=60
php bin/cajeer monitor check --json
php bin/cajeer operations check
php bin/cajeer update status
```

Use an external HTTPS uptime check for `/health/live` and `/health/ready` (ready must be 200 with `{"status":"ready"}`; a 503 requires investigation). Do not expose private monitoring or diagnostics to unauthenticated visitors. The authenticated monitoring tab is `/admin/developer?tab=monitoring`. `monitor check` exits nonzero when a threshold fails; monitor its exit status externally, not just stdout. No automatic alert delivery is provided. Keep PHP-FPM and web-server logs private and rotate them with the OS.

## Scheduler

Example cron, after replacing paths and choosing the storage owner. Ensure stderr and stdout are handled by your secured scheduler/log service; never expose them over HTTP.

```cron
*/5 * * * * cd /var/www/cajeer-lite && /usr/bin/php bin/cajeer monitor check --json
15 3 * * * cd /var/www/cajeer-lite && /usr/bin/php bin/cajeer operations backup create
45 3 * * * cd /var/www/cajeer-lite && /usr/bin/php bin/cajeer monitor check --verify-backup
```

Copy backups off-host and verify periodically. Do not overlap a full backup with update or recovery. Do not schedule auto-install until the maintenance orchestration in AUTOMATIC_UPDATES.md has been implemented and verified. Never enable the worker-quiesced flag on a live worker pool.

## Operational limits

The private request journal rotates at roughly two MiB, tracks retained measurements only and may drop samples under contention. It excludes IPs, query strings, and request content. It is not an exact counter, distributed metrics collector or substitute for FPM pool statistics. Configure disk, p95, 5xx and memory thresholds using [OPERATIONAL_RELIABILITY.md](OPERATIONAL_RELIABILITY.md). Review free disk and backup age before updates. Use an independent metrics service for long-term trends, process utilization, and alert routing.

## Failure triage

If live fails, check proxy/FPM/process and TLS; if ready fails but live passes, inspect `doctor`, storage integrity, free disk and update/CMS recovery status. Stop writes if data integrity is suspect, follow BACKUP_RECOVERY.md, and avoid repeatedly restarting an incomplete transaction. If p95 or errors rise, inspect FPM capacity, disk I/O, external provider timeouts and log contention. Disable a failing optional integration through its supported settings; do not bypass auth, TLS or consent.

Run `python3 tests/operational_load_147.py --seconds 60 --workers 4` only on an isolated test machine. For longer staging soak choose duration and thresholds from the operational objectives, collect p95/p99, error rate, resource trends and backup/recovery evidence. The included script uses disposable local PHP, not actual production FPM traffic.
