<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\AI\{AIConfig,CostPolicy,UsageLimiter};
if (!function_exists('pcntl_fork')) {echo "SKIP: pcntl unavailable\n";exit(0);}
$root=sys_get_temp_dir().'/cajeer-ai-cost-concurrency-'.bin2hex(random_bytes(8));mkdir($root,0700,true);
try {
    $policy=CostPolicy::DEFAULTS;
    $policy['enabled']=true;$policy['daily_cents']=2;$policy['monthly_cents']=3;
    $policy['input_cents_per_million']=1000;$policy['output_cents_per_million']=1000;
    $policy['max_concurrent']=1;
    CostPolicy::save($root,$policy);
    $config=AIConfig::DEFAULTS;$config['enabled']=true;
    $config['daily_requests']=20;$config['daily_tokens']=100000;
    $limiter=new UsageLimiter($root);
    $marker=$root.'/ready';
    $pid=pcntl_fork();
    if ($pid===-1) throw new RuntimeException('Cannot fork');
    if ($pid===0) {
        try {
            $reservation=(new UsageLimiter($root))->reserve($config,'First worker');
            file_put_contents($marker,'1');usleep(350000);
            (new UsageLimiter($root))->finish($reservation,15,['input_tokens'=>10,'output_tokens'=>5]);
            exit(0);
        } catch(Throwable $e) {fwrite(STDERR,$e->getMessage());exit(2);}
    }
    for($i=0;$i<100 && !is_file($marker);$i++)usleep(10000);
    if(!is_file($marker))throw new RuntimeException('Worker never acquired reservation');
    try {$limiter->reserve($config,'Second worker');throw new RuntimeException('Concurrency limit bypassed');}
    catch(RuntimeException $e) {if(!str_contains($e->getMessage(),'Concurrent OpenAI'))throw $e;}
    pcntl_waitpid($pid,$status);
    if(pcntl_wexitstatus($status)!==0)throw new RuntimeException('Worker failed');
    $new=$limiter->reserve($config,'After first worker');
    $limiter->finish($new,null);
    $stat=$limiter->status();
    if($stat['requests']!==2 || $stat['active_requests']!==0 || $stat['spent_microcents']<=0)
        throw new RuntimeException('Incorrect concurrent accounting');
    echo "Lite 1.2.8 multi-process monetary quota and concurrent slot isolation OK\n";
} finally {
    if(is_file($root.'/ready'))unlink($root.'/ready');
    foreach(glob($root.'/storage/ai/*')?:[] as $file)if(is_file($file))unlink($file);
    foreach([$root.'/storage/ai',$root.'/storage',$root] as $dir)if(is_dir($dir))rmdir($dir);
}
