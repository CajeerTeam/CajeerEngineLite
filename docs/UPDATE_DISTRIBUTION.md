# Update Package Distribution 1.5.2

`php bin/cajeer update auto check` now reads signed `update-index.json` assets from eligible GitHub Releases. The index is a JSON envelope with base64 `payload` and `signature`; the payload is UTF-8 JSON containing format 1, product, target version and up to 16 exact-base packages (from, name, bytes, SHA-256). RSA-SHA256 uses the same configured `CAJEER_UPDATE_TRUSTED_KEY_FILE` as the updater. Metadata must match the official repository/tag asset URL and size; each actual package still undergoes signature, exact installed base, schema, PHP and file precondition checks. The index cannot authorize installation by itself.

When signed indexes exist, only their edges are used; discovery rejects incomplete, tampered or unreachable distributions. `plan` lists the shortest signed route to the highest reachable version, `available` is the first step, `latest` the reachable destination. One transition per `update auto run`; restart/reload PHP workers and re-run discovery in a subsequent approved window. Preview install requires separate opt-in for each prerelease step. Legacy releases without indexes still use the prior nearest-release behavior when no indexed releases are visible (exact package compatibility is checked before installation). A legacy installation with no signed path needs a manually verified bridge update. GitHub metadata is not an authenticity guarantee and discovery cannot detect a withheld release.

## Publishing

Use clean exports of exact supported tags (not edited VERSION files). Existing workflow `Signed Lite release` accepts `base_tag` (primary adjacent base) and optional `extra_base_tags` (space-separated, up to 15 distinct earlier versions). The primary asset retains `CajeerEngineLite-<to>.cjrupdate` for old clients. Extra assets are `CajeerEngineLite-<from>-to-<to>.cjrupdate`. The original five release assets/checksum protocol remain unchanged; the signed index separately authenticates all packages. Every base uses its own updater to build, install, verify data and roll back. Unsupported old updater transitions fail the build. Documentation under `docs/` is source-only for code updates (included in full ZIP); existing updater does not manage that path. Refresh it separately if needed.

Local release build (keys outside source and private key mode 0600):

```sh
python3 tests/release_distribution_152.py --base /srv/src/1.5.1 \
  --extra-base /srv/src/1.5.0 --candidate /srv/src/1.5.2 \
  --output /srv/releases/1.5.2 --signing-key /secure/private.pem \
  --trusted-key /etc/cajeer/trust.pem
python3 tests/release_distribution_152.py --verify /srv/releases/1.5.2 \
  --target 1.5.2 --trusted-key /etc/cajeer/trust.pem
```

No keys ship with the CMS. GitHub publication still requires protected signing and publish environments. Do not claim production sign-off based on these rehearsals. Existing workers must be quiesced by the operator; scheduler and automatic service orchestration are later releases.
