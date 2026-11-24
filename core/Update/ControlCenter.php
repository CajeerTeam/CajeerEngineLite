<?php
declare(strict_types=1);
namespace CajeerEngine\Update;

/** Private, bounded mailbox between authenticated HTTP and the operator's CLI worker.
 * No paths, URLs, commands or credentials can be supplied through this mailbox.
 */
final class ControlCenter
{
    private const DIR='storage/app/update-control';
    private const TTL=86400;
    private const RETRY_DELAYS=[300,900];
    public function __construct(private readonly string $root, private readonly ?ReleaseHttp $transport=null) {}
    private function path(string $name): string {return OperationFiles::path($this->root,self::DIR.'/'.$name);}
    private static function defaults(): array {return ['channel'=>'stable','checks'=>false,'install'=>false,'interval'=>6,'window'=>'02:00-04:00'];}
    private static function blank(): array {return ['format'=>1,'settings'=>self::defaults(),'job'=>null,'check'=>null,'history'=>[],'last_attempt'=>0];}
    private static function digest(array $s): string {return hash('sha256',json_encode($s,JSON_THROW_ON_ERROR));}
    private static function version(mixed $v): bool {return DistributionIndex::version($v);}
    private static function hash(mixed $v): bool {return is_string($v)&&preg_match('/^[a-f0-9]{64}$/D',$v)===1;}
    private static function settings(array $p): array
    {
        if(!in_array($p['channel']??null,['stable','preview'],true)||!is_bool($p['checks']??null)||!is_bool($p['install']??null)||
           !is_int($p['interval']??null)||$p['interval']<1||$p['interval']>168||!is_string($p['window']??null)||($p['install']&&!$p['checks']))
            throw new \InvalidArgumentException('Некорректные настройки обновлений');
        try {AutomaticUpdates::window($p['window']);} catch (\RuntimeException) {throw new \InvalidArgumentException('Некорректное окно UTC (HH:MM-HH:MM)');}
        return ['channel'=>$p['channel'],'checks'=>$p['checks'],'install'=>$p['install'],'interval'=>$p['interval'],'window'=>$p['window']];
    }
    private static function verified(mixed $v): bool
    {
        return is_array($v)&&self::version($v['from']??null)&&self::version($v['to']??null)&&version_compare($v['to'],$v['from'],'>')&&
            self::hash($v['sha256']??null)&&is_int($v['files']??null)&&$v['files']>=1&&$v['files']<=UpdatePackage::MAX_FILES&&is_bool($v['preview']??null);
    }
    private static function validJob(mixed $j): bool
    {
        return is_array($j)&&is_string($j['id']??null)&&preg_match('/^[a-f0-9]{24}$/D',$j['id'])&&in_array($j['type']??null,['check','install'],true)&&
            in_array($j['status']??null,['queued','running'],true)&&is_int($j['at']??null)&&in_array($j['channel']??null,['stable','preview'],true)&&
            ($j['type']==='check'?($j['target']??null)===null:self::verified($j['target']??null))&&
            (!array_key_exists('attempts',$j)||(is_int($j['attempts'])&&$j['attempts']>=0&&$j['attempts']<=count(self::RETRY_DELAYS)+1))&&
            (!array_key_exists('not_before',$j)||(is_int($j['not_before'])&&$j['not_before']>=0&&$j['not_before']<=$j['at']+self::TTL));
    }
    private function read(): array
    {
        $file=$this->path('state.json'); clearstatcache(true,$file);$st=@lstat($file);
        if($st===false)return self::blank();
        if(($st['mode']&0077)!==0)throw new \RuntimeException('Unsafe update control state permissions');
        $s=json_decode(UpdatePackage::readRegular($file,256000),true,32,JSON_THROW_ON_ERROR);
        if(!is_array($s)||($s['format']??null)!==1||!is_array($s['settings']??null)||!is_int($s['last_attempt']??null)||
            !is_array($s['history']??null)||!array_is_list($s['history'])||count($s['history'])>40||!array_key_exists('job',$s)||!array_key_exists('check',$s)||
            ($s['job']!==null&&!self::validJob($s['job'])))throw new \RuntimeException('Invalid update control state');
        $s['settings']=self::settings($s['settings']);
        $c=$s['check'];
        if($c!==null&&(!is_array($c)||!self::version($c['installed']??null)||!is_int($c['at']??null)||!in_array($c['channel']??null,['stable','preview'],true)||
            !array_key_exists('verified',$c)||($c['verified']!==null&&!self::verified($c['verified']))||!is_string($c['notes']??null)||strlen($c['notes'])>4000||
            !is_array($c['plan']??null)||count($c['plan'])>600||array_filter($c['plan'],static fn($v)=>!self::version($v))||
            (($c['latest']??null)!==null&&!self::version($c['latest']))))throw new \RuntimeException('Invalid check result');
        foreach($s['history'] as $h)if(!is_array($h)||!is_string($h['id']??null)||!preg_match('/^[a-f0-9]{24}$/D',$h['id'])||
            !in_array($h['type']??null,['check','install'],true)||!in_array($h['result']??null,['checked','installed','failed','cancelled','interrupted','expired'],true)||
            !is_int($h['at']??null)||(($h['to']??null)!==null&&!self::version($h['to'])))throw new \RuntimeException('Invalid update history');
        return $s;
    }
    private function lock(string $name,bool $wait=false): mixed
    {
        $h=OperationLock::openLockFile($this->path($name),0600);
        if(!flock($h,LOCK_EX|($wait?0:LOCK_NB))){fclose($h);throw new \RuntimeException('Update control busy; retry');}
        return $h;
    }
    private function edit(callable $fn): mixed
    {
        $h=$this->lock('state.lock');
        try {$s=$this->read();$before=self::digest($s);$result=$fn($s);if($before!==self::digest($s))OperationFiles::json($this->path('state.json'),$s);return $result;}
        finally {OperationLock::release($h);}
    }
    private function fresh(array $s): bool
    {
        $c=$s['check'];return $c!==null&&$c['at']<=time()&&time()-$c['at']<=self::TTL&&$c['channel']===$s['settings']['channel']&&$c['installed']===UpdatePackage::version($this->root);
    }
    public function status(): array
    {
        $s=$this->read();$engine=(new UpdateEngine($this->root))->status();
        return $s+['revision'=>self::digest($s),'fresh'=>$this->fresh($s),'installed'=>$engine['installed_version'],'pending'=>$engine['pending']!==null,'scheduler'=>(new Scheduler($this->root))->heartbeat()];
    }
    private static function finish(array &$s,string $result): void
    {
        $j=$s['job'];$s['history'][]=['id'=>$j['id'],'type'=>$j['type'],'result'=>$result,'at'=>time(),'to'=>$j['target']['to']??null];
        $s['history']=array_slice($s['history'],-40);$s['job']=null;
    }
    private static function queue(array &$s,string $type,?array $target=null): void
    {
        $s['job']=['id'=>bin2hex(random_bytes(12)),'type'=>$type,'status'=>'queued','at'=>time(),'channel'=>$s['settings']['channel'],'target'=>$target,'attempts'=>0,'not_before'=>0];
    }
    /** All public mutations use a compare-and-swap revision. */
    public function submit(array $form): void
    {
        $this->edit(function(array &$s)use($form):void {
            if(!is_string($form['revision']??null)||!hash_equals(self::digest($s),$form['revision']))throw new \InvalidArgumentException('Состояние изменилось. Обновите страницу.');
            $action=$form['action']??null;
            if($action==='cancel') {
                if(($s['job']['status']??null)!=='queued')throw new \InvalidArgumentException('Можно отменить только ожидающее задание');
                self::finish($s,'cancelled');return;
            }
            if($s['job']!==null)throw new \InvalidArgumentException('Сначала завершите или отмените текущее задание');
            if($action==='settings') {
                $interval=$form['interval']??null;
                if(!is_string($interval)||!preg_match('/^[0-9]{1,3}$/D',$interval))throw new \InvalidArgumentException('Некорректный интервал');
                foreach(['checks','install'] as $k)if(isset($form[$k])&&$form[$k]!=='1')throw new \InvalidArgumentException('Некорректный флаг');
                $settings=self::settings(['channel'=>$form['channel']??null,'checks'=>($form['checks']??null)==='1','install'=>($form['install']??null)==='1','interval'=>(int)$interval,'window'=>$form['window']??null]);
                if($settings['channel']!==$s['settings']['channel']){$s['check']=null;$s['last_attempt']=0;}
                $s['settings']=$settings;return;
            }
            if($action==='check'){self::queue($s,'check');return;}
            if($action==='install') {
                $v=$s['check']['verified']??null;
                if(!$this->fresh($s)||!self::verified($v)||($form['confirm']??null)!==$v['to'])throw new \InvalidArgumentException('Нужна актуальная проверка и подтверждение версии');
                self::queue($s,'install',$v);return;
            }
            throw new \InvalidArgumentException('Неизвестное действие');
        });
    }
    private static function allowed(array $s,array $j): bool
    {
        if($j['type']==='check')return true;
        if(isset($j['channel'])&&$j['channel']!==$s['settings']['channel'])throw new \RuntimeException('Job channel no longer matches settings');
        return getenv('CAJEER_AUTO_UPDATE_ENABLED')==='1'&&getenv('CAJEER_AUTO_UPDATE_INSTALL')==='1'&&
            getenv('CAJEER_AUTO_UPDATE_WORKERS_QUIESCED')==='1'&&(!$j['target']['preview']||getenv('CAJEER_AUTO_UPDATE_ALLOW_PREVIEW_INSTALL')==='1')&&
            AutomaticUpdates::window()&&AutomaticUpdates::window($s['settings']['window']);
    }
    /** One job per invocation; no privilege escalation, shell commands or service control. */
    public function work(bool $scheduled=false): array
    {
        if(PHP_SAPI!=='cli'||getenv('CAJEER_UPDATE_CONTROL_WORKER')!=='1')throw new \RuntimeException('CLI worker requires explicit operator opt-in');
        $lease=$this->lock('worker.lock');
        try {
            $claim=$this->edit(function(array &$s)use($scheduled):array {
                if(($s['job']['status']??null)==='running') {if($scheduled&&$s['job']['type']==='install')$s['settings']['install']=false;self::finish($s,'interrupted');$s['check']=null;$s['last_attempt']=time();return ['action'=>'interrupted'];}
                if((new UpdateEngine($this->root))->status()['pending']!==null)return ['action'=>'recovery_required'];
                if($s['job']===null) {
                    if($s['settings']['install']&&$this->fresh($s)&&self::verified($s['check']['verified'])) {
                        // Do not enqueue unattended work outside the system-approved window.
                        $probe=['type'=>'install','target'=>$s['check']['verified']];
                        if(self::allowed($s,$probe))self::queue($s,'install',$probe['target']);
                    }
                    if($s['job']===null&&$s['settings']['checks']&&($s['last_attempt']>time()||time()-$s['last_attempt'] >= $s['settings']['interval']*3600))self::queue($s,'check');
                }
                if($s['job']===null)return ['action'=>'idle'];
                if(time()-$s['job']['at']>self::TTL||$s['job']['at']>time()||($s['job']['type']==='install'&&(!$this->fresh($s)||$s['job']['target']!==$s['check']['verified']))) {
                    self::finish($s,'expired');return ['action'=>'expired'];
                }
                if(($s['job']['not_before']??0)>time())return ['action'=>'retry_wait','id'=>$s['job']['id'],'next_at'=>$s['job']['not_before']];
                if(!self::allowed($s,$s['job']))return ['action'=>'deferred','id'=>$s['job']['id']];
                $s['job']['status']='running';
                if($s['job']['type']==='check')$s['job']['attempts']=min(count(self::RETRY_DELAYS)+1,($s['job']['attempts']??0)+1);
                $s['last_attempt']=time();
                return ['action'=>'claimed','job'=>$s['job'],'settings'=>$s['settings']];
            });
            if($claim['action']!=='claimed')return $claim;
            $job=$claim['job'];$auto=new AutomaticUpdates($this->root,$this->transport);
            try {
                if($job['type']==='check'){$check=$auto->verifiedCheck($job['channel']);$result='checked';}
                else {
                    $check=null;$run=$auto->run($job['target'],$job['channel'],$claim['settings']['window']);
                    $result=$run['action']==='installed'?'installed':null;
                }
                $this->edit(function(array &$s)use($job,$check,$result,$scheduled):void {
                    if(($s['job']['id']??null)!==$job['id']||$s['job']['status']!=='running')throw new \RuntimeException('Job state changed');
                    if($result===null){$s['job']['status']='queued';return;}
                    $s['check']=$check;self::finish($s,$result);
                    if($scheduled&&$result==='installed')$s['last_attempt']=0; // Re-discover the next step in a fresh process.
                });
                return ['action'=>$result??'deferred','id'=>$job['id']];
            } catch(\Throwable $e) {
                $retry=$this->edit(function(array &$s)use($job,$scheduled):?int {
                    if(($s['job']['id']??null)!==$job['id'])return null;
                    $s['check']=null;
                    $delay=self::RETRY_DELAYS[($job['attempts']??1)-1]??null;
                    if($scheduled&&$job['type']==='check'&&$delay!==null&&time()+$delay<=$job['at']+self::TTL){
                        $s['job']['status']='queued';$s['job']['not_before']=time()+$delay;return $s['job']['not_before'];
                    }
                    // Never replay a failed installation unattended. Require renewed opt-in.
                    if($scheduled&&$job['type']==='install')$s['settings']['install']=false;
                    self::finish($s,'failed');return null;
                });
                if($retry!==null)return ['action'=>'retry_scheduled','id'=>$job['id'],'next_at'=>$retry,'attempts'=>$job['attempts']];
                throw $e;
            }
        } finally {OperationLock::release($lease);}
    }
}
