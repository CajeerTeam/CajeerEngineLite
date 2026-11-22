<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Auth\{OidcClient,OidcFlow,SessionRegistry,RecoveryService};
use CajeerEngine\Lite;
use CajeerEngine\Storage\SiteState;

function assert118(bool $ok,string $message): void { if (!$ok) throw new RuntimeException('1.1.8: '.$message); }
function denied118(callable $fn,string $message): void {
    try { $fn(); } catch (RuntimeException|InvalidArgumentException $exception) { return; }
    throw new RuntimeException('1.1.8 accepted unsafe input: '.$message);
}
function b118(string $x): string { return rtrim(strtr(base64_encode($x),'+/','-_'),'='); }
function remove118(string $path): void {
    if (is_link($path)||is_file($path)) {unlink($path);return;}
    if (is_dir($path)) {foreach(new FilesystemIterator($path) as $entry) remove118($entry->getPathname());rmdir($path);}
}
assert118(version_compare(Lite::VERSION,'1.2.2','>=') && SiteState::SCHEMA===13,'version and unchanged schema');
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
assert118($key instanceof OpenSSLAsymmetricKey,'OpenSSL RSA');
$info=openssl_pkey_get_details($key);
$issuer=OidcClient::ISSUER;
$metadata=['issuer'=>$issuer,'authorization_endpoint'=>$issuer.'/protocol/openid-connect/auth',
 'token_endpoint'=>$issuer.'/protocol/openid-connect/token',
 'jwks_uri'=>$issuer.'/protocol/openid-connect/certs',
 'code_challenge_methods_supported'=>['S256'],'response_types_supported'=>['code'],
 'grant_types_supported'=>['authorization_code']];
$jwks=['keys'=>[['kty'=>'RSA','kid'=>'key-118','alg'=>'RS256','use'=>'sig',
    'key_ops'=>['verify'],'n'=>b118($info['rsa']['n']),'e'=>b118($info['rsa']['e'])]]];
$nonce='';$requests=[];
$driver=static function(string $url,string $method,array $headers,string $body) use (&$metadata,&$jwks,&$nonce,&$requests,$issuer,$key):array {
    $requests[]=$url;
    if (str_ends_with($url,'openid-configuration')) return [200,json_encode($metadata,JSON_THROW_ON_ERROR)];
    if (str_ends_with($url,'/certs')) return [200,json_encode($jwks,JSON_THROW_ON_ERROR)];
    if (str_ends_with($url,'/token')) {
        parse_str($body,$fields);
        assert118(($fields['grant_type']??null)==='authorization_code' &&
            ($fields['code_verifier']??null)!==null && strlen($fields['code_verifier'])===43,'authorization code with PKCE');
        $claims=['iss'=>$issuer,'aud'=>'lite-118','sub'=>'owner-118','nonce'=>$nonce,'iat'=>time(),'exp'=>time()+300];
        $h=b118(json_encode(['kid'=>'key-118','alg'=>'RS256','typ'=>'JWT'],JSON_THROW_ON_ERROR));
        $p=b118(json_encode($claims,JSON_THROW_ON_ERROR));
        openssl_sign($h.'.'.$p,$signature,$key,OPENSSL_ALGO_SHA256);
        return [200,json_encode(['id_token'=>$h.'.'.$p.'.'.b118($signature),'access_token'=>'test-access','token_type'=>'Bearer'],JSON_THROW_ON_ERROR)];
    }
    throw new RuntimeException('Unexpected URL');
};
$client=new OidcClient('lite-118','private-test-client-secret','https://lite.example.test',$driver);
$session=[];
$url=OidcFlow::start($session,$client,'login');
parse_str((string)parse_url($url,PHP_URL_QUERY),$params);
$nonce=$params['nonce'];
$oldTx=$session['cajeer_oidc_transaction'];
denied118(fn()=>OidcFlow::start($session,$client,'bind'),'overwriting pending PKCE transaction');
assert118($session['cajeer_oidc_transaction']===$oldTx,'original pending state preserved');
$before=count($requests);
denied118(function() use (&$session,$client,$params):void { OidcFlow::finish($session,$client,['state'=>$params['state'],'code'=>'valid-code','iss'=>'https://evil.example']); },'issuer mismatch');
assert118(count($requests)===$before,'mismatch rejected before network request');
assert118(!isset($session['cajeer_oidc_transaction']),'invalid callback consumes transaction');
$url=OidcFlow::start($session,$client,'login');
parse_str((string)parse_url($url,PHP_URL_QUERY),$params);
$nonce=$params['nonce'];
$user=OidcFlow::finish($session,$client,['state'=>$params['state'],'code'=>'valid-code','iss'=>$issuer]);
assert118($user['identity']['sub']==='owner-118','issuer validated callback completes');
denied118(fn()=>OidcFlow::finish($session,$client,['state'=>$params['state'],'code'=>'valid-code']),'replayed callback');
$url=OidcFlow::start($session,$client,'login');
OidcFlow::cancel($session);
assert118(!isset($session['cajeer_oidc_transaction']),'explicit transaction cancel');
OidcFlow::start($session,$client,'login');
$session['cajeer_oidc_transaction']['created']=time()-301;
OidcFlow::start($session,$client,'login');
assert118($session['cajeer_oidc_transaction']['created']>time()-300,'expired transaction replaced');
OidcFlow::cancel($session);
// JOSE header substitution and unsupported critical semantics must fail even with valid signatures.
$jwt=function(array $header,array $claims) use ($key):string {
    $h=b118(json_encode($header,JSON_THROW_ON_ERROR));$p=b118(json_encode($claims,JSON_THROW_ON_ERROR));
    openssl_sign($h.'.'.$p,$sig,$key,OPENSSL_ALGO_SHA256);
    return $h.'.'.$p.'.'.b118($sig);
};
$claims=['iss'=>$issuer,'aud'=>'lite-118','sub'=>'owner-118','nonce'=>'nonce-118','iat'=>time(),'exp'=>time()+300];
$header=['kid'=>'key-118','alg'=>'RS256','typ'=>'JWT'];
assert118($client->verifyIdToken($jwt($header,$claims),'nonce-118')['sub']==='owner-118','valid signed JWT');
foreach (['crit'=>['exp'],'jku'=>'https://evil.test/jwks','jwk'=>['kty'=>'RSA'],
 'x5u'=>'https://evil.test/cert','x5c'=>[],'b64'=>false,'zip'=>'DEF','cty'=>'JWT'] as $name=>$value) {
    denied118(fn()=> $client->verifyIdToken($jwt($header+[$name=>$value],$claims),'nonce-118'),'forbidden JOSE '.$name);
}
$jwks['keys'][0]['key_ops']=['sign'];
denied118(fn()=> $client->verifyIdToken($jwt($header,$claims),'nonce-118'),'sign-only JWK used to verify');
$jwks['keys'][0]['key_ops']=['verify'];
$metadata['code_challenge_methods_supported']=['plain'];
denied118(fn()=> $client->begin('login'),'PKCE downgrade');
$metadata['code_challenge_methods_supported']=['S256'];
$metadata['response_types_supported']=['token'];
denied118(fn()=> $client->begin('login'),'implicit response downgrade');
$metadata['response_types_supported']=['code'];
$metadata['grant_types_supported']=['implicit'];
denied118(fn()=> $client->begin('login'),'authorization grant downgrade');
$metadata['grant_types_supported']=['authorization_code'];

$root=sys_get_temp_dir().'/lite-auth-harden-'.bin2hex(random_bytes(8));mkdir($root,0700,true);
try {
    $app=new Lite($root);
    $app->install('SecurePasswordForHardening118','https://lite.example.test','Hardening');
    $site=$app->load();$registry=new SessionRegistry($root);
    $device=[];$registry->issue($device,$site);
    assert118($registry->valid($device,$site),'valid session');
    $path=$root.'/storage/auth/sessions.json';$data=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
    $id=SessionRegistry::currentId($device);
    $data['items'][$id]['seen']=time()+3600;
    file_put_contents($path,json_encode($data,JSON_THROW_ON_ERROR));
    assert118(!$registry->valid($device,$site),'future session timestamp revoked');
    $oidc=['oidc_identity'=>['iss'=>$issuer,'sub'=>'owner-118'],'oidc_sid'=>'sid-118'];
    $registry->issue($oidc,$site);
    $path=$root.'/storage/auth/sessions.json';$data=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
    for($i=0;$i<512;$i++) $data['replays'][hash('sha256','replay-'.$i)]=time()+120;
    file_put_contents($path,json_encode($data,JSON_THROW_ON_ERROR));
    $logout=['iss'=>$issuer,'jti'=>'new-verified-logout','exp'=>time()+120,'sid'=>'sid-118'];
    denied118(fn()=> $registry->backchannelLogout($logout,$site),'replay eviction while live entries remain');
    assert118($registry->valid($oidc,$site),'no accidental session revocation on replay-cache overflow');
    $data=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
    assert118(count($data['replays'])===512,'live jtis retained');
    $recovery=new RecoveryService($app);
    $grant=$recovery->issue();
    $recovery->complete($grant,'DifferentSecurePassword118');
    assert118($app->login('DifferentSecurePassword118'),'CLI recovery functional');
    assert118(!$registry->valid($oidc,$app->load()),'recovery revokes OIDC sessions');
    denied118(fn()=> $recovery->complete($grant,'AnotherSecurePassword118'),'recovery token replay');
} finally {remove118($root);}
echo "Lite 1.1.8 OIDC transaction, issuer/JWKS, session replay and recovery hardening OK\n";
