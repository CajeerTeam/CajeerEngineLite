# Upgrades and rollback — 1.4.8+

## Prepare

Record installed `VERSION`, PHP, site schema, canonical origin and trusted-key fingerprint. Rehearse on staging restored from an off-host backup of code, storage, templates and host secrets. Stop PHP-FPM and all scheduled/long-running jobs before replacing files. A CLI flag is not proof workers are stopped. Back up and verify a CMS TAR in addition to the independent full snapshot. Retain the exact pre-upgrade source tree and original file permissions.

```sh
php bin/cajeer compatibility check --json
php bin/cajeer operations backup create
php bin/cajeer operations backup list
# Replace NAME with the actual archive name:
php bin/cajeer operations backup verify NAME
php bin/cajeer update status
```

## Signed upgrade (base >= 1.4.4, schema 14)

Use an exact adjacent `.cjrupdate` from the authorized release. Verify the publisher's independently provisioned RSA public key and the signed SHA256SUMS as in INSTALL.md. Do not use ZIP files as signed updates. Example for a deployed 1.4.7 site:

```sh
export CAJEER_UPDATE_TRUSTED_KEY_FILE=/etc/cajeer/update-trusted.pem
php bin/cajeer update verify /secure/CajeerEngineLite-1.4.8.cjrupdate
CAJEER_UPDATE_CONFIRM=INSTALL php bin/cajeer update install /secure/CajeerEngineLite-1.4.8.cjrupdate
php bin/cajeer doctor
php bin/cajeer security audit --json
php bin/cajeer compatibility check --json
php bin/cajeer update status
```

The updater checks the exact base version and changed-file hashes, not arbitrary version jumps. Locally modified managed files must be reconciled on staging rather than forced. Source-only repository metadata (release workflow, CODEOWNERS, funding) is not deployed by the 1.4.7 signed installer; keep it in the repository. If a release changes an unsupported runtime path the new release builder refuses to produce an incomplete delta. Never bypass this check. Use the separately reviewed manual transition if needed.

## Manual overlay and legacy migration

For a patch ZIP explicitly prepared against your installed release, verify provenance, stop writers, back up, then overlay relative paths (never overwrite storage or host secrets). A full ZIP is for a clean installation; blindly overlaying it can leave removed files. Check VERSION and `core/Lite.php`, reconcile removals according to the published change list, and preserve custom templates. Older releases without Update Engine require a manual transition. Run `php bin/cajeer compatibility migration preview` for a schema below 14; after backup, run `CAJEER_COMPATIBILITY_CONFIRM=MIGRATE php bin/cajeer compatibility migration apply`. Do not attempt a schema-changing signed update or downgrade. See COMPATIBILITY.md.

## Restart and validate

Run `php bin/cajeer release stable-check --json`. Exit 1 is failure; exit 2 is automated pass but manual acceptance pending. Restart FPM (including OPcache) only after code checks succeed. Check actual TLS, `/health/ready`, page/admin flows, integrations, logs and resources. On failure stop writers again, investigate, and use the following procedure.

## Recover or roll back

For interrupted installation: `php bin/cajeer update recover`. For a completed update, obtain the last update ID from `update status`, then run `CAJEER_UPDATE_CONFIRM=ROLLBACK php bin/cajeer update rollback <id>` while workers remain stopped. Rollback restores code, not edits made to the live site after upgrade. Never remove an active journal manually. If recovery refuses, preserve evidence and use the independent backup; see [BACKUP_RECOVERY.md](BACKUP_RECOVERY.md). After rollback run all health checks before restarting.

## Automatic updates

See [AUTOMATIC_UPDATES.md](AUTOMATIC_UPDATES.md). Enable only after signed releases, trusted key, actual traffic drain, backup/recovery and staging acceptance are in place. The scheduler must quiesce workers externally. Opt-in, UTC window, worker flag and recovery permissions are separate. No automatic update setting is enabled by this release.
