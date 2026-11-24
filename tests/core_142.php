<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use CajeerEngine\Lite;
use CajeerEngine\Media\MediaLibrary;
use CajeerEngine\Operations\{CmsOperations,RecoveryJournal};
use CajeerEngine\Storage\{SiteRepository,SiteState};
use CajeerEngine\Update\{OperationFiles,OperationLock};

function ok142(bool $ok,string $message): void {if (!$ok) throw new RuntimeException('1.4.2: '.$message);}
function rejected142(callable $fn,string $message): void {
    try {$fn();} catch (Throwable) {return;}
    throw new RuntimeException('1.4.2: expected rejection: '.$message);
}
function clean142(string $dir):void {
    if (!is_dir($dir)) return;
    $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($items as $item) $item->isDir()&&!$item->isLink()?rmdir($item->getPathname()):unlink($item->getPathname());
    rmdir($dir);
}
function png142(): string {
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGOUT9rBwMDAxMDAwMDAAAAOPQE9xgjZwQAAAABJRU5ErkJggg==',true);
}
ok142(version_compare(Lite::VERSION,'1.4.2','>=') && SiteState::SCHEMA===14,'version, storage schema');
$source=sys_get_temp_dir().'/cms142-source-'.bin2hex(random_bytes(6));
$target=sys_get_temp_dir().'/cms142-target-'.bin2hex(random_bytes(6));
mkdir($source,0700); mkdir($target,0700);
try {
    $src=new Lite($source); $dst=new Lite($target);
    $src->install('OriginalPassword142!','https://source.example.test','Source');
    $dst->install('KeepPassword142!','https://target.example.test','Target');
    $png=png142(); $image=$source.'/asset.png';file_put_contents($image,$png);
    $sourceMedia=new MediaLibrary($source);
    $asset=$sourceMedia->ingest($image,'photo.png');
    $src->change(static function(array $state)use($asset):array {
        $state['pages']['/']['title']='Transferred home';
        $state['seo']['default_image']=$asset['id'];
        return $state;
    });
    $srcOps=new CmsOperations($source); $destOps=new CmsOperations($target);
    $backup=$srcOps->createBackup();
    ok142($srcOps->verifyBackup($backup)['media']===1,'full backup is verified before publication');
    ok142(count($srcOps->names())===1,'one completed archive visible');
    $sourcePath=$source.'/storage/media/files/'.$asset['id'].'.png';
    $entries=[$asset['id']=>$asset];$paths=[$asset['id']=>$sourcePath];
    $destMedia=new MediaLibrary($target);
    $indexPath=$target.'/storage/media/index.json';
    $filePath=$target.'/storage/media/files/'.$asset['id'].'.png';
    ok142(!file_exists($indexPath),'fresh media index is absent');
    rejected142(static fn()=> $destMedia->importPreservingIds($entries,$paths,static function():void {
        throw new RuntimeException('Simulated site save failure');
    }), 'failed import callback');
    ok142(!file_exists($indexPath) && !file_exists($filePath), 'failed import restores absent index and removes new asset');
    // A pre-existing unindexed path must never be mistaken for a file created
    // by a failed import and removed by its recovery journal.
    OperationFiles::writeNew($filePath,$png,0600);
    $collisionExport=$srcOps->exportArchive();
    rejected142(static fn()=>$destOps->importFile($collisionExport),
        'unindexed collision is rejected before recovery journal publication');
    $srcOps->removeExport($collisionExport);
    ok142(file_get_contents($filePath)===$png && !RecoveryJournal::pending($target),
        'unindexed collision never deleted as a new import asset');
    OperationFiles::remove($filePath);

    // Simulate a hard worker exit between durable media publication and site commit.
    // This is a real subprocess; PHP finally/catch blocks in the interrupted
    // worker are not allowed to run.
    $code=<<<'CHILD'
require $argv[1].'/bootstrap.php';
$root=$argv[2];$src=$argv[3];$id=$argv[4];
$media=new \CajeerEngine\Media\MediaLibrary($root);
$repo=new \CajeerEngine\Storage\SiteRepository($root);
$journal=new \CajeerEngine\Operations\RecoveryJournal($root);
$files=[$id=>$src.'/storage/media/files/'.$id.'.png'];
$meta=(new \CajeerEngine\Media\MediaLibrary($src))->listing()[0];
$lock=\CajeerEngine\Update\OperationLock::acquire($root,true);
$media->importPreservingIds([$id=>$meta],$files,static function():void {
    exit(73); // abnormal termination after index and asset were committed
},function(?string $before,array $added) use($repo,$journal):void {
    $repo->exclusive(function()use($repo,$journal,$before,$added):void {
        $old=$repo->raw();if($old===null)throw new RuntimeException('missing live site');
        $journal->begin($old,$before,$added);
    });
});
CHILD;
    $worker=proc_open([PHP_BINARY,'-r',$code,dirname(__DIR__), $target,$source,$asset['id']],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($worker)) throw new RuntimeException('Cannot simulate import worker failure');
    fclose($pipes[0]); $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$codeExit=proc_close($worker);
    ok142($codeExit===73,'worker exited after media publication: '.$stdout.' '.$stderr);
    ok142(RecoveryJournal::pending($target) && file_exists($filePath) && file_exists($indexPath),
        'interrupted import has persistent undo marker and new media');
    rejected142(static fn()=>(new SiteRepository($target))->read(), 'site reads fail closed while recovery pending');
    rejected142(static fn()=>$destMedia->listing(), 'media reads fail closed while recovery pending');
    ok142($destOps->recoverPending(),'interrupted import automatically replays undo journal');
    ok142(!RecoveryJournal::pending($target) && !file_exists($filePath) && !file_exists($indexPath),
        'recovery restores exact absent index and removes partially imported media');
    ok142(!$destOps->recoverPending(),'recovery is idempotent');
    ok142($dst->load()['pages']['/']['title']!=='Transferred home', 'interrupted import preserved old site');

    // Full import after recovery: original credentials and canonical URL survive.
    $portable=$srcOps->exportArchive();
    $destOps->importFile($portable);
    ok142($dst->load()['pages']['/']['title']==='Transferred home','valid import commits site');
    ok142(password_verify('KeepPassword142!',$dst->load()['password']),'destination credentials preserved');
    ok142($dst->load()['settings']['url']==='https://target.example.test','destination origin preserved');
    ok142($destMedia->read($asset['id'])['bytes']===$png,'media checksum intact after import');
    ok142(!RecoveryJournal::pending($target),'success removes durable marker');
    $srcOps->removeExport($portable);

    // Audit and explicit repair of files orphaned by a crash after index removal.
    $orphan=$target.'/storage/media/files/'.str_repeat('f',32).'.png';
    OperationFiles::writeNew($orphan,$png,0600);
    $staged=$target.'/storage/media/.tmp-'.str_repeat('a',24);
    OperationFiles::writeNew($staged,'interrupted-index-write',0600);
    ok142($destOps->auditStorage()['orphan_files']===2,'audit detects unindexed asset and interrupted index stage');
    $fixed=$destOps->auditStorage(true);
    ok142($fixed['orphan_files_removed']===2 && !file_exists($orphan) && !file_exists($staged),
        'repair removes only orphaned and interrupted staging files');
    ok142($destMedia->read($asset['id'])['bytes']===$png,'indexed assets survive repair');

    // Fail closed on tampering: indexed media must be recovered from a backup,
    // never silently dropped by an audit/repair operation.
    file_put_contents($filePath,'tampered');
    rejected142(static fn()=>$destOps->auditStorage(true),'corrupt registered media rejected');
    rejected142(static fn()=>$destOps->createBackup(),'damaged media cannot produce a ready backup');
    ok142($destOps->names()===[],'failed backup leaves no ready TAR');
    OperationFiles::write($filePath,$png,0600);
    ok142($destOps->auditStorage()['indexed_files']===1,'corrected bytes pass audit');

    // Never prune the only good recovery point in favor of a newer corrupt TAR.
    $old=$srcOps->createBackup();
    $new=$srcOps->createBackup();
    $newFile=$srcOps->backupPath($new);
    $last=file_get_contents($newFile);$offset=strpos($last,'Transferred home');
    ok142($offset!==false,'backup contains page body');
    $last[$offset]='X';OperationFiles::write($newFile,$last,0600);
    rejected142(static fn()=>$srcOps->pruneBackups(1),'retention refuses corrupt newest backup');
    ok142(in_array($old,$srcOps->names(),true),'old verified backup retained after rejected prune');
    ok142($srcOps->verifyBackup($old)['media']===1,'old backup still restorable');

    // Source failure and recovery of indexed file contents remain deterministic.
    $indexBefore=file_get_contents($indexPath);
    $destMedia->rename($asset['id'],'new-name.png');
    ok142($destMedia->read($asset['id'])['meta']['name']==='new-name.png','atomic index rename works');
    $destMedia->rename($asset['id'],'photo.png');
    ok142(file_get_contents($indexPath)===$indexBefore,'repeated metadata update preserves stable JSON');

    // Concurrent readers see either the entire verified image or its absence,
    // never a torn index/asset pair during deletion.
    $dst->change(static function(array $state):array {
        $state['seo']['default_image']='';
        return $state;
    });
    $ready=$target.'/media-reader-ready';
    $readerCode=<<<'READER'
require $argv[1].'/bootstrap.php';
$lib=new \CajeerEngine\Media\MediaLibrary($argv[2]);
file_put_contents($argv[3],'ready');
for($i=0;$i<300;$i++) {
    $file=$lib->read($argv[4]);
    if($file!==null && hash('sha256',$file['bytes'])!==$argv[5]) exit(44);
    usleep(1500);
}
READER;
    $reader=proc_open([PHP_BINARY,'-r',$readerCode,dirname(__DIR__),$target,$ready,
        $asset['id'],hash('sha256',$png)],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$readerPipes);
    if (!is_resource($reader)) throw new RuntimeException('Cannot start concurrent media reader');
    fclose($readerPipes[0]);
    for($i=0;$i<150 && !is_file($ready);$i++) usleep(10000);
    ok142(is_file($ready),'media reader worker started');
    $destMedia->delete($asset['id']);
    $readerOut=stream_get_contents($readerPipes[1]);$readerErr=stream_get_contents($readerPipes[2]);
    fclose($readerPipes[1]);fclose($readerPipes[2]);
    ok142(proc_close($reader)===0,'reader/deleter synchronization: '.$readerOut.' '.$readerErr);
    ok142($destMedia->read($asset['id'])===null && !file_exists($filePath), 'media delete removes index and file');

    echo "Storage & Recovery 1.4.2: crash undo, strict index rollback, complete TAR publication, corrupted media rejection, audited repair, retention and concurrent delete OK\n";
} finally {clean142($source);clean142($target);}
