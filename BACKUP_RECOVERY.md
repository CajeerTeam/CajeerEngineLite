# Backup and recovery runbook

## What to protect

CMS Operations TAR includes site JSON and registered media only. An independent full backup must include source code, custom templates, entire `storage/`, updater snapshots and journals, PHP-FPM/web server configuration, secrets and certificate/key material according to the host policy. Encrypt private backups, keep an off-host copy and restrict readers. Record version, schema, archive hash, timestamp, ownership and restoration instructions. Keep at least one verified older copy, not just the newest. Define RPO/RTO for the site and test them; the CMS does not guarantee those objectives.

## Routine backup

```sh
php bin/cajeer operations audit
php bin/cajeer operations backup create
php bin/cajeer operations backup list
php bin/cajeer operations backup verify NAME
php bin/cajeer monitor check --verify-backup
```

Replace NAME with the returned filename. Copy the verified TAR to protected off-host storage; store its checksum independently. For a consistent full filesystem snapshot stop writers or use a proven atomic snapshot procedure. Do not copy a running site's independently changing JSON and media and call the result a consistent full backup. Never store archives under `public/`.

## Restore content only

Stop writers, preserve the current full snapshot, verify the selected archive, then use `CAJEER_OPERATIONS_CONFIRM=NAME php bin/cajeer operations backup restore NAME` (both NAME values identical). For a portable export from another installation: `CAJEER_OPERATIONS_CONFIRM=IMPORT php bin/cajeer operations import /secure/site.tar`. Import preserves local authorization and revokes sessions; do not expect all server secrets or template files to be restored. Run `operations audit`, `doctor`, readiness and authentication checks before restart.

## Recovery decision tree

- Interrupted CMS import: `php bin/cajeer operations recover`. Keep the private recovery marker and original snapshots until recovery succeeds. Read [STORAGE_RECOVERY.md](STORAGE_RECOVERY.md).
- Interrupted code update: `php bin/cajeer update recover`. For the last completed code update, see [UPGRADE.md](UPGRADE.md). Do not manually delete `storage/app/updates/active.json`.
- Corrupted `site.json`, missing media or failed recovery: stop workers, preserve the entire damaged tree and logs for investigation, restore the last known-good **matching code and storage** on an isolated host, validate hashes, account access and content, then restore production under maintenance. Avoid editing JSON to silence an error.
- Disk exhaustion: stop mutations, preserve valid backups, free space outside active transaction directories; do not prune the last good backup or delete a recovery journal. Run `operations audit`; orphan repair is separate and destructive, requires `CAJEER_OPERATIONS_CONFIRM=REPAIR_ORPHANS` and a verified snapshot.

Record the actual restore time, recovered content date and outcomes of `/health/ready`, login, pages, media, SEO and integrations. Rehearse periodically and before each release. A successful `backup create` is not proof of recoverability.
