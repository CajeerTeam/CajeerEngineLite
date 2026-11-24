<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Update\{AutomaticUpdates,TrustPolicy,UpdatePackage,UpdateEngine,DistributionIndex,ReleaseHttp,ReleaseDiscovery,OperationFiles};
function check157(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
function reject157(callable $fn,string $why):void {try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('Expected rejection: '.$why);}
function clean157(string $path):void {foreach(new FilesystemIterator($path) as $f){if($f->isDir()&&!$f->isLink())clean157($f->getPathname());else unlink($f->getPathname());}rmdir($path);}
function fixture157(string $path,string $version):void {
    OperationFiles::write($path.'/VERSION',"$version\n",0644);
    OperationFiles::write($path.'/core/Lite.php',"<?php final class Test157 { public const VERSION = '$version'; }\n",0644);
}
function rsa157(string $path):array {
    $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
    if(!$key||!openssl_pkey_export($key,$secret))throw new RuntimeException('RSA test key generation failed');
    $public=openssl_pkey_get_details($key)['key'];OperationFiles::write($path,$secret);
    return [$key,$public,hash('sha256',$public)];
}
function index157(OpenSSLAsymmetricKey $key,string $version,string $package):string {
    $raw=json_encode(['format'=>1,'product'=>'CajeerEngineLite','to'=>$version,'packages'=>[['from'=>'1.5.7','name'=>'CajeerEngineLite-'.$version.'.cjrupdate','bytes'=>filesize($package),'sha256'=>hash_file('sha256',$package)]]],JSON_THROW_ON_ERROR);
    openssl_sign($raw,$sig,$key,OPENSSL_ALGO_SHA256);
    return json_encode(['payload'=>base64_encode($raw),'signature'=>base64_encode($sig)],JSON_THROW_ON_ERROR);
}
class Http157 extends ReleaseHttp {
    public array $replies=[];public array $pages=[];public int $opens=0;
    protected function open(string $url,string $accept,?string $etag=null):array {
        $this->opens++;$r=array_shift($this->replies);if(!$r)throw new RuntimeException('No reply fixture');
        $h=fopen('php://temp','w+b');fwrite($h,$r['body']??'');rewind($h);
        return ['status'=>$r['status'],'headers'=>$r['headers'],'stream'=>$h];
    }
    public function page(string $url,?string $etag=null):array {return ['status'=>200,'headers'=>[],'releases'=>$this->pages];}
}
$tmp=sys_get_temp_dir().'/security157-'.bin2hex(random_bytes(10));mkdir($tmp,0700);
try {
    [$old,$oldPem,$oldId]=rsa157($tmp.'/old.key');[$new,$newPem,$newId]=rsa157($tmp.'/new.key');
    OperationFiles::write($tmp.'/legacy.pem',$oldPem,0644);
    fixture157($tmp.'/base','1.5.7');fixture157($tmp.'/next','1.5.8');fixture157($tmp.'/later','1.5.9');
    UpdatePackage::build($tmp.'/base',$tmp.'/next',$tmp.'/old.pkg',$tmp.'/old.key');
    UpdatePackage::build($tmp.'/base',$tmp.'/next',$tmp.'/new.pkg',$tmp.'/new.key');
    UpdatePackage::build($tmp.'/base',$tmp.'/later',$tmp.'/later.pkg',$tmp.'/new.key');
    putenv('CAJEER_UPDATE_TRUSTED_KEY_FILE='.$tmp.'/legacy.pem');putenv('CAJEER_UPDATE_TRUST_POLICY_FILE');
    $engine=new UpdateEngine($tmp.'/base');
    check157($engine->verify($tmp.'/old.pkg')['signature_key_sha256']===$oldId,'Legacy compatibility');
    reject157(fn()=>$engine->verify($tmp.'/new.pkg'),'untrusted replacement key');
    chmod($tmp.'/legacy.pem',0666);reject157(fn()=>$engine->verify($tmp.'/old.pkg'),'writable legacy key');chmod($tmp.'/legacy.pem',0644);
    putenv('CAJEER_UPDATE_ALLOW_LEGACY_AUTO');
    foreach(['CAJEER_AUTO_UPDATE_ENABLED'=>'1','CAJEER_AUTO_UPDATE_INSTALL'=>'1','CAJEER_AUTO_UPDATE_WORKERS_QUIESCED'=>'1','CAJEER_AUTO_UPDATE_WINDOW_UTC'=>gmdate('H:i',time()-3600).'-'.gmdate('H:i',time()+3600)] as $k=>$v)putenv($k.'='.$v);
    try {(new AutomaticUpdates($tmp.'/base',new Http157()))->run();throw new LogicException('Unexpected legacy auto acceptance');}
    catch(RuntimeException $e) {check157(str_contains($e->getMessage(),'Managed update trust policy'),'legacy auto requires explicit opt-in');}
    // Transport must not accept redirects to arbitrary GitHub pages or length mismatch.
    $http=new Http157();$http->replies=[['status'=>302,'headers'=>['location'=>'https://github.com/other/repo']]];
    reject157(fn()=>$http->download('https://github.com/Test/Repo/releases/download/v1/a',$tmp.'/dl',60,null),'redirect outside CDN');
    check157($http->opens===1&&!file_exists($tmp.'/dl'),'redirect no follow and no residue');
    $http->replies=[['status'=>200,'headers'=>['content-length'=>'61'],'body'=>str_repeat('a',60)]];
    reject157(fn()=>$http->download('https://github.com/Test/Repo/releases/download/v1/a',$tmp.'/dl',60,null),'bad Content-Length');
    $http->replies=[['status'=>302,'headers'=>['location'=>'https://release-assets.githubusercontent.com/test?token=test']],['status'=>200,'headers'=>['content-length'=>'60'],'body'=>str_repeat('a',60)]];
    check157($http->download('https://github.com/Test/Repo/releases/download/v1/a',$tmp.'/dl',60,hash('sha256',str_repeat('a',60)))['bytes']===60,'valid CDN download');
    // Root-owned trust policies are mandatory by design. CI runs this file
    // under root in a disposable hosted runner; normal unprivileged developers
    // can still exercise transport and legacy paths without weakening checks.
    if(fileowner($tmp)!==0){echo "Security 1.5.7: legacy and transport OK; managed policy fixture requires isolated root runner\n";return;}
    $policyFile=$tmp.'/policy.json';
    $policy=static function(array $revoked=[],string $floor='1.5.8')use($policyFile,$oldPem,$newPem):void {
        OperationFiles::json($policyFile,['format'=>1,'minimum_target'=>$floor,'keys'=>[$oldPem,$newPem],'revoked'=>$revoked],0644);
    };
    $policy();putenv('CAJEER_UPDATE_TRUST_POLICY_FILE='.$policyFile);
    check157(TrustPolicy::configured()->report()['active_fingerprints']===[$oldId,$newId],'rotation overlap');
    check157($engine->verify($tmp.'/old.pkg')['signature_key_sha256']===$oldId&&$engine->verify($tmp.'/new.pkg')['signature_key_sha256']===$newId,'both signatures accepted during rotation');
    $oldIndex=index157($old,'1.5.8',$tmp.'/old.pkg');$newIndex=index157($new,'1.5.8',$tmp.'/new.pkg');
    check157(count(DistributionIndex::verify($oldIndex,'1.5.8',TrustPolicy::configured()))===1,'old index accepted');
    $policy([$oldId]);
    reject157(fn()=>$engine->verify($tmp.'/old.pkg'),'revoked signer with legacy fallback configured');
    reject157(fn()=>DistributionIndex::verify($oldIndex,'1.5.8',TrustPolicy::configured()),'revoked index signer');
    check157(count(DistributionIndex::verify($newIndex,'1.5.8',TrustPolicy::configured()))===1,'rotated signer index');
    putenv('CAJEER_UPDATE_CONFIRM=INSTALL');reject157(fn()=>$engine->install($tmp.'/old.pkg'),'revoked signed install');
    check157(UpdatePackage::version($tmp.'/base')==='1.5.7','revoked package did not mutate code');
    $policy([$oldId],'1.5.9');reject157(fn()=>$engine->verify($tmp.'/new.pkg'),'version floor verify');reject157(fn()=>$engine->install($tmp.'/new.pkg'),'version floor install');
    reject157(fn()=>DistributionIndex::verify($newIndex,'1.5.8',TrustPolicy::configured()),'index below floor');
    check157($engine->verify($tmp.'/later.pkg')['to']==='1.5.9','newer target available');
    // Discovery filters below-floor releases before choosing a legacy asset.
    foreach(['1.5.8','1.5.9'] as $v)$http->pages[]=['draft'=>false,'prerelease'=>false,'tag_name'=>'v'.$v,'assets'=>[['name'=>'CajeerEngineLite-'.$v.'.cjrupdate','state'=>'uploaded','browser_download_url'=>'https://github.com/Test/Repo/releases/download/v'.$v.'/CajeerEngineLite-'.$v.'.cjrupdate','size'=>120]]];
    check157((new ReleaseDiscovery($tmp.'/base',$http))->fetch('Test/Repo','1.5.7','stable')['available']['version']==='1.5.9','discovery floor');
    // Invalid managed policy MUST NOT fall back to the old key.
    chmod($policyFile,0666);reject157(fn()=>$engine->verify($tmp.'/old.pkg'),'writable policy');chmod($policyFile,0644);
    symlink($policyFile,$tmp.'/link.json');putenv('CAJEER_UPDATE_TRUST_POLICY_FILE='.$tmp.'/link.json');reject157(fn()=>$engine->verify($tmp.'/later.pkg'),'policy symlink');
    putenv('CAJEER_UPDATE_TRUST_POLICY_FILE='.$tmp.'/missing.json');reject157(fn()=>$engine->verify($tmp.'/old.pkg'),'missing policy');
    putenv('CAJEER_UPDATE_TRUST_POLICY_FILE='.$policyFile);$policy([$oldId,$newId]);reject157(fn()=>$engine->verify($tmp.'/later.pkg'),'all signing keys revoked');
    $policy([$oldId],'1.5.9');
    // Exercise the real automatic pipeline with a signed index, verified package
    // and rotation between the check and the queued install. Only HTTP is mocked.
    $packageBytes=file_get_contents($tmp.'/later.pkg');$indexBytes=index157($new,'1.5.9',$tmp.'/later.pkg');
    $prefix='https://github.com/Test/Repo/releases/download/v1.5.9/';
    $http->pages=[['draft'=>false,'prerelease'=>false,'tag_name'=>'v1.5.9','assets'=>[
        ['name'=>'CajeerEngineLite-1.5.9.cjrupdate','state'=>'uploaded','browser_download_url'=>$prefix.'CajeerEngineLite-1.5.9.cjrupdate','size'=>strlen($packageBytes),'digest'=>'sha256:'.hash('sha256',$packageBytes)],
        ['name'=>'update-index.json','state'=>'uploaded','browser_download_url'=>$prefix.'update-index.json','size'=>strlen($indexBytes)]
    ]]];
    $response=static fn(string $body):array=>['status'=>200,'headers'=>['content-length'=>(string)strlen($body)],'body'=>$body];
    putenv('CAJEER_AUTO_UPDATE_GITHUB_REPO=Test/Repo');
    $auto=new AutomaticUpdates($tmp.'/base',$http);
    $http->replies=[$response($indexBytes),$response($packageBytes)];
    $expected=$auto->verifiedCheck('stable')['verified'];check157($expected['to']==='1.5.9','managed verified check');
    $policy([$newId],'1.5.9');$http->replies=[$response($indexBytes)];
    reject157(fn()=>$auto->run($expected,'stable'),'revoked after verification');
    check157(UpdatePackage::version($tmp.'/base')==='1.5.7','queued install rechecks trust');
    $policy([$oldId],'1.5.9');$http->replies=[$response($indexBytes),$response($packageBytes)];
    $installed=$auto->run($expected,'stable')['installed'];check157(UpdatePackage::version($tmp.'/base')==='1.5.9','real automatic install using rotated key');
    $planPath=$tmp.'/base/storage/app/updates/history/'.$installed['id'].'/plan.json';
    chmod($planPath,0644);putenv('CAJEER_UPDATE_CONFIRM=ROLLBACK');reject157(fn()=>$engine->rollback($installed['id']),'public recovery plan permissions');chmod($planPath,0600);
    $plan=OperationFiles::read($planPath);$bad=$plan;$bad['files'][]=$bad['files'][0];OperationFiles::json($planPath,$bad);
    putenv('CAJEER_UPDATE_CONFIRM=ROLLBACK');reject157(fn()=>$engine->rollback($installed['id']),'duplicate recovery plan entry');
    check157(UpdatePackage::version($tmp.'/base')==='1.5.9','invalid rollback plan refused before mutation');
    OperationFiles::json($planPath,$plan);
    // Emergency rollback intentionally does not depend on current signing trust.
    $policy([$newId],'1.5.9');$engine->rollback($installed['id']);
    check157(UpdatePackage::version($tmp.'/base')==='1.5.7','rollback after key revocation');
    putenv('CAJEER_UPDATE_CONFIRM=INSTALL');reject157(fn()=>$engine->install($tmp.'/later.pkg'),'revoked package replay');
    echo "Security 1.5.7: key overlap/rotation, revocation, floor, fail-closed policy, signed index, transport, real install and emergency rollback OK\n";
} finally {clean157($tmp);}
