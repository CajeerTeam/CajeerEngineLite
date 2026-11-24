<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Monitoring\{RequestLog,OperationalHealth};
use CajeerEngine\Operations\CmsOperations;
use CajeerEngine\Update\OperationLock;
function check147(bool $yes,string $why):void {if(!$yes)throw new RuntimeException($why);}
$root=sys_get_temp_dir().'/cajeer-monitor-'.bin2hex(random_bytes(8));mkdir($root,0700);
try {
    // Operational diagnostics now include the installed release/control state.
    file_put_contents($root.'/VERSION',Lite::VERSION."\n");
    (new Lite($root))->install('Operational147Pass!','http://localhost:8080','Monitor test');
    check147(RequestLog::report($root)['all']['requests']===0,'no invented telemetry');
    for($i=1;$i<=20;$i++)check147(RequestLog::record($root,'public',$i===20?500:200,$i*1000,4194304),'append');
    $r=RequestLog::report($root);check147($r['all']['requests']===20&&$r['all']['errors_5xx']===1&&$r['all']['p95_ms']===19.0&&$r['all']['error_percent']===5.0,'percentiles and errors');
    $dir=$root.'/storage/app/monitoring';$path=$dir.'/requests.jsonl';
    $lock=OperationLock::openLockFile($dir.'/log.lock',0600);flock($lock,LOCK_EX);
    check147(!RequestLog::record($root,'admin',200,5,1),'nonblocking under contention');flock($lock,LOCK_UN);fclose($lock);
    file_put_contents($path,'{"incomplete":',FILE_APPEND);
    check147(RequestLog::record($root,'admin',403,2000,1024),'repair truncated append');
    check147(RequestLog::report($root)['invalid_records']===0,'truncated tail repaired');
    $line=json_encode(['at'=>time(),'group'=>'public','status'=>200,'us'=>10,'memory'=>1])."\n";
    file_put_contents($path,str_repeat($line,intdiv(RequestLog::MAX_BYTES,strlen($line))));
    // Force rotation with a record longer than the remaining bytes.
    check147(RequestLog::record($root,'public',200,123456789,123456789),'rotation');
    check147(is_file($dir.'/requests.previous.jsonl')&&filesize($path)<RequestLog::MAX_BYTES,'bounded rotation');
    check147(RequestLog::report($root)['all']['requests']>1000,'rotation retains complete records');
    $ops=new CmsOperations($root);$ops->createBackup();
    $report=OperationalHealth::run($root,60,true);
    check147($report['ok']&&in_array(['id'=>'backup.integrity','status'=>'pass','value'=>true],$report['checks'],true),'verified backup and diagnostics');
    putenv('CAJEER_MONITOR_MIN_FREE_MB=1000000');check147(!OperationalHealth::run($root)['ok'],'disk threshold failure');putenv('CAJEER_MONITOR_MIN_FREE_MB');
    putenv('CAJEER_MONITORING=0');check147(!RequestLog::report($root)['enabled'],'opt-out');putenv('CAJEER_MONITORING');
    $target=$root.'/private.txt';file_put_contents($target,'do not overwrite');unlink($path);symlink($target,$path);
    try {RequestLog::record($root,'public',200,1,1);throw new LogicException('symlink accepted');} catch(RuntimeException) {}
    check147(file_get_contents($target)==='do not overwrite','symlink unchanged');
    echo "Operational Reliability 1.4.7: metrics, percentiles, nonblocking lock, partial recovery, rotation, backup verification, disk alert, opt-out and symlink refusal OK\n";
} finally {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());
    }rmdir($root);
}
