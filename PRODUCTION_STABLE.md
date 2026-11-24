# 1.5.0 — Production Stable: deployment acceptance

The version identifies the stable release line. A particular installation is accepted only after its own live checks and operator review. Schema 14 and the existing CMS features remain frozen. The ZIP patch from 1.4.9 is a manual source overlay, not an official signed update. Do not confuse a disposable test key or fixture evidence with production acceptance.

## Official release

Build the official 1.5.0 artifacts from exact tags **v1.4.8 → v1.5.0** using the protected workflow in RELEASE.md. This is the last stable base, so the automatic updater can skip 1.4.9 (prerelease). The 1.4.9 operator may either use a separately signed exact 1.4.9 → 1.5.0 build from the same target sources or the supplied manual source patch after independent verification. Never install the 1.4.8 package on 1.4.9. The official workflow refuses a different base for 1.5.0. Obtain the five artifacts from the authorized publisher and independently provision its public RSA key. Keep the artifacts and key outside the project, in a trusted operator-owned directory; don't allow the web worker or untrusted processes to modify them. Do not use a test signing key for publication.

`release-report.json` now includes SHA-256 for every shipped runtime file (VERSION, bootstrap, router, CLI, core, public, templates). The signed checksum list binds the report, full ZIP and incremental package. The gate verifies all three hashes, the RSA signature, signed package and exact local runtime tree. Local custom templates or extra runtime files intentionally fail: incorporate them in a separately reviewed and signed build or use the unmodified official tree. A proxy's version header is a version check, not remote attestation of the full executable tree. Run the gate on the installed server itself using the same data and environment as FPM.

## Automated gates

Follow INSTALL.md, UPGRADE.md and BACKUP_RECOVERY.md. Stop writers for update/rollback; take and verify an independent off-host backup. Restore it on an isolated staging host and exercise the exact signed upgrade and rollback. Verify full CI, including PHP 8.4/8.5, real browser navigation (Chromium/Firefox/WebKit), and web-server checks. Restart FPM and verify the production deployment; create a new CMS backup. Keep monitoring enabled and observe real traffic, latency and errors against your agreed thresholds. The following checks verify the actual locally installed code, runtime compatibility, security, recovery drill, fresh verified backup, pending update state, HTTPS readiness, and telemetry. At least 20 retained measurements in the past hour, latest within five minutes, no invalid records, and all configured health thresholds are required for the live gate. Best-effort telemetry is not an exact traffic counter and these checks are not a substitute for external monitoring/load testing.

```sh
export CAJEER_UPDATE_TRUSTED_KEY_FILE=/etc/cajeer/update-trusted.pem
php bin/cajeer release production-check --artifacts=/srv/release/1.5.0 --json
CAJEER_PRODUCTION_LIVE_CONFIRM=LIVE php bin/cajeer release production-check \
  --artifacts=/srv/release/1.5.0 --live --json > /secure/review/live.json
```

Exit codes: 1 = failed/refused (some failures are on stderr); 2 = automated gates passed, review still pending; 0 is reserved for an explicitly recorded operator sign-off. Local checks do not contact providers. For live checks explicitly add `--provider=NAME` for every configured API provider reported under `required_providers`. The underlying RC probe is reused; no network probes are implicitly enabled. Paid AI probes additionally require `--paid` and `CAJEER_AI_PAID_PROBE_CONFIRM=I_ACCEPT_API_CHARGES`; read-only Clarity calls may consume quota. Browser tracking without reporting credentials, GTM, actual OIDC login/logout, AI billing and actual analytics delivery/consent are mandatory manual review where applicable. Unconfigured providers must be explicitly marked not applicable with a reason in the operator evidence, not called successful. Do not run this gate on a public host with disposable TLS trust or without authorization.

## Documented human sign-off

Create a private review directory outside public/ and fill eight real evidence files: `ci.txt`, `browser_e2e.txt`, `staging_fpm_tls.txt`, `providers_and_consent.txt`, `offhost_restore.txt`, `upgrade_rollback.txt`, `load_soak.txt`, `production_observation.txt`. Include exact tag/commit and artifact digest, actual results, dates, test environment and links to retained evidence. Resolve all blockers before approval; include justified N/A only for disabled integrations. Redact tokens and personal information. The evidence format is operator text, not a claim that arbitrary text has been independently validated.

In the same directory create `approval.json`: keys `reviewer` (human reviewer identifier), `decision` (`approve`), `version` (`1.5.0`), `receipt_sha256`, `site_sha256` and `origin_sha256` copied from the fresh gate report (`artifact.receipt_sha256`, `site_sha256` and `origin_sha256`), and `evidence` (object mapping each of the eight basenames without `.txt` to the SHA-256 of its actual file). Use `sha256sum` to calculate each digest. Do not fabricate evidence. The operator must review the actual content before approving.

```sh
CAJEER_PRODUCTION_LIVE_CONFIRM=LIVE \
CAJEER_PRODUCTION_SIGNOFF_CONFIRM=I_REVIEWED_PRODUCTION_EVIDENCE \
php bin/cajeer release sign-off --artifacts=/srv/release/1.5.0 \
  --live --evidence=/secure/review --json
```

Include the same provider flags as above. The command reruns live checks and verifies the approval and evidence hashes. It rejects changed runtime/site state, incomplete evidence and missing consent. Pause content edits during review; if the site changes, obtain a new report and review the new state before signing off. The final write briefly obtains exclusive operations and site locks; if busy, retry. It atomically creates a private, non-overwriting record in `storage/app/release-signoff/<id>.json`, with exact runtime and artifact hashes, review identity, evidence digests, gate checks, monitoring and UTC timestamps. Status `operator_attested` records the human decision, not verified identity or independent certification of evidence text. The record is not a cryptographically signed reviewer statement and the storage owner could modify it: export it and the underlying evidence to an independently protected audit store, optionally sign there. No record changes the result of a subsequent gate or approves a different deployment. Repeat review after changes. Keep external uptime/FPM metrics and alerts active; see OPERATIONS.md.

## Local reproduction

```sh
python3 tests/production_stable_150.py
python3 tests/production_stable_150.py --base /path/to/clean/previous/source
```

The test builds real RSA-signed disposable artifacts and exercises actual loopback HTTPS via Nginx, page/media/backup/restore, production gate and test-only sign-off, refusal of missing consent, altered evidence/artifacts, runtime drift and disabled monitoring. It is not production FPM, actual provider credentials, browser matrix, long soak or human sign-off. Never retain its synthetic approval as real evidence.
