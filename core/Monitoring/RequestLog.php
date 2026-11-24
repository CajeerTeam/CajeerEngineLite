<?php
declare(strict_types=1);
namespace CajeerEngine\Monitoring;

use CajeerEngine\Update\{OperationFiles,OperationLock};

/** Best-effort request telemetry. Fixed cardinality, no request data, bounded disk usage. */
final class RequestLog
{
    public const MAX_BYTES=1048576;
    public const GROUPS=['public','admin','media','health','metadata'];
    public static function enabled(): bool { return getenv('CAJEER_MONITORING')!=='0'; }
    private static function directory(string $root): string {
        $dir=OperationFiles::path($root,'storage/app/monitoring');
        OperationFiles::dir($dir,0700);
        $st=@lstat($dir);
        if (!is_array($st)||($st['mode']&077)!==0||($st['mode']&0170000)!==0040000) throw new \RuntimeException('Monitoring directory not private');
        return $dir;
    }
    private static function open(string $path): mixed {
        $h=OperationLock::openLockFile($path,0600);
        $st=fstat($h);
        if (!is_array($st)||($st['mode']&077)!==0||$st['size']>self::MAX_BYTES) { fclose($h);throw new \RuntimeException('Unsafe monitoring file'); }
        return $h;
    }
    public static function start(string $root): void {
        if (!self::enabled() || !is_file($root.'/storage/site.json')) return;
        $start=hrtime(true);
        $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
        $path=is_string($path)?$path:'';
        $group=match(true) {
            $path==='/admin'||str_starts_with($path,'/admin/')=>'admin',
            str_starts_with($path,'/media/')=>'media',
            str_starts_with($path,'/health/')=>'health',
            in_array($path,['/robots.txt','/sitemap.xml'],true)=>'metadata', default=>'public'
        };
        register_shutdown_function(static function() use($root,$start,$group): void {
            $last=error_get_last();
            $fatal=is_array($last)&&in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true);
            $status=http_response_code();
            try { self::record($root,$group,$fatal?500:(is_int($status)?$status:200),max(0,(int)((hrtime(true)-$start)/1000)),memory_get_peak_usage(true)); }
            catch (\Throwable) { /* Telemetry must never turn a successful request into an outage. */ }
        });
    }
    /** Returns false when a concurrent writer or reader owns the lock. No wait on the request path. */
    public static function record(string $root,string $group,int $status,int $us,int $memory): bool {
        if (!in_array($group,self::GROUPS,true)||$status<100||$status>599||$us<0||$memory<0) throw new \InvalidArgumentException('Invalid request measurement');
        $dir=self::directory($root);$lock=self::open($dir.'/log.lock');
        try {
            if (!flock($lock,LOCK_EX|LOCK_NB)) return false;
            $file=$dir.'/requests.jsonl';$old=$dir.'/requests.previous.jsonl';
            $line=json_encode(['at'=>time(),'group'=>$group,'status'=>$status,'us'=>$us,'memory'=>$memory],JSON_THROW_ON_ERROR)."\n";
            $h=self::open($file);
            try {
                $size=fstat($h)['size'];
                // A worker killed mid-append can leave a truncated last line. Keep preceding complete records.
                if ($size>0) {
                    fseek($h,-1,SEEK_END);
                    if (fread($h,1)!=="\n") {
                        $tail=min(512,$size); fseek($h,-$tail,SEEK_END); $bytes=fread($h,$tail);
                        $pos=is_string($bytes)?strrpos($bytes,"\n"):false;
                        if ($pos===false && $size>$tail) throw new \RuntimeException('Invalid monitoring tail');
                        $size=$pos===false?0:$size-$tail+$pos+1;
                        if (!ftruncate($h,$size)) throw new \RuntimeException('Cannot repair monitoring tail');
                    }
                }
                if ($size+strlen($line)>self::MAX_BYTES) {
                    fclose($h);$h=null;
                    if (file_exists($old)||is_link($old)) { $previous=self::open($old);fclose($previous); }
                    if (!rename($file,$old)) throw new \RuntimeException('Cannot rotate monitoring log');
                    $h=self::open($file);$size=0;
                }
                fseek($h,0,SEEK_END);
                if (fwrite($h,$line)!==strlen($line)||!fflush($h)) {
                    @ftruncate($h,$size);throw new \RuntimeException('Cannot append monitoring log');
                }
            } finally { if (is_resource($h)) fclose($h); }
            return true;
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
    public static function report(string $root,int $minutes=60): array {
        if ($minutes<1||$minutes>1440) throw new \InvalidArgumentException('Window must be 1–1440 minutes');
        $now=time();$rows=[];$invalid=0;$oldest=null;$newest=null;
        $dir=OperationFiles::path($root,'storage/app/monitoring');
        if (file_exists($dir)) {
            $dir=self::directory($root);$lock=self::open($dir.'/log.lock');
            try {
                if (!flock($lock,LOCK_SH|LOCK_NB)) throw new \RuntimeException('Monitoring log busy; retry');
                $snapshots=[];
                foreach (['requests.previous.jsonl','requests.jsonl'] as $name) {
                    $path=$dir.'/'.$name;
                    if (!file_exists($path)&&!is_link($path)) continue;
                    $h=self::open($path);
                    try {
                        $raw=stream_get_contents($h,self::MAX_BYTES+1);
                        if (!is_string($raw)||strlen($raw)>self::MAX_BYTES) throw new \RuntimeException('Invalid monitoring snapshot');
                        $snapshots[]=$raw;
                    } finally { fclose($h); }
                }
            } finally { flock($lock,LOCK_UN);fclose($lock); }
            // Decode and calculate percentiles outside the lock; do not block writers
            // for the duration of a potentially expensive report.
            foreach ($snapshots as $raw) {
                $lines=explode("\n",$raw);$partial=array_pop($lines);
                if ($partial!=='') ++$invalid;
                foreach ($lines as $line) {
                    $r=json_decode($line,true);
                    if (!is_array($r)||!is_int($r['at']??null)||!in_array($r['group']??null,self::GROUPS,true)||!is_int($r['status']??null)||$r['status']<100||$r['status']>599||!is_int($r['us']??null)||$r['us']<0||!is_int($r['memory']??null)||$r['memory']<0) { ++$invalid;continue; }
                    $oldest=min($oldest??$r['at'],$r['at']);$newest=max($newest??$r['at'],$r['at']);
                    if ($r['at']>=$now-$minutes*60&&$r['at']<=$now) $rows[]=['group'=>$r['group'],'status'=>$r['status'],'us'=>$r['us'],'memory'=>$r['memory']];
                }
            }
        }
        $groups=[];foreach(self::GROUPS as $group) $groups[$group]=self::summarize(array_values(array_filter($rows,static fn($r)=>$r['group']===$group)));
        return ['enabled'=>self::enabled(),'window_minutes'=>$minutes,'oldest_retained_at'=>$oldest,'newest_retained_at'=>$newest,'invalid_records'=>$invalid,'best_effort'=>true,'all'=>self::summarize($rows),'groups'=>$groups];
    }
    private static function summarize(array $rows): array {
        $n=count($rows);$errors=0;$clientErrors=0;$peak=0;$times=[];
        foreach($rows as $r) { $errors+=(int)($r['status']>=500);$clientErrors+=(int)($r['status']>=400&&$r['status']<500);$peak=max($peak,$r['memory']);$times[]=$r['us']; }
        sort($times,SORT_NUMERIC);
        $p=static fn(float $q)=>$n?round($times[(int)ceil($n*$q)-1]/1000,3):null;
        return ['requests'=>$n,'errors_5xx'=>$errors,'errors_4xx'=>$clientErrors,'error_percent'=>$n?round(100*$errors/$n,3):null,'p50_ms'=>$p(.5),'p95_ms'=>$p(.95),'p99_ms'=>$p(.99),'peak_memory_bytes'=>$n?$peak:null];
    }
}
