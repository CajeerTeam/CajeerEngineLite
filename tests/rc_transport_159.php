<?php
declare(strict_types=1);
// Test-only adapter: run from a disposable installation, not a web endpoint.
if(PHP_SAPI!=='cli'||getenv('CAJEER_RC_TEST_FIXTURE')!=='1')exit(1);
require getcwd().'/bootstrap.php';
use CajeerEngine\Update\{ReleaseHttp,Scheduler,ControlCenter,OperationFiles,AutomaticUpdates};
final class RcHttp159 extends ReleaseHttp {
    protected function open(string $url,string $accept,?string $etag=null):array {
        $dir=getenv('TEST_WORK');self::assertSafeUrl($url);$status=200;
        if(str_starts_with($url,'https://api.github.com/')) {
            $body=file_get_contents($dir.'/releases.json');
            if(is_file($dir.'/outage')){$status=503;$body='unavailable';}
        } elseif(str_ends_with($url,'/update-index.json')) $body=file_get_contents($dir.'/update-index.json');
        elseif(str_ends_with($url,'/CajeerEngineLite-'.getenv('TEST_TARGET').'.cjrupdate'))$body=file_get_contents($dir.'/package');
        else throw new RuntimeException('Unexpected test URL');
        if(!is_string($body))throw new RuntimeException('Missing response fixture');
        $stream=fopen('php://temp','w+b');fwrite($stream,$body);rewind($stream);
        return ['status'=>$status,'headers'=>['content-length'=>(string)strlen($body)],'stream'=>$stream];
    }
}
$c=new ControlCenter(getcwd(),new RcHttp159());
switch($argv[1]??'') {
    case 'configure':
        $c->submit(['action'=>'settings','revision'=>$c->status()['revision'],'channel'=>'preview','checks'=>'1','install'=>'1','interval'=>'1','window'=>getenv('CAJEER_AUTO_UPDATE_WINDOW_UTC')]);break;
    case 'retry-now':
        $file=getcwd().'/storage/app/update-control/state.json';$s=OperationFiles::read($file);
        if(($s['job']['attempts']??0)!==1||$s['job']['not_before']<=time())throw new RuntimeException('Retry not persisted');
        $s['job']['not_before']=time()-1;OperationFiles::json($file,$s);break;
    case 'stable':
        $r=(new AutomaticUpdates(getcwd(),new RcHttp159()))->verifiedCheck('stable');if($r['verified']!==null)throw new RuntimeException('Preview leaked to stable');break;
    case 'tick':
        echo json_encode((new Scheduler(getcwd(),new RcHttp159()))->tick(),JSON_THROW_ON_ERROR).PHP_EOL;break;
    default:throw new RuntimeException('Unknown fixture command');
}
