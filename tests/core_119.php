<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use CajeerEngine\Lite;
use CajeerEngine\Auth\{AuthAcceptance,AuthPolicy,OidcClient,OidcFlow,OwnerIdentity,RecoveryService,SessionRegistry};
use CajeerEngine\Storage\SiteState;

function ok119(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('1.1.9 acceptance: '.$message);
}
function fails119(callable $work, string $message): void {
    try { $work(); } catch (RuntimeException|InvalidArgumentException|JsonException $e) { return; }
    throw new RuntimeException('1.1.9 accepted unsafe action: '.$message);
}
function b119(string $raw): string { return rtrim(strtr(base64_encode($raw),'+/','-_'),'='); }
function del119(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (is_dir($path)) { foreach (new FilesystemIterator($path) as $item) del119($item->getPathname()); rmdir($path); }
}
function origin119(): string { return 'https://lite.example.test'; }

ok119(Lite::VERSION === '1.1.9' && SiteState::SCHEMA === 12, 'version and compatible schema');
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
ok119($key instanceof OpenSSLAsymmetricKey, 'RSA key generation');
$details=openssl_pkey_get_details($key);
$issuer=OidcClient::ISSUER;
$clientId='acceptance-119';
$metadata=['issuer'=>$issuer, 'authorization_endpoint'=>$issuer.'/protocol/openid-connect/auth',
    'token_endpoint'=>$issuer.'/protocol/openid-connect/token', 'jwks_uri'=>$issuer.'/protocol/openid-connect/certs',
    'code_challenge_methods_supported'=>['S256'], 'response_types_supported'=>['code']];
$jwks=['keys'=>[['kid'=>'key119', 'kty'=>'RSA', 'alg'=>'RS256', 'use'=>'sig',
    'n'=>b119($details['rsa']['n']), 'e'=>b119($details['rsa']['e'])]]];
$sign=static function(array $claims, bool $logout=false)use($key): string {
    $h=b119(json_encode(['alg'=>'RS256','kid'=>'key119','typ'=>$logout?'logout+jwt':'JWT'], JSON_THROW_ON_ERROR));
    $p=b119(json_encode($claims,JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT));
    openssl_sign($h.'.'.$p, $sig, $key, OPENSSL_ALGO_SHA256);
    return $h.'.'.$p.'.'.b119($sig);
};
$nonce=''; $fault=''; $calls=[];
$driver=static function(string $url,string $method,array $headers,string $body)use(&$nonce,&$fault,&$calls,$metadata,$jwks,$clientId,$issuer,$sign):array {
    $calls[]=$url;
    if (str_ends_with($url,'openid-configuration')) return [200,json_encode($metadata,JSON_THROW_ON_ERROR)];
    if (str_ends_with($url,'/certs')) return [200,json_encode($jwks,JSON_THROW_ON_ERROR)];
    if (str_ends_with($url,'/token')) {
        parse_str($body,$values);
        ok119($method==='POST' && isset($values['code_verifier']) && strlen($values['code_verifier'])===43,
            'PKCE verifier required on code exchange');
        ok119(($values['redirect_uri']??null)===origin119().'/admin/oidc/callback', 'exact redirect');
        $exp=time()+300;
        if ($fault==='expired_id_token') $exp=time()-1;
        $claims=['iss'=>$issuer, 'sub'=>'owner-119', 'aud'=>$clientId, 'nonce'=>$nonce,
            'iat'=>time()-30, 'auth_time'=>time()-20, 'exp'=>$exp, 'sid'=>'remote-119'];
        $reply=['id_token'=>$sign($claims), 'access_token'=>'opaque-access-119', 'token_type'=>'Bearer'];
        if ($fault==='missing_access') unset($reply['access_token']);
        return [200,json_encode($reply,JSON_THROW_ON_ERROR)];
    }
    throw new RuntimeException('Unexpected OIDC endpoint');
};
$client=new OidcClient($clientId,'strong-acceptance-secret-119',origin119(),$driver);
$flow=static function(string $mode)use($client,&$nonce,$issuer):array {
    $session=[];
    $url=OidcFlow::start($session,$client,$mode);
    parse_str((string)parse_url($url,PHP_URL_QUERY),$args);
    $nonce=$args['nonce'];
    return OidcFlow::finish($session,$client,['state'=>$args['state'],'code'=>'valid-code-119','iss'=>$issuer]);
};
$identity=$flow('login')['identity'];
ok119($identity===['iss'=>$issuer,'sub'=>'owner-119','sid'=>'remote-119'], 'signed interactive login verified');
$fault='missing_access';
fails119(fn()=>$flow('login'), 'code token response without access token');
$fault='expired_id_token';
fails119(fn()=>$flow('login'), 'ID token expired by one second');
$fault='';
$malformedAud=['iss'=>$issuer,'sub'=>'owner-119','nonce'=>'test-nonce', 'aud'=>['client'=>$clientId],
    'iat'=>time()-10,'exp'=>time()+120];
fails119(fn()=> $client->verifyIdToken($sign($malformedAud),'test-nonce'), 'OIDC audience JSON object');
$malformedAud['aud']=[$clientId,$clientId];
fails119(fn()=> $client->verifyIdToken($sign($malformedAud),'test-nonce'), 'duplicate OIDC audience');
$logout=['iss'=>$issuer,'aud'=>$clientId,'iat'=>time(),'exp'=>time()+180,
    'jti'=>'acceptance-logout-119', 'sub'=>'owner-119', 'sid'=>'remote-119',
    'events'=>['http://schemas.openid.net/event/backchannel-logout'=>new stdClass()]];
ok119($client->verifyLogoutToken($sign($logout,true))['jti']==='acceptance-logout-119', 'signed back-channel logout verified');
$badEvent=$logout; $badEvent['events']['some-other-event']=new stdClass();
fails119(fn()=> $client->verifyLogoutToken($sign($badEvent,true)), 'extra logout event');
$badEvent=$logout; $badEvent['events']['http://schemas.openid.net/event/backchannel-logout']=(object)['unexpected'=>1];
fails119(fn()=> $client->verifyLogoutToken($sign($badEvent,true)), 'nonempty logout event');

$root=sys_get_temp_dir().'/lite-acceptance119-'.bin2hex(random_bytes(8));
mkdir($root,0700,true);
try {
    $app=new Lite($root);
    $password='AcceptancePassword1234';
    $app->install($password,origin119(),'Real migration and identity acceptance');
    $seed=$app->load();
    $seed['pages']['/']['body']='Acceptance document retained across upgrades';
    $seed['integrations']['ga4']['measurement_id']='G-1234567890';
    $seed['integrations']['ga4']['enabled']=true;
    $seed['reporting']['ga4_property_id']=''; // Keep valid reporting defaults.
    // Compare migrations of actual persistent storage, not fabricated API responses.
    foreach ([9,10,11] as $version) {
        $legacy=$seed;
        $legacy['schema']=$version;
        if ($version===9) { unset($legacy['identity'],$legacy['auth_policy']); }
        if ($version===10) {
            $legacy['identity']=['owner'=>['iss'=>$issuer,'sub'=>'legacy-owner-119']];
            unset($legacy['auth_policy']);
        }
        if ($version===11) {
            $legacy['identity']=['owner'=>['iss'=>$issuer,'sub'=>'legacy-owner-119'],
                'generation'=>OwnerIdentity::generation()];
            unset($legacy['auth_policy']);
        }
        SiteState::validate($legacy);
        file_put_contents($root.'/storage/site.json', SiteState::encode($legacy));
        $original=file_get_contents($root.'/storage/site.json');
        ok119($app->migrate(), 'migration '.$version.'->12 ran');
        $upgraded=$app->load();
        ok119($upgraded['schema']===12 && $upgraded['pages']===$seed['pages'] &&
            $upgraded['integrations']===$seed['integrations'] && $upgraded['password']===$seed['password'],
            'migration '.$version.' preserves actual website and analytics configuration');
        ok119($upgraded['auth_policy']===AuthPolicy::DEFAULTS, 'migration '.$version.' initializes hybrid policy');
        if ($version>=10) ok119($upgraded['identity']['owner']['sub']==='legacy-owner-119', 'existing owner preserved');
        else ok119($upgraded['identity']['owner']===null, 'unbound legacy site remains unbound');
        $backupFound=false;
        foreach ($app->backups()->names() as $name) {
            $app->backups()->verify($name);
            $json=json_decode(file_get_contents($root.'/storage/backups/'.$name),true,8,JSON_THROW_ON_ERROR);
            if (base64_decode($json['payload'],true)===$original) $backupFound=true;
        }
        ok119($backupFound, 'pre-migration backup contains exact original bytes for '.$version);
        ok119(!$app->migrate(), 'idempotent migration '.$version);
    }
    // Test the real session registry and owner actions on a migrated installation.
    $site=$app->load();
    $app->change(static function(array $state) use ($issuer):array {
        $new=['iss'=>$issuer,'sub'=>'owner-119'];
        return $state['identity']['owner']===null
            ? OwnerIdentity::bind($state,$new)
            : OwnerIdentity::replace($state,$state['identity']['owner'],$new,$state['identity']['generation']);
    });
    $site=$app->load();
    $sessions=new SessionRegistry($root);
    $local=[]; $oidc=['oidc_identity'=>['iss'=>$issuer,'sub'=>'owner-119'],'oidc_sid'=>'remote-119'];
    $sessions->issue($local,$site,['REMOTE_ADDR'=>'192.0.2.100']);
    $sessions->issue($oidc,$site,['REMOTE_ADDR'=>'192.0.2.101']);
    ok119($sessions->valid($local,$site)&&$sessions->valid($oidc,$site), 'both login modes and independent leases');
    $stats=$sessions->inspect($site);
    ok119($stats['active']===2 && $stats['local']===1 && $stats['oidc']===1,'locked session inspection');
    ok119($sessions->backchannelLogout($client->verifyLogoutToken($sign($logout,true)),$site)===1,
        'verified logout revokes one matching OIDC lease');
    ok119(!$sessions->valid($oidc,$site) && $sessions->valid($local,$site), 'local device survives SSO logout');
    ok119($sessions->backchannelLogout($client->verifyLogoutToken($sign($logout,true)),$site)===0,
        'logout replay is idempotent');
    $savedBackup=$app->backups()->create();
    $app->backups()->verify($savedBackup);
    $app->change(static fn(array $state): array=>AuthPolicy::switch($state, AuthPolicy::OIDC_ONLY,
        $state['identity']['generation'],$state['auth_policy']['revision'],['iss'=>$issuer,'sub'=>'owner-119']));
    $restricted=$app->load();
    ok119(!AuthPolicy::localAllowed($restricted) && !$sessions->valid($local,$restricted),'SSO-only disables password and old leases');
    // A content restore cannot roll back the active authentication policy or revive sessions.
    $app->backups()->restore($savedBackup);
    $restored=$app->load();
    ok119($restored['auth_policy']['mode']===AuthPolicy::OIDC_ONLY && $restored['identity']===$restricted['identity'],
        'backup restore cannot downgrade SSO-only or revert owner generation');
    ok119(!$sessions->valid($local,$restored), 'backup cannot resurrect old lease');
    $withoutEnv=AuthAcceptance::run($root,$restored);
    ok119(!$withoutEnv['ok'], 'SSO-only missing OIDC credentials fails release diagnostics');
    putenv('CAJEER_OIDC_CLIENT_ID='.$clientId);
    putenv('CAJEER_OIDC_CLIENT_SECRET=strong-acceptance-secret-119');
    $diagnostics=AuthAcceptance::run($root,$restored);
    ok119($diagnostics['ok'] && $diagnostics['sessions']['active']===0 && count($diagnostics['manual'])===4,
        'real auth acceptance checks session storage, recovery and OIDC config');
    // The recovery command actually restores password login and revokes all SSO leases.
    $recovery=new RecoveryService($app);
    $grant=$recovery->issue();
    $newPassword='RecoveredAcceptancePass119';
    $recovery->complete($grant,$newPassword);
    $recovered=$app->load();
    ok119($recovered['auth_policy']['mode']===AuthPolicy::HYBRID &&
        $recovered['identity']['owner']===null && $app->login($newPassword) && !$app->login($password),
        'one-time CLI recovery enables new local credentials');
    fails119(fn()=> $recovery->complete($grant,'AnotherPassword119'), 'consumed recovery grant');
    $app->backups()->restore($savedBackup);
    $last=$app->load();
    ok119($last['identity']['owner']===null && $last['auth_policy']['mode']===AuthPolicy::HYBRID &&
        $app->login($newPassword), 'restore cannot undo emergency recovery');
    $entry=$root.'/storage/auth/sessions.json';
    $raw=file_get_contents($entry); chmod($entry,0644);
    ok119(!AuthAcceptance::run($root,$last)['ok'], 'unsafe session registry permissions fail release gate');
    chmod($entry,0600); file_put_contents($entry,$raw);
    ok119(AuthAcceptance::run($root,$last)['ok'], 'fixed registry permissions restore readiness');
} finally {
    putenv('CAJEER_OIDC_CLIENT_ID'); putenv('CAJEER_OIDC_CLIENT_SECRET');
    del119($root);
}
echo "Lite 1.1.9 signed OIDC/logout, migrations 9-12, owner, devices, SSO-only, backup and CLI recovery OK\n";
