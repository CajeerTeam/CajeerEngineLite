<?php
declare(strict_types=1);
namespace CajeerEngine\Update;

use CajeerEngine\Storage\SiteState;

/** Offline, transaction-journaled installer. Never runs scripts from a release package. */
final class UpdateEngine
{
    private const HISTORY = 'storage/app/updates/history';
    private const ACTIVE = 'storage/app/updates/active.json';
    private const LAST = 'storage/app/updates/last.json';
    private const STAGE = 'storage/app/updates/staging';

    public function __construct(private readonly string $root) {}

    private function location(string $path): string { return OperationFiles::path($this->root,$path); }
    private function trusted(): TrustPolicy { return TrustPolicy::configured(); }
    private static function id(string $id): string
    {
        if(!preg_match('/^[a-f0-9]{24}$/D',$id)) throw new \InvalidArgumentException('Invalid update ID');
        return $id;
    }
    private function readPrivate(string $path,int $max=262144): array
    {
        clearstatcache(true,$path);$st=@lstat($path);
        if(!is_array($st)||($st['mode']&0077)!==0)throw new \RuntimeException('Unsafe update transaction permissions');
        $data=json_decode(UpdatePackage::readRegular($path,$max),true,32,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new \RuntimeException('Invalid update transaction data');
        return $data;
    }
    private function journal(): ?array
    {
        $file=$this->location(self::ACTIVE);
        if(!file_exists($file))return null;
        $data=$this->readPrivate($file,4096);
        if(array_keys($data)!==['id','mode'] || !in_array($data['mode'],['install','rollback'],true)||!is_string($data['id'])) throw new \RuntimeException('Invalid update recovery journal');
        self::id($data['id']);
        return $data;
    }
    private function plan(string $id): array
    {
        $data=$this->readPrivate($this->location(self::HISTORY.'/'.self::id($id).'/plan.json'));
        if(array_keys($data)!==['id','from','to','created_at','files','site_sha256'] || $data['id']!==$id ||
            !DistributionIndex::version($data['from'])||!DistributionIndex::version($data['to'])||version_compare($data['from'],$data['to'],'>=')||!is_array($data['files'])||!array_is_list($data['files'])||count($data['files'])<2||count($data['files'])>UpdatePackage::MAX_FILES||
            !is_string($data['created_at'])||($data['site_sha256']!==null&&(!is_string($data['site_sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$data['site_sha256'])))) throw new \RuntimeException('Invalid update recovery plan');
        $prior='';$versions=[];
        foreach($data['files'] as $entry) {
            if(!is_array($entry)||array_keys($entry)!==['path','before','after','before_mode','mode']||
                !is_string($entry['path'])||!UpdatePackage::permitted($entry['path'])||strcmp($prior,$entry['path'])>=0||
                ($entry['before']!==null&&(!is_string($entry['before'])||!preg_match('/^[a-f0-9]{64}$/D',$entry['before'])))||
                ($entry['after']!==null&&(!is_string($entry['after'])||!preg_match('/^[a-f0-9]{64}$/D',$entry['after'])))||
                !in_array($entry['before_mode'],[0,0644,0755],true)||!in_array($entry['mode'],[0,0644,0755],true)||
                ($entry['before']===null)!==($entry['before_mode']===0)||($entry['after']===null)!==($entry['mode']===0)||
                ($entry['before']===null&&$entry['after']===null)) throw new \RuntimeException('Invalid update plan entry');
            $prior=$entry['path'];
            if(in_array($prior,['VERSION','core/Lite.php'],true)&&$entry['before']!==null&&$entry['after']!==null)$versions[]=$prior;
        }
        if(count($versions)!==2)throw new \RuntimeException('Missing recovery version entries');
        return $data;
    }
    private function fresh(string $path): void
    {
        if(file_exists($path)||is_link($path))throw new \RuntimeException('Update workspace already exists');
        OperationFiles::dir(dirname($path),0700);
        if(!mkdir($path,0700)) throw new \RuntimeException('Cannot create private update workspace');
    }
    /** Remove only updater-owned private staging files; history snapshots remain. */
    private static function cleanup(string $dir): void
    {
        if(!file_exists($dir)&&!is_link($dir))return;
        if(is_link($dir)||!is_dir($dir)) throw new \RuntimeException('Unsafe updater staging directory');
        foreach(new \DirectoryIterator($dir) as $entry) {
            if($entry->isDot())continue;
            $path=$entry->getPathname();
            if($entry->isLink())throw new \RuntimeException('Symlink in updater staging');
            if($entry->isDir())self::cleanup($path);
            elseif($entry->isFile()) {if(!unlink($path))throw new \RuntimeException('Cannot clean updater staging file');}
            else throw new \RuntimeException('Unsafe updater staging member');
        }
        if(!rmdir($dir))throw new \RuntimeException('Cannot clean updater staging directory');
    }
    private function report(array $result): array
    {
        return ['from'=>$result['manifest']['from'],'to'=>$result['manifest']['to'],
            'files'=>count($result['manifest']['files']),'signature_key_sha256'=>$result['key_sha256'],
            'package_sha256'=>$result['package_sha256']];
    }
    public function verify(string $package): array
    {
        try {
            $result=UpdatePackage::verify($package,$this->trusted());
            $this->preflight($result['manifest']);
            $report=$this->report($result);
            TrustPolicy::audit('verified',$report+['key_sha256'=>$result['key_sha256']]);
            return $report;
        } catch(\Throwable $e) {TrustPolicy::audit('verification_rejected');throw $e;}
    }
    private static function runtimeHash(string $path): string
    {return hash('sha256',UpdatePackage::readRegular($path,UpdatePackage::MAX_FILE));}
    private function preflight(array $manifest): void
    {
        if(version_compare(PHP_VERSION,$manifest['php_min'],'<')) throw new \RuntimeException('PHP runtime is incompatible with this release');
        if(UpdatePackage::version($this->root)!==$manifest['from']) throw new \RuntimeException('Update base version does not match installed version');
        if($this->journal()!==null) throw new \RuntimeException('Incomplete update; run update recover before continuing');
        if(OperationLock::maintenance($this->root)) throw new \RuntimeException('Another maintenance or recovery operation is pending');
        // Code-only releases never mutate site data and do not execute migration hooks.
        $site=$this->location('storage/site.json');
        if(file_exists($site)) {
            $state=OperationFiles::read($site);
            $schema=$state['schema']??null;
            if (!is_int($schema)||$schema<$manifest['schema_min']||$schema>$manifest['schema_max']) throw new \RuntimeException('Site schema incompatible with update');
            SiteState::validate($state);
        }
        foreach($manifest['files'] as $entry) {
            $path=$this->location($entry['path']);
            $existing=@lstat($path);
            if($entry['before']===null) {
                if($existing!==false) throw new \RuntimeException('Unexpected existing file: '.$entry['path']);
            } else {
                if(!is_array($existing)||($existing['mode']&0170000)!==0100000||$existing['nlink']!==1||
                    !hash_equals($entry['before'],self::runtimeHash($path))) throw new \RuntimeException('Modified base file: '.$entry['path']);
            }
        }
    }
    public function install(string $package,?string $expectedSha256=null): array
    {
        if(getenv('CAJEER_UPDATE_CONFIRM')!=='INSTALL') throw new \RuntimeException('Stop PHP-FPM/workers and set CAJEER_UPDATE_CONFIRM=INSTALL');
        $lock=OperationLock::acquire($this->root,true);
        $id=bin2hex(random_bytes(12));
        $stage=$this->location(self::STAGE.'/'.$id);
        $history=$this->location(self::HISTORY.'/'.$id);
        $activated=false;
        try {
            if($this->journal()!==null) throw new \RuntimeException('Incomplete update; recover first');
            $this->fresh($stage);
            $validated=UpdatePackage::verify($package,$this->trusted(),$stage);
            if($expectedSha256!==null&&!hash_equals($expectedSha256,$validated['package_sha256']))throw new \RuntimeException('Staged package differs from verified download');
            $manifest=$validated['manifest'];
            $this->preflight($manifest);
            $newVersion=$this->fromStaged($stage,'VERSION');
            if(trim($newVersion)!==$manifest['to']) throw new \RuntimeException('Payload VERSION does not match signed manifest');
            $class=$this->fromStaged($stage,'core/Lite.php');
            if(!preg_match("/public const VERSION\\s*=\\s*'".preg_quote($manifest['to'],'/')."'\\s*;/",$class)) throw new \RuntimeException('Payload application version mismatch');
            $this->lint($manifest,$stage);
            // Refresh policy before the first persistent change, including revocation.
            $fresh=$this->trusted();
            $fresh->assertTarget($manifest['to']);
            if(!in_array($validated['key_sha256'],$fresh->report()['active_fingerprints'],true))throw new \RuntimeException('Signing key no longer trusted');
            $this->fresh($history);
            $plan=['id'=>$id,'from'=>$manifest['from'],'to'=>$manifest['to'],'created_at'=>gmdate('c'),'files'=>[],'site_sha256'=>null];
            $site=$this->location('storage/site.json');
            if(file_exists($site)) {
                $plan['site_sha256']=OperationFiles::hash($site);
                OperationFiles::copy($site,$history.'/site.json',0600);
            }
            foreach($manifest['files'] as $entry) {
                $current=$this->location($entry['path']);
                $oldMode=$entry['before']===null?0:((fileperms($current)&0111)!==0?0755:0644);
                if($entry['before']!==null) {
                    $backup=OperationFiles::path($history,'original/'.$entry['path']);
                    OperationFiles::copy($current,$backup,0600);
                    if(!hash_equals($entry['before'],self::runtimeHash($backup))) throw new \RuntimeException('Pre-update snapshot failed integrity check');
                }
                $plan['files'][]=['path'=>$entry['path'],'before'=>$entry['before'],'after'=>$entry['after'],
                    'before_mode'=>$oldMode,'mode'=>$entry['mode']];
            }
            OperationFiles::json($history.'/plan.json',$plan);
            OperationFiles::json($this->location(self::ACTIVE),['id'=>$id,'mode'=>'install']);
            $activated=true;
            // Publish all changes; VERSION is written last and indicates a complete release.
            foreach($manifest['files'] as $entry) if(!in_array($entry['path'],['VERSION','core/Lite.php'],true)) $this->apply($entry,$stage);
            foreach(['core/Lite.php','VERSION'] as $last) foreach($manifest['files'] as $entry) if($entry['path']===$last) $this->apply($entry,$stage);
            $this->validateInstalled($plan,true);
            // A successful install preserves its private recovery snapshot for CLI rollback.
            OperationFiles::json($this->location(self::LAST),['id'=>$id,'from'=>$plan['from'],'to'=>$plan['to']]);
            OperationFiles::remove($this->location(self::ACTIVE)); $activated=false;
            TrustPolicy::audit('installed',['id'=>$id,'from'=>$plan['from'],'to'=>$plan['to'],'key_sha256'=>$validated['key_sha256'],'package_sha256'=>$validated['package_sha256']]);
            return ['id'=>$id,'from'=>$plan['from'],'to'=>$plan['to'],'changed'=>count($plan['files']),
                'rollback'=>'php bin/cajeer update rollback '.$id];
        } catch(\Throwable $error) {
            TrustPolicy::audit('installation_failed',['id'=>$id]);
            if($activated) {
                try {$this->restore($this->plan($id));OperationFiles::remove($this->location(self::ACTIVE));}
                catch(\Throwable $rollback) { throw new \RuntimeException('Update failed and automatic rollback failed; run update recover: '.$rollback->getMessage(),0,$error); }
            }
            throw $error;
        } finally {
            if(!$activated) {
                try {self::cleanup($stage);} catch(\Throwable $failure) {error_log('Update staging cleanup: '.get_class($failure));}
            }
            OperationLock::release($lock);
        }
    }
    private function fromStaged(string $stage,string $name): string
    {
        $file=OperationFiles::path($stage,'files/'.$name);
        if(!is_file($file)||is_link($file)||filesize($file)>UpdatePackage::MAX_FILE) throw new \RuntimeException('Missing staged release file');
        $contents=UpdatePackage::readRegular($file,UpdatePackage::MAX_FILE);
        if(!is_string($contents))throw new \RuntimeException('Cannot read staged file');
        return $contents;
    }
    /** Lint every replacement PHP source before publishing the first file. */
    private function lint(array $manifest,string $stage): void
    {
        if(!function_exists('proc_open')||!is_file(PHP_BINARY)) throw new \RuntimeException('CLI PHP linter is unavailable');
        foreach($manifest['files'] as $file) {
            if($file['action']!=='write'||(!str_ends_with($file['path'],'.php')&&$file['path']!=='bin/cajeer')) continue;
            $path=OperationFiles::path($stage,'files/'.$file['path']);
            $process=@proc_open([PHP_BINARY,'-n','-l',$path],
                [['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
            if(!is_resource($process))throw new \RuntimeException('Cannot launch PHP syntax check');
            fclose($pipes[0]);
            $stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
            $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
            $status=proc_close($process);
            if($status!==0)throw new \RuntimeException('Invalid release PHP syntax: '.$file['path']);
        }
    }
    private function apply(array $entry,string $stage): void
    {
        $path=$this->location($entry['path']);
        // Check the signed preimage again immediately before publication.
        clearstatcache(true,$path);
        if($entry['before']===null) {if(@lstat($path)!==false)throw new \RuntimeException('Unexpected new-file destination');}
        elseif(!hash_equals($entry['before'],self::runtimeHash($path)))throw new \RuntimeException('Base changed before publication');
        if($entry['action']==='remove') {OperationFiles::remove($path);return;}
        $data=$this->fromStaged($stage,$entry['path']);
        if(!hash_equals($entry['after'],hash('sha256',$data))) throw new \RuntimeException('Staged release data changed');
        // Directories created by the updater must be readable by PHP-FPM/web workers.
        OperationFiles::dir(dirname($path),0755);
        if($entry['before']===null)OperationFiles::writeNew($path,$data,$entry['mode']);
        else OperationFiles::write($path,$data,$entry['mode']);
        if(!hash_equals($entry['after'],self::runtimeHash($path))) throw new \RuntimeException('Installed release file checksum failed');
    }
    private function validateInstalled(array $plan,bool $installed): void
    {
        foreach($plan['files'] as $entry) {
            $actual=$installed?$entry['after']:$entry['before'];
            $path=$this->location($entry['path']);
            if($actual===null) {if(file_exists($path)||is_link($path))throw new \RuntimeException('Unexpected release file: '.$entry['path']);}
            elseif(!hash_equals($actual,self::runtimeHash($path))) throw new \RuntimeException('Release integrity mismatch: '.$entry['path']);
        }
        if(UpdatePackage::version($this->root)!==($installed?$plan['to']:$plan['from'])) throw new \RuntimeException('Release version validation failed');
    }
    private function restore(array $plan): void
    {
        // Refuse to overwrite unrelated edits. Both old and new states are legal
        // during a crash in the middle of atomic per-file publishing.
        foreach($plan['files'] as $entry) {
            $file=$this->location($entry['path']);
            if(file_exists($file)||is_link($file)) {
                $hash=self::runtimeHash($file);
                if(!in_array($hash,array_filter([$entry['before'],$entry['after']]),true)) throw new \RuntimeException('Cannot rollback modified file: '.$entry['path']);
            } elseif($entry['before']!==null&&$entry['after']!==null) {
                // A missing file is not a valid state for an overwrite operation.
                throw new \RuntimeException('Cannot rollback missing file: '.$entry['path']);
            }
            if($entry['before']!==null) {
                $backup=$this->location(self::HISTORY.'/'.$plan['id'].'/original/'.$entry['path']);
                if(!hash_equals($entry['before'],self::runtimeHash($backup))) throw new \RuntimeException('Recovery snapshot checksum mismatch');
            }
        }
        // Restore VERSION last, for the same reason it is installed last.
        foreach($plan['files'] as $entry) if(!in_array($entry['path'],['VERSION','core/Lite.php'],true)) $this->restoreOne($plan['id'],$entry);
        foreach(['core/Lite.php','VERSION'] as $last) foreach($plan['files'] as $entry) if($entry['path']===$last) $this->restoreOne($plan['id'],$entry);
        $this->validateInstalled($plan,false);
        if($plan['site_sha256']!==null) {
            $site=$this->location('storage/site.json');
            // Updates have no site migrations. Never roll back independently edited live content.
            if(!is_file($site)) throw new \RuntimeException('Site state missing after update rollback');
        }
    }
    private function restoreOne(string $id,array $entry): void
    {
        $target=$this->location($entry['path']);
        if($entry['before']===null) {OperationFiles::remove($target);return;}
        $source=$this->location(self::HISTORY.'/'.$id.'/original/'.$entry['path']);
        $data=UpdatePackage::readRegular($source,UpdatePackage::MAX_FILE);
        if(!is_string($data)||hash('sha256',$data)!==$entry['before'])throw new \RuntimeException('Invalid backup content');
        OperationFiles::dir(dirname($target),0755);
        OperationFiles::write($target,$data,$entry['before_mode']);
    }
    public function recover(): array
    {
        $lock=OperationLock::acquire($this->root,true);
        try {
            $active=$this->journal();
            if($active===null)return ['status'=>'clean'];
            $plan=$this->plan($active['id']);
            // Load audit code before rollback can remove this version's class file.
            TrustPolicy::audit('recovery_started',['id'=>$active['id']]);
            $this->restore($plan);
            OperationFiles::remove($this->location(self::ACTIVE));
            try {self::cleanup($this->location(self::STAGE.'/'.$active['id']));}
            catch(\Throwable $failure) {error_log('Update staging cleanup: '.get_class($failure));}
            $last=$this->location(self::LAST);
            if(is_file($last)&& ($this->readPrivate($last,4096)['id']??null)===$active['id'])OperationFiles::remove($last);
            TrustPolicy::audit('recovered',['id'=>$active['id'],'from'=>$plan['to'],'to'=>$plan['from']]);
            return ['status'=>'recovered','id'=>$active['id'],'version'=>$plan['from']];
        } finally {OperationLock::release($lock);}
    }
    public function rollback(string $id): array
    {
        if(getenv('CAJEER_UPDATE_CONFIRM')!=='ROLLBACK') throw new \RuntimeException('Stop workers and set CAJEER_UPDATE_CONFIRM=ROLLBACK');
        self::id($id);
        $lock=OperationLock::acquire($this->root,true);
        try {
            if($this->journal()!==null)throw new \RuntimeException('Incomplete update; recover first');
            $last=$this->readPrivate($this->location(self::LAST),4096);
            if(($last['id']??null)!==$id)throw new \RuntimeException('Only the latest installed update can be rolled back');
            $plan=$this->plan($id);
            if(UpdatePackage::version($this->root)!==$plan['to'])throw new \RuntimeException('Cannot rollback unexpected installed version');
            TrustPolicy::audit('rollback_started',['id'=>$id,'from'=>$plan['to'],'to'=>$plan['from']]);
            OperationFiles::json($this->location(self::ACTIVE),['id'=>$id,'mode'=>'rollback']);
            $this->restore($plan);
            OperationFiles::remove($this->location(self::LAST));
            OperationFiles::remove($this->location(self::ACTIVE));
            TrustPolicy::audit('rolled_back',['id'=>$id,'from'=>$plan['to'],'to'=>$plan['from']]);
            return ['status'=>'rolled_back','id'=>$id,'version'=>$plan['from']];
        } finally {OperationLock::release($lock);}
    }
    /** Called during offline maintenance with the exclusive operations lease held. */
    public function verifyCurrentSnapshot(): bool
    {
        if(!OperationLock::exclusiveHeld($this->root))throw new \RuntimeException('Exclusive operations lease required for verification');
        $status=$this->status();
        if($status['pending']!==null)throw new \RuntimeException('Incomplete update');
        $last=$status['last'];
        if($last===null)return false; // Manual installations have no engine snapshot.
        if(!is_string($last['id']??null)||!is_string($last['to']??null))throw new \RuntimeException('Invalid installed update receipt');
        $plan=$this->plan($last['id']);
        if($plan['from']!==($last['from']??null)||$plan['to']!==$last['to'])throw new \RuntimeException('Installed update plan mismatch');
        if($plan['to']!==$status['installed_version']) {
            // A manually overlaid newer release can retain an older receipt.
            // Never claim that receipt covers the new runtime.
            if(version_compare($plan['to'],$status['installed_version'],'<'))return false;
            throw new \RuntimeException('Installed update receipt mismatch');
        }
        $this->validateInstalled($plan,true);
        return true;
    }
    public function status(): array
    {
        $last=$this->location(self::LAST);
        return ['installed_version'=>UpdatePackage::version($this->root),'pending'=>$this->journal(),
            'last'=>is_file($last)?$this->readPrivate($last,4096):null];
    }
}
