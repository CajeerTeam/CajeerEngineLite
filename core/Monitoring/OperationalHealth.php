<?php
declare(strict_types=1);
namespace CajeerEngine\Monitoring;

use CajeerEngine\Lite;
use CajeerEngine\Storage\{SiteRepository,SiteState};
use CajeerEngine\Operations\{CmsOperations,RecoveryJournal};
use CajeerEngine\Update\{OperationFiles,OperationLock};

final class OperationalHealth
{
    private static function threshold(string $name,int $default,int $min,int $max): int {
        $v=getenv($name); if ($v===false) return $default;
        if (!preg_match('/^[0-9]{1,10}$/D',$v)||(int)$v<$min||(int)$v>$max) throw new \InvalidArgumentException('Invalid threshold '.$name);
        return (int)$v;
    }
    public static function run(string $root,int $minutes=60,bool $verifyBackup=false): array {
        $checks=[];$add=static function(string $id,string $status,mixed $value)use(&$checks):void{$checks[]=['id'=>$id,'status'=>$status,'value'=>$value];};
        $min=self::threshold('CAJEER_MONITOR_MIN_FREE_MB',100,1,1000000)*1048576;
        $maxAge=self::threshold('CAJEER_MONITOR_BACKUP_MAX_HOURS',48,1,8760)*3600;
        $maxP95=self::threshold('CAJEER_MONITOR_P95_MS',1000,1,600000);
        $maxError=self::threshold('CAJEER_MONITOR_ERROR_PERCENT',5,0,100);
        $maxMemory=self::threshold('CAJEER_MONITOR_PEAK_MB',128,1,32768)*1048576;
        $storage=OperationFiles::path($root,'storage');
        $free=@disk_free_space($storage);$total=@disk_total_space($storage);
        $add('disk.free_bytes',$free===false?'fail':($free<$min?'fail':'pass'),$free===false?null:(int)$free);
        $add('disk.total_bytes',$total===false?'warn':'pass',$total===false?null:(int)$total);
        $mode=@fileperms($storage);
        $add('storage.private_writable',is_int($mode)&&($mode&077)===0&&is_writable($storage)?'pass':'fail',is_writable($storage));
        try {
            $pending=OperationLock::maintenance($root)||RecoveryJournal::pending($root);
            $add('operations.pending',$pending?'fail':'pass',$pending);
        } catch(\Throwable) {$add('operations.pending','fail',null);}
        try {
            $site=(new SiteRepository($root))->read();
            $add('site.schema',($site['schema']??null)===SiteState::SCHEMA?'pass':'fail',$site['schema']??null);
        } catch(\Throwable) {$add('site.schema','fail',null);}
        $add('runtime.memory_limit','pass',(string)ini_get('memory_limit'));
        $load=function_exists('sys_getloadavg')?sys_getloadavg():false;
        $add('runtime.load_average',$load===false?'warn':'pass',$load===false?null:$load);
        try {
            $ops=new CmsOperations($root);$names=$ops->names();$age=null;
            if ($names!==[]) { $path=$ops->backupPath($names[0]);$stamp=filemtime($path);if ($stamp!==false&&$stamp<=time()) $age=time()-$stamp; }
            $add('backup.age_seconds',$age===null||$age>$maxAge?'warn':'pass',$age);
            if ($verifyBackup) { if ($names===[]) $add('backup.integrity','fail',false); else { $ops->verifyBackup($names[0]);$add('backup.integrity','pass',true); } }
        } catch(\Throwable) {$add('backup.access','fail',false);}
        $metrics=null;
        try {
            $metrics=RequestLog::report($root,$minutes);$all=$metrics['all'];
            $add('monitoring.records',!$metrics['enabled']||$all['requests']===0?'warn':($metrics['invalid_records']>0?'warn':'pass'),$all['requests']);
            $add('http.p95_ms',$all['requests']<20?'warn':($all['p95_ms']>$maxP95?'fail':'pass'),$all['p95_ms']);
            $add('http.peak_memory_bytes',$all['requests']===0?'warn':($all['peak_memory_bytes']>$maxMemory?'fail':'pass'),$all['peak_memory_bytes']);
            $add('http.error_percent',$all['requests']<20?'warn':($all['error_percent']>$maxError?'fail':'pass'),$all['error_percent']);
        } catch(\Throwable) {$add('monitoring.records','fail',null);}
        $counts=['pass'=>0,'warn'=>0,'fail'=>0];foreach($checks as $c) ++$counts[$c['status']];
        return ['version'=>Lite::VERSION,'collected_at'=>gmdate('c'),'ok'=>$counts['fail']===0,'counts'=>$counts,'checks'=>$checks,'metrics'=>$metrics,'thresholds'=>['min_free_bytes'=>$min,'backup_max_age_seconds'=>$maxAge,'p95_ms'=>$maxP95,'error_percent'=>$maxError,'peak_memory_bytes'=>$maxMemory]];
    }
}
