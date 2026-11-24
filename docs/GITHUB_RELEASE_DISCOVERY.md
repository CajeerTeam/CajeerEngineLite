# GitHub Release Discovery — 1.5.1

The public GitHub Releases discovery component is integrated into the existing CLI auto-updater, not a separate installer. It does not execute release code or trust GitHub metadata as a signature.

## Configuration and commands

```sh
export CAJEER_AUTO_UPDATE_GITHUB_REPO=CajeerTeam/CajeerEngineLite
export CAJEER_AUTO_UPDATE_CHANNEL=stable
php bin/cajeer update auto status
php bin/cajeer update auto check
```

Use the actual official public repository. No GitHub token is required or sent. The CLI requires verified TLS, a valid CA trust store, and connectivity to GitHub. A repository change invalidates cached metadata. `check` changes only private discovery/cache and status, not application files or site data.

- `stable` (default): excludes drafts and GitHub prereleases.
- `preview`: includes stable releases and GitHub prereleases with numeric `X.Y.Z` tags. Drafts are never eligible. Tags with suffixes such as `-rc.1` are not supported by the existing numeric-version package format. Channel switching never permits downgrades.
- Preview auto-install additionally requires `CAJEER_AUTO_UPDATE_ALLOW_PREVIEW_INSTALL=1`. All existing install opt-ins, pinned key, exact-base check, worker quiescence and maintenance window remain mandatory.

`check` returns `installed`, `available` (nearest newer eligible version), `latest` (highest eligible version), `channel`, `pages`, `revalidated_pages`, and `checked_at`. Each candidate contains version, tag, asset URL, declared bytes, optional SHA-256 and prerelease flag. A null `available` means the completed scan found no eligible newer package, not that every GitHub release is compatible. `latest` is informational; it is not automatically selected for installation.

## Pagination and network failure

The client reads 30 releases per page, up to 20 pages, constructing each API URL itself (never navigating to an arbitrary Link URL). A page with no successor ends the scan. If more pages exist after the configured hard limit, discovery fails instead of selecting from an incomplete catalog. Duplicate release IDs across pages or duplicate eligible versions fail closed. GitHub pagination is not a transactional snapshot; retry a scan if a release is published concurrently.

Each successful scan atomically writes private `storage/app/updates/auto/discovery.json` (0600), storing only selection metadata and valid ETags per page, scoped to the configured repository. Every subsequent check revalidates every needed page; HTTP 304 requires matching cached metadata. Without an ETag the page is fetched normally. No stale offline result is used to select an installation. Cache is bounded (12 MB); invalid JSON is discarded and refreshed, unsafe paths, hard links and permissions fail closed. The `state.json` status reports failures without response bodies. To clear a damaged cache, stop the auto-update scheduler, inspect the private directory and remove only `discovery.json` as the CLI owner; this also removes cooldown state, so do not do this to bypass GitHub rate limits.

A metadata response is capped at 512,000 bytes, with a 20-second stream timeout/body budget and a 120-second scan budget checked before each attempt (an in-flight request may extend it). Transient transport errors, incomplete bodies, HTTP 408 and selected 5xx responses get at most three attempts per page (250/500 ms backoff). HTTP 429, 403 (including secondary rate-limit responses), and 503 with Retry-After trigger persisted cooldown, using Retry-After or X-RateLimit-Reset where available, otherwise 60 seconds. Cooldown is capped at 24 hours and survives process restarts. Failed scans return nonzero; `run` does not download/install from partial or stale results. Schedule the next check after cooldown; no long sleep is held inside CLI.

## Distribution compatibility

Only `CajeerEngineLite-X.Y.Z.cjrupdate`, with uploaded state, expected size and exact canonical GitHub repository release path, is eligible. Ambiguous assets, invalid digests and unrelated URL hosts or paths are excluded. The selected package still must pass RSA-SHA256, SHA-256, version, PHP and schema validation by the existing engine. This release does not add multi-base distribution, graph resolution, privileged PHP-FPM control or post-update automatic live rollback. An incompatible nearest package fails before touching installed files; do not bypass the signed `from` version. See [AUTOMATIC_UPDATES.md](AUTOMATIC_UPDATES.md) and [UPDATE_ENGINE.md](UPDATE_ENGINE.md).

## Regression and rollout

```sh
php tests/core_151.php
php tests/core_145.php
php tests/core_144.php
bash tests/automatic_updates_cli_145.sh
php bin/cajeer doctor
```

The discovery tests drive the actual metadata decoder and selection/cache logic with controlled HTTP response streams; the existing updater test installs and recovers a real signed package with temporary keys. Run `update auto check` against the official repository on staging to verify real network and release availability before enabling automatic installation. No official GitHub publication or live production sign-off is implied by local fixtures.
