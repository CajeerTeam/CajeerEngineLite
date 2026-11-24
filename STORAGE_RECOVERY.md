# CajeerEngine Lite 1.4.2 — Storage recovery runbook

## Before upgrading or importing

Stop web workers and scheduled jobs before a manual upgrade. Preserve an **off-host** copy of the entire project, custom `.cjr` templates, runtime secrets and `storage/`; CMS TAR covers site and media content only, not PHP, templates or host secrets. Use a production-like staging installation for recovery drills. JSON schema remains 14.

## Operational commands

```sh
php bin/cajeer operations check
php bin/cajeer operations audit
php bin/cajeer operations backup create
php bin/cajeer operations backup list
php bin/cajeer operations backup verify <filename>
php bin/cajeer operations recover
CAJEER_OPERATIONS_CONFIRM=REPAIR_ORPHANS php bin/cajeer operations repair-orphans
php bin/cajeer release stable-check --json
```

`operations audit` verifies every indexed asset against SHA-256 and reports unindexed regular files. It exits nonzero when orphans exist; it never deletes files. `repair-orphans` requires an explicit environment confirmation, verifies each indexed media asset first and removes **only** well-formed unindexed private files. It will refuse unsafe directory entries or a missing/corrupted indexed asset. Recover damaged indexed data from a verified TAR instead of forcing a cleanup.

## Interrupted CMS import

A full import holds the CMS operation lease and the media/site locks. Before changing data, it persists the exact previous `site.json` and media index in `storage/app/cms-operations/recovery/` and publishes `pending.json` last. The marker blocks ordinary reads while an import is incomplete. An HTTP request (other than `/health/live`) attempts recovery automatically; concurrent requests may temporarily return 503. `php bin/cajeer operations recover` performs the same undo explicitly under the exclusive lease. Recovery restores `site.json`, restores the previous media index including its absence, verifies SHA-256 before deleting only newly imported assets, and removes the marker after all steps succeeded. Re-running recovery when there is no pending import is harmless.

If HTTP continues returning 503, stop workers, inspect **permissions and disk health**, make a forensic copy of the whole `storage/` directory, and run `php bin/cajeer operations recover`. Do not manually delete the `pending.json` marker or recovery snapshots. If the protected snapshots are damaged or a new media file changed unexpectedly, the tool refuses unsafe rollback. Restore a known-good off-host project + storage snapshot on staging first, verify checksums and account access, and only then recover production.

A completed import revokes administrative sessions as in 1.4.1. If the process stops during the final revocation step and recovery reverts content, some sessions may already have been revoked; users may need to sign in again. This is fail-closed, not a credential rollback.

## Full backup / retention

CMS TAR is built under a temporary private path, verified on disk (manifest, file hashes, media MIME/metadata and references), and only then published atomically as a new backup. A failed export cannot appear in the ready-backup inventory. Retention refuses to prune old copies if any retained archive is damaged; independently verify archived copies before pruning. Both `site-*.json` and `cms-*.tar` backups are private and must not be served from the public document root.

## Limits and acceptance

This mechanism assumes a local filesystem with working atomic rename/link, `flock` and `fsync`. A sudden power loss or unsafe external writes can still require recovery from independent media; backups should be stored off-host. Run `php tests/core_142.php` and `bash tests/storage_recovery_http_142.sh` on staging, then validate critical live pages, sessions, and a restore drill before production sign-off.
