<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use CajeerEngine\Auth\{OidcClient,OidcFlow,OwnerIdentity};
use CajeerEngine\Lite;
use CajeerEngine\Storage\SiteState;

function expect112(bool $ok, string $msg): void { if (!$ok) throw new RuntimeException($msg); }
function rejects112(callable $task, string $why): void {
    try { $task(); } catch (RuntimeException $error) { return; }
    throw new RuntimeException('Unsafe behavior accepted: '.$why);
}
function b64url112(string $v): string { return rtrim(strtr(base64_encode($v), '+/', '-_'), '='); }
function remove112(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $e) if (!$e->isDot()) remove112($e->getPathname());
    rmdir($path);
}
$origin='https://lite.example.org';
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
expect112($key instanceof OpenSSLAsymmetricKey, 'RSA test key');
$details=openssl_pkey_get_details($key);
expect112(is_array($details) && isset($details['rsa']['n'], $details['rsa']['e']), 'RSA details');
$jwks=['keys'=>[['kty'=>'RSA','use'=>'sig','alg'=>'RS256','kid'=>'skif4er-test-key',
    'n'=>b64url112($details['rsa']['n']), 'e'=>b64url112($details['rsa']['e'])]]];
$issuer=OidcClient::ISSUER;
$auth=$issuer.'/protocol/openid-connect/auth';
$token=$issuer.'/protocol/openid-connect/token';
$certs=$issuer.'/protocol/openid-connect/certs';
$discovery=['issuer'=>$issuer,'authorization_endpoint'=>$auth,'token_endpoint'=>$token,
    'jwks_uri'=>$certs,'code_challenge_methods_supported'=>['S256']];
$nonce='';
$claims=['iss'=>$issuer,'sub'=>'owner-test-sub-1234','aud'=>'lite-client',
    'nonce'=>'', 'iat'=>time(), 'exp'=>time()+600];
$jwt=function(array $payload, ?OpenSSLAsymmetricKey $signingKey=null, array $header=[])use($key): string {
    $h=b64url112(json_encode(array_replace(['alg'=>'RS256','typ'=>'JWT','kid'=>'skif4er-test-key'],$header),JSON_THROW_ON_ERROR));
    $p=b64url112(json_encode($payload,JSON_THROW_ON_ERROR));
    $message=$h.'.'.$p;
    if (!openssl_sign($message,$signature,$signingKey ?? $key,OPENSSL_ALGO_SHA256)) throw new RuntimeException('RSA signing');
    return $message.'.'.b64url112($signature);
};
$requests=[];
$driver=static function(string $url,string $method,array $headers,string $body)use(&$requests,$discovery,$jwks,&$claims,$jwt,$token,$certs): array {
    $requests[]=[$url,$method,$headers,$body];
    if ($url===$certs) return [200,json_encode($jwks,JSON_THROW_ON_ERROR)];
    if (str_ends_with($url,'openid-configuration')) return [200,json_encode($discovery,JSON_THROW_ON_ERROR)];
    if ($url===$token) {
        parse_str($body,$fields);
        expect112($method==='POST' && ($fields['grant_type']??null)==='authorization_code', 'code exchange');
        expect112(($fields['redirect_uri']??'')==='https://lite.example.org/admin/oidc/callback', 'exact callback URI');
        expect112(isset($fields['code_verifier']) && strlen($fields['code_verifier'])===43,'PKCE verifier');
        expect112(in_array('Authorization: Basic '.base64_encode('lite-client:strong-test-client-secret'),$headers,true), 'client auth');
        return [200,json_encode(['id_token'=>$jwt($claims),'token_type'=>'Bearer','access_token'=>'secret-access-token'],JSON_THROW_ON_ERROR)];
    }
    throw new RuntimeException('Unexpected provider API URL');
};
$client=new OidcClient('lite-client','strong-test-client-secret',$origin,$driver);
expect112($client->redirectUri()===$origin.'/admin/oidc/callback','redirect origin');
$session=[];
$url=OidcFlow::start($session,$client,'login');
parse_str((string)parse_url($url,PHP_URL_QUERY),$args);
expect112(str_starts_with($url,$auth.'?'),'authorization endpoint');
expect112(($args['response_type']??null)==='code' && ($args['code_challenge_method']??null)==='S256','PKCE authorization');
expect112(($args['scope']??null)==='openid profile email' && ($args['redirect_uri']??null)===$client->redirectUri(),'OIDC scope and redirect');
$tx=$session['cajeer_oidc_transaction'];
expect112($args['code_challenge']===b64url112(hash('sha256',$tx['verifier'],true)),'PKCE hash');
expect112($args['state']===$tx['state'] && $args['nonce']===$tx['nonce'], 'authorization nonce and state');
$claims['nonce']=$tx['nonce'];
$user=OidcFlow::finish($session,$client,['state'=>$args['state'],'code'=>'good-code']);
expect112($user['mode']==='login' && $user['identity']===['iss'=>$issuer,'sub'=>'owner-test-sub-1234'],'verified identity');
expect112(!isset($session['cajeer_oidc_transaction']),'single-use transaction');
rejects112(fn()=>OidcFlow::finish($session,$client,['state'=>$args['state'],'code'=>'good-code']),'callback replay');
$tx=OidcFlow::start($session,$client,'bind');
$before=count($requests);
rejects112(function() use (&$session,$client) { OidcFlow::finish($session,$client,['state'=>'untrusted-state','code'=>'good-code']); },'mismatched state');
expect112(count($requests)===$before,'network request before verifying callback state');
expect112(!isset($session['cajeer_oidc_transaction']),'invalid callback not consumed');

$valid=$claims;
foreach ([
    'wrong issuer'=>['iss'=>'https://attacker.example'],
    'wrong audience'=>['aud'=>'other-client'],
    'wrong nonce'=>['nonce'=>'attacker-nonce'],
    'expired'=>['exp'=>time()-100],
    'future issuance'=>['iat'=>time()+3600],
    'wrong authorized party'=>['aud'=>['lite-client','other-client'],'azp'=>'other-client'],
    'missing authorized party'=>['aud'=>['lite-client','other-client']],
    'future not-before'=>['nbf'=>time()+600],
    'invalid access hash'=>['at_hash'=>'corrupted'],
] as $reason=>$changes) {
    rejects112(fn()=> $client->verifyIdToken($jwt(array_replace($valid,$changes)),$valid['nonce'],'secret-access-token'),$reason);
}
$otherKey=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
rejects112(fn()=> $client->verifyIdToken($jwt($valid,$otherKey),$valid['nonce']),'foreign RSA key');
rejects112(fn()=> $client->verifyIdToken($jwt($valid,null,['alg'=>'none']),$valid['nonce']),'alg none');
rejects112(fn()=> $client->verifyIdToken($jwt($valid,null,['alg'=>'HS256']),$valid['nonce']),'alg confusion');
rejects112(fn()=> $client->verifyIdToken($jwt($valid,null,['kid'=>'unknown']),$valid['nonce']),'unknown key');
$withHash=$valid;
$withHash['at_hash']=b64url112(substr(hash('sha256','secret-access-token',true),0,16));
expect112($client->verifyIdToken($jwt($withHash),$valid['nonce'],'secret-access-token')['sub']==='owner-test-sub-1234','valid at_hash');
rejects112(fn()=> $client->verifyIdToken($jwt($withHash),$valid['nonce'],'different-token'),'access token substitution');
$root=sys_get_temp_dir().'/lite-oidc-'.bin2hex(random_bytes(8));
mkdir($root,0700,true);
try {
    $app=new Lite($root);
    $app->install('ExtremelyStrongPassword123',$origin,'OIDC integration');
    expect112(version_compare(Lite::VERSION,'1.2.2','>=') && SiteState::SCHEMA===14 && $app->load()['identity']['owner']===null, 'new install identity');
    $legacy=$app->load();
    $legacy['pages']['/auth/legacy']=Lite::page(['path'=>'/auth/legacy','title'=>'Legacy auth content','body'=>'Preserved','published'=>'1','menu'=>'0']);
    unset($legacy['identity']); $legacy['schema']=9;
    file_put_contents($app->file(),SiteState::encode($legacy));
    $migrated=$app->load();
    expect112($migrated['schema']===14 && $migrated['identity']['owner']===null,'schema 9 to 10');
    expect112(isset($migrated['pages']['/auth/legacy']), 'legacy auth page lost during update');
    $backup=$app->backups()->names();
    expect112(count($backup)>0 && $app->backups()->verify($backup[0])['schema']===9,'migration backup');
    $identity=$user['identity'];
    expect112(!OwnerIdentity::matches(null,$identity),'unbound owner gains access');
    $app->change(static fn(array $state): array => OwnerIdentity::bind($state,$identity));
    expect112(OwnerIdentity::matches($app->load()['identity']['owner'],$identity),'bound owner denied');
    expect112(!OwnerIdentity::matches($app->load()['identity']['owner'],['iss'=>$issuer,'sub'=>'other-user']), 'other valid OIDC user gains admin');
    expect112(!OwnerIdentity::matches($app->load()['identity']['owner'],['iss'=>'https://other.realm','sub'=>$identity['sub']]), 'issuer substitution');
    rejects112(fn()=>$app->change(static fn(array $state): array => OwnerIdentity::bind($state,['iss'=>$issuer,'sub'=>'other-user'])), 'owner rebind without approval');
    expect112($app->load()['identity']['owner']===$identity,'bound OIDC owner');
    $invalid=$app->load(); $invalid['identity']['owner']['iss']='https://evil.example';
    rejects112(fn()=>SiteState::validate($invalid),'foreign OIDC issuer persisted');
    $invalid=$app->load(); $invalid['identity']['owner']['sub']='';
    rejects112(fn()=>SiteState::validate($invalid),'empty OIDC subject');
    expect112($app->login('ExtremelyStrongPassword123'),'local fallback remains available');
    expect112($app->load()['identity']['owner']===$identity,'local login changed identity');
} finally { remove112($root); }
rejects112(fn()=>new OidcClient('lite-client','strong-test-client-secret','http://lite.example.org',$driver),'unencrypted callback');
rejects112(fn()=>new OidcClient('lite-client','strong-test-client-secret','https://lite.example.org?redirect=evil',$driver),'invalid origin');
$wrongMetadata=static fn($url)=>[200,json_encode(['issuer'=>'https://evil.test','authorization_endpoint'=>'https://evil.test/authorize','token_endpoint'=>'https://evil.test/token','jwks_uri'=>'https://evil.test/jwks'])];
$clientWrongMetadata=new OidcClient('lite-client','strong-test-client-secret',$origin,$wrongMetadata);
rejects112(fn()=>$clientWrongMetadata->begin('login'),'malicious provider metadata');
echo "Lite 1.1.2 OIDC PKCE, RS256, callback replay, owner authorization and JSON migration OK\n";
