# Release engineering — 1.4.8+

## Inputs and build

Use two **clean source exports**, not running CMS directories. Python 3.11+, PHP 8.4.1+, OpenSSL CLI and Git are required on the build machine. The release script lives at `tests/release_build_148.py` so the 1.4.7 updater can deploy it. It builds real artifacts through the *base* Update Engine and fails if any changed deployment path was silently omitted. It rejects private storage, unknown files, symlinks and non-regular files. This is a path/inventory check, not a content secret scanner; review the source and scan for secrets separately. Protect the build host and use trusted source commits only. A source export is never a live backup.

Example using exact approved tags:

```sh
umask 077
work=$(mktemp -d)
mkdir "$work/base" "$work/next"
git archive refs/tags/v1.4.7 | tar -x -C "$work/base"
git archive refs/tags/v1.4.8 | tar -x -C "$work/next"
python3 tests/release_build_148.py --base "$work/base" --candidate "$work/next" \
  --output "$work/dist" --signing-key /secure/release-private.pem \
  --trusted-key /secure/release-trusted.pem
```

The output directory must not exist. Keep it in a private operator-owned directory. Run on a disposable build runner with no live provider credentials. Both source versions must agree with `core/Lite.php`; adjacent patch/minor/major releases are allowed, not arbitrary jumps. The exact previous tag must reflect deployed base hashes. Keep the signing and independent trusted key outside the source trees, mode 0600 for the private key. Generate and provision keys using UPDATE_ENGINE.md. Never commit a private key or use a test key for a published release.

## Actual build checks

1. Create RSA-SHA256 `.cjrupdate` with the base updater and independently compare signed manifest paths and before/after hashes to the actual source diff. Fail on unsupported runtime changes (e.g. `router.php` with the 1.4.7 base). `.github/workflows/release.yml`, CODEOWNERS and FUNDING are explicitly repository-only changes.
2. Generate a sorted, normalized full ZIP, verify its inventory/CRC/SHA-256, unpack into a temporary empty installation, initialize and run `doctor`, security audit and loopback HTTP readiness, home, robots and sitemap.
3. Initialize a second copy of the base, verify/install the exact package through CLI, compare deployed code bytes to target and site SHA-256 to pre-update state, check HTTP and diagnostics. Roll back through CLI and compare original bytes and site data. Flip a package byte and verify rejection. No live site is touched.
4. Sign the checksum list with the release key, verify it against the independently supplied public key and publish only the complete output directory. The JSON receipt always marks `production_accepted: false`.

Expected files: `CajeerEngineLite-<version>.cjrupdate` (incremental update, filename matches auto-discovery), `CajeerEngineLite-<version>.zip` (full clean install), `release-report.json`, `SHA256SUMS`, `SHA256SUMS.sig`. ZIP and RSA PKCS#1 v1.5 signed package are reproducible for identical clean source bytes, relevant file modes, key and toolchain; ZIP compression can differ across zlib versions. The receipt records PHP version, complete source-tree inventory digests, checks, and (when supplied by the tagged workflow) exact commit IDs; reproducibility is not a cross-toolchain guarantee.

```sh
cd "$work/dist"
openssl dgst -sha256 -verify /secure/release-trusted.pem -signature SHA256SUMS.sig SHA256SUMS
sha256sum --strict -c SHA256SUMS
```

## GitHub release workflow

Configure repository tag protection (approved immutable `vX.Y.Z` tags), restrict workflow edits and release permissions, and create `release-signing` and `release-publish` environments with required reviewers and protected tag deployment restrictions. Configure environment secret `CAJEER_RELEASE_PRIVATE_KEY` (PEM) **only** for signing; set `CAJEER_RELEASE_PUBLIC_KEY` (independently pinned public PEM) as a trusted variable accessible to both environments. Review the exact tagged code including old updater and release script: signing runs trusted repository code, and an untrusted commit could read its key. Use an isolated runner; review and pin third-party action revisions per repository policy. Never expose the signing environment to pull requests or forks.

After reviewing source and staging results, merge the release workflow into the default branch and create a protected numeric version tag. Since 1.5.8 the tag triggers the workflow with the highest earlier published stable base. For bootstrap or additional bases, select **Signed Lite release** in Actions, run it on the target tag and supply the exact previous tag. See [RELEASE_AUTOMATION_MONITORING.md](RELEASE_AUTOMATION_MONITORING.md). The workflow checks tag/version and ancestry, runs the reusable full CI suite and a separate disposable-key exact tag rehearsal, then waits for protected signing approval. It builds and verifies official artifacts, uploads only the output files, then a separate write-permission publication job verifies checksum signature, checksum list, versions and unchanged tag commits. It creates a draft, uploads all assets, downloads and re-verifies their exact signed inventory against the approved build, rechecks tag commits and draft state, and only then publishes. Existing releases are not overwritten. If a draft remains after failure, inspect and resolve it manually; do not replace an already published artifact. GitHub permissions, secrets, environment approval and actual API execution require the maintainer to configure and run the workflow; a local ZIP cannot certify them.

## Release gates

Run `python3 tests/release_engineering_148.py` (disposable keys, install/remove/rollback, deterministic artifacts, dirty tree and wrong-key failures), existing CI, and [PRODUCTION_ACCEPTANCE.md](PRODUCTION_ACCEPTANCE.md). Staging must test the real target infrastructure, external providers, browser navigation, rollback from independent backup and monitoring. Do not publish as production-accepted without recording reviewer, time, environment, evidence and unresolved issues. Keep release notes, old packages and public trust history; no overwriting tagged artifacts.

### Production release candidate (1.4.9)

The exact-tag rehearsal uses a populated site and checks page delivery, media hashes and verified TAR backup before/after upgrade and rollback. CI also runs `tests/rc_staging_149.py` with Nginx and actual certificate validation. Official 1.4.9 publication must be a GitHub prerelease (not latest). Auto-update discovery skips prereleases; install manually on staging with a pinned trusted key. Do not mark 1.4.9 stable to bypass the 1.5 acceptance gate. See RELEASE_CANDIDATE.md.

## 1.5.0 stable publication

Dispatch on v1.5.0 with base_tag v1.4.8 (last stable). The protected release-publish reviewer must review the signed artifacts and actual staging/CI/provider/rollback evidence before allowing publication. The receipt includes the signed runtime inventory. The release report never asserts production acceptance. Each installation requires its own [production gate and sign-off](PRODUCTION_STABLE.md). For an existing 1.4.9 installation, build a separate exact-base signed transition outside the official channel or use a verified manual patch.

## Multiple exact bases (1.5.2)

See [UPDATE_DISTRIBUTION.md](UPDATE_DISTRIBUTION.md) for signed index, optional `extra_base_tags` and verification. The primary legacy checksum inventory remains unchanged.
