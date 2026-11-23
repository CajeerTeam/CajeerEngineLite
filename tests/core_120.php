<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use CajeerEngine\Lite;
use CajeerEngine\Auth\{OidcClient,OidcFlow,OwnerIdentity,StableAcceptance,DeploymentProbe};
use CajeerEngine\Storage\SiteState;

function check120(bool $good,string $why): void { if (!$good) throw new RuntimeException('Stable 1.2.0: '.$why); }
function fails120(callable $fn,string $why): void {
    try { $fn(); } catch (RuntimeException|InvalidArgumentException $e) { return; }
    throw new RuntimeException('Stable 1.2.0 accepted: '.$why);
}
function b120(string $raw): string { return rtrim(strtr(base64_encode($raw),'+/','-_'),'='); }
function clean120(string $path): void {
    if (is_link($path)||is_file($path)) { unlink($path); return; }
    if (is_dir($path)) { foreach(new FilesystemIterator($path) as $f) clean120($f->getPathname());rmdir($path); }
}
check120(version_compare(Lite::VERSION,'1.2.2','>=') && SiteState::SCHEMA===14,'release and unchanged JSON schema');
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
check120($key instanceof OpenSSLAsymmetricKey,'RSA 2048 support');
$details=openssl_pkey_get_details($key);
$issuer=OidcClient::ISSUER;
$metadata=['issuer'=>$issuer,'authorization_endpoint'=>$issuer.'/protocol/openid-connect/auth',
    'token_endpoint'=>$issuer.'/protocol/openid-connect/token',
    'jwks_uri'=>$issuer.'/protocol/openid-connect/certs',
    'end_session_endpoint'=>$issuer.'/protocol/openid-connect/logout',
    'code_challenge_methods_supported'=>['S256'], 'response_types_supported'=>['code'],
    'id_token_signing_alg_values_supported'=>['RS256'], 'backchannel_logout_supported'=>true];
$keys=['keys'=>[['kid'=>'stable-key','kty'=>'RSA','alg'=>'RS256','use'=>'sig',
    'n'=>b120($details['rsa']['n']),'e'=>b120($details['rsa']['e'])]]];
$nonce='';$mode='';$fault='';
$sign=static function(array $claims)use($key):string {
    $header=b120(json_encode(['alg'=>'RS256','kid'=>'stable-key','typ'=>'JWT'],JSON_THROW_ON_ERROR));
    $payload=b120(json_encode($claims,JSON_THROW_ON_ERROR));
    check120(openssl_sign($header.'.'.$payload,$signature,$key,OPENSSL_ALGO_SHA256),'RSA signing');
    return $header.'.'.$payload.'.'.b120($signature);
};
$driver=static function(string $url,string $method,array $headers,string $body)
    use(&$metadata,&$keys,&$nonce,&$mode,&$fault,$sign,$issuer):array {
    if (str_ends_with($url,'openid-configuration')) return [200,json_encode($metadata,JSON_THROW_ON_ERROR)];
    if (str_ends_with($url,'/certs')) return [200,json_encode($keys,JSON_THROW_ON_ERROR)];
    if (str_ends_with($url,'/token')) {
        parse_str($body,$args);
        check120($method==='POST' && ($args['code_verifier']??'')!=='' &&
            ($args['redirect_uri']??null)==='https://lite.example.test/admin/oidc/callback',
            'real authorization code exchange with PKCE and exact redirect');
        $code=$args['code'];
        $hash=b120(substr(hash('sha256',$code,true),0,16));
        if ($fault==='wrong_hash') $hash=b120(random_bytes(16));
        $claims=['iss'=>$issuer,'sub'=>'stable-owner','aud'=>'stable-client',
            'nonce'=>$nonce,'iat'=>time()-5,'exp'=>time()+300,
            'auth_time'=>time()-5,'c_hash'=>$hash,'sid'=>'stable-sid'];
        return [200,json_encode(['token_type'=>'Bearer','access_token'=>'test-access-token',
            'id_token'=>$sign($claims)],JSON_THROW_ON_ERROR)];
    }
    throw new RuntimeException('Unexpected mock endpoint');
};
$client=new OidcClient('stable-client','strong-test-secret-120','https://lite.example.test',$driver);
$probe=$client->probeProvider();
check120($probe['signing_keys']===1 && $probe['backchannel_advertised'] &&
    $probe['redirect_uri']==='https://lite.example.test/admin/oidc/callback',
    'actual discovery, JWT signing key and logout capability verification');
$flow=static function()use($client,&$nonce,$issuer):array {
    $session=[];$url=OidcFlow::start($session,$client,'login');
    parse_str((string)parse_url($url,PHP_URL_QUERY),$q);
    check120(($q['code_challenge_method']??null)==='S256' && strlen($q['code_challenge'])===43,
        'authorization request uses S256');
    $nonce=$q['nonce'];
    return OidcFlow::finish($session,$client,['state'=>$q['state'],'code'=>'single-use-code-120','iss'=>$issuer]);
};
check120($flow()['identity']['sub']==='stable-owner','RS256 signed ID token with matching c_hash');
$fault='wrong_hash'; fails120($flow,'mismatched c_hash must fail closed');$fault='';
$bad=$metadata;$metadata['end_session_endpoint']='https://malicious.invalid/logout';
fails120(fn()=>$client->probeProvider(),'untrusted logout metadata');$metadata=$bad;
$bad=$keys;$keys=['keys'=>[]];fails120(fn()=>$client->probeProvider(),'missing signing keys');$keys=$bad;
$keys['keys'][]=$keys['keys'][0];fails120(fn()=>$client->probeProvider(),'duplicated RSA kid');$keys=$bad;

$root=sys_get_temp_dir().'/lite-release120-'.bin2hex(random_bytes(8));
mkdir($root,0700,true);
$envId=getenv('CAJEER_OIDC_CLIENT_ID');$envSecret=getenv('CAJEER_OIDC_CLIENT_SECRET');
try {
    // Build a real deployed-file layout for permission and rollback diagnostics.
    $source=dirname(__DIR__);
    foreach (['core','public','bin'] as $sub) {
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source.'/'.$sub,FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $entry) {
            $dest=$root.'/'.substr($entry->getPathname(),strlen($source)+1);
            if ($entry->isDir()) { if (!is_dir($dest)) mkdir($dest,0700,true); }
            else { if (!is_dir(dirname($dest))) mkdir(dirname($dest),0700,true); copy($entry->getPathname(),$dest); }
        }
    }
    foreach (['bootstrap.php','router.php'] as $file) copy($source.'/'.$file,$root.'/'.$file);
    $app=new Lite($root);
    $app->install('StableProductionPassword120','https://lite.example.test','Stable 1.2.0');
    $app->change(static fn(array $state):array=>OwnerIdentity::bind($state,
        ['iss'=>OidcClient::ISSUER,'sub'=>'stable-owner']));
    $site=$app->load();
    $snapshot=file_get_contents($root.'/storage/site.json');
    check120($app->migrate()===false && $snapshot===file_get_contents($root.'/storage/site.json'),
        '1.1.9 schema 12 upgrades to 1.2.0 without rewriting site data');
    putenv('CAJEER_OIDC_CLIENT_ID=stable-client');putenv('CAJEER_OIDC_CLIENT_SECRET=strong-test-secret-120');
    $local=StableAcceptance::run($root,$site);
    check120($local['automated_ok'] && !$local['live_checked'] && !$local['production_accepted'] &&
        $local['status']==='pending_live','local checks cannot certify external IdP and site');
    $pageDriver=static function(string $url):array {
        $path=parse_url($url,PHP_URL_PATH);
        $headers=['HTTP/1.1 200 OK'];$body='';$status=200;
        if ($path==='/health/live') $body='{"status":"ok"}';
        elseif ($path==='/admin') {
            $headers=array_merge($headers,[
                'Strict-Transport-Security: max-age=31536000',
                'Cache-Control: no-store',
                "Content-Security-Policy: default-src 'self'; script-src 'none'; frame-ancestors 'none'",
                'Set-Cookie: CajeerLite=opaque; path=/; Secure; HttpOnly; SameSite=Lax']);
        } elseif (in_array($path,['/admin/oidc/login','/admin/oidc/backchannel-logout'],true)) $status=405;
        elseif ($path==='/admin/recovery') $status=404;
        else throw new RuntimeException('Unexpected public route');
        return [$status,$headers,$body];
    };
    $live=StableAcceptance::run($root,$site,true,$driver,$pageDriver);
    check120($live['automated_ok'] && $live['live_ok'] && $live['status']==='pending_manual' &&
        !$live['production_accepted'] && count($live['manual'])>=5,
        'live read-only probes pass but interactive account acceptance remains required');
    $brokenDriver=static function(string $url)use($pageDriver):array {
        [$status,$headers,$body]=$pageDriver($url);
        if (str_ends_with($url,'/admin')) $headers=array_filter($headers,static fn(string $h):bool=>!str_starts_with($h,'Set-Cookie:'));
        return [$status,array_values($headers),$body];
    };
    $badHttp=DeploymentProbe::run($site['settings']['url'],$brokenDriver);
    check120(!$badHttp['ok'],'missing secure session cookie must fail live probe');
    $path=$root.'/core/Auth/OidcClient.php';chmod($path,0666);
    check120(!StableAcceptance::run($root,$site)['automated_ok'],'writable authentication code blocked');
    chmod($path,0644);
    putenv('CAJEER_OIDC_CLIENT_SECRET');
    check120(!StableAcceptance::run($root,$site)['automated_ok'],'missing production OIDC credentials blocked');
} finally {
    if ($envId===false) putenv('CAJEER_OIDC_CLIENT_ID'); else putenv('CAJEER_OIDC_CLIENT_ID='.$envId);
    if ($envSecret===false) putenv('CAJEER_OIDC_CLIENT_SECRET'); else putenv('CAJEER_OIDC_CLIENT_SECRET='.$envSecret);
    clean120($root);
}
echo "Lite 1.2.0 live-capability verifier, signed c_hash, HTTPS security, readiness and schema compatibility OK\n";
