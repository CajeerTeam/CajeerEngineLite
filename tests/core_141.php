<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use CajeerEngine\Auth\SessionRegistry;
use CajeerEngine\Operations\TarPackage;
use CajeerEngine\Lite;
use CajeerEngine\Storage\{BackupService,SiteRepository,SiteState};
use CajeerEngine\Update\OperationFiles;

function ok141(bool $ok, string $message): void { if (!$ok) throw new RuntimeException('1.4.1: '.$message); }
function reject141(callable $call,string $message): void {
    try { $call(); } catch (RuntimeException|InvalidArgumentException|JsonException) { return; }
    throw new RuntimeException('1.4.1: expected failure: '.$message);
}
function clean141(string $dir):void {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {if ($f->isDir()&&!$f->isLink()) rmdir($f->getPathname()); else unlink($f->getPathname());}
    rmdir($dir);
}
ok141(Lite::VERSION==='1.4.1' && SiteState::SCHEMA===14,'version and schema');
$root=sys_get_temp_dir().'/cms141-'.bin2hex(random_bytes(8)); mkdir($root,0700);
try {
    $app=new Lite($root); $app->install('ValidPasswordFor141!','https://example.test','Original');
    $sitePath=$root.'/storage/site.json';
    $initial=file_get_contents($sitePath); $inode=lstat($sitePath)['ino'];
    $app->change(static fn(array $state): array=>$state);
    ok141(file_get_contents($sitePath)===$initial && lstat($sitePath)['ino']===$inode,'no-op transaction avoids replacing site inode');
    // Authentication rate limits are unaffected by idempotent site transactions.
    $app->change(static function(array $s):array{$s['login']=['count'=>5,'until'=>time()+300];return $s;});
    $heldInode=lstat($sitePath)['ino'];
    ok141(!$app->login('wrong-password'),'locked login is rejected');
    ok141(lstat($sitePath)['ino']===$heldInode,'locked login does not write the site again');
    $app->change(static function(array $s):array{$s['login']=['count'=>0,'until'=>0];return $s;});

    $large=str_repeat("a\n\xc3\xa9",170000);
    $target=$root.'/private-new.txt';
    OperationFiles::writeNew($target,$large,0600);
    ok141(file_get_contents($target)===$large,'complete large data write');
    ok141((fileperms($target)&0077)===0,'private new-file permissions');
    reject141(static fn()=>OperationFiles::writeNew($target,'bad'),'no-clobber existing destination');
    ok141(file_get_contents($target)===$large,'collision leaves bytes unchanged');
    symlink('missing',$root.'/dangling');
    reject141(static fn()=>OperationFiles::writeNew($root.'/dangling','bad'),'no-clobber dangling symlink');
    ok141(is_link($root.'/dangling'),'symlink left untouched');

    $partial=$root.'/incomplete.tar';
    reject141(static fn()=>TarPackage::create($partial,['site.json'=>$root.'/missing.json']),'TAR must reject absent sources');
    ok141(!file_exists($partial),'failed TAR creation leaves no partial archive');

    $repo=new SiteRepository($root);$backups=new BackupService($repo);
    $backup=$backups->create();
    $app->change(static function(array $s):array {$s['pages']['/']['body']='Modified after backup';return $s;});
    $modified=file_get_contents($sitePath);
    $registry=new SessionRegistry($root);$session=[];
    $registry->issue($session,$app->load());
    ok141($registry->valid($session,$app->load()),'pre-restore lease valid');
    $sessionPath=$root.'/storage/auth/sessions.json';
    rename($sessionPath,$sessionPath.'.protected');
    symlink('missing-session-store',$sessionPath);
    reject141(static fn()=>$backups->restore($backup),'registry failure must cancel restore');
    ok141(file_get_contents($sitePath)===$modified,'site exact bytes rolled back after failed revocation');
    ok141(is_link($sessionPath),'foreign session path not replaced');
    unlink($sessionPath);rename($sessionPath.'.protected',$sessionPath);
    ok141($registry->valid($session,$app->load()),'session remains valid after failed restore');
    $rescue=$backups->restore($backup);
    ok141($rescue!=='' && $backups->verify($rescue)['reason']==='pre-restore','pre-restore recovery copy exists');
    ok141($app->load()['pages']['/']['body']!=='Modified after backup','content restored');
    ok141(!$registry->valid($session,$app->load()),'successful restore revokes old lease');

    // Damaged JSON is recoverable and its exact bytes remain in a rescue snapshot.
    $invalid='{"schema":oops'; OperationFiles::write($sitePath,$invalid,0600);
    $damagedRescue=$backups->restore($backup);
    ok141($app->load()['pages']['/']['title']==='Добро пожаловать','restore from damaged state');
    $rescued=$root.'/storage/backups/'.$damagedRescue;
    $decoded=json_decode((string)file_get_contents($rescued),true,512,JSON_THROW_ON_ERROR);
    ok141(base64_decode($decoded['payload'],true)===$invalid,'damaged original preserved exactly');

    echo "Core Stability 1.4.1: idempotent saves, durable writes, no-clobber publication, restore rollback and revoked leases OK\n";
} finally {clean141($root);}
