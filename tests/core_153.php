<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Update\{ControlCenter,ControlCenterView,ReleaseHttp,UpdatePackage,UpdateEngine,OperationFiles,OperationLock};
function ok153(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function reject153(callable $f,string $why):void{try{$f();}catch(Throwable){return;}throw new RuntimeException('Expected rejection: '.$why);}
function clean153(string $p):void{foreach(new FilesystemIterator($p) as $f){if($f->isDir()&&!$f->isLink())clean153($f->getPathname());else unlink($f->getPathname());}rmdir($p);}
function fixture153(string $p,string $v):void{OperationFiles::write($p.'/VERSION',"$v\n",0644);OperationFiles::write($p.'/core/Lite.php',"<?php final class Test153 {public const VERSION = '$v';}\n",0644);}
class Http153 extends ReleaseHttp {
    public array $releases=[];public string $bytes='';public bool $fail=false;public int $calls=0;
    public function page(string $url,?string $etag=null):array{$this->calls++;if($this->fail)throw new RuntimeException('Test provider unavailable');return ['status'=>200,'headers'=>[],'releases'=>$this->releases];}
    public function download(string $url,string $destination,int $expectedSize,?string $digest):array{OperationFiles::write($destination,$this->bytes);return ['bytes'=>strlen($this->bytes),'sha256'=>hash('sha256',$this->bytes)];}
}
$tmp=sys_get_temp_dir().'/control153-'.bin2hex(random_bytes(8));mkdir($tmp,0700);
try{
    $root=$tmp.'/base';$target=$tmp.'/target';fixture153($root,'1.5.3');fixture153($target,'1.5.4');
    $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);openssl_pkey_export($key,$pem);
    OperationFiles::write($tmp.'/private.pem',$pem);OperationFiles::write($tmp.'/public.pem',openssl_pkey_get_details($key)['key']);
    UpdatePackage::build($root,$target,$tmp.'/package',$tmp.'/private.pem');
    $http=new Http153();$http->bytes=file_get_contents($tmp.'/package');$asset=['name'=>'CajeerEngineLite-1.5.4.cjrupdate','state'=>'uploaded','size'=>strlen($http->bytes),'browser_download_url'=>'https://github.com/Test/Repo/releases/download/v1.5.4/CajeerEngineLite-1.5.4.cjrupdate'];
    $http->releases=[['id'=>1,'draft'=>false,'prerelease'=>false,'tag_name'=>'v1.5.4','body'=>'<script>alert(1)</script> release notes','assets'=>[$asset]]];
    $window=gmdate('H:i',time()-3600).'-'.gmdate('H:i',time()+3600);
    foreach(['CAJEER_UPDATE_TRUSTED_KEY_FILE'=>$tmp.'/public.pem','CAJEER_AUTO_UPDATE_GITHUB_REPO'=>'Test/Repo','CAJEER_AUTO_UPDATE_WINDOW_UTC'=>$window,'CAJEER_AUTO_UPDATE_ENABLED'=>'1','CAJEER_AUTO_UPDATE_INSTALL'=>'1','CAJEER_AUTO_UPDATE_WORKERS_QUIESCED'=>'0','CAJEER_UPDATE_CONTROL_WORKER'=>'0'] as $k=>$v)putenv($k.'='.$v);
    $c=new ControlCenter($root,$http);$submit=static function(array $form)use($c){$c->submit($form+['revision'=>$c->status()['revision']]);};
    ok153($c->status()['settings']['install']===false,'default opt-out');reject153(fn()=>$c->work(),'worker gate');
    putenv('CAJEER_UPDATE_CONTROL_WORKER=1');
    $stale=$c->status()['revision'];$submit(['action'=>'settings','channel'=>'stable','interval'=>'6','window'=>$window]);
    reject153(fn()=>$c->submit(['action'=>'check','revision'=>$stale]),'stale revision');
    reject153(fn()=>$submit(['action'=>'install','confirm'=>'1.5.4']),'unchecked installation');
    reject153(fn()=>$submit(['action'=>'settings','channel'=>'stable','interval'=>'6','window'=>'00:00-00:00']),'invalid window');
    $submit(['action'=>'check']);ok153($http->calls===0,'web submission performs no network request');
    reject153(fn()=>$submit(['action'=>'check']),'duplicate queue');
    $lease=OperationLock::openLockFile($root.'/storage/app/update-control/worker.lock',0600);flock($lease,LOCK_EX);
    reject153(fn()=>$c->work(),'parallel worker');OperationLock::release($lease);
    ok153($c->work()['action']==='checked','verified check');$s=$c->status();
    ok153($s['fresh']&&$s['check']['verified']['to']==='1.5.4'&&$s['check']['verified']['sha256']===hash('sha256',$http->bytes),'verified digest');
    $html=ControlCenterView::render($s,'test');ok153(str_contains($html,'&lt;script&gt;')&&!str_contains($html,'<script>'),'release notes escaped');
    reject153(fn()=>$submit(['action'=>'install','confirm'=>'1.5.5']),'wrong confirmation');
    $submit(['action'=>'install','confirm'=>'1.5.4']);ok153($c->work()['action']==='deferred','quiescence gate');
    $submit(['action'=>'cancel']);ok153($c->status()['job']===null,'cancel pending');
    $submit(['action'=>'install','confirm'=>'1.5.4']);putenv('CAJEER_AUTO_UPDATE_WORKERS_QUIESCED=1');
    // Replace the candidate with a different valid signature/payload for the same target version.
    OperationFiles::write($target.'/README.md','different valid package',0644);UpdatePackage::build($root,$target,$tmp.'/different',$tmp.'/private.pem');
    putenv('CAJEER_UPDATE_CONFIRM=INSTALL');reject153(fn()=>(new UpdateEngine($root))->install($tmp.'/package',str_repeat('0',64)),'installer stage digest gate');
    ok153(UpdatePackage::version($root)==='1.5.3','staged mismatch leaves original');
    $original=$http->bytes;$http->bytes=file_get_contents($tmp.'/different');$http->releases[0]['assets'][0]['size']=strlen($http->bytes);
    reject153(fn()=>$c->work(),'changed verified package');ok153(UpdatePackage::version($root)==='1.5.3','no change after mismatch');
    $http->bytes=$original;$http->releases[0]['assets'][0]['size']=strlen($original);
    $submit(['action'=>'check']);$c->work();$submit(['action'=>'install','confirm'=>'1.5.4']);
    ok153($c->work()['action']==='installed'&&UpdatePackage::version($root)==='1.5.4','real signed install');
    ok153(!$c->status()['fresh']&&$c->status()['job']===null,'clear stale verification');
    // Simulate a killed worker after durable claim, with no code changes.
    $submit(['action'=>'check']);$state=$root.'/storage/app/update-control/state.json';$s=OperationFiles::read($state);$s['job']['status']='running';OperationFiles::json($state,$s);
    ok153($c->work()['action']==='interrupted','interrupted claim not replayed');
    $submit(['action'=>'settings','channel'=>'stable','interval'=>'1','window'=>$window,'checks'=>'1','install'=>'1']);
    $s=OperationFiles::read($state);$s['last_attempt']=time()-3601;OperationFiles::json($state,$s);
    $http->releases=[];ok153($c->work()['action']==='checked','periodic check');ok153($c->work()['action']==='idle','interval respected');
    $next=$tmp.'/next';fixture153($next,'1.5.5');UpdatePackage::build($root,$next,$tmp.'/next-package',$tmp.'/private.pem');
    $http->bytes=file_get_contents($tmp.'/next-package');$http->releases=[['id'=>2,'draft'=>false,'prerelease'=>false,'tag_name'=>'v1.5.5','assets'=>[['name'=>'CajeerEngineLite-1.5.5.cjrupdate','state'=>'uploaded','size'=>strlen($http->bytes),'browser_download_url'=>'https://github.com/Test/Repo/releases/download/v1.5.5/CajeerEngineLite-1.5.5.cjrupdate']]]];
    $s=OperationFiles::read($state);$s['last_attempt']=time()-3601;OperationFiles::json($state,$s);
    ok153($c->work()['action']==='checked','periodic signed verification');
    putenv('CAJEER_AUTO_UPDATE_INSTALL=0');ok153($c->work()['action']==='idle','operator permission cannot be overridden by UI');
    putenv('CAJEER_AUTO_UPDATE_INSTALL=1');ok153($c->work()['action']==='installed'&&UpdatePackage::version($root)==='1.5.5','opt-in automatic install on subsequent tick');
    ok153(!glob($root.'/storage/app/updates/auto/downloads/*'),'download cleanup');
    ok153((fileperms($state)&0777)===0600,'private state');chmod($state,0644);reject153(fn()=>$c->status(),'unsafe state permissions');chmod($state,0600);
    echo "Update Control Center: revision, queue, signed verification, digest pin, actual install, worker gates, cancellation, crash and policy OK\n";
}finally{clean153($tmp);}
