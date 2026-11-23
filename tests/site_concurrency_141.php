<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
function check141(bool $condition,string $message):void {if(!$condition)throw new RuntimeException('1.4.1 concurrency: '.$message);}
$root=sys_get_temp_dir().'/cms141-concurrent-'.bin2hex(random_bytes(8));mkdir($root,0700);
try {
    $app=new Lite($root);$app->install('ValidPasswordFor141!','https://example.test','Original');
    $app->change(static function(array $s):array {$s['pages']['/']['body']='0';return $s;});
    $code='require '.var_export(dirname(__DIR__).'/bootstrap.php',true).';'.
        '$app=new \\CajeerEngine\\Lite($argv[1]);'.
        'for ($i=0;$i<4;$i++) $app->change(static function(array $s):array {'.
        '$s["pages"]["/"]["body"]=(string)((int)$s["pages"]["/"]["body"]+1);'.
        'usleep(12000); return $s; });';
    $workers=[];
    for ($i=0;$i<8;$i++) {
        $proc=proc_open([PHP_BINARY,'-r',$code,$root],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($proc)) throw new RuntimeException('Cannot launch worker');
        fclose($pipes[0]);$workers[]=[$proc,$pipes];
    }
    foreach ($workers as [$proc,$pipes]) {
        $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        check141(proc_close($proc)===0,'worker failed: '.$stdout.' '.$stderr);
    }
    check141($app->load()['pages']['/']['body']==='32','32 serialized increments with no lost writes');
    check141(is_array(json_decode((string)file_get_contents($root.'/storage/site.json'),true,512,JSON_THROW_ON_ERROR)),'valid JSON after contention');
    // Simultaneous publishers must not overwrite one another, even if both
    // start with a non-existent destination.
    $writer='require '.var_export(dirname(__DIR__).'/bootstrap.php',true).';'.
        'try { \CajeerEngine\Update\OperationFiles::writeNew($argv[1]."/race.txt",$argv[2]); }'.
        'catch (\RuntimeException $e) { exit(8); }';
    $publishers=[];
    for($i=0;$i<4;$i++) {
        $proc=proc_open([PHP_BINARY,'-r',$writer,$root,'candidate-'.(string)$i],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc))throw new RuntimeException('Cannot launch publisher');
        fclose($pipes[0]);$publishers[]=[$proc,$pipes];
    }
    $succeeded=0;$rejected=0;
    foreach($publishers as [$proc,$pipes]) {
        $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        $exit=proc_close($proc);
        if($exit===0)$succeeded++;
        elseif($exit===8)$rejected++;
        else throw new RuntimeException('Publisher error: '.$stdout.' '.$stderr);
    }
    check141($succeeded===1 && $rejected===3,'one winner and three no-clobber rejections');
    check141(preg_match('/^candidate-[0-3]$/D',(string)file_get_contents($root.'/race.txt'))===1,'one complete published payload');
    echo "Core Stability 1.4.1 concurrency: 8 processes / 32 writes / no lost updates; 4-way no-clobber publish OK\n";
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {if ($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($root);
}
