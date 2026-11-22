<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use CajeerEngine\Auth\{OidcClient,SessionRegistry};
use CajeerEngine\Lite;
use CajeerEngine\Storage\SiteState;

function check115(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function reject115(callable $action,string $message): void {
    try {$action();} catch (RuntimeException|InvalidArgumentException $e) {return;}
    throw new RuntimeException('Unsafe logout accepted: '.$message);
}
function b115(string $s): string {return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
function rm115(string $path):void {
    if (is_file($path)||is_link($path)) {unlink($path);return;}
    if (is_dir($path)) {foreach (new FilesystemIterator($path) as $child) rm115($child->getPathname());rmdir($path);}
}

check115(Lite::VERSION==='1.1.9' && SiteState::SCHEMA===12,'release version / no site migration');
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
check115($key instanceof OpenSSLAsymmetricKey,'OpenSSL RSA available');
$detail=openssl_pkey_get_details($key);
$jwks=['keys'=>[['kty'=>'RSA','use'=>'sig','alg'=>'RS256','kid'=>'test-kid','n'=>b115($detail['rsa']['n']),'e'=>b115($detail['rsa']['e'])]]];
$issuer=OidcClient::ISSUER;
$logoutUrl=$issuer.'/protocol/openid-connect/logout';
$metadata=['issuer'=>$issuer,'authorization_endpoint'=>$issuer.'/protocol/openid-connect/auth',
    'token_endpoint'=>$issuer.'/protocol/openid-connect/token','jwks_uri'=>$issuer.'/protocol/openid-connect/certs',
    'end_session_endpoint'=>$logoutUrl, 'code_challenge_methods_supported'=>['S256']];
$driver=static function(string $url,string $method,array $headers,string $body)use($metadata,$jwks): array {
    if (str_ends_with($url,'openid-configuration')) return [200,json_encode($metadata,JSON_THROW_ON_ERROR)];
    if (str_ends_with($url,'certs')) return [200,json_encode($jwks,JSON_THROW_ON_ERROR)];
    throw new RuntimeException('Unexpected external URL');
};
$client=new OidcClient('test-client','top-secret-test-client','https://lite.test',$driver);
$remote=$client->globalLogoutUrl('https://lite.test');
parse_str((string)parse_url($remote,PHP_URL_QUERY),$qp);
check115(str_starts_with($remote,$logoutUrl.'?') && $qp['client_id']==='test-client' &&
    $qp['post_logout_redirect_uri']==='https://lite.test/admin/oidc/logged-out', 'exact provider logout URL');
reject115(fn()=>$client->globalLogoutUrl('https://attacker.test'),'untrusted redirect');
$badDriver=static fn(string $url)=>[200,json_encode(array_replace($metadata,['end_session_endpoint'=>'https://evil.test/logout']),JSON_THROW_ON_ERROR)];
$badClient=new OidcClient('test-client','top-secret-test-client','https://lite.test',$badDriver);
reject115(fn()=>$badClient->globalLogoutUrl('https://lite.test'),'metadata endpoint injection');
$sign=static function(array $claims, array $header=[])use($key):string {
    $h=b115(json_encode(array_replace(['alg'=>'RS256','kid'=>'test-kid','typ'=>'logout+jwt'],$header),JSON_THROW_ON_ERROR));
    $p=b115(json_encode($claims,JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT));
    $message=$h.'.'.$p;
    openssl_sign($message,$signature,$key,OPENSSL_ALGO_SHA256);
    return $message.'.'.b115($signature);
};
$now=time();
$claims=['iss'=>$issuer,'aud'=>'test-client','iat'=>$now,'exp'=>$now+180,'jti'=>'unique-test-token-115',
    'sub'=>'owner-subject','sid'=>'provider-session-1',
    'events'=>['http://schemas.openid.net/event/backchannel-logout'=>new stdClass()]];
$jwt=$sign($claims);
check115(($client->verifyLogoutToken($jwt)['sub']??null)==='owner-subject','valid signed logout token');
$alternative=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
$forged=explode('.',$jwt);
openssl_sign($forged[0].'.'.$forged[1],$foreignSig,$alternative,OPENSSL_ALGO_SHA256);
reject115(fn()=>$client->verifyLogoutToken($forged[0].'.'.$forged[1].'.'.b115($foreignSig)),'invalid signature');
foreach([
 'nonce'=>['nonce'=>'not-allowed'], 'wrong issuer'=>['iss'=>'https://elsewhere.test'],
 'wrong audience'=>['aud'=>'someone-else'], 'expired'=>['exp'=>$now-100],
 'future issued'=>['iat'=>$now+3600], 'no subject'=>['sub'=>null,'sid'=>null],
 'bad event'=>['events'=>['other'=>new stdClass()]],
 'bad event payload'=>['events'=>['http://schemas.openid.net/event/backchannel-logout'=>'not-an-object']],
 'short jti'=>['jti'=>'a'], 'future expiry'=>['exp'=>$now+7200],
] as $name=>$override) reject115(fn()=>$client->verifyLogoutToken($sign(array_replace($claims,$override))),$name);
reject115(fn()=>$client->verifyLogoutToken($sign($claims,['alg'=>'none'])),'unsigned');
reject115(fn()=>$client->verifyLogoutToken($sign($claims,['typ'=>'ID_TOKEN'])),'wrong JWT type');
$root=sys_get_temp_dir().'/cajeer-unified-'.bin2hex(random_bytes(8)); mkdir($root,0700,true);
try {
    $app=new Lite($root);
    $app->install('PasswordForUnifiedLogout123','https://lite.test','Logout integration');
    $site=$app->load();
    $registry=new SessionRegistry($root);
    $alice=['oidc_identity'=>['iss'=>$issuer,'sub'=>'owner-subject'],'oidc_sid'=>'provider-session-1'];
    $alice2=['oidc_identity'=>['iss'=>$issuer,'sub'=>'owner-subject'],'oidc_sid'=>'provider-session-2'];
    $other=['oidc_identity'=>['iss'=>$issuer,'sub'=>'other-subject'],'oidc_sid'=>'provider-session-9'];
    $local=[];
    $registry->issue($alice,$site); $registry->issue($alice2,$site);
    $registry->issue($other,$site); $registry->issue($local,$site);
    check115($registry->backchannelLogout($client->verifyLogoutToken($jwt),$site)===1,'sid+sub revokes only one session');
    check115(!$registry->valid($alice,$site) && $registry->valid($alice2,$site) && $registry->valid($local,$site) && $registry->valid($other,$site),'session selection');
    check115($registry->backchannelLogout($client->verifyLogoutToken($jwt),$site)===0,'replay idempotent');
    $subOnly=array_replace($claims,['jti'=>'sub-wide-logout-115']);unset($subOnly['sid']);
    check115($registry->backchannelLogout($client->verifyLogoutToken($sign($subOnly)),$site)===1,'subject-only revokes remaining user sessions');
    check115(!$registry->valid($alice2,$site) && $registry->valid($other,$site) && $registry->valid($local,$site),'different principal and local session unaffected');
    $sidOnly=array_replace($claims,['jti'=>'sid-only-logout-115','sid'=>'provider-session-9']);unset($sidOnly['sub']);
    check115($registry->backchannelLogout($client->verifyLogoutToken($sign($sidOnly)),$site)===1,'sid-only token revokes matching OIDC session');
    check115(!$registry->valid($other,$site) && $registry->valid($local,$site),'local unaffected');
    $body=file_get_contents($root.'/storage/auth/sessions.json');
    check115(!str_contains($body,'owner-subject') && !str_contains($body,'provider-session-1') && !str_contains($body,'unique-test-token-115') &&
        !str_contains($body,$local['cajeer_session_token']),'private registry does not store identifiers or JWT');
    $saved=json_decode($body,true,16,JSON_THROW_ON_ERROR);
    check115($saved['version']===2 && count($saved['replays'])===3,'replay cache durable');
    // Installed 1.1.4 registry must upgrade without losing a local session, while retiring unmappable OIDC leases.
    $oldLocal=[]; $registry->issue($oldLocal,$site);
    $legacy=json_decode(file_get_contents($root.'/storage/auth/sessions.json'),true,16,JSON_THROW_ON_ERROR);
    $legacy['version']=1; unset($legacy['replays']);
    file_put_contents($root.'/storage/auth/sessions.json',json_encode($legacy,JSON_THROW_ON_ERROR));
    check115($registry->valid($oldLocal,$site),'v1 local session retained');
    // A v1 OIDC session lacked a subject/sid fingerprint: it must not bypass IdP logout after upgrade.
    $legacyOidc=['oidc_identity'=>['iss'=>$issuer,'sub'=>'owner-subject']];
    $id=$registry->issue($legacyOidc,$site);
    $raw=json_decode(file_get_contents($root.'/storage/auth/sessions.json'),true,16,JSON_THROW_ON_ERROR);
    unset($raw['items'][$id]['oidc_subject']); $raw['version']=1; unset($raw['replays']);
    file_put_contents($root.'/storage/auth/sessions.json',json_encode($raw,JSON_THROW_ON_ERROR));
    check115(!$registry->valid($legacyOidc,$site) && $registry->valid($oldLocal,$site),'unmappable legacy OIDC lease revoked');
} finally {rm115($root);}
echo "Lite 1.1.6 OIDC global logout, signed back-channel tokens, replay, selective revocation and v1 registry compatibility OK\n";
