<?php
declare(strict_types=1);
namespace CajeerEngine\Auth;

use CajeerEngine\Lite;
use CajeerEngine\Storage\SiteState;

/** Inspect the real authorization storage and deploy-time prerequisites without contacting the IdP. */
final class AuthAcceptance
{
    /** @return array{ok:bool,checks:list<array{id:string,status:string,message:string}>,sessions:?array,manual:list<string>} */
    public static function run(string $root, array $site): array
    {
        $checks = [];
        $ok = true;
        $add = static function (string $id, string $status, string $message) use (&$checks, &$ok): void {
            if ($status === 'fail') $ok = false;
            $checks[] = compact('id', 'status', 'message');
        };
        $mode = $site['auth_policy']['mode'] ?? null;
        try {
            SiteState::validate($site);
            if ($site['schema'] !== SiteState::SCHEMA) throw new \RuntimeException('Site migration pending');
            AuthPolicy::validate($site['auth_policy']);
            $add('state', 'pass', 'Schema '.SiteState::SCHEMA.' and authentication policy validated');
        } catch (\Throwable) {
            $add('state', 'fail', 'Invalid or outdated site authorization state');
            return ['ok'=>false,'checks'=>$checks,'sessions'=>null,'manual'=>self::manual()];
        }
        $owner = $site['identity']['owner'];
        if ($mode === AuthPolicy::OIDC_ONLY && !OwnerIdentity::validSubject($owner)) {
            $add('owner', 'fail', 'SSO-only requires a linked owner');
        } elseif ($owner !== null) {
            $add('owner', 'pass', 'Owner identity is bound by issuer and subject');
        } else {
            $add('owner', 'pass', 'No external owner linked; local recovery path remains available');
        }
        $origin = $site['settings']['url'];
        $requiresOidc = $mode === AuthPolicy::OIDC_ONLY;
        $configured = getenv('CAJEER_OIDC_CLIENT_ID') !== false || getenv('CAJEER_OIDC_CLIENT_SECRET') !== false;
        $ready = false;
        if (!$configured) {
            $add('oidc_configuration', $requiresOidc ? 'fail' : ($owner ? 'warn' : 'pass'),
                $requiresOidc ? 'SSO-only site has no OIDC client credentials' : 'OIDC credentials are not configured');
        } else {
            try {
                if (!extension_loaded('openssl')) throw new \RuntimeException('OpenSSL is unavailable');
                OidcClient::fromEnvironment($origin); // constructor validates credentials and HTTPS redirect origin
                $ready = true;
                $add('oidc_configuration', 'pass', 'OIDC client credentials and HTTPS origin are valid');
            } catch (\Throwable) {
                $add('oidc_configuration', $requiresOidc ? 'fail' : 'warn',
                    'Invalid OIDC credentials, OpenSSL or HTTPS origin');
            }
        }
        // Fail closed for SSO-only even when environment unexpectedly looks configured.
        if ($requiresOidc && (!$ready || !str_starts_with($origin, 'https://'))) {
            $add('sso_prerequisites', 'fail', 'SSO-only cannot operate without a valid HTTPS OIDC client');
        } else {
            $add('sso_prerequisites', 'pass', 'Authentication mode has a usable entry path');
        }
        $siteFile = $root.'/storage/site.json';
        clearstatcache(true, $siteFile);
        $stat = @lstat($siteFile);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 ||
            ($stat['mode'] & 0077) !== 0) {
            $add('site_permissions', 'fail', 'Site state must be a private, regular 0600 file');
        } else {
            $add('site_permissions', 'pass', 'Site state is private and not a symlink');
        }
        $sessionStats = null;
        try {
            $registry = new SessionRegistry($root);
            $registry->phpSessionDirectory();
            $sessionStats = $registry->inspect($site);
            $add('session_registry', 'pass', 'Session registry parsed, expired leases pruned and storage verified');
        } catch (\Throwable) {
            $add('session_registry', 'fail', 'Session registry or private PHP session storage is unsafe or unavailable');
        }
        try {
            $service = new RecoveryService(new Lite($root));
            $service->status(); // validates one-time grant and its lock file, if present
            RecoveryService::restoredCredentials($root, $site); // validate protected backup-restore credentials
            $add('recovery', 'pass', 'CLI recovery and protected credentials can be read safely');
        } catch (\Throwable) {
            $add('recovery', 'fail', 'CLI recovery metadata or private file storage is unsafe');
        }
        return ['ok'=>$ok,'checks'=>$checks,'sessions'=>$sessionStats,'manual'=>self::manual()];
    }

    private static function manual(): array
    {
        return [
            'Verify the registered SkiF4er ID callback and post-logout redirect URI on staging with HTTPS.',
            'Complete a live OIDC code+PKCE login, owner reauthentication and unauthorized-account rejection.',
            'Confirm Back-Channel Logout reaches this installation and revokes only matching devices.',
            'Exercise recovery from a lost SSO identity and restore a backup with the web application stopped.',
        ];
    }
}
