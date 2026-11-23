<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Media\MediaLibrary;
use CajeerEngine\Operations\{CmsOperations,TarPackage,OperationsView};
use CajeerEngine\Storage\SiteState;
function ok138(bool $condition,string $message): void { if (!$condition) throw new RuntimeException('1.3.8: '.$message); }
function reject138(callable $fn,string $message):void {try {$fn();}catch(InvalidArgumentException|RuntimeException|JsonException){return;}throw new RuntimeException('1.3.8: expected rejection: '.$message);}
function delete138(string $root):void { $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($iterator as $item){if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($root); }
$root=sys_get_temp_dir().'/lite138-'.bin2hex(random_bytes(6));mkdir($root);mkdir($root.'/storage');
$other=sys_get_temp_dir().'/lite138-other-'.bin2hex(random_bytes(6));mkdir($other);mkdir($other.'/storage');
try {
    $app=new Lite($root);$app->install('OriginalPassword138!','https://example.test','Original');
    ok138(version_compare(Lite::VERSION,'1.3.8','>=')&&SiteState::SCHEMA===14,'version and compatible schema');
    $media=new MediaLibrary($root);$sample=$root.'/sample.png';
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGOUT9rBwMDAxMDAwMDAAAAOPQE9xgjZwQAAAABJRU5ErkJggg==',true);
    file_put_contents($sample,$png);$entry=$media->ingest($sample,'public-asset.png');
    $app->change(function(array $state)use($entry):array { $state['pages']['/']['title']='Archive content';$state['seo']['default_image']=$entry['id'];return $state; });
    $service=new CmsOperations($root);$backup=$service->createBackup();
    $info=$service->verifyBackup($backup);
    ok138($info['kind']==='backup'&&$info['media']===1&&$info['pages']===1,'full archive, manifest and actual media bytes');
    $archive=$service->backupPath($backup);
    ok138((fileperms($archive)&0777)===0600,'private archive');
    $html=OperationsView::render($service,'abcd');
    ok138(str_contains($html,'name="action" value="restore"')&&str_contains($html,$backup),'operational UI');
    $export=$service->exportArchive();
    $portable=$service->verifyFile($export);
    ok138($portable['kind']==='export','portable export');
    $another=new Lite($other);$another->install('KeepThePassword138!','https://other.test','Other');
    $target=new CmsOperations($other);
    $target->importFile($export);
    ok138($another->load()['pages']['/']['title']==='Archive content','content transfer');
    ok138(password_verify('KeepThePassword138!',$another->load()['password']),'target credentials preserved');
    ok138($another->load()['settings']['url']==='https://other.test','target administrative hostname preserved');
    ok138(!password_verify('OriginalPassword138!',$another->load()['password']),'origin password not transferred');
    ok138((new MediaLibrary($other))->read($entry['id'])['bytes']===$png,'asset identifiers preserved');
    $service->removeExport($export);
    // Restore from private backup, after state changes and media removal.
    $app->change(static function(array $s):array {$s['pages']['/']['title']='New content';$s['seo']['default_image']='';$s['password']=password_hash('UpdatedPassword138!',PASSWORD_DEFAULT);return $s;});
    $media->delete($entry['id']);
    $service->restoreBackup($backup);
    $restored=$app->load();
    ok138($restored['pages']['/']['title']==='Archive content'&&$media->read($entry['id'])['bytes']===$png,'backup restore full content+asset');
    ok138(password_verify('UpdatedPassword138!',$restored['password']),'restore cannot roll back credentials');
    $old=$app->load();
    $damaged=$root.'/damaged.tar';copy($archive,$damaged);
    $body=file_get_contents($damaged);$offset=strpos($body,'Archive content');ok138($offset!==false,'find payload');
    $body[$offset]='X';file_put_contents($damaged,$body);
    reject138(fn()=>$service->verifyFile($damaged),'tampered tar fails checksum');
    reject138(fn()=>$service->importFile($damaged),'tampered import rejected');
    ok138($app->load()===$old,'bad import has no effects');
    reject138(fn()=>$service->restoreBackup('../../etc/passwd'),'path traversal');
    reject138(fn()=>$service->pruneBackups(0),'cannot prune all backups');
    $cache=$root.'/storage/analytics-cache';mkdir($cache);file_put_contents($cache.'/'.str_repeat('a',64).'.json','{}');
    ok138($service->clearAnalyticsCache()===1,'actual cache removal');
    $service->createBackup();ok138(count($service->names())===2,'backups remain listed');
    ok138($service->pruneBackups(1)===1&&count($service->names())===1,'explicit retention');
    ok138($service->health()['media_files']===1,'health reports live media');
    echo "CMS Operations 1.3.8: TAR export/backup/verify/import/restore, media IDs, auth, rollback, checksum, cache, retention OK\n";
} finally {delete138($root);delete138($other);}
