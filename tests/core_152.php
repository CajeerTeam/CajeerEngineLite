<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Update\{AutomaticUpdates,DistributionIndex,ReleaseHttp,UpdatePackage,UpdateEngine,OperationFiles};
function ok152(bool $ok,string $reason):void {if(!$ok)throw new RuntimeException($reason);}
function reject152(callable $fn,string $reason):void {try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('Expected failure: '.$reason);}
function clean152(string $dir):void {foreach(new FilesystemIterator($dir) as $f){if($f->isDir()&&!$f->isLink())clean152($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
function fixture152(string $dir,string $version):void {
    OperationFiles::write($dir.'/VERSION',"$version\n",0644);
    OperationFiles::write($dir.'/core/Lite.php',"<?php final class Fixture152 { public const VERSION = '$version'; }\n",0644);
    OperationFiles::write($dir.'/README.md',"Fixture $version\n",0644);
}
class Http152 extends ReleaseHttp {
    public array $releases=[];public array $files=[];public ?string $tamper=null;
    public function page(string $url,?string $etag=null):array{return ['status'=>200,'headers'=>[],'releases'=>$this->releases];}
    public function download(string $url,string $destination,int $expectedSize,?string $digest):array {
        $raw=$this->files[$url]??throw new RuntimeException('Unknown asset');
        if($this->tamper===$url)$raw[10]=$raw[10]==='a'?'b':'a';
        OperationFiles::write($destination,$raw,0600);
        return ['bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw)];
    }
}
function rel152(Http152 $http,OpenSSLAsymmetricKey $key,string $to,array $packages,bool $preview=false):array {
    $prefix='https://github.com/Test/Repo/releases/download/v'.$to.'/';$assets=[];$entries=[];
    foreach($packages as $from=>$file){
        $name='CajeerEngineLite-'.$from.'-to-'.$to.'.cjrupdate';$raw=file_get_contents($file);$url=$prefix.$name;$http->files[$url]=$raw;
        $entries[]=['from'=>$from,'name'=>$name,'bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw)];
        $assets[]=['name'=>$name,'state'=>'uploaded','browser_download_url'=>$url,'size'=>strlen($raw),'digest'=>'sha256:'.hash('sha256',$raw)];
    }
    $payload=json_encode(['format'=>1,'product'=>'CajeerEngineLite','to'=>$to,'packages'=>$entries],JSON_THROW_ON_ERROR);
    openssl_sign($payload,$sig,$key,OPENSSL_ALGO_SHA256);
    $raw=json_encode(['payload'=>base64_encode($payload),'signature'=>base64_encode($sig)],JSON_THROW_ON_ERROR);
    $url=$prefix.DistributionIndex::NAME;$http->files[$url]=$raw;
    $assets[]=['name'=>DistributionIndex::NAME,'state'=>'uploaded','browser_download_url'=>$url,'size'=>strlen($raw)];
    return ['draft'=>false,'prerelease'=>$preview,'tag_name'=>'v'.$to,'assets'=>$assets];
}
$tmp=sys_get_temp_dir().'/distribution152-'.bin2hex(random_bytes(8));mkdir($tmp,0700);
try {
    $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);openssl_pkey_export($key,$pem);
    OperationFiles::write($tmp.'/private.pem',$pem);OperationFiles::write($tmp.'/public.pem',openssl_pkey_get_details($key)['key']);
    foreach(['1.5.2','1.5.3','1.5.4','1.5.5','1.5.6'] as $v)fixture152($tmp.'/'.$v,$v);
    foreach([['1.5.2','1.5.3'],['1.5.2','1.5.4'],['1.5.3','1.5.4'],['1.5.4','1.5.5'],['1.5.5','1.5.6']] as [$from,$to])UpdatePackage::build($tmp.'/'.$from,$tmp.'/'.$to,$tmp.'/'.$from.'-'.$to,$tmp.'/private.pem');
    $http=new Http152();$http->releases=[rel152($http,$key,'1.5.6',['1.5.5'=>$tmp.'/1.5.5-1.5.6'],true),rel152($http,$key,'1.5.5',['1.5.4'=>$tmp.'/1.5.4-1.5.5']),rel152($http,$key,'1.5.4',['1.5.2'=>$tmp.'/1.5.2-1.5.4','1.5.3'=>$tmp.'/1.5.3-1.5.4']),rel152($http,$key,'1.5.3',['1.5.2'=>$tmp.'/1.5.2-1.5.3'])];
    foreach(['CAJEER_UPDATE_TRUSTED_KEY_FILE'=>$tmp.'/public.pem','CAJEER_AUTO_UPDATE_GITHUB_REPO'=>'Test/Repo','CAJEER_AUTO_UPDATE_CHANNEL'=>'stable','CAJEER_AUTO_UPDATE_ENABLED'=>'1','CAJEER_AUTO_UPDATE_INSTALL'=>'1','CAJEER_AUTO_UPDATE_WORKERS_QUIESCED'=>'1','CAJEER_AUTO_UPDATE_WINDOW_UTC'=>gmdate('H:i',time()-3600).'-'.gmdate('H:i',time()+3600)] as $k=>$v)putenv($k.'='.$v);
    $root=$tmp.'/1.5.2';$auto=new AutomaticUpdates($root,$http);$r=$auto->check();
    ok152($r['distribution']==='signed'&&array_column($r['plan'],'version')===['1.5.4','1.5.5'],'shortest route to highest stable');
    putenv('CAJEER_AUTO_UPDATE_CHANNEL=preview');ok152($auto->check()['latest']['version']==='1.5.6','preview included');putenv('CAJEER_AUTO_UPDATE_CHANNEL=stable');
    $indexUrl='https://github.com/Test/Repo/releases/download/v1.5.4/update-index.json';$http->tamper=$indexUrl;
    reject152(fn()=>$auto->run(),'tampered signed index');ok152(UpdatePackage::version($root)==='1.5.2','no mutation');$http->tamper=null;
    $saved=$http->releases;$http->releases=[$saved[1]];reject152(fn()=>$auto->check(),'no route');$http->releases=$saved;
    $http->tamper='https://github.com/Test/Repo/releases/download/v1.5.4/CajeerEngineLite-1.5.2-to-1.5.4.cjrupdate';reject152(fn()=>$auto->run(),'tampered package');$http->tamper=null;
    $first=$auto->run();ok152($first['installed']['to']==='1.5.4','real exact-base install');
    $second=(new AutomaticUpdates($root,$http))->run();ok152($second['installed']['to']==='1.5.5','second verified step');
    ok152((new AutomaticUpdates($root,$http))->run()['action']==='up_to_date','stable ignores preview');
    putenv('CAJEER_UPDATE_CONFIRM=ROLLBACK');(new UpdateEngine($root))->rollback($second['installed']['id']);ok152(UpdatePackage::version($root)==='1.5.4','rollback step');
    ok152(!glob($root.'/storage/app/updates/auto/downloads/*'),'temporary assets removed');
    echo "Distribution 1.5.2: signed indexes, exact-base graph, preview exclusion, tamper/no-path rejection, two installs and rollback OK\n";
}finally{clean152($tmp);}
