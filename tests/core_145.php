<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Update\{AutomaticUpdates,ReleaseHttp,UpdatePackage,UpdateEngine,OperationFiles,OperationLock};
function yes145(bool $condition,string $label): void {if(!$condition)throw new RuntimeException('1.4.5: '.$label);}
function reject145(callable $callback,string $label): void {
    try {$callback();}catch(Throwable){return;}
    throw new RuntimeException('Expected rejection: '.$label);
}
function cleanup145(string $dir): void {
    if(!is_dir($dir))return;
    foreach(new FilesystemIterator($dir) as $entry) {
        if($entry->isDir()&&!$entry->isLink()) cleanup145($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($dir);
}
function fixture145(string $dir,string $version): void {
    mkdir($dir,0700,true);
    OperationFiles::write($dir.'/VERSION',$version."\n",0644);
    OperationFiles::write($dir.'/core/Lite.php',"<?php final class Fixture145 { public const VERSION = '$version'; }\n",0644);
    OperationFiles::write($dir.'/README.md',"Fixture $version\n",0644);
}
class FixtureReleases145 extends ReleaseHttp {
    public array $releases=[];
    public string $package='';
    public bool $mutate=false;
    public int $downloads=0;
    public function page(string $url, ?string $etag=null): array {
        if($url!=='https://api.github.com/repos/CajeerTeam/CajeerEngineLite/releases?per_page=30')throw new RuntimeException('Incorrect GitHub API endpoint');
        return ['status'=>200,'headers'=>[],'releases'=>$this->releases];
    }
    public function download(string $url,string $destination,int $expectedSize,?string $digest): array {
        $this->downloads++;
        if(!str_starts_with($url,'https://github.com/CajeerTeam/CajeerEngineLite/releases/download/v1.4.6/'))throw new RuntimeException('Unexpected download');
        OperationFiles::copy($this->package,$destination,0600);
        if($this->mutate) {
            $data=file_get_contents($destination);
            $data[100]=$data[100]==='a'?'b':'a';
            OperationFiles::write($destination,$data,0600);
        }
        return ['bytes'=>filesize($destination),'sha256'=>OperationFiles::hash($destination)];
    }
}
$tmp=sys_get_temp_dir().'/cajeer-auto-145-'.bin2hex(random_bytes(6));
try {
    $base=$tmp.'/base';$next=$tmp.'/next';
    fixture145($base,'1.4.5');fixture145($next,'1.4.6');
    OperationFiles::write($next.'/core/NewRelease145.php',"<?php final class NewRelease145 {}\n",0644);
    $pair=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
    if(!$pair||!openssl_pkey_export($pair,$secret))throw new RuntimeException('Test RSA key generation failed');
    OperationFiles::write($tmp.'/private.pem',$secret,0600);
    OperationFiles::write($tmp.'/trusted.pem',openssl_pkey_get_details($pair)['key'],0600);
    $package=$tmp.'/release.cjrupdate';
    UpdatePackage::build($base,$next,$package,$tmp.'/private.pem');
    $http=new FixtureReleases145();$http->package=$package;
    $asset='CajeerEngineLite-1.4.6.cjrupdate';
    $url='https://github.com/CajeerTeam/CajeerEngineLite/releases/download/v1.4.6/'.$asset;
    $http->releases=[['draft'=>false,'prerelease'=>false,'tag_name'=>'v1.4.6','assets'=>[
        ['name'=>$asset,'state'=>'uploaded','browser_download_url'=>$url,
         'size'=>filesize($package),'digest'=>'sha256:'.OperationFiles::hash($package)]]]];
    putenv('CAJEER_AUTO_UPDATE_GITHUB_REPO=CajeerTeam/CajeerEngineLite');
    putenv('CAJEER_UPDATE_TRUSTED_KEY_FILE='.$tmp.'/trusted.pem');
    putenv('CAJEER_AUTO_UPDATE_ENABLED=1');
    putenv('CAJEER_AUTO_UPDATE_INSTALL=1');
    putenv('CAJEER_AUTO_UPDATE_WORKERS_QUIESCED=1');
    $start=gmdate('H:i',time()-3600);$end=gmdate('H:i',time()+3600);
    putenv('CAJEER_AUTO_UPDATE_WINDOW_UTC='.$start.'-'.$end);
    yes145(AutomaticUpdates::window(),'UTC window inside including wrap');
    yes145(!AutomaticUpdates::window(gmdate('H:i',time()+3600).'-'.gmdate('H:i',time()+7200)),'outside UTC window');
    reject145(fn()=>AutomaticUpdates::window('25:11-26:00'),'invalid UTC window');
    reject145(fn()=>AutomaticUpdates::window('10:00-10:00'),'empty UTC window');
    foreach(['http://github.com/release','https://evil.example/release','https://github.com:8443/release',
        'https://user:password@github.com/release','https://github.com/release#fragment',
        'https://github.com.evil.example/releases','file:///etc/passwd'] as $bad) {
        reject145(fn()=>ReleaseHttp::assertSafeUrl($bad),'untrusted transport URL');
    }
    ReleaseHttp::assertSafeUrl($url);
    $automatic=new AutomaticUpdates($base,$http);
    yes145($automatic->status()['enabled']===true,'opt-in reflected in status');
    $check=$automatic->check();
    yes145($check['available']['version']==='1.4.6','real release discovery and SemVer');
    yes145($http->downloads===0,'check does not download packages');
    putenv('CAJEER_AUTO_UPDATE_ENABLED=0');
    reject145(fn()=>$automatic->run(),'disabled auto updates');
    putenv('CAJEER_AUTO_UPDATE_ENABLED=1');
    putenv('CAJEER_AUTO_UPDATE_INSTALL=0');
    reject145(fn()=>$automatic->run(),'disabled automatic install');
    putenv('CAJEER_AUTO_UPDATE_INSTALL=1');
    putenv('CAJEER_AUTO_UPDATE_WORKERS_QUIESCED=0');
    reject145(fn()=>$automatic->run(),'workers not quiesced');
    putenv('CAJEER_AUTO_UPDATE_WORKERS_QUIESCED=1');
    putenv('CAJEER_AUTO_UPDATE_WINDOW_UTC='.gmdate('H:i',time()+3600).'-'.gmdate('H:i',time()+7200));
    yes145($automatic->run()['action']==='outside_window','no download or mutation outside window');
    yes145($http->downloads===0,'no network download outside window');
    putenv('CAJEER_AUTO_UPDATE_WINDOW_UTC='.$start.'-'.$end);
    $lock=OperationLock::openLockFile($base.'/storage/app/updates/auto/check.lock',0600);
    flock($lock,LOCK_EX|LOCK_NB);
    reject145(fn()=>$automatic->run(),'two cron executions cannot overlap');
    flock($lock,LOCK_UN);fclose($lock);
    $http->mutate=true;
    reject145(fn()=>$automatic->run(),'download digest mismatch and signature tampering');
    yes145(UpdatePackage::version($base)==='1.4.5','failed download did not touch code');
    yes145(count(glob($base.'/storage/app/updates/auto/downloads/*'))===0,'failed downloads removed');
    $http->mutate=false;
    $installed=$automatic->run();
    yes145($installed['action']==='installed'&&$installed['installed']['to']==='1.4.6','real install from downloaded signed package');
    yes145(UpdatePackage::version($base)==='1.4.6'&&is_file($base.'/core/NewRelease145.php'),'new release published');
    yes145($automatic->status()['last_run']['event']==='installed','durable install state');
    $finished=$automatic->run();
    yes145($finished['action']==='up_to_date','no reinstall of the same release');
    $updateId=$installed['installed']['id'];
    OperationFiles::json($base.'/storage/app/updates/active.json',['id'=>$updateId,'mode'=>'install']);
    putenv('CAJEER_AUTO_UPDATE_RECOVER=0');
    reject145(fn()=>$automatic->run(),'pending recovery blocks new installs');
    putenv('CAJEER_AUTO_UPDATE_RECOVER=1');
    $recovered=$automatic->run();
    yes145($recovered['action']==='recovered'&&UpdatePackage::version($base)==='1.4.5','automatic crash recovery rolls back code');
    yes145(!is_file($base.'/storage/app/updates/active.json'),'journal cleared after verified recovery');
    $http->releases=[];
    yes145($automatic->run()['action']==='up_to_date','empty release list handled');
    echo "Automatic Updates 1.4.5: GitHub release metadata, signing, download, window, opt-in, concurrent lock, actual install and crash recovery OK\n";
} finally {
    foreach(['CAJEER_AUTO_UPDATE_GITHUB_REPO','CAJEER_UPDATE_TRUSTED_KEY_FILE','CAJEER_AUTO_UPDATE_ENABLED',
        'CAJEER_AUTO_UPDATE_INSTALL','CAJEER_AUTO_UPDATE_WORKERS_QUIESCED','CAJEER_AUTO_UPDATE_WINDOW_UTC',
        'CAJEER_AUTO_UPDATE_RECOVER'] as $key)putenv($key);
    cleanup145($tmp);
}
