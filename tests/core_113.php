<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Auth\{OidcClient,OidcFlow,OwnerIdentity};
use CajeerEngine\Lite;
use CajeerEngine\Storage\SiteState;

function ok113(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function fails113(callable $fn, string $message): void {
    try { $fn(); } catch (RuntimeException|InvalidArgumentException $e) { return; }
    throw new RuntimeException('Unsafe operation succeeded: '.$message);
}
function b64_113(string $v): string { return rtrim(strtr(base64_encode($v),'+/','-_'),'='); }
function rm113(string $path): void {
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    if (is_dir($path)) { foreach (new DirectoryIterator($path) as $e) if (!$e->isDot()) rm113($e->getPathname()); rmdir($path); }
}
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
ok113($key instanceof OpenSSLAsymmetricKey, 'RSA signing key');
$details=openssl_pkey_get_details($key);
$issuer=OidcClient::ISSUER;
$jwks=['keys'=>[['kty'=>'RSA','use'=>'sig','alg'=>'RS256','kid'=>'binding-tests',
    'n'=>b64_113($details['rsa']['n']), 'e'=>b64_113($details['rsa']['e'])]]];
$discovery=['issuer'=>$issuer,
    'authorization_endpoint'=>$issuer.'/protocol/openid-connect/auth',
    'token_endpoint'=>$issuer.'/protocol/openid-connect/token',
    'jwks_uri'=>$issuer.'/protocol/openid-connect/certs','code_challenge_methods_supported'=>['S256']];
$sub='old-owner';
$authTime=time();
$useAuthTime=true;
$token=function(string $nonce) use (&$sub,&$authTime,&$useAuthTime,$key,$issuer): string {
    $header=b64_113(json_encode(['typ'=>'JWT','alg'=>'RS256','kid'=>'binding-tests'],JSON_THROW_ON_ERROR));
    $claims=['iss'=>$issuer,'sub'=>$sub,'aud'=>'lite-owner-test','nonce'=>$nonce,
        'iat'=>time(),'exp'=>time()+300];
    if ($useAuthTime) $claims['auth_time']=$authTime;
    $body=b64_113(json_encode($claims,JSON_THROW_ON_ERROR));
    $signed=$header.'.'.$body;
    ok113(openssl_sign($signed,$signature,$key,OPENSSL_ALGO_SHA256), 'sign ID Token');
    return $signed.'.'.b64_113($signature);
};
$nonce='';
$driver=static function(string $url,string $method,array $headers,string $body) use (&$nonce,$token,$discovery,$jwks,$issuer): array {
    if (str_ends_with($url,'openid-configuration')) return [200,json_encode($discovery,JSON_THROW_ON_ERROR)];
    if (str_ends_with($url,'/certs')) return [200,json_encode($jwks,JSON_THROW_ON_ERROR)];
    if (str_ends_with($url,'/token')) {
        parse_str($body,$args);
        ok113($method==='POST' && isset($args['code_verifier']), 'real code exchange/PKCE');
        return [200,json_encode(['id_token'=>$token($nonce),'token_type'=>'Bearer',
            'access_token'=>'unit-test-access'],JSON_THROW_ON_ERROR)];
    }
    throw new RuntimeException('Unexpected OIDC URL');
};
$client=new OidcClient('lite-owner-test','strong-secret-key-owner','https://lite.example.test',$driver);
$session=[];
$complete=function(string $mode, array $context=[])use(&$session,&$nonce,$client): array {
    $url=OidcFlow::start($session,$client,$mode,$context);
    parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
    $nonce=$query['nonce'];
    if ($mode!=='login') {
        ok113(($query['prompt']??null)==='login' && ($query['max_age']??null)==='0', 'fresh interactive OIDC auth');
    }
    $result=OidcFlow::finish($session,$client,['state'=>$query['state'],'code'=>'valid-code-123']);
    ok113($result['mode']===$mode && $result['context']===$context, 'verified OIDC context');
    fails113(fn()=>OidcFlow::finish($session,$client,['state'=>$query['state'],'code'=>'valid-code-123']), 'callback replay');
    return $result;
};
$root=sys_get_temp_dir().'/lite-owner-'.bin2hex(random_bytes(8));
mkdir($root,0700,true);
try {
    $app=new Lite($root);
    $app->install('ExtremelyStrongPassword123','https://lite.example.test','Owner binding');
    $state=$app->load();
    ok113(Lite::VERSION==='1.1.8' && SiteState::SCHEMA===12 && $state['schema']===12,'release baseline');
    $gen0=$state['identity']['generation'];
    ok113(preg_match('/^[a-f0-9]{48}$/D',$gen0)===1 && $state['identity']['owner']===null,'fresh identity');
    $old=['iss'=>$issuer,'sub'=>'old-owner'];
    $new=['iss'=>$issuer,'sub'=>'new-owner'];
    fails113(fn()=>OwnerIdentity::bind($state,['iss'=>'https://evil.test','sub'=>'wrong']), 'foreign issuer');
    fails113(fn()=>OwnerIdentity::bind($state,$old,str_repeat('0',48)), 'stale initial binding');
    $sub='old-owner';
    $bound=$complete('bind',['generation'=>$gen0])['identity'];
    $state=$app->change(static fn(array $d): array => OwnerIdentity::bind($d,$bound,$gen0));
    $gen1=$state['identity']['generation'];
    ok113($gen1!==$gen0 && OwnerIdentity::matches($state['identity']['owner'],$old),'initial owner');
    fails113(fn()=> $app->change(static fn(array $d): array => OwnerIdentity::bind($d,$new)), 'unapproved second binding');
    // Site-state check emulates validation performed for *every* authenticated PHP session.
    fails113(fn()=>OwnerIdentity::assertGeneration($state,$gen0),'stale local session after binding');
    $sub='intruder';
    $wrongCurrent=$complete('replace_current',['generation'=>$gen1])['identity'];
    ok113(!OwnerIdentity::matches($state['identity']['owner'],$wrongCurrent),'foreign subject blocked by handler');
    $sub='old-owner';
    $authTime=time()-301;
    fails113(fn()=> $complete('replace_current',['generation'=>$gen1]),'stale step-up identity');
    $authTime=time(); $useAuthTime=false;
    fails113(fn()=> $complete('replace_current',['generation'=>$gen1]),'missing OIDC auth_time');
    $useAuthTime=true;
    $verified=$complete('replace_current',['generation'=>$gen1])['identity'];
    ok113(OwnerIdentity::matches($state['identity']['owner'],$verified),'old-owner step-up');
    $sub='new-owner';
    $chosen=$complete('replace_new',['generation'=>$gen1,'previous_owner'=>$verified])['identity'];
    fails113(fn()=> OwnerIdentity::replace($state,$verified,$verified,$gen1),'same account replacement');
    // Simulate another concurrent owner operation between callback and write.
    fails113(fn()=> OwnerIdentity::replace($state,$verified,$chosen,$gen0),'stale replace');
    $state=$app->change(static fn(array $d): array => OwnerIdentity::replace($d,$verified,$chosen,$gen1));
    $gen2=$state['identity']['generation'];
    ok113($gen2!==$gen1 && OwnerIdentity::matches($state['identity']['owner'],$new),'atomic replacement');
    ok113(!OwnerIdentity::matches($state['identity']['owner'],$old),'previous owner revoked');
    fails113(fn()=>OwnerIdentity::assertGeneration($state,$gen1),'previous sessions revoked');
    fails113(fn()=> $app->change(static fn(array $d): array => OwnerIdentity::unbind($d,$old,$gen1)), 'stale unlink');
    fails113(fn()=>OwnerIdentity::unbind($state,$old,$gen2),'wrong owner unlink');
    $sub='new-owner';
    $validCurrent=$complete('unbind',['generation'=>$gen2])['identity'];
    ok113(OwnerIdentity::matches($state['identity']['owner'],$validCurrent),'unlink reauth');
    $state=$app->change(static fn(array $d): array => OwnerIdentity::unbind($d,$validCurrent,$gen2));
    ok113($state['identity']['owner']===null && $state['identity']['generation']!==$gen2,'unlink revokes sessions');
    ok113($app->login('ExtremelyStrongPassword123'),'local recovery still works');
    fails113(fn()=>OwnerIdentity::assertGeneration($state,$gen2),'sessions invalid after unlink');
    fails113(fn()=>OwnerIdentity::replace($state,$new,$old,$state['identity']['generation']),'replace on unbound site');
    $oldSchema=$app->load();
    $oldSchema['identity']=['owner'=>$new]; $oldSchema['schema']=10;
    file_put_contents($app->file(),SiteState::encode($oldSchema));
    $migrated=$app->load();
    ok113($migrated['schema']===12 && $migrated['identity']['owner']===$new &&
        preg_match('/^[a-f0-9]{48}$/D',$migrated['identity']['generation'])===1,'existing bound schema 10 preserved');
    $backups=$app->backups()->names();
    $found=false;
    foreach ($backups as $name) if ($app->backups()->verify($name)['schema']===10) $found=true;
    ok113($found,'original schema 10 backup');
    $invalid=$migrated; $invalid['identity']['generation']='insecure';
    fails113(fn()=>SiteState::validate($invalid),'invalid generation rejected');
    ok113($migrated['pages']===$oldSchema['pages'] && $migrated['integrations']===$oldSchema['integrations'] &&
        $migrated['password']===$oldSchema['password'],'migration preserves website and credentials');
} finally { rm113($root); }
echo "Lite 1.1.3 owner bind, fresh reauth, two-step replace, revoke, unlink and migration OK\n";
