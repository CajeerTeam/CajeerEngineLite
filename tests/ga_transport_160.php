<?php
declare(strict_types=1);
/** Disposable HTTP fixture. The production tag decoder and streaming downloader are used. */
require_once dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Update\{ReleaseHttp,OperationFiles,TrustPolicy};
use CajeerEngine\Release\{ProductionStable,UpdatesGA};
final class GaHttp160 extends ReleaseHttp
{
    public array $release;
    public ?string $tamper=null;
    public int $status=200;
    public function __construct(private readonly string $directory){
        $tag='v'.Lite::VERSION;$assets=[];
        foreach(new DirectoryIterator($directory) as $f){if($f->isDot())continue;$name=$f->getFilename();
            $assets[]=['name'=>$name,'state'=>'uploaded','size'=>$f->getSize(),'browser_download_url'=>'https://github.com/Test/Repo/releases/download/'.$tag.'/'.$name,'digest'=>'sha256:'.hash_file('sha256',$f->getPathname())];}
        $this->release=['tag_name'=>$tag,'draft'=>false,'prerelease'=>false,'assets'=>$assets];
    }
    protected function open(string $url,string $accept,?string $etag=null):array {
        $tag='v'.Lite::VERSION;
        if($url==='https://api.github.com/repos/Test/Repo/releases/tags/'.$tag){$body=json_encode($this->release,JSON_THROW_ON_ERROR);$status=$this->status;}
        elseif($url==='https://api.github.com/repos/Test/Repo/releases?per_page=30'){$body=json_encode([$this->release],JSON_THROW_ON_ERROR);$status=$this->status;}
        else {
            $prefix='https://github.com/Test/Repo/releases/download/'.$tag.'/';
            if(!str_starts_with($url,$prefix))throw new RuntimeException('Unexpected test URL');
            $name=substr($url,strlen($prefix));$body=file_get_contents(OperationFiles::path($this->directory,$name));$status=200;
            if($this->tamper===$name)$body[0]=chr(ord($body[0])^1);
        }
        $h=fopen('php://temp','w+b');fwrite($h,$body);rewind($h);
        return ['status'=>$status,'headers'=>['content-length'=>(string)strlen($body)],'stream'=>$h];
    }
}
function reject160(callable $fn,string $label):void {try{$fn();}catch(Throwable){return;}throw new RuntimeException('Accepted '.$label);}
function ok160(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function cleanup160(string $path):void{foreach(new FilesystemIterator($path) as $e){if($e->isDir()&&!$e->isLink())cleanup160($e->getPathname());else unlink($e->getPathname());}rmdir($path);}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    if($argc!==4)throw new RuntimeException('Usage: ga_transport_160.php source-root artifact-dir public-key');
    $root=$argv[1];$dir=$argv[2];$key=TrustPolicy::single($argv[3]);$artifact=ProductionStable::artifacts($root,$dir,$key);
    $dist=UpdatesGA::distribution($dir,$key,$artifact);$http=new GaHttp160($dir);
    $tmp=sys_get_temp_dir().'/ga160-'.bin2hex(random_bytes(8));mkdir($tmp,0700);
    try{
        $verify=fn()=>UpdatesGA::publication($tmp,$dir,$dist,'Test/Repo',$http);
        $ok=$verify();ok160($ok['assets']===count($dist['hashes'])&&$ok['tag']==='v'.Lite::VERSION,'complete publication');
        $http->release['draft']=true;reject160($verify,'draft');$http->release['draft']=false;
        $http->release['prerelease']=true;reject160($verify,'preview');$http->release['prerelease']=false;
        $orig=$http->release;$http->release['assets'][0]['browser_download_url'].='?tampered';reject160($verify,'asset URL');$http->release=$orig;
        $http->release['assets'][0]['digest']='sha256:'.str_repeat('0',64);reject160($verify,'wrong API digest');$http->release=$orig;
        array_pop($http->release['assets']);reject160($verify,'missing asset');$http->release=$orig;
        $http->tamper='update-index.json';reject160($verify,'download tampering');$http->tamper=null;
        $http->status=503;reject160($verify,'GitHub outage');$http->status=200;
        ok160($verify()===$ok,'stable publication identity');
        ok160(count(glob($tmp.'/storage/app/updates/auto/downloads/*'))===0,'temporary downloads removed');
        $s=['settings'=>['channel'=>'stable','checks'=>false,'install'=>false],'scheduler'=>null,'monitoring'=>['ok'=>true,'alerts'=>[]],'pending'=>false,'job'=>null,'fresh'=>false];
        $checks=UpdatesGA::configuration($s,$key,true);ok160(in_array('fail',array_column($checks,'status'),true),'unconfigured deployment blocked');
        echo "GA published assets: exact tag, signed inventory, streaming bytes, wrong channel, tampering, outage and cleanup OK\n";
    }finally{cleanup160($tmp);}
}
