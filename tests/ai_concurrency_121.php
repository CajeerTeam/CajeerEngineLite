<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\AI\{AIConfig,UsageLimiter};
if (!function_exists('pcntl_fork')) {echo "SKIP: pcntl unavailable (concurrency tested on Linux CI)\n";exit(0);}
$root=sys_get_temp_dir().'/cajeer-ai-concurrency-'.bin2hex(random_bytes(8));
mkdir($root,0700,true);
$config=AIConfig::DEFAULTS;
$config['enabled']=true;$config['daily_requests']=4;$config['daily_tokens']=5000;
$ids=[];$accepted=0;$blocked=0;
try {
    for($i=0;$i<10;$i++) {
        $pid=pcntl_fork();
        if($pid===-1) throw new RuntimeException('Cannot fork AI quota test');
        if($pid===0) {
            try {
                $limiter=new UsageLimiter($root);
                $reservation=$limiter->reserve($config,'parallel test');
                usleep(random_int(1000,30000));
                $limiter->finish($reservation,25);
                exit(0);
            } catch (RuntimeException $error) {
                if(str_contains($error->getMessage(),'budget exhausted')) exit(1);
                fwrite(STDERR,$error->getMessage()."\n");exit(2);
            }
        }
        $ids[]=$pid;
    }
    foreach($ids as $pid){pcntl_waitpid($pid,$status);$code=pcntl_wexitstatus($status);
        if($code===0)$accepted++;elseif($code===1)$blocked++;else throw new RuntimeException('Child execution failed');}
    $state=(new UsageLimiter($root))->status();
    if($accepted!==4 || $blocked!==6 || $state['requests']!==4 ||
        $state['tokens']!==100 || $state['reserved_tokens']!==0) {
        throw new RuntimeException('AI parallel quotas were exceeded: '.json_encode($state));
    }
    echo "Lite 1.2.1 parallel AI quota atomicity: 4 accepted, 6 blocked OK\n";
} finally {
    foreach(glob($root.'/storage/ai/*')?:[] as $file) if(is_file($file))unlink($file);
    foreach([$root.'/storage/ai',$root.'/storage',$root] as $dir) if(is_dir($dir))rmdir($dir);
}
