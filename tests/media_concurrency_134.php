<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Media\MediaLibrary;
$root=sys_get_temp_dir().'/cajeer-media-concurrent-'.bin2hex(random_bytes(5));
mkdir($root);mkdir($root.'/storage');
try {
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGOUT9rBwMDAxMDAwMDAAAAOPQE9xgjZwQAAAABJRU5ErkJggg==');
    file_put_contents($root.'/test.png',$png);
    $code='require '.var_export(dirname(__DIR__).'/bootstrap.php',true).';'.
          '$lib=new \\CajeerEngine\\Media\\MediaLibrary($argv[1]);'.
          '$lib->ingest($argv[1]."/test.png","parallel.png");';
    $children=[];
    for($i=0;$i<8;$i++) {
        $process=proc_open([PHP_BINARY,'-r',$code,$root],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start media worker');
        fclose($pipes[0]);$children[]=[$process,$pipes];
    }
    foreach($children as [$proc,$pipes]) {
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($proc)!==0) throw new RuntimeException('Concurrent write failed: '.$out.' '.$err);
    }
    $lib=new MediaLibrary($root);
    $items=$lib->listing();
    if (count($items)!==8 || $lib->statistics()['bytes']!==8*strlen($png)) throw new RuntimeException('Concurrent media writes lost or duplicated data');
    foreach($items as $item) if ($lib->read($item['id'])['bytes']!==$png) throw new RuntimeException('Concurrent upload corrupted bytes');
    echo "Media Library 1.3.4 concurrency: 8 competing processes, atomic private index and intact files OK\n";
} finally {
    $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($items as $item){if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());}
    rmdir($root);
}
