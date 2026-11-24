# CajeerEngine Lite 1.4.4 — Update Engine

The CLI updater **installs and rolls back real application files**, not only a manifest or schema. It is intended for offline deployments on one local POSIX filesystem. The installed CMS must already have version **1.4.4 or newer** to use `php bin/cajeer update`. The initial **1.4.3 → 1.4.4** upgrade is a conventional manual ZIP overlay; do not attempt to run a CLI command that does not exist in 1.4.3.

## Signed package format and trust

- Release stream: `.cjrupdate`, magic `CAJEER-UPDATE-1`, length-prefixed JSON manifest, RSA-SHA256 signature over the exact manifest bytes, followed by the changed files in signed order. This is **not** a ZIP/TAR. No PHP ZipArchive, Composer, shell `tar`, or build-time Node dependency is needed.
- The manifest fixes `from`, `to`, minimum PHP, supported site schema, file paths, operations, prior SHA-256, new SHA-256, sizes, and modes. All file contents are individually hashed while reading the package. Deletions are signed and require a matching prior file hash.
- Signed packages **never provide their own trusted key**. The verifier uses the separately provisioned, trusted, RSA public key from `CAJEER_UPDATE_TRUSTED_KEY_FILE` (absolute filesystem path, preferably outside the web root and outside the site checkout). Configure this environment variable for the CLI user. No default trust-on-first-use or unsigned fallback is allowed.
- Keep the signing **private key** in a protected release-build environment, not in the deployed CMS, the source tree, backups, or a web-accessible directory. The signing and trusted public key must belong to your actual release owner. A publicly shared ZIP patch from a chat is **not** automatically an authenticated official release.
- Source paths are allowlisted. `storage/`, `.env`, `vendor/`, arbitrary `.php` in `public/`, unknown scripts, plugins, and server secrets cannot be installed by a package. Only schema 14 code-only upgrades are supported by 1.4.4; the installer does not run arbitrary migration hooks or update `site.json`/media. Future schema-changing releases need an additional separately tested migration protocol.
- Limits: 512 affected files, 12 MB per file, 96 MB total package. Release payloads are not extracted to arbitrary paths and cannot contain symlinks, special files, or permissions outside the allowlist. A release cannot delete `bin/cajeer`, `bootstrap.php`, or `public/index.php`.

## Key provisioning (release operator)

Generate an independent RSA signing pair using your approved key-management process. Example on a trusted build machine:

```sh
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out /secure/release-private.pem
openssl pkey -in /secure/release-private.pem -pubout -out /secure/release-trusted.pem
chmod 600 /secure/release-private.pem
```

Install only the public key on the CMS host (for example `/etc/cajeer/update-trusted.pem`, owned by root and readable by the CLI updater user). Pass its **absolute** path through the environment when using `verify` and `install`. Never commit either server trust configuration or the signing private key into the application repository.

## Building a signed update on a trusted release machine

Prepare two complete source trees: `current/` at the exact version that the package will replace and `next/` at the intended target version. `next/VERSION` and `next/core/Lite.php` must agree. Changes inside `storage/` are ignored. Build with an installed 1.4.4+ CLI whose root contains `current/`:

```sh
CAJEER_UPDATE_SIGNING_KEY_FILE=/secure/release-private.pem \
  php current/bin/cajeer update package /absolute/next /absolute/release-1.4.5.cjrupdate
```

The builder diffs managed files, hashes both versions, signs the release, writes the actual file bytes, and refuses to overwrite an existing output. Distribute the signed `.cjrupdate` and publish its SHA-256 through a second independent channel. In production, pin the authorized key; a SHA-256 from an untrusted download page alone is not authorization.

## Installing on staging/production

1. Preserve an independent **offline** backup of the whole project, `storage/`, templates, and server secrets. Verify recovery on staging. Update snapshots inside `storage/app/updates/history/` are an additional convenience, **not a substitute** for an off-host backup.
2. Stop PHP-FPM workers, queue consumers, cron jobs, and other processes touching the deployment. Quiescing running HTTP workers cannot be guaranteed by PHP alone. Do not run updates with active application workers or on a shared-NFS/multi-node installation.
3. Verify expected base version, supported schema, and key fingerprint; stage the package on the server and run:

```sh
export CAJEER_UPDATE_TRUSTED_KEY_FILE=/etc/cajeer/update-trusted.pem
php bin/cajeer update status
php bin/cajeer update verify /absolute/release-1.4.5.cjrupdate
CAJEER_UPDATE_CONFIRM=INSTALL php bin/cajeer update install /absolute/release-1.4.5.cjrupdate
php bin/cajeer doctor
php bin/cajeer security audit --json
php bin/cajeer release stable-check --json
```

4. Only after CLI diagnostics and schema/application checks succeed, restart PHP-FPM and check `/health/ready`, main pages, admin login, templates, and enabled OIDC/AI/analytics integrations. Invalidate OPcache or restart workers after code changes. `release stable-check` exit code `2` still means manual production sign-off is outstanding.

## What happens during installation

The engine acquires the shared application operations lock, checks pending maintenance, verifies the pinned-key signature, checks all package bytes, exact installed base version and original file hashes, site schema and required PHP, and lints every replacement PHP file. **No application file changes before all these checks pass.** The installer also refuses unknown target paths and unsafe symlinks.

It creates private, checksummed snapshots of all replaced/deleted files plus a separate snapshot of `storage/site.json` when present. A durable `storage/app/updates/active.json` journal is written **before** publishing any release file. Each individual file is published atomically and synchronized, obsolete files are removed, and `VERSION` is written last. A successful install records `last.json` and clears the active marker. Files under `storage/app/updates/staging/` are temporary; the backup under `history/<id>/` is retained for rollback.

New PHP entrypoint requests see HTTP 503 while the active marker exists. This guard is not a replacement for stopping HTTP workers and does not intercept static files served directly by the web server. The updater never executes scripts embedded in the package.

## Rollback and interrupted updates

For an **interrupted** installation or rollback, stop workers and run:

```sh
php bin/cajeer update recover
```

Recovery restores each modified/deleted file from the private snapshot, removes newly created files, checks original hashes, and leaves the journal in place if anything cannot be safely restored. Do not delete or edit `active.json` to bypass failed recovery. Investigate the reported filesystem conflict, preserve a copy of the journal and snapshot, and retry only after fixing the underlying condition. HTTP will continue returning 503 while recovery is pending.

To deliberately roll back the **last successfully installed** update, again stop workers:

```sh
php bin/cajeer update status
CAJEER_UPDATE_CONFIRM=ROLLBACK php bin/cajeer update rollback <24-hex-update-id>
php bin/cajeer doctor
```

Rollback refuses to overwrite files that no longer match their old or newly installed checksums, and it refuses anything other than the latest installed update. It restores **application files only**, not arbitrary post-upgrade site edits. If site data must be recovered, use an independent CMS backup and `operations` recovery procedure. Do not run a downgrade across a schema migration: 1.4.4 explicitly supports **schema 14 only**.

## Exit codes, testing and limitations

Every `update` subcommand returns `0` on success and `1` on error. Reports are JSON. CI includes `php tests/core_144.php` and `bash tests/update_engine_cli_144.sh` with real signed builds, version transitions, content replacement/removal, failure rollback, interrupted-operation recovery, wrong keys, tampering, conflicts, and maintenance blocking.

The update engine does not download packages, poll GitHub, auto-apply updates, provide a browser-side install UI, or sign releases with an embedded vendor private key. Automatic checks/installation and release distribution are scheduled for version 1.4.5, after staging and production acceptance of this offline engine. Production sign-off requires deployment-specific tests and operator approval.
