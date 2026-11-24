# Operational Reliability — 1.4.7

## Deployment

Stop PHP-FPM and scheduled jobs, take an independent backup of the application, `storage/` and deployment secrets, and overlay the 1.4.7 patch on 1.4.6. JSON schema remains 14. Run the tests and doctor before restart. Run CLI monitoring under the same service account that owns the private storage; do not make storage world-readable to accommodate monitoring.

```sh
php tests/core_147.php
bash tests/operational_http_147.sh
python3 tests/operational_load_147.py --seconds 15 --workers 4
php bin/cajeer doctor
php bin/cajeer monitor check --json
```

## Monitoring and alerting

The authenticated Developer Tools → Мониторинг page (`/admin/developer?tab=monitoring`) shows the last 60 minutes of retained PHP request measurements, resource checks and backup freshness. CLI JSON is intended for cron/systemd or an external monitoring agent:

```sh
php bin/cajeer monitor status --minutes=15
php bin/cajeer monitor check --minutes=15
php bin/cajeer monitor check --verify-backup
```

`status` gives observed request counts, 4xx/5xx, 5xx percentage, nearest-rank p50/p95/p99 in milliseconds and maximum PHP allocated memory for all requests and fixed route groups. `check` exits 1 on a failed check and 0 otherwise; warnings are separate and must be evaluated by the operator. Invalid arguments or unreadable metrics also return a nonzero status (`check` can report the latter as a failed check). Example cron, using the service account and an absolute deployment path:

```cron
*/5 * * * * cd /srv/cajeer && /usr/bin/php bin/cajeer monitor check --minutes=15
17 3 * * * cd /srv/cajeer && /usr/bin/php bin/cajeer monitor check --verify-backup
```

Configure the scheduler/collector to deliver failed exit codes and warning statuses to operators. A scheduler and alert delivery service are not bundled. Avoid logging unbounded JSON to a local file; use your system journal with retention or an external collector. Verify the latest full CMS TAR on a schedule; age alone does not establish integrity or recoverability. Continue independent off-site backups and restore rehearsals.

Environment settings (CLI for checks; PHP-FPM for request collection):

| Variable | Default | Meaning |
|---|---:|---|
| `CAJEER_MONITORING` | enabled | exact `0` disables new HTTP measurements |
| `CAJEER_MONITOR_MIN_FREE_MB` | 100 | minimum free disk MiB, failure below threshold |
| `CAJEER_MONITOR_BACKUP_MAX_HOURS` | 48 | warn if no full TAR or age exceeds threshold |
| `CAJEER_MONITOR_P95_MS` | 1000 | fail above observed aggregate p95 |
| `CAJEER_MONITOR_ERROR_PERCENT` | 5 | fail above observed aggregate 5xx percentage |
| `CAJEER_MONITOR_PEAK_MB` | 128 | fail above observed request peak allocated MiB |

Latency and error thresholds warn rather than pass/fail when fewer than 20 measurements are present. Disk space, storage permissions, pending operations and current schema are also checked. Host load average and the CLI PHP memory limit are informational (not container-specific CPU or FPM memory measurements). Readiness validates current schema without triggering a legacy migration; interrupted CMS import recovery still precedes readiness as in earlier releases. Use an external HTTP monitor for `/health/live` and `/health/ready` (200/503), and keep web-server access/error logs and PHP-FPM metrics separately.

## Request log semantics

Files are `storage/app/monitoring/requests.jsonl`, `requests.previous.jsonl` and `log.lock` in a 0700 directory, with 0600 single-link regular files. The logger records UTC epoch seconds, fixed group, HTTP status, PHP elapsed microseconds and allocated peak bytes. No raw path, query string, headers, IP, cookies, POST data, exception text, credentials, or page content is retained. The generic unhandled-exception log now contains the exception class rather than its potentially sensitive message. Web-server and PHP-native logs are outside this protection.

Each request appends one bounded record under a nonblocking lock. Rotation keeps two files up to 1 MiB each; a truncated tail is repaired by the next writer. Reports snapshot bytes under a shared lock, then calculate statistics outside the lock. Collection is intentionally best effort: contention, disk failures and killed workers can lose measurements. Static assets, requests rejected before bootstrap during code replacement, proxy/network delays, FPM queue time and startup failures are not represented. The retained time range is reported; it can be much shorter than the requested window under heavy load. Counts and rates are based on retained records, not authoritative traffic or billing totals. Cross-node aggregation is not implemented. Disable collection by setting `CAJEER_MONITORING=0` in FPM and restarting it; existing files remain until an operator removes them with workers stopped. Do not rotate these files with an external logrotate process while the app is writing.

## Load and soak tests

The Python standard-library runner creates a disposable CMS, installs an actual site, creates a backup and launches a loopback PHP development server with four worker processes. It sends concurrent real HTTP requests to the home page, readiness, robots and sitemap routes, checks HTTP status/body, calculates a bounded latency histogram, and verifies telemetry and backup integrity at the end. It does not send requests to any external host. Linux/POSIX is required for process-group cleanup. Use for local or CI regression only, not as capacity certification for production FPM.

```sh
python3 tests/operational_load_147.py --seconds 60 --workers 8 --p95-ms 1000
# Dedicated staging machine; up to 3600 seconds per run
python3 tests/operational_load_147.py --seconds 3600 --workers 16 --p95-ms 1000
```

The test fails if there are transport/HTTP errors, fewer than 20 requests, missing/corrupt telemetry, p95 above the chosen threshold, or an operational check fails. The histogram rounds up to milliseconds and saturates at 10 seconds. Production acceptance additionally requires extended tests on the real Nginx/Apache + PHP-FPM deployment, mixed read/write/admin workflows, representative media and data size, resource monitoring, controlled backup/restore and agreed capacity targets. Do not run a load test against an active public site without explicit authorization and capacity planning.
