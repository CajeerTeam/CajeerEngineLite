<?php
declare(strict_types=1);
namespace CajeerEngine\Auth;

use CajeerEngine\Lite;
use CajeerEngine\Storage\SiteState;

/** Release gate based on actual storage/code checks, with optional live HTTPS probes.
 * Human-operated account sign-in and IdP registration cannot be self-certified.
 */
final class StableAcceptance
{
    /** @return array{version:string,automated_ok:bool,live_checked:bool,live_ok:bool,production_accepted:bool,status:string,checks:array,manual:list<string>} */
    public static function run(string $root, array $site, bool $live=false, mixed $oidcDriver=null, mixed $siteDriver=null): array
    {
        $base=AuthAcceptance::run($root,$site);
        $checks=$base['checks'];
        $add=static function(string $id,string $status,string $message)use(&$checks):void {
            $checks[]=compact('id','status','message');
        };
        $add('release_version',Lite::VERSION==='1.2.1' && $site['schema']===SiteState::SCHEMA ? 'pass':'fail',
            'Lite 1.2.1 requires current JSON schema '.SiteState::SCHEMA);
        $origin=$site['settings']['url'];
        $owner=$site['identity']['owner']??null;
        $add('owner_bound',OwnerIdentity::validSubject($owner)?'pass':'fail',
            'Production SSO requires a securely bound SkiF4er ID owner');
        $configReady=false;
        try {
            if (!extension_loaded('openssl')) throw new \RuntimeException('OpenSSL unavailable');
            $client=$oidcDriver===null ? OidcClient::fromEnvironment($origin) :
                new OidcClient((string)getenv('CAJEER_OIDC_CLIENT_ID'),
                    (string)getenv('CAJEER_OIDC_CLIENT_SECRET'),$origin,$oidcDriver);
            $configReady=true;
            $add('oidc_client','pass','OIDC client credentials and canonical redirect URI are valid');
        } catch (\Throwable $e) {
            $add('oidc_client','fail','Production OIDC client is missing or invalid (no secrets displayed)');
        }
        foreach (['bootstrap.php','public/index.php','router.php','bin/cajeer',
                  'core/Auth/OidcClient.php','core/Auth/OidcFlow.php','core/Auth/SessionRegistry.php',
                  'core/Auth/RecoveryService.php','core/Auth/OwnerIdentity.php',
                  'core/Auth/StableAcceptance.php'] as $rel) {
            $path=$root.'/'.$rel;
            clearstatcache(true,$path);
            $stat=@lstat($path);
            $safe=is_array($stat) && ($stat['mode'] & 0170000)===0100000 &&
                $stat['nlink']===1 && ($stat['mode'] & 0022)===0;
            $add('immutable.'.$rel,$safe?'pass':'fail',
                $safe?'Executable code is not group/world writable: '.$rel:
                    'Missing or unsafe executable code: '.$rel);
        }
        $liveOk=false;
        if ($live) {
            try {
                if (!$configReady) throw new \RuntimeException('OIDC client unavailable');
                $provider=$client->probeProvider();
                $add('live.discovery','pass','Verified SkiF4er discovery, logout endpoint and '.
                    $provider['signing_keys'].' usable RSA signing key(s)');
                $add('live.backchannel_capability', $provider['backchannel_advertised']?'pass':'warn',
                    $provider['backchannel_advertised']?'Provider advertises Back-Channel Logout':
                        'Provider does not advertise Back-Channel Logout; confirm client registration manually');
            } catch (\Throwable $e) {
                $add('live.discovery','fail','SkiF4er ID live Discovery/JWKS verification failed');
            }
            try {
                $http=DeploymentProbe::run($origin,$siteDriver);
                $checks=array_merge($checks,$http['checks']);
            } catch (\Throwable $e) {
                $add('live.deployment','fail','Public application HTTPS probe could not run');
            }
            $liveOk=!in_array('fail',array_column(array_filter($checks,
                static fn(array $check):bool=>str_starts_with($check['id'],'live.') || str_starts_with($check['id'],'http.')),'status'),true);
        } else {
            $add('live.probes','pending','Run auth release-check --live to verify actual HTTPS deployment and SkiF4er ID metadata/JWKS');
        }
        $automatedOk=!in_array('fail',array_column($checks,'status'),true) && $base['ok'];
        $manual=[
            'Register and verify the exact callback URI: '.rtrim($origin,'/').'/admin/oidc/callback',
            'Verify Keycloak Back-Channel Logout URL and the registered post-logout redirect on the real client.',
            'Complete live PKCE login as the linked owner; reject a different valid SkiF4er ID account.',
            'With two actual browsers, revoke one session; verify the other stays authenticated.',
            'Log out from SkiF4er ID and confirm the IdP Back-Channel Logout reaches Lite and revokes its session.',
            'Test CLI recovery and restore of a verified backup on staging; confirm that old SSO access stays revoked.',
        ];
        // Live probes are read-only and cannot assert that a real user signed in,
        // or that Keycloak delivered a back-channel logout. Never claim production accepted.
        return ['version'=>Lite::VERSION,'automated_ok'=>$automatedOk,
            'live_checked'=>$live,'live_ok'=>$live && $liveOk,
            'production_accepted'=>false,
            'status'=>!$automatedOk?'failed':($live?'pending_manual':'pending_live'),
            'checks'=>$checks,'manual'=>$manual];
    }
}
