<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use CajeerEngine\Lite;
use CajeerEngine\Auth\OidcClient;
use CajeerEngine\Developer\TemplateManager;
use CajeerEngine\Operations\TarPackage;
use CajeerEngine\Security\{AdminRequestGuard,RequestRejected,SecurityAudit};
use CajeerEngine\Template\{TemplateCompiler,TemplateSandbox};

function ok143(bool $condition,string $message):void {if(!$condition)throw new RuntimeException('1.4.3: '.$message);}
function rejects143(callable $f,string $description):void {
    try {$f();}catch(Throwable){return;}
    throw new RuntimeException('1.4.3 accepted unsafe: '.$description);
}
function clean143(string $dir):void {
    if (!is_dir($dir)) return;
    foreach(new FilesystemIterator($dir) as $item) {
        $name=$item->getPathname();
        if ($item->isDir() && !$item->isLink()) clean143($name); else unlink($name);
    }
    rmdir($dir);
}
function b143(string $s):string {return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
ok143(Lite::VERSION==='1.4.3','release version');
$origin='http://localhost:19043';
$server=['HTTP_HOST'=>'localhost:19043','CONTENT_TYPE'=>'application/x-www-form-urlencoded','CONTENT_LENGTH'=>'10','HTTP_SEC_FETCH_SITE'=>'same-origin'];
AdminRequestGuard::check($origin,$server,'/admin','POST');
foreach (['same-site','cross-site','invalid',''] as $site) {
    try {AdminRequestGuard::check($origin,array_replace($server,['HTTP_SEC_FETCH_SITE'=>$site]),'/admin','POST');throw new RuntimeException('Fetch-Site accepted: '.$site);}
    catch(RequestRejected $e){ok143($e->httpStatus===403,'same-site rejection status');}
}
foreach (['text/plain','application/json',"application/x-www-form-urlencoded;\r\nX-Injected: 1"] as $type) {
    try { AdminRequestGuard::check($origin,array_replace($server,['CONTENT_TYPE'=>$type]),'/admin','POST');throw new RuntimeException('Bad content type accepted');}
    catch(RequestRejected $e){ok143($e->httpStatus===415,'content type rejected');}
}
AdminRequestGuard::check($origin,array_replace($server,['CONTENT_TYPE'=>'multipart/form-data; boundary=----test']),'/admin/media','POST');
// OIDC back-channel logout is authenticated by the JWT, never by cookies/Origin.
AdminRequestGuard::check($origin,array_replace($server,['HTTP_SEC_FETCH_SITE'=>'cross-site']),'/admin/oidc/backchannel-logout','POST');
$compiler=new TemplateCompiler();
ok143(str_contains($compiler->compile("@include('default.header')"),"__engine->include"),'supported include directive remains executable');
ok143(str_contains($compiler->compile('The word include is harmless.'),'include'),'ordinary template text');
rejects143(fn()=>$compiler->compile("<?php system('id'); ?>"),'raw PHP template');
rejects143(fn()=>$compiler->compile('@foreach($list as $__engine)unsafe@endforeach'),'loop overwrites engine');
rejects143(fn()=>$compiler->compile('@foreach($list as $context)unsafe@endforeach'),'loop overwrites context');

$root=sys_get_temp_dir().'/lite-143-'.bin2hex(random_bytes(8));
mkdir($root,0700);
try {
    mkdir($root.'/templates/default',0700,true);
    file_put_contents($root.'/templates/default/page.cjr','Original safe template');
    $manager=new TemplateManager($root);
    ok143($manager->read('default/page.cjr')['source']==='Original safe template','safe template read');
    $hardlink=$root.'/templates/default/shared.cjr';
    ok143(link($root.'/templates/default/page.cjr',$hardlink),'prepare hardlink fixture');
    rejects143(fn()=>$manager->read('default/page.cjr'),'template hardlink source');
    rejects143(fn()=>$manager->read('default/shared.cjr'),'template hardlink alias');
    $sandbox=new TemplateSandbox($root.'/templates');
    rejects143(fn()=>$sandbox->readSource($hardlink),'public template renderer hardlink');
    unlink($hardlink);
    $file=$root.'/templates/default/page.cjr';
    file_put_contents($file,'Updated source');
    ok143($manager->read('default/page.cjr')['source']==='Updated source','normal template remains readable');
    mkdir($root.'/public',0700);
    file_put_contents($root.'/public/index.php','<?php echo 1;');
    $audit=SecurityAudit::run($root);
    ok143($audit['ok'] && !$audit['dependency_inventory']['vendor_directory'],'minimal source dependency audit passes');
    file_put_contents($root.'/public/backdoor.php','<?php echo "unsafe";');
    ok143(!SecurityAudit::run($root)['ok'],'hidden public PHP endpoint flagged');
    unlink($root.'/public/backdoor.php');
    file_put_contents($root.'/composer.json','{"require":{"vendor/package":"1.0"}}');
    ok143(!SecurityAudit::run($root)['ok'],'unlocked dependency manifest flagged');
    file_put_contents($root.'/composer.lock','{}');
    ok143(SecurityAudit::run($root)['ok'],'lockfile inventory restored');
    unlink($root.'/composer.lock');
    symlink($root.'/composer.json',$root.'/composer.lock');
    ok143(!SecurityAudit::run($root)['ok'],'symlinked lockfile rejected');
    unlink($root.'/composer.lock');
    file_put_contents($root.'/composer.lock','{}');

    $stage=$root.'/stage';mkdir($stage,0700);
    $src=[];
    foreach (['site.json'=>'{}','media/index.json'=>'{}'] as $name=>$body) {
        $path=$root.'/'.str_replace('/','-', $name);file_put_contents($path,$body);$src[$name]=$path;
    }
    $checks=[];foreach($src as $name=>$file)$checks[$name]=['size'=>filesize($file),'sha256'=>hash_file('sha256',$file)];
    $manifest=$root.'/manifest.json';
    file_put_contents($manifest,json_encode(['format'=>1,'kind'=>'export','created_at'=>gmdate('Y-m-d\TH:i:s\Z'),'files'=>$checks],JSON_THROW_ON_ERROR));
    $tar=$root.'/valid.tar';
    TarPackage::create($tar,['manifest.json'=>$manifest]+$src);
    link($src['site.json'],$root.'/site-hardlink.json');
    rejects143(fn()=>TarPackage::create($root.'/hardlink.tar',['manifest.json'=>$manifest]+$src),'hardlinked TAR source');
    ok143(!is_file($root.'/hardlink.tar'),'failed TAR source does not expose partial archive');
    unlink($root.'/site-hardlink.json');
    ok143(count(TarPackage::unpack($tar,$stage)['files'])===3,'valid on-disk USTAR');
    // Change member mode but recalculate header checksum, so this is not merely
    // an invalid-checksum rejection.
    $bytes=file_get_contents($tar);
    $header=substr($bytes,0,512);
    $header=substr_replace($header,sprintf("%07o\0",04755),100,8);
    $header=substr_replace($header,str_repeat(' ',8),148,8);
    $sum=array_sum(array_map('ord',str_split($header)));
    $header=substr_replace($header,sprintf("%06o\0 ",$sum),148,8);
    $corrupt=$root.'/unsafe.tar'; file_put_contents($corrupt,$header.substr($bytes,512));
    $stage2=$root.'/stage2';mkdir($stage2,0700);
    rejects143(fn()=>TarPackage::unpack($corrupt,$stage2),'suid TAR member with valid checksum');

    $issuer=OidcClient::ISSUER;
    $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
    ok143($key instanceof OpenSSLAsymmetricKey,'generated RSA test key');
    $details=openssl_pkey_get_details($key);
    $jwks=['keys'=>[['kty'=>'RSA','kid'=>'key143','alg'=>'RS256','use'=>'sig',
        'n'=>b143($details['rsa']['n']),'e'=>b143("\x01")]]];
    $driver=static fn(string $url):array=>[200,json_encode($jwks,JSON_THROW_ON_ERROR)];
    $client=new OidcClient('lite-143','unit-test-oidc-secret','https://lite.example.test',$driver);
    $head=b143(json_encode(['alg'=>'RS256','kid'=>'key143'],JSON_THROW_ON_ERROR));
    $body=b143(json_encode(['iss'=>$issuer,'aud'=>'lite-143','sub'=>'tester','nonce'=>'nonce143','iat'=>time()-10,'exp'=>time()+300],JSON_THROW_ON_ERROR));
    openssl_sign($head.'.'.$body,$signature,$key,OPENSSL_ALGO_SHA256);
    rejects143(fn()=>$client->verifyIdToken($head.'.'.$body.'.'.b143($signature),'nonce143'),'unsafe RSA e=1 signing key');
    echo "Security Hardening 1.4.3: admin origin/content-type, template inode/escaping, TAR metadata, RSA JWK, dependency scan OK\n";
} finally {clean143($root);}
