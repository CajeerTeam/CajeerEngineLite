<?php
declare(strict_types=1);
namespace CajeerEngine\Release;

use CajeerEngine\Lite;
use CajeerEngine\Update\{AutomaticUpdates,ControlCenter,DistributionIndex,OperationFiles,OperationLock,ReleaseHttp,TrustPolicy,UpdatePackage};

/** Final acceptance for unattended Stable updates. No installation is performed here. */
final class UpdatesGA
{
    public const EVIDENCE=['github_update_e2e','systemd_reboot_recovery','supervisor_preflight'];
    private const MAX_TOTAL=256_000_000;

    /** Exact signed distribution inventory, including the RC upgrade path. */
    public static function distribution(string $dir,TrustPolicy $key,array $artifact): array
    {
        $raw=UpdatePackage::readRegular(OperationFiles::path($dir,DistributionIndex::NAME),DistributionIndex::MAX_BYTES);
        $hash=hash('sha256',$raw);
        if(!hash_equals($artifact['distribution_index_sha256']??'', $hash))throw new \RuntimeException('GA requires the signed distribution index');
        $entries=DistributionIndex::verify($raw,Lite::VERSION,$key);
        $from=array_column($entries,'from');
        if(Lite::VERSION==='1.6.0'&&!in_array('1.5.9',$from,true))throw new \RuntimeException('GA distribution lacks the exact RC upgrade package');
        $expected=[DistributionIndex::NAME=>$hash,
            'CajeerEngineLite-'.Lite::VERSION.'.zip'=>$artifact['zip_sha256'],
            'release-report.json'=>$artifact['receipt_sha256']];
        foreach(['SHA256SUMS','SHA256SUMS.sig'] as $name)$expected[$name]=hash('sha256',UpdatePackage::readRegular(OperationFiles::path($dir,$name),16384));
        foreach($entries as $entry)$expected[$entry['name']]=$entry['sha256'];
        $names=array_values(array_diff(scandir($dir)?:[],['.','..']));sort($names,SORT_STRING);ksort($expected,SORT_STRING);
        if($names!==array_keys($expected))throw new \RuntimeException('Unexpected GA artifact inventory');
        return ['index_sha256'=>$hash,'bases'=>$from,'hashes'=>$expected];
    }

    /** Download every actual published asset, compare to independently verified local release.
     * The GitHub digest is optional metadata, not a trust root. Never install a downloaded file. */
    public static function publication(string $root,string $dir,array $distribution,string $repo,?ReleaseHttp $http=null): array
    {
        if(!preg_match('~^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$~D',$repo))throw new \RuntimeException('Configure the official GitHub repository');
        $tag='v'.Lite::VERSION;$http??=new ReleaseHttp();$r=$http->releaseByTag($repo,$tag);
        if(($r['tag_name']??null)!==$tag||($r['draft']??null)!==false||($r['prerelease']??null)!==false||!is_array($r['assets']??null)||!array_is_list($r['assets'])||count($r['assets'])!==count($distribution['hashes']))throw new \RuntimeException('Exact stable release is not published');
        $files=[];$total=0;$prefix='https://github.com/'.$repo;
        foreach($r['assets'] as $asset){
            if(!is_array($asset))throw new \RuntimeException('Invalid published asset entry');
            $name=$asset['name']??null;$url=$asset['browser_download_url']??null;$size=$asset['size']??null;$digest=$asset['digest']??null;
            if(!is_string($name)||!isset($distribution['hashes'][$name])||isset($files[$name])||($asset['state']??null)!=='uploaded'||!is_string($url)||
                strtolower(substr($url,0,strlen($prefix)))!==strtolower($prefix)||substr($url,strlen($prefix))!=='/releases/download/'.$tag.'/'.$name||
                !is_int($size)||$size<60||$size>UpdatePackage::MAX_BYTES||($digest!==null&&$digest!=='sha256:'.$distribution['hashes'][$name]))throw new \RuntimeException('Unexpected published asset metadata');
            ReleaseHttp::assertSafeUrl($url);
            $path=OperationFiles::path($dir,$name);clearstatcache(true,$path);$stat=@lstat($path);
            if(!is_array($stat)||($stat['mode']&0170000)!==0100000||$stat['nlink']!==1||$stat['size']!==$size)throw new \RuntimeException('Published asset size differs from local distribution');
            $total+=$size;if($total>self::MAX_TOTAL)throw new \RuntimeException('GA publication exceeds aggregate download budget');
            $files[$name]=['url'=>$url,'size'=>$size,'sha256'=>$distribution['hashes'][$name]];
        }
        ksort($files,SORT_STRING);
        $lock=OperationLock::openLockFile(OperationFiles::path($root,'storage/app/updates/auto/check.lock'),0600);
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new \RuntimeException('Update discovery is busy');}
        try {
            $downloadDir=OperationFiles::path($root,'storage/app/updates/auto/downloads');OperationFiles::dir($downloadDir,0700);
            $deadline=hrtime(true)+300_000_000_000;
            foreach($files as $entry){
                if(hrtime(true)>$deadline)throw new \RuntimeException('GA publication verification time budget exceeded');
                $dest=$downloadDir.'/'.bin2hex(random_bytes(16)).'.ga';
                try {
                    $receipt=$http->download($entry['url'],$dest,$entry['size'],$entry['sha256']);
                    if($receipt['bytes']!==$entry['size']||!hash_equals($entry['sha256'],$receipt['sha256'])||!hash_equals($entry['sha256'],(string)hash_file('sha256',$dest)))throw new \RuntimeException('Published asset differs from signed distribution');
                } finally {if(is_file($dest)&&!is_link($dest))OperationFiles::remove($dest);}
            }
        } finally {OperationLock::release($lock);}
        $identity=['repository'=>strtolower($repo),'tag'=>$tag,'hashes'=>$distribution['hashes']];
        return ['repository'=>$identity['repository'],'tag'=>$tag,'assets'=>count($files),'bytes'=>$total,
            'publication_sha256'=>hash('sha256',json_encode($identity,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];
    }

    /** Report actual configuration/heartbeat. Disabled installation is safe but not GA accepted. */
    public static function configuration(array $status,TrustPolicy $key,bool $live,?int $now=null): array
    {
        $now??=time();$checks=[];
        $add=static function(string $id,bool $ok)use(&$checks,$live):void{$checks[]=['id'=>$id,'status'=>$ok?'pass':($live?'fail':'warn')];};
        $trust=$key->report();$add('updates.managed_trust',$trust['mode']==='managed'&&$key->permitsTarget(Lite::VERSION));
        $add('updates.stable_policy',$status['settings']['channel']==='stable'&&$status['settings']['checks']&&$status['settings']['install']&&
            getenv('CAJEER_AUTO_UPDATE_ENABLED')==='1'&&getenv('CAJEER_AUTO_UPDATE_INSTALL')==='1'&&getenv('CAJEER_AUTO_UPDATE_ALLOW_PREVIEW_INSTALL')!=='1');
        try {AutomaticUpdates::window();$window=true;}catch(\Throwable){$window=false;}
        $add('updates.maintenance_window',$window);
        $heartbeat=$status['scheduler'];$at=$heartbeat['finished_at']??null;
        $add('updates.scheduler_heartbeat',is_int($at)&&$at<=$now&&$at>=$now-900&&in_array($heartbeat['action'],['idle','checked','installed','deferred','retry_wait','retry_scheduled'],true));
        $add('updates.monitoring',$status['monitoring']['ok']&&$status['monitoring']['alerts']===array_values(array_filter($status['monitoring']['alerts'],static fn($alert)=>$alert['severity']==='info')));
        $add('updates.no_pending_work',!$status['pending']&&$status['job']===null);
        $add('updates.recent_verified_check',$status['fresh']&&($status['check']['channel']??null)==='stable');
        return $checks;
    }

    public static function run(string $root,array $site,string $dir,TrustPolicy $key,bool $live,array $providers,bool $paid,?ReleaseHttp $transport=null): array
    {
        if($live&&getenv('CAJEER_PRODUCTION_LIVE_CONFIRM')!=='LIVE')throw new \InvalidArgumentException('GA live checks require explicit LIVE confirmation');
        $artifact=ProductionStable::artifacts($root,$dir,$key);
        $distribution=self::distribution($dir,$key,$artifact);
        $publication=$live?self::publication($root,$dir,$distribution,(string)getenv('CAJEER_AUTO_UPDATE_GITHUB_REPO'),$transport):null;
        $report=ProductionStable::run($root,$site,$dir,$key,$live,$providers,$paid);
        if($report['artifact']!==$artifact||self::distribution($dir,$key,$artifact)!==$distribution)throw new \RuntimeException('Release artifacts changed during GA acceptance');
        if($live&&TrustPolicy::configured()->report()!==$key->report())throw new \RuntimeException('Trust policy changed during live acceptance');
        $control=(new ControlCenter($root))->status();
        $checks=self::configuration($control,$key,$live);
        $checks[]=['id'=>'updates.signed_distribution','status'=>'pass'];
        $checks[]=['id'=>'updates.github_publication','status'=>$live?'pass':'not_run'];
        $report['checks']=array_merge($report['checks'],$checks);
        $report['automated_ok']=$report['automated_ok']&&!in_array('fail',array_column($checks,'status'),true);
        $report['status']=!$report['automated_ok']?'failed':($live?'pending_signoff':'pending_live');
        $report['updates_ga']=['distribution_index_sha256'=>$distribution['index_sha256'],'supported_bases'=>$distribution['bases'],
            'publication'=>$publication,'control_revision'=>$control['revision'],'trust'=>$key->report(),'required_evidence'=>array_merge(ProductionStable::EVIDENCE,self::EVIDENCE)];
        return $report;
    }
}
