<?php
declare(strict_types=1);
namespace CajeerEngine\Update;

/** One durable scheduler tick. No daemon, shell commands, or implicit installation permission. */
final class Scheduler
{
    private const DIR='storage/app/update-control';
    private const ACTIONS=['running','idle','checked','installed','deferred','interrupted','expired','recovery_required','retry_wait','retry_scheduled','failed'];
    public function __construct(private readonly string $root,private readonly ?ReleaseHttp $transport=null) {}
    private function path(string $name):string {return OperationFiles::path($this->root,self::DIR.'/'.$name);}
    public function heartbeat():?array
    {
        $p=$this->path('scheduler.json');clearstatcache(true,$p);$st=@lstat($p);
        if($st===false)return null;
        if(($st['mode']&0077)!==0)throw new \RuntimeException('Unsafe scheduler state permissions');
        $s=json_decode(UpdatePackage::readRegular($p,4096),true,8,JSON_THROW_ON_ERROR);
        if(!is_array($s)||($s['format']??null)!==1||!in_array($s['action']??null,self::ACTIONS,true)||
            !is_int($s['started_at']??null)||$s['started_at']<0||!array_key_exists('finished_at',$s)||
            ($s['action']==='running'?$s['finished_at']!==null:(!is_int($s['finished_at'])||$s['finished_at']<$s['started_at'])))
            throw new \RuntimeException('Invalid scheduler state');
        return array_intersect_key($s,array_flip(['format','action','started_at','finished_at']));
    }
    public function status():array
    {
        $s=$this->heartbeat();$control=(new ControlCenter($this->root))->status();
        $now=time();$age=$s===null?null:$now-($s['finished_at']??$s['started_at']);
        return ['heartbeat'=>$s,'age_seconds'=>$age,'stale'=>$age===null||$age<0||$age>900,
            'job'=>$control['job'],'settings'=>$control['settings'],'pending'=>$control['pending'],
            'next_periodic_check_at'=>$control['settings']['checks']?($control['last_attempt']>$now?$now:max($now,$control['last_attempt']+$control['settings']['interval']*3600)):null];
    }
    public function tick():array
    {
        if(PHP_SAPI!=='cli'||getenv('CAJEER_UPDATE_CONTROL_WORKER')!=='1')throw new \RuntimeException('Scheduler requires explicit CLI worker opt-in');
        $h=OperationLock::openLockFile($this->path('scheduler.lock'),0600);
        if(!flock($h,LOCK_EX|LOCK_NB)){fclose($h);return ['action'=>'busy'];}
        try {
            $this->heartbeat(); // Fail closed on corrupt state rather than silently erase it.
            $started=time();$p=$this->path('scheduler.json');
            OperationFiles::json($p,['format'=>1,'action'=>'running','started_at'=>$started,'finished_at'=>null]);
            try {$result=(new ControlCenter($this->root,$this->transport))->work(true);}
            catch(\Throwable $e){
                OperationFiles::json($p,['format'=>1,'action'=>'failed','started_at'=>$started,'finished_at'=>max($started,time())]);
                throw $e;
            }
            OperationFiles::json($p,['format'=>1,'action'=>$result['action'],'started_at'=>$started,'finished_at'=>max($started,time())]);
            return $result;
        } finally {OperationLock::release($h);}
    }
}
