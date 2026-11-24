<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Update\{ControlCenter,UpdateMonitoring,UpdateEngine,OperationFiles,OperationLock,UpdatePackage};
function ok158(bool $value,string $why):void{if(!$value)throw new RuntimeException($why);}
function reject158(callable $fn,string $why):void{try{$fn();}catch(Throwable){return;}throw new RuntimeException('Expected rejection: '.$why);}
function cleanup158(string $path):void{foreach(new FilesystemIterator($path) as $e){if($e->isDir()&&!$e->isLink())cleanup158($e->getPathname());else unlink($e->getPathname());}rmdir($path);}
function fixture158(string $path,string $version):void{OperationFiles::write($path.'/VERSION',"$version\n",0644);OperationFiles::write($path.'/core/Lite.php',"<?php final class Fixture158 {public const VERSION = '$version';}\n",0644);}
$tmp=sys_get_temp_dir().'/update158-'.bin2hex(random_bytes(8));mkdir($tmp,0700);
try{
    $base=$tmp.'/base';fixture158($base,'1.5.8');$engine=new UpdateEngine($base);$control=new ControlCenter($base);
    $state=$control->status();ok158(!in_array('scheduler_stale',array_column($state['monitoring']['alerts'],'id')),'disabled scheduler not alarmed');
    $control->submit(['revision'=>$state['revision'],'action'=>'settings','channel'=>'stable','checks'=>'1','interval'=>'6','window'=>'02:00-04:00']);
    $state=$control->status();ok158(in_array('scheduler_stale',array_column($state['monitoring']['alerts'],'id')),'missing heartbeat alert');
    $state['scheduler']=['action'=>'failed','started_at'=>1000,'finished_at'=>1001];$state['history']=[['type'=>'install','result'=>'failed','at'=>1000]];
    $r=UpdateMonitoring::report($state,1002);ok158(!$r['ok']&&in_array('install_failure',array_column($r['alerts'],'id')),'failed install alert');
    $state['history'][]=['type'=>'install','result'=>'installed','at'=>1002];$state['scheduler']['action']='idle';
    ok158(!in_array('install_failure',array_column(UpdateMonitoring::report($state,1003)['alerts'],'id')),'latest success clears old failure');
    $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);openssl_pkey_export($key,$pem);OperationFiles::write($tmp.'/private.pem',$pem);OperationFiles::write($tmp.'/public.pem',openssl_pkey_get_details($key)['key']);
    putenv('CAJEER_UPDATE_TRUSTED_KEY_FILE='.$tmp.'/public.pem');putenv('CAJEER_UPDATE_TRUST_POLICY_FILE');putenv('CAJEER_UPDATE_CONFIRM=INSTALL');
    $ids=[];
    foreach(['1.5.9','1.6.0','1.6.1'] as $i=>$version){
        $target=$tmp.'/target'.$i;fixture158($target,$version);$pkg=$tmp.'/pkg'.$i;
        UpdatePackage::build($base,$target,$pkg,$tmp.'/private.pem');$id=$engine->install($pkg)['id'];$ids[]=$id;
        $plan=$base.'/storage/app/updates/history/'.$id.'/plan.json';$p=OperationFiles::read($plan);$p['created_at']=gmdate('c',time()-(10-$i)*86400);OperationFiles::json($plan,$p);
    }
    $r=$engine->snapshots(1,1);ok158($r['ok']&&$r['protected']===$ids[2]&&count($r['eligible'])===2,'preview retains latest');
    reject158(fn()=>$engine->snapshots(1,1,true),'explicit confirmation');putenv('CAJEER_UPDATE_RETENTION_CONFIRM=PRUNE');
    $active=$base.'/storage/app/updates/active.json';OperationFiles::json($active,['id'=>$ids[2],'mode'=>'rollback']);reject158(fn()=>$engine->snapshots(1,1,true),'active transaction');OperationFiles::remove($active);
    $h=OperationLock::acquire($base,true);reject158(fn()=>$engine->snapshots(1,1,true),'concurrent transaction');OperationLock::release($h);
    $backup=$base.'/storage/app/updates/history/'.$ids[0].'/original/VERSION';$old=file_get_contents($backup);OperationFiles::write($backup,'damaged');
    ok158(!$engine->snapshots(1,1)['ok'],'corruption visible');reject158(fn()=>$engine->snapshots(1,1,true),'corruption blocks prune');OperationFiles::write($backup,$old);
    $link=$base.'/storage/app/updates/history/'.$ids[0].'/unexpected';symlink($tmp.'/public.pem',$link);reject158(fn()=>$engine->snapshots(1,1,true),'symlink blocks prune');unlink($link);
    $r=$engine->snapshots(1,1,true);ok158(count($r['removed'])===2&&is_dir($base.'/storage/app/updates/history/'.$ids[2]),'old snapshots deleted, latest retained');
    putenv('CAJEER_UPDATE_CONFIRM=ROLLBACK');ok158($engine->rollback($ids[2])['version']==='1.6.0','latest rollback after pruning');
    echo "Release monitoring and snapshot retention OK\n";
}finally{cleanup158($tmp);}
