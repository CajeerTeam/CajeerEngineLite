# Security and vulnerability handling

## Supported deployment

CajeerEngine Lite 1.4.8 is a single-site, single-node, local-file CMS. Follow INSTALL.md and COMPATIBILITY.md. Do not treat this release as an independent penetration-test or CVE certification. Keep PHP, OpenSSL, system packages and any optional build dependencies updated through trusted distribution channels.

- Document root must be `public/`. Private state, TAR, templates and recovery files must never be served. Restrict filesystem permissions; HTTP workers may write only required storage. Do not enable template writes (`CAJEER_DEVELOPER_TEMPLATE_WRITE`) except during authorized maintenance.
- Use HTTPS, correct canonical URL, secure cookies and exact trusted proxy IPs; firewall the origin. The proxy must overwrite forwarded host/protocol. Do not forward arbitrary client headers as trusted signals. Disable unused providers, rotate credentials and keep private keys and provider tokens outside source and public paths.
- Use a unique long admin password; configure SkiF4er ID/OIDC with exact registered callback and trusted provider settings if enabled. Verify actual account login, logout and revocation on staging. Do not reuse production IdP or AI credentials in CI. Review auth logs without logging tokens or passwords.
- Keep signing private keys in a protected build environment. Independently distribute the RSA public key and review fingerprint changes. Never trust a key supplied by a release download. Do not accept unsigned packages or use `--insecure` TLS options. Require protected tag and environment approval before signing. If a key is compromised, disable automatic updates, revoke the compromised trust key by your host provisioning process, independently install a new key and review installed versions before resuming. Existing signatures do not expire by themselves.
- Only import trusted, verified site exports; never extract arbitrary TAR into the document root. Preserve rollback markers if recovery fails. No private site data belongs in a release artifact.

## Audit and incident response

Run `php bin/cajeer security audit --json`, `php bin/cajeer compatibility check --json`, and the security/HTTP regression suites. The offline dependency inventory is not an online CVE scanner. Independently review current OS/PHP advisories and CI action changes; lock and review approved action revisions in your repository. For a suspected compromise, isolate the host, stop writers and automatic updates, preserve logs/backups and rotate affected secrets from a clean system. Recover from known-good code and storage, then verify login, sessions and external integrations.

## Responsible disclosure

Use the repository's private vulnerability reporting feature if enabled, or contact the maintainer privately through the repository's published contact channel. Include affected version, minimal reproduction in a disposable test environment, impact, and redacted diagnostic evidence. Do not post credentials, private data or exploit payloads publicly; do not test a third-party deployment without permission. There is no invented support SLA or promise of a bounty. Coordinate disclosure with the maintainer.

Implementation details and regression cases: [SECURITY_HARDENING.md](SECURITY_HARDENING.md), [STORAGE_RECOVERY.md](STORAGE_RECOVERY.md), [UPDATE_ENGINE.md](UPDATE_ENGINE.md).
