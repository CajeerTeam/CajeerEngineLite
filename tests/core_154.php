<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Update\{Scheduler,ControlCenter,ReleaseHttp,UpdatePackage,UpdateEngine,OperationFiles,OperationLock};
function ok154(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function reject154(callable $f,string $why):void{try{$f();}catch(Throwable){return;}throw new RuntimeException('Expected rejection: '.$why);}
function clean154(string $p):void{foreach(new FilesystemIterator($p) as $f){if($f->isDir()&&!$f->isLink())clean154($f->getPathname());else unlink($f->getPathname());}rmdir($p);}
function fixture154(string $p,string $v):void{OperationFiles::write($p.'/VERSION',"$v\n",0644);OperationFiles::write($p.'/core/Lite.php',"<?php final class Fixture154 {public const VERSION = '$v';}\n",0644);}
class Http154 extends ReleaseHttp {
    public array $releases=[];public string $bytes='';public bool $fail=false;public int $calls=0;
    public function page(string $url,?string $etag=null):array{$this->calls++;if($this->fail)throw new RuntimeException('Test provider unavailable');return ['status'=>200,'headers'=>[],'releases'=>$this->releases];}
    public function download(string $url,string $destination,int $expectedSize,?string $digest):array{OperationFiles::write($destination,$this->bytes);return ['bytes'=>strlen($this->bytes),'sha256'=>hash('sha256',$this->bytes)];}
}
$tmp=sys_get_temp_dir().'/scheduler154-'.bin2hex(random_bytes(8));mkdir($tmp,0700);
try {
    $root=$tmp.'/base';$target=$tmp.'/target';fixture154($root,'1.5.4');fixture154($target,'1.5.5');
    $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);openssl_pkey_export($key,$pem);
    OperationFiles::write($tmp.'/private.pem',$pem);OperationFiles::write($tmp.'/public.pem',openssl_pkey_get_details($key)['key']);
    UpdatePackage::build($root,$target,$tmp.'/package',$tmp.'/private.pem');
    $http=new Http154();$http->bytes=file_get_contents($tmp.'/package');
    $http->releases=[['id'=>1,'draft'=>false,'prerelease'=>false,'tag_name'=>'v1.5.5','assets'=>[['name'=>'CajeerEngineLite-1.5.5.cjrupdate','state'=>'uploaded','size'=>strlen($http->bytes),'browser_download_url'=>'https://github.com/Test/Repo/releases/download/v1.5.5/CajeerEngineLite-1.5.5.cjrupdate']]]];
    $window=gmdate('H:i',time()-3600).'-'.gmdate('H:i',time()+3600);
    foreach(['CAJEER_UPDATE_TRUSTED_KEY_FILE'=>$tmp.'/public.pem','CAJEER_AUTO_UPDATE_GITHUB_REPO'=>'Test/Repo','CAJEER_AUTO_UPDATE_WINDOW_UTC'=>$window,'CAJEER_AUTO_UPDATE_ENABLED'=>'1','CAJEER_AUTO_UPDATE_INSTALL'=>'1','CAJEER_AUTO_UPDATE_WORKERS_QUIESCED'=>'0','CAJEER_UPDATE_CONTROL_WORKER'=>'0'] as $k=>$v)putenv($k.'='.$v);
    $c=new ControlCenter($root,$http);$scheduler=fn()=>new Scheduler($root,$http);$tick=fn()=>$scheduler()->tick();
    $submit=static function(array $form)use($c){$c->submit($form+['revision'=>$c->status()['revision']]);};
    $state=$root.'/storage/app/update-control/state.json';$hb=$root.'/storage/app/update-control/scheduler.json';
    $alter=static function(callable $f)use($state){$s=OperationFiles::read($state);$f($s);OperationFiles::json($state,$s);};
    reject154($tick,'explicit opt-in');putenv('CAJEER_UPDATE_CONTROL_WORKER=1');
    ok154($scheduler()->status()['stale']&&$tick()['action']==='idle','default opt-out');
    $heartbeat=file_get_contents($hb);$lock=OperationLock::openLockFile(dirname($hb).'/scheduler.lock',0600);flock($lock,LOCK_EX);
    ok154($tick()['action']==='busy'&&file_get_contents($hb)===$heartbeat,'parallel tick leaves heartbeat untouched');OperationLock::release($lock);
    $submit(['action'=>'check']);$http->fail=true;
    $r=$tick();$id=$r['id'];ok154($r['action']==='retry_scheduled'&&$r['attempts']===1&&$r['next_at']>=time()+298,'first delay');
    $calls=$http->calls;ok154($tick()['action']==='retry_wait'&&$http->calls===$calls,'no early network retry');
    $alter(static function(&$s){$s['job']['not_before']=time()-1;});
    $r=$tick();ok154($r['id']===$id&&$r['attempts']===2&&$r['next_at']>=time()+898,'second delay');
    $alter(static function(&$s){$s['job']['not_before']=time()-1;});reject154($tick,'retry exhaustion');
    ok154($c->status()['job']===null&&array_slice($c->status()['history'],-1)[0]['result']==='failed'&&$scheduler()->status()['heartbeat']['action']==='failed','terminal failure persisted');
    $submit(['action'=>'check']);$tick();$submit(['action'=>'cancel']);ok154($c->status()['job']===null,'cancel delayed check');
    $http->fail=false;$submit(['action'=>'settings','channel'=>'stable','interval'=>'1','window'=>$window,'checks'=>'1','install'=>'1']);
    $alter(static function(&$s){$s['last_attempt']=time()-3601;});
    ok154($tick()['action']==='checked'&&$c->status()['check']['verified']['to']==='1.5.5','periodic verified check');
    ok154($c->maintenancePlan()==='install','supervisor detects opted-in install before quiescence');
    ok154($tick()['action']==='idle','quiescence gate and interval');
    $submit(['action'=>'install','confirm'=>'1.5.5']);ok154($c->maintenancePlan()==='install','supervisor detects queued install');ok154($tick()['action']==='deferred','manual install deferred without quiescence');
    putenv('CAJEER_AUTO_UPDATE_WORKERS_QUIESCED=1');
    $http->fail=true;reject154($tick,'install failure no replay');ok154(!$c->status()['settings']['install']&&$c->status()['job']===null,'automatic install paused');
    $http->fail=false;$submit(['action'=>'settings','channel'=>'stable','interval'=>'1','window'=>$window,'checks'=>'1','install'=>'1']);
    $submit(['action'=>'check']);ok154($tick()['action']==='checked','explicit new verification');
    putenv('CAJEER_AUTO_UPDATE_WINDOW_UTC='.gmdate('H:i',time()+3600).'-'.gmdate('H:i',time()+7200));
    ok154($c->maintenancePlan()==='tick','supervisor does not stop services outside window');
    ok154($tick()['action']==='idle'&&UpdatePackage::version($root)==='1.5.4','maintenance window enforced');
    putenv('CAJEER_AUTO_UPDATE_WINDOW_UTC='.$window);
    ok154($tick()['action']==='installed'&&UpdatePackage::version($root)==='1.5.5','real signed automatic install');
    $http->releases=[];ok154($tick()['action']==='checked','fresh next step immediately after install');
    $submit(['action'=>'check']);$alter(static function(&$s){$s['job']['status']='running';});ok154($tick()['action']==='interrupted','abandoned claim');
    $last=(new UpdateEngine($root))->status()['last'];
    // An actual update journal blocks every subsequent job until operator recovery.
    OperationFiles::json($root.'/storage/app/updates/active.json',['id'=>$last['id'],'mode'=>'install']);
    ok154($tick()['action']==='recovery_required','pending recovery blocks scheduling');
    OperationFiles::remove($root.'/storage/app/updates/active.json');
    $old=time()-901;OperationFiles::json($hb,['format'=>1,'action'=>'idle','started_at'=>$old,'finished_at'=>$old]);ok154($scheduler()->status()['stale'],'stale heartbeat');
    chmod($hb,0644);reject154($tick,'unsafe heartbeat mode');chmod($hb,0600);
    OperationFiles::write($hb,'invalid');reject154($tick,'corrupt heartbeat fails closed');
    echo "Scheduler: opt-in, interval, persistent retries/exhaustion, cancellation, overlap, windows, signed installation, failure pause, recovery and heartbeat OK\n";
} finally {clean154($tmp);}
