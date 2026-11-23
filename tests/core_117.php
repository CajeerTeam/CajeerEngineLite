<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use CajeerEngine\Auth\{AuthPolicy,OwnerIdentity,OidcClient,OidcFlow,SessionRegistry,RecoveryService};
use CajeerEngine\Lite;
use CajeerEngine\Storage\SiteState;

function assert117(bool $yes, string $description): void { if (!$yes) throw new RuntimeException($description); }
function reject117(callable $fn, string $description): void {
    try { $fn(); } catch (RuntimeException|InvalidArgumentException|JsonException $ex) { return; }
    throw new RuntimeException('Unsafe action accepted: '.$description);
}
function b64117(string $input): string { return rtrim(strtr(base64_encode($input),'+/','-_'),'='); }
function rm117(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (is_dir($path)) { foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) rm117($entry->getPathname()); rmdir($path); }
}

assert117(version_compare(Lite::VERSION,'1.2.2','>=') && SiteState::SCHEMA===14,'application version and schema');
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
assert117($key instanceof OpenSSLAsymmetricKey,'RSA test key');
$details=openssl_pkey_get_details($key);
$issuer=OidcClient::ISSUER;
$discovery=['issuer'=>$issuer,'authorization_endpoint'=>$issuer.'/protocol/openid-connect/auth',
    'token_endpoint'=>$issuer.'/protocol/openid-connect/token',
    'jwks_uri'=>$issuer.'/protocol/openid-connect/certs','code_challenge_methods_supported'=>['S256']];
$jwks=['keys'=>[['kty'=>'RSA','alg'=>'RS256','use'=>'sig','kid'=>'policy-kid',
    'n'=>b64117($details['rsa']['n']),'e'=>b64117($details['rsa']['e'])]]];
$nonce=''; $subject='linked-owner'; $authTime=time();
$driver=static function(string $url,string $method,array $headers,string $body) use (&$nonce,&$subject,&$authTime,$discovery,$jwks,$key,$issuer): array {
    if (str_ends_with($url,'openid-configuration')) return [200,json_encode($discovery,JSON_THROW_ON_ERROR)];
    if (str_ends_with($url,'/certs')) return [200,json_encode($jwks,JSON_THROW_ON_ERROR)];
    if (str_ends_with($url,'/token')) {
        parse_str($body,$fields);
        assert117($method==='POST' && strlen($fields['code_verifier']??'')===43,'PKCE code verifier');
        $claims=['iss'=>$issuer,'sub'=>$subject,'aud'=>'lite-policy','nonce'=>$nonce,
            'iat'=>time(),'exp'=>time()+300,'auth_time'=>$authTime,'sid'=>'oidc-sid-117'];
        $header=b64117(json_encode(['alg'=>'RS256','typ'=>'JWT','kid'=>'policy-kid'],JSON_THROW_ON_ERROR));
        $payload=b64117(json_encode($claims,JSON_THROW_ON_ERROR));
        $data=$header.'.'.$payload;
        assert117(openssl_sign($data,$signature,$key,OPENSSL_ALGO_SHA256),'sign token');
        return [200,json_encode(['token_type'=>'Bearer','id_token'=>$data.'.'.b64117($signature),'access_token'=>'test-access'],JSON_THROW_ON_ERROR)];
    }
    throw new RuntimeException('Unexpected IdP URL');
};
$client=new OidcClient('lite-policy','very-secret-policy-key','https://lite.example.test',$driver);
$flow=function() use ($client,&$nonce): array {
    $session=[];
    $url=OidcFlow::start($session,$client,'auth_policy',['target'=>AuthPolicy::OIDC_ONLY]);
    parse_str((string)parse_url($url,PHP_URL_QUERY),$params);
    assert117(($params['prompt']??null)==='login' && ($params['max_age']??null)==='0','new interactive login required');
    $nonce=$params['nonce'];
    $result=OidcFlow::finish($session,$client,['state'=>$params['state'],'code'=>'verified-policy-code']);
    reject117(fn()=>OidcFlow::finish($session,$client,['state'=>$params['state'],'code'=>'verified-policy-code']),'callback replay');
    $identity=$result['identity']; unset($identity['sid']);
    return $identity;
};
$root=sys_get_temp_dir().'/cajeer-auth-policy-'.bin2hex(random_bytes(8));
mkdir($root,0700,true);
try {
    $app=new Lite($root);
    $app->install('OriginalPassword123!','https://lite.example.test','Policy test');
    $site=$app->load();
    assert117($site['schema']===14 && $site['auth_policy']===AuthPolicy::DEFAULTS && AuthPolicy::localAllowed($site),'fresh install is hybrid');
    $unbound=$site; $unbound['auth_policy']['mode']=AuthPolicy::OIDC_ONLY;
    reject117(fn()=>SiteState::validate($unbound),'SSO-only without a linked owner');
    foreach (['other','HYBRID',null,12] as $invalid) {
        $bad=$site; $bad['auth_policy']['mode']=$invalid;
        reject117(fn()=>SiteState::validate($bad),'invalid mode');
    }
    $bad=$site; $bad['auth_policy']['revision']=0;
    reject117(fn()=>SiteState::validate($bad),'invalid revision');
    reject117(fn()=>AuthPolicy::switch($site,AuthPolicy::OIDC_ONLY,$site['identity']['generation'],1,
        ['iss'=>$issuer,'sub'=>'linked-owner']),'cannot change unbound owner');
    // The original schema 11 must upgrade with no loss of settings, pages or owner identity.
    $old=$site; $old['schema']=11; unset($old['auth_policy']);
    file_put_contents($app->file(),SiteState::encode($old));
    $migrated=$app->load();
    assert117($migrated['schema']===14 && $migrated['auth_policy']===AuthPolicy::DEFAULTS &&
        $migrated['pages']===$old['pages'] && $migrated['password']===$old['password'],'11->12 migration');
    assert117(count(array_filter($app->backups()->names(),
        fn(string $name): bool => $app->backups()->verify($name)['schema']===11))===1,'migration backup');
    $owner=['iss'=>$issuer,'sub'=>'linked-owner'];
    $site=$app->change(fn(array $data): array => OwnerIdentity::bind($data,$owner));
    $generation=$site['identity']['generation'];
    $registry=new SessionRegistry($root);
    $local=[]; $oidc=['oidc_identity'=>$owner,'oidc_sid'=>'oidc-sid-117'];
    $registry->issue($local,$site);
    $registry->issue($oidc,$site);
    assert117($registry->valid($local,$site) && $registry->valid($oidc,$site),'both modes work in hybrid');
    $preSwitchBackup=$app->backups()->create();
    $subject='foreign-account';
    reject117(fn()=>AuthPolicy::switch($site,AuthPolicy::OIDC_ONLY,$generation,1,$flow()),'unlinked valid SSO account');
    $subject='linked-owner'; $authTime=time()-360;
    reject117(fn()=> $flow(),'stale authentication time');
    $authTime=time(); $identity=$flow();
    reject117(fn()=>AuthPolicy::switch($site,'other',$generation,1,$identity),'invalid target');
    reject117(fn()=>AuthPolicy::switch($site,AuthPolicy::HYBRID,$generation,1,$identity),'no-op mode');
    reject117(fn()=>AuthPolicy::switch($site,AuthPolicy::OIDC_ONLY,str_repeat('0',48),1,$identity),'wrong generation');
    reject117(fn()=>AuthPolicy::switch($site,AuthPolicy::OIDC_ONLY,$generation,2,$identity),'stale revision');
    $site=$app->change(fn(array $data): array => AuthPolicy::switch($data,AuthPolicy::OIDC_ONLY,$generation,1,$identity));
    assert117(!AuthPolicy::localAllowed($site) && !AuthPolicy::sessionAllowed($site,$local) &&
        AuthPolicy::sessionAllowed($site,$oidc),'local sign-in and old local sessions blocked');
    assert117(!$registry->valid($local,$site) && !$registry->valid($oidc,$site),'all old leases rotated');
    $newLocal=[];
    reject117(fn()=> $registry->issue($newLocal,$site),'session registry denies local method in SSO-only');
    $oidcFresh=['oidc_identity'=>$owner,'oidc_sid'=>'fresh-sid'];
    $registry->issue($oidcFresh,$site);
    assert117($registry->valid($oidcFresh,$site),'verified new OIDC lease works');
    reject117(fn()=>AuthPolicy::switch($site,AuthPolicy::HYBRID,$generation,1,$identity),'stale callback');
    // Restoring the old hybrid backup must keep live SSO-only and current owner.
    $app->backups()->restore($preSwitchBackup);
    $site=$app->load();
    assert117($site['auth_policy']['mode']===AuthPolicy::OIDC_ONLY && $site['identity']['owner']===$owner,
        'content restore cannot remove SSO policy or owner');
    // Return to hybrid requires another verified IdP step-up and rotates leases again.
    $nextGeneration=$site['identity']['generation'];
    $identity=$flow();
    $site=$app->change(fn(array $data): array => AuthPolicy::switch($data,AuthPolicy::HYBRID,$nextGeneration,2,$identity));
    assert117(AuthPolicy::localAllowed($site) && $site['auth_policy']['revision']===3,'SSO-only to hybrid');
    // Recovery from SSO-only requires only trusted server CLI, not a public bypass.
    $site=$app->change(fn(array $data): array => AuthPolicy::switch($data,AuthPolicy::OIDC_ONLY,
        $data['identity']['generation'],3,$identity));
    $recovery=new RecoveryService($app);
    $token=$recovery->issue();
    $recovery->complete($token,'NewPasswordForRecovery123!');
    $site=$app->load();
    assert117($site['auth_policy']['mode']===AuthPolicy::HYBRID && $site['identity']['owner']===null &&
        $app->login('NewPasswordForRecovery123!') && !$app->login('OriginalPassword123!'),
        'CLI recovery unlocks local mode and resets the lost SSO owner');
    reject117(fn()=> $recovery->complete($token,'AnotherPassword123!'),'one-time recovery');
    $app->backups()->restore($preSwitchBackup);
    assert117($app->load()['auth_policy']['mode']===AuthPolicy::HYBRID &&
        $app->load()['identity']['owner']===null && $app->login('NewPasswordForRecovery123!'),
        'protected recovery policy and credentials survive backup rollback');
    echo "Lite 1.1.7 policy, OIDC step-up, revocation, migration, recovery and backup tests OK\n";
} finally { rm117($root); }
