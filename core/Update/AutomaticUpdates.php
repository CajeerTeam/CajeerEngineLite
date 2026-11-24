<?php
declare(strict_types=1);
namespace CajeerEngine\Update;

/** Cron-invoked release discovery, verified download and opt-in update orchestration. */
final class AutomaticUpdates
{
    private const STATE='storage/app/updates/auto/state.json';
    private const LOCK='storage/app/updates/auto/check.lock';
    private const DOWNLOAD='storage/app/updates/auto/downloads';
    public function __construct(private readonly string $root,private readonly ?ReleaseHttp $transport=null) {}

    private function engine(): UpdateEngine {return new UpdateEngine($this->root);}
    private function path(string $relative): string{return OperationFiles::path($this->root,$relative);}
    private static function flag(string $name): bool {return getenv($name)==='1';}
    private static function repository(): string
    {
        $repo=getenv('CAJEER_AUTO_UPDATE_GITHUB_REPO');
        if(!is_string($repo)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}\/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/D',$repo))
            throw new \RuntimeException('Configure CAJEER_AUTO_UPDATE_GITHUB_REPO=owner/repository');
        return $repo;
    }
    public static function window(?string $spec=null,?int $now=null): bool
    {
        $spec ??= getenv('CAJEER_AUTO_UPDATE_WINDOW_UTC') ?: '';
        if(!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])-([01][0-9]|2[0-3]):([0-5][0-9])$/D',$spec,$m))
            throw new \RuntimeException('Set CAJEER_AUTO_UPDATE_WINDOW_UTC=HH:MM-HH:MM (UTC)');
        $from=(int)$m[1]*60+(int)$m[2];$to=(int)$m[3]*60+(int)$m[4];
        if($from===$to)throw new \RuntimeException('Maintenance window cannot have zero duration');
        $minute=(int)gmdate('G',$now??time())*60+(int)gmdate('i',$now??time());
        return $from<$to?($minute>=$from&&$minute<$to):($minute>=$from||$minute<$to);
    }
    private function readState(): ?array
    {
        $file=$this->path(self::STATE);
        if(!file_exists($file))return null;
        $state=OperationFiles::read($file);
        if(($state['format']??null)!==1||!is_string($state['event']??null)||!is_string($state['at']??null))
            throw new \RuntimeException('Invalid automatic update state');
        return $state;
    }
    private function record(string $event,array $details=[]): void
    {
        OperationFiles::json($this->path(self::STATE),['format'=>1,'event'=>$event,'at'=>gmdate('c')]+$details);
    }
    private function lock(): mixed
    {
        $handle=OperationLock::openLockFile($this->path(self::LOCK),0600);
        if(!flock($handle,LOCK_EX|LOCK_NB)){fclose($handle);throw new \RuntimeException('Automatic update already running');}
        return $handle;
    }
    private function fetch(?string $channel=null): array
    {
        return (new ReleaseDiscovery($this->root,$this->transport??new ReleaseHttp()))->fetch(
            self::repository(),UpdatePackage::version($this->root),$channel??ReleaseDiscovery::channel());
    }
    public function status(): array
    {
        return ['installed_version'=>UpdatePackage::version($this->root),
            'channel'=>ReleaseDiscovery::channel(),
            'enabled'=>self::flag('CAJEER_AUTO_UPDATE_ENABLED'),
            'automatic_install'=>self::flag('CAJEER_AUTO_UPDATE_INSTALL'),
            'pending'=>$this->engine()->status()['pending'],
            'last_run'=>$this->readState()];
    }
    public function check(): array
    {
        $lock=$this->lock();
        try {
            $result=$this->fetch();
            $this->record('checked',['installed'=>$result['installed'],
                'available'=>$result['available']['version']??null,'channel'=>$result['channel'],
                'distribution'=>$result['distribution'],'plan'=>array_column($result['plan'],'version'),
                'pages'=>$result['pages'],'revalidated_pages'=>$result['revalidated_pages']]);
            return ['action'=>'checked']+$result;
        } catch(\Throwable $e) {
            try {$this->record('failed',['failure_type'=>get_class($e)]);}
            catch(\Throwable) {error_log('Auto update could not record discovery failure');}
            throw $e;
        } finally {OperationLock::release($lock);}
    }
    private function within(?string $window): bool
    {
        return self::window() && ($window===null || self::window($window));
    }
    /** Download and verify exact-base package, without modifying runtime files. */
    public function verifiedCheck(string $channel): array
    {
        if(!in_array($channel,['stable','preview'],true)) throw new \InvalidArgumentException('Invalid channel');
        $lock=$this->lock(); $download=null;
        try {
            $result=$this->fetch($channel); $release=$result['available']; $verified=null;
            if($release!==null) {
                OperationFiles::dir($this->path(self::DOWNLOAD),0700);
                $download=$this->path(self::DOWNLOAD.'/'.bin2hex(random_bytes(16)).'.cjrupdate');
                $receipt=($this->transport??new ReleaseHttp())->download($release['url'],$download,$release['bytes'],$release['sha256']);
                $check=$this->engine()->verify($download);
                if($receipt['bytes']!==$release['bytes'] || $check['from']!==$result['installed'] || $check['to']!==$release['version'] ||
                   !hash_equals($check['package_sha256'],$receipt['sha256']) || ($release['sha256']!==null&&!hash_equals($release['sha256'],$receipt['sha256'])))
                    throw new \RuntimeException('Package verification mismatch');
                $verified=['from'=>$check['from'],'to'=>$check['to'],'sha256'=>$check['package_sha256'],'files'=>$check['files'],'preview'=>$release['prerelease']];
            }
            return ['installed'=>$result['installed'],'latest'=>$result['latest']['version']??null,'verified'=>$verified,'channel'=>$channel,
                'notes'=>$release['notes']??'','plan'=>array_column($result['plan'],'version'),'at'=>time()];
        } finally {
            try {if(is_string($download)&&is_file($download)&&!is_link($download))OperationFiles::remove($download);}
            finally {OperationLock::release($lock);}
        }
    }
    public function run(?array $expected=null, ?string $channel=null, ?string $window=null): array
    {
        if(!self::flag('CAJEER_AUTO_UPDATE_ENABLED'))throw new \RuntimeException('Auto updates disabled: CAJEER_AUTO_UPDATE_ENABLED=1 required');
        if(!self::flag('CAJEER_AUTO_UPDATE_INSTALL'))throw new \RuntimeException('Auto install disabled: CAJEER_AUTO_UPDATE_INSTALL=1 required');
        if(!self::flag('CAJEER_AUTO_UPDATE_WORKERS_QUIESCED'))throw new \RuntimeException('Worker quiescence acknowledgement required');
        // Prevent any network requests or file changes outside the operator-approved window.
        if(!$this->within($window))return ['action'=>'outside_window','version'=>UpdatePackage::version($this->root)];
        $lock=$this->lock();
        $download=null;
        try {
            $pending=$this->engine()->status()['pending'];
            if($pending!==null) {
                if($expected!==null)throw new \RuntimeException('Interrupted update requires operator recovery');
                if(!self::flag('CAJEER_AUTO_UPDATE_RECOVER'))throw new \RuntimeException('Interrupted update requires update recover (or explicit auto recovery opt-in)');
                $result=$this->engine()->recover();
                try {$this->record('recovered',['result'=>$result]);}
                catch(\Throwable $logError) {error_log('Auto update status write failed after recovery');}
                return ['action'=>'recovered','result'=>$result];
            }
            if(!TrustPolicy::managedConfigured()&&!self::flag('CAJEER_UPDATE_ALLOW_LEGACY_AUTO'))
                throw new \RuntimeException('Managed update trust policy required for automatic installation');
            if(!TrustPolicy::managedConfigured())TrustPolicy::audit('legacy_auto_opt_in');
            // Re-evaluate the window after locking; cron may start near its end.
            if(!$this->within($window))return ['action'=>'outside_window','version'=>UpdatePackage::version($this->root)];
            $result=$this->fetch($channel);
            $release=$result['available'];
            if ($expected!==null && ($result['installed']!==$expected['from'] || ($release['version']??null)!==$expected['to'] || ($release['prerelease']??null)!==$expected['preview'])) throw new \RuntimeException('Requested update is no longer the available exact transition');
            if($release===null) {
                $this->record('up_to_date',['installed'=>$result['installed']]);
                return ['action'=>'up_to_date','version'=>$result['installed']];
            }
            if ($release['prerelease'] && !self::flag('CAJEER_AUTO_UPDATE_ALLOW_PREVIEW_INSTALL')) throw new \RuntimeException('Preview installation requires CAJEER_AUTO_UPDATE_ALLOW_PREVIEW_INSTALL=1');
            if (!$this->within($window)) return ['action'=>'window_closed','version'=>$result['installed']];
            OperationFiles::dir($this->path(self::DOWNLOAD),0700);
            $download=$this->path(self::DOWNLOAD.'/'.bin2hex(random_bytes(16)).'.cjrupdate');
            $file=($this->transport??new ReleaseHttp())->download($release['url'],$download,$release['bytes'],$release['sha256']);
            if($file['bytes']!==$release['bytes']||!preg_match('/^[a-f0-9]{64}$/D',$file['sha256'])||($release['sha256']!==null&&!hash_equals($release['sha256'],$file['sha256'])))throw new \RuntimeException('Invalid download receipt');
            $check=$this->engine()->verify($download);
            if($check['from']!==$result['installed']||$check['to']!==$release['version']||
                !hash_equals($check['package_sha256'],$file['sha256']))throw new \RuntimeException('Signed package version or checksum mismatch');
            if($expected!==null && !hash_equals($expected['sha256'],$check['package_sha256'])) throw new \RuntimeException('Verified package changed; check again');
            if(!$this->within($window)) {
                $this->record('window_closed',['available'=>$release['version']]);
                return ['action'=>'window_closed','version'=>$result['installed']];
            }
            $previous=getenv('CAJEER_UPDATE_CONFIRM');
            putenv('CAJEER_UPDATE_CONFIRM=INSTALL');
            try {$installed=$this->engine()->install($download,$check['package_sha256']);}
            finally {$previous===false?putenv('CAJEER_UPDATE_CONFIRM'):putenv('CAJEER_UPDATE_CONFIRM='.$previous);}
            try {$this->record('installed',['version'=>$release['version'],'id'=>$installed['id'],
                'package_sha256'=>$file['sha256']]);}
            catch(\Throwable $logError) {error_log('Auto update status write failed after successful install');}
            return ['action'=>'installed','installed'=>$installed,'release_sha256'=>$file['sha256']];
        } catch(\Throwable $e) {
            // Do not store credentials, request bodies, or potentially attacker-controlled error strings.
            try {$this->record('failed',['failure_type'=>get_class($e)]);}
            catch(\Throwable $logError) {error_log('Auto update could not record failure status');}
            throw $e;
        } finally {
            try {if(is_string($download)&&is_file($download)&&!is_link($download))OperationFiles::remove($download);}
            finally {OperationLock::release($lock);}
        }
    }
}
