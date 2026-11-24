# Update Security Hardening (1.5.7)

This release extends the existing signed `.cjrupdate` format without changing the wire format or JSON site schema (14). It does not trust GitHub release metadata as authorization to execute code. The package and signed distribution index must be verified independently; an index's authenticated package digest is still checked against downloaded bytes. A trusted signing key authorizes code, so protect signing credentials outside the CMS and deployment host.

## Deploy the managed trust policy

Use Linux with local storage, a dedicated site account and the independent root-owned maintenance supervisor documented in `MAINTENANCE_ORCHESTRATION.md` and `AUTOMATIC_HEALTH_ROLLBACK.md`. Stop automatic installation before changing the policy or services. The operator must authenticate public keys through an independent release-signing channel; do not automatically fetch keys from GitHub or the same release being verified.

Create `/etc/cajeer` as root-owned 0755. Prepare the policy on the trusted administrator machine, using only public PEM files. Example (Python 3; `release-current.pem` and optionally `release-next.pem` are independently verified SPKI public keys):

```sh
python3 - release-current.pem > update-trust.json <<'PY'
import json, pathlib, sys
print(json.dumps({'format': 1, 'minimum_target': '1.5.7',
                  'keys': [pathlib.Path(p).read_text() for p in sys.argv[1:]],
                  'revoked': []}, indent=2))
PY
sudo install -d -o root -g root -m 0755 /etc/cajeer
sudo install -o root -g root -m 0644 update-trust.json /etc/cajeer/update-trust.json.new
sudo mv -T /etc/cajeer/update-trust.json.new /etc/cajeer/update-trust.json
```

The canonical absolute policy path, its file and ancestors must be root-controlled and not writable by group/others (root-owned sticky parent directories are allowed with root-owned descendants). Symlinks, hardlinked policy files, malformed policies and empty active trust sets are rejected. Policy is bounded to 64 KiB, 1–8 public keys and at most 128 revoked SHA-256 fingerprints. RSA keys must be 2048–8192 bits. No private key belongs in the policy. Keep policy files on an administrator-controlled filesystem, not on a CMS-writable mount. If mandatory access controls are in use, permit the site user to read the policy, not write it.

Set `CAJEER_UPDATE_TRUST_POLICY_FILE=/etc/cajeer/update-trust.json` in the root-owned maintenance environment and in any operator-controlled CLI/scheduler environment. The web application does not edit or fetch this file. Check using the site account, never PHP as root in a writable application tree:

```sh
sudo -u cajeer env CAJEER_UPDATE_TRUST_POLICY_FILE=/etc/cajeer/update-trust.json \
  /usr/bin/php /srv/cajeer/bin/cajeer update trust status
```

The JSON report contains only mode, minimum target and active/revoked fingerprints, not PEM material. `CAJEER_UPDATE_TRUSTED_KEY_FILE` is retained for explicit legacy offline verify/install and build tooling. If managed policy is configured but absent, invalid or unable to verify a signature, the operation fails; it never falls back to the legacy key. Direct legacy `update auto run` or control-worker automatic installation additionally requires `CAJEER_UPDATE_ALLOW_LEGACY_AUTO=1` for compatibility; do not enable this in production. The privileged supervisor requires managed policy and does not forward this escape hatch.

## Rotate or revoke signing keys

1. Pause automatic updates and wait for the update/maintenance worker to finish. Independently provision the new public key. Add it to `keys` alongside the old key, atomically replace the policy as above, and verify both fingerprints via `update trust status`.
2. Sign future packages and indexes using the new private key on the protected build host. Rehearse the transition on staging with both trusted public keys. Every package and index is still checked against active policy, including a fresh policy check immediately before preparing persistent installation state.
3. After verification, add the old fingerprint (exact lower-case SHA-256 of OpenSSL's canonical public PEM, as printed by `update trust status`) to `revoked`, and optionally remove its PEM from `keys`. Keep the revoked fingerprint in the policy. Re-publish/update required signed distribution indexes with an active key or publish a new release above the security floor. Do not silently ignore a bad index: discovery fails closed if any considered signed index is invalid.
4. Set `minimum_target` to the earliest safe installable version. Discovery filters lower targets, and both package and index validation enforce the floor. The exact installed-base and strictly increasing-version checks remain mandatory. Ensure a directly reachable signed package exists at/above the floor for each supported installed version. If an intermediate transition is below the floor, it cannot be installed. The floor is operator-maintained; it is not an automatically advancing hardware-backed monotonic counter.
5. Resume automation only after checking the actual site, backups, guard and TLS readiness. A policy change is not a remote revocation service and does not terminate a transaction already applying files. Disable/drain workers first for immediate revocation. If a signing key is compromised, treat all releases signed with it as suspect, inspect installed code from an independently trusted source, and rotate credentials as necessary.

An explicit latest-transaction rollback/recovery is intentionally not blocked by revocation or the target floor: it restores previously captured local bytes, not a downloaded downgrade. The supervisor keeps its existing durable hold after health rollback. A recovery to an earlier version may restore older code without managed trust support; leave automation disabled until a trusted upgrade is manually applied. Never lower the floor or clear the hold merely to repeat a rejected release.

## Protect privileged deployment and transaction state

Upgrade the independently installed supervisor and each managed service's guard from a reviewed trusted release, outside the CMS-writable tree. Disable the supervisor timer and previous cron/update workers first. Do not copy a root executable from an unverified tree. Example after verification:

```sh
sudo install -o root -g root -m 0755 deploy/cajeer-maintenance.sh /usr/local/sbin/cajeer-maintenance
# Create the private update mount point BEFORE restarting the managed services.
sudo install -d -o cajeer -g cajeer -m 0700 /srv/cajeer/storage/app/updates
# Merge deploy/cajeer-maintenance-guard.conf into EACH managed service drop-in.
sudo systemctl daemon-reload
```

The managed FPM/background service drop-ins must include both the canonical application root and `storage/app/updates` in `ReadOnlyPaths`, with `NoNewPrivileges=yes`. Only normal CMS storage is writable; do not add `ReadWritePaths` for the updates directory or its descendants. The supervisor refuses new installs and recovery when the unit boundary is missing. Restart managed services after applying drop-ins, verify the actual mount namespace on staging, and ensure no outside jobs or unguarded processes use the deployment UID. The queue (`storage/app/update-control`) remains writable to the web application; the privileged worker still validates signatures and exact digest, target, system opt-in and windows independently. The updater account must retain write access to transaction state and code only during offline operations. Never allow sudo/systemctl arbitrary command execution through the CMS.

This is a hardened service namespace boundary, not independent Unix identities. An arbitrary process outside the guarded units with the same UID, or a compromised host/root account, remains outside the threat model. Existing rollback snapshots are local protected state, not separately signed by an offline key. Before upgrading, inspect old transaction snapshots/receipts and make independent trusted backups; do not assume old snapshots were protected retroactively. Do not enable automation without actual mount isolation and root-owned configuration validation. Recovery validates bounded private transaction metadata, ordered unique paths, modes and SHA-256 before applying existing snapshots.

## Audit and verification

Security events are JSON on the CLI PHP error log (stderr in the default CLI setup), with component `update-security`: successful/rejected verification, signed distribution, installation, recovery, rollback and legacy opt-in. Successful package/transaction events contain versions, signing-key fingerprint and package hash when available. No private keys, URLs, credentials or file contents are logged. Use `journalctl -u cajeer-maintenance.service` or your independent log collector; configure retention/access and alerting at system level. This is operational audit logging, not a cryptographically tamper-proof ledger.

Downloads are still TLS-verified; redirects are limited to GitHub asset CDN hosts, and a declared Content-Length must match the expected asset size. PHP CLI entry-point replacements are syntax-checked as well as `.php` members.

Local tests:

```sh
php tests/core_157.php
php tests/core_152.php
bash tests/update_engine_cli_144.sh
bash tests/maintenance_orchestration_155.sh
bash tests/maintenance_units_155.sh
python3 tests/automatic_health_156.py
```

The managed-policy test requires an isolated root-owned fixture (CI runs it on a disposable runner using `sudo`; normal unprivileged execution tests legacy/transport only). Do not run application tests as root on a real production installation. Verify the policy with the dedicated site account on staging, and rehearse active/revoked signatures, below-floor releases, TLS, actual systemd mount isolation, interrupted installation and emergency rollback before enabling unattended updates. No private test key or pre-authorized production policy is shipped.
