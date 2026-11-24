<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Update\{AutomaticUpdates,ReleaseDiscovery,ReleaseHttp,OperationFiles};
function yes151(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);}
function reject151(callable $fn,string $match): void {
    try {$fn();}catch(Throwable $e){yes151(str_contains($e->getMessage(),$match),$e->getMessage());return;}
    throw new RuntimeException('Expected rejection: '.$match);
}
class Fixture151 extends ReleaseHttp {
    public array $responses=[]; public array $calls=[];
    // Run the production HTTP response decoder using a controlled stream.
    protected function open(string $url,string $accept,?string $etag=null): array {
        $this->calls[]=[$url,$etag];
        if(!$this->responses)throw new RuntimeException('Unexpected test network call');
        $response=array_shift($this->responses);
        if($response instanceof Throwable)throw $response;
        [$status,$headers,$body]=$response;
        $h=fopen('php://temp','w+b');fwrite($h,is_string($body)?$body:json_encode($body,JSON_THROW_ON_ERROR));rewind($h);
        return ['status'=>$status,'headers'=>$headers,'stream'=>$h];
    }
}
function rel151(string $v,int $id,bool $preview=false): array {
    $name='CajeerEngineLite-'.$v.'.cjrupdate';
    return ['id'=>$id,'tag_name'=>'v'.$v,'draft'=>false,'prerelease'=>$preview,'body'=>'unneeded metadata', 'assets'=>[
        ['name'=>$name,'state'=>'uploaded','browser_download_url'=>'https://github.com/CajeerTeam/CajeerEngineLite/releases/download/v'.$v.'/'.$name,'size'=>1024,'digest'=>'sha256:'.str_repeat('a',64)]]];
}
function clear151(string $dir): void {
    foreach(new FilesystemIterator($dir) as $item){if($item->isDir()&&!$item->isLink())clear151($item->getPathname());else unlink($item->getPathname());}rmdir($dir);
}
$tmp=sys_get_temp_dir().'/cajeer-discovery-151-'.bin2hex(random_bytes(8));mkdir($tmp,0700);
try {
    OperationFiles::write($tmp.'/VERSION',"1.5.1\n",0644);
    putenv('CAJEER_AUTO_UPDATE_GITHUB_REPO=CajeerTeam/CajeerEngineLite');putenv('CAJEER_AUTO_UPDATE_CHANNEL=stable');
    $repo='CajeerTeam/CajeerEngineLite';$http=new Fixture151();$auto=new AutomaticUpdates($tmp,$http);
    $page=[];for($i=40;$i>=11;$i--)$page[]=rel151('1.5.'.$i,$i);
    $draft=rel151('1.5.2',100);$draft['draft']=true;
    $bad=rel151('1.5.3',101);$bad['assets'][0]['browser_download_url'].='?x=1';
    $duplicate=rel151('1.5.4',102);$duplicate['assets'][]=$duplicate['assets'][0];
    $badDigest=rel151('1.5.5',103);$badDigest['assets'][0]['digest']=[];
    $last=[rel151('1.5.6',6,true),rel151('1.5.7',7),$draft,$bad,$duplicate,$badDigest];
    $http->responses=[[200,['etag'=>'W/"a"','link'=>'<https://evil.invalid>; rel="next"'],$page],[200,['etag'=>'"b"'],$last]];
    $r=$auto->check();yes151($r['available']['version']==='1.5.7'&&$r['latest']['version']==='1.5.40'&&$r['pages']===2,'stable pagination and nearest/latest');
    yes151($http->calls[1][0]==='https://api.github.com/repos/'.$repo.'/releases?per_page=30&page=2','next URL constructed, not followed');
    $cache=$tmp.'/storage/app/updates/auto/discovery.json';yes151((fileperms($cache)&0777)===0600&&!str_contains(file_get_contents($cache),'unneeded metadata'),'private compact cache');
    $http->responses=[[304,[],[]],[304,[],[]]];putenv('CAJEER_AUTO_UPDATE_CHANNEL=preview');
    $r=$auto->check();yes151($r['available']['version']==='1.5.6'&&$r['available']['prerelease']&&$r['revalidated_pages']===2,'conditional cache and preview');
    yes151($http->calls[2][1]==='W/"a"'&&$http->calls[3][1]==='"b"','correct per-page ETags');
    putenv('CAJEER_AUTO_UPDATE_ENABLED=1');putenv('CAJEER_AUTO_UPDATE_INSTALL=1');putenv('CAJEER_AUTO_UPDATE_WORKERS_QUIESCED=1');
    putenv('CAJEER_AUTO_UPDATE_WINDOW_UTC='.gmdate('H:i',time()-3600).'-'.gmdate('H:i',time()+3600));
    putenv('CAJEER_AUTO_UPDATE_ALLOW_PREVIEW_INSTALL=0');$http->responses=[[304,[],[]],[304,[],[]]];
    reject151(fn()=>$auto->run(),'Preview installation requires');
    // Retrying transient network/HTTP failure then success; no stale fallback.
    $http->responses=[new RuntimeException('Cannot contact GitHub release endpoint'),[502,[],[]],[200,['etag'=>'"c"'],[rel151('1.5.8',8)]]];
    $r=$auto->check();yes151($r['available']['version']==='1.5.8'&&$r['pages']===1,'retry and cache truncation');
    $http->responses=[[429,['retry-after'=>'120'],[]]];
    reject151(fn()=>$auto->check(),'throttled until');$calls=count($http->calls);
    reject151(fn()=>(new AutomaticUpdates($tmp,$http))->check(),'throttled until');yes151(count($http->calls)===$calls,'cooldown persisted across instances');
    $saved=OperationFiles::read($cache);$saved['blocked_until']=time()-1;OperationFiles::json($cache,$saved);
    $http->responses=[[503,[],[]],[503,[],[]],[503,[],[]]];reject151(fn()=>$auto->check(),'HTTP status 503');
    yes151($auto->status()['last_run']['event']==='failed','failure status not up to date');
    $http->responses=[[304,[],[]]];yes151($auto->check()['available']['version']==='1.5.8','revalidated cache after transient failure');
    $http->responses=[[200,['link'=>'<ignored>; rel="next"'],[rel151('1.5.8',8)]],[200,[],[rel151('1.5.9',8)]]];
    reject151(fn()=>$auto->check(),'changed during pagination');
    $http->responses=[[200,[],[rel151('1.5.8',8),rel151('1.5.8',9)]]];reject151(fn()=>$auto->check(),'Ambiguous');
    $http->responses=[];for($i=0;$i<20;$i++)$http->responses[]=[200,['link'=>'<ignored>; rel="next"'],[]];
    reject151(fn()=>$auto->check(),'page limit exceeded');
    $http->responses=[[403,['x-ratelimit-remaining'=>'0','x-ratelimit-reset'=>(string)(time()+200)],[]]];reject151(fn()=>$auto->check(),'throttled until');
    yes151(OperationFiles::read($cache)['blocked_until']>=time()+190,'primary rate limit reset honored');
    // Repo changes invalidate both ETag and throttle state; unexpected 304 is rejected.
    $http->responses=[[304,[],[]]];reject151(fn()=>(new ReleaseDiscovery($tmp,$http))->fetch('Other/Repo','1.5.1','stable'),'304 without');
    yes151(end($http->calls)[1]===null,'cache isolated by repo');
    $http->responses=[[200,[],str_repeat(' ',512001)]];reject151(fn()=>$http->page('https://api.github.com/repos/'.$repo.'/releases?per_page=30'),'size limit');
    $http->responses=[[200,['content-length'=>'100'],'[]']];reject151(fn()=>$http->page('https://api.github.com/repos/'.$repo.'/releases?per_page=30'),'Incomplete');
    reject151(fn()=>$http->page('https://github.com/'.$repo.'/releases?per_page=30'),'Invalid GitHub release API');
    yes151(!ReleaseHttp::validEtag("\"bad\r\nheader\""),'header injection rejected');
    chmod($cache,0644);reject151(fn()=>$auto->check(),'Unsafe release discovery cache');chmod($cache,0600);
    putenv('CAJEER_AUTO_UPDATE_CHANNEL=unknown');reject151(fn()=>$auto->check(),'Invalid update channel');
    echo "GitHub Release Discovery 1.5.1: pagination, ETag/304, channels, metadata validation, retry, durable rate limits, cache isolation and failures OK\n";
} finally {clear151($tmp);}
