<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use CajeerEngine\Lite;
use CajeerEngine\Auth\{RecoveryService,SessionRegistry,OwnerIdentity,OidcClient};
use CajeerEngine\Storage\SiteState;

function ok116(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function reject116(callable $fn, string $message): void {
    try { $fn(); } catch (RuntimeException|InvalidArgumentException|JsonException $e) { return; }
    throw new RuntimeException('Incorrectly allowed: '.$message);
}
function wipe116(string $path): void {
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    if (is_dir($path)) { foreach (new FilesystemIterator($path) as $entry) wipe116($entry->getPathname()); rmdir($path); }
}

ok116(Lite::VERSION==='1.2.0' && SiteState::SCHEMA===12, 'release version and schema');
$root = sys_get_temp_dir().'/cajeer-recovery-'.bin2hex(random_bytes(10));
mkdir($root,0700,true);
try {
    mkdir($root.'/public',0755);
    $app = new Lite($root);
    $oldPassword='OldPasswordSecure!123';
    $newPassword='ChangedPasswordSecure!456';
    $app->install($oldPassword, 'https://lite.example', 'Recovery integration');
    $owner=['iss'=>OidcClient::ISSUER,'sub'=>'lost-original-account'];
    $app->change(fn(array $state):array => OwnerIdentity::bind($state,$owner));
    $before=$app->load();
    $preRestore=$app->backups()->create();
    $registry=new SessionRegistry($root);
    $local=[]; $oidc=['oidc_identity'=>$owner, 'oidc_sid'=>'abc123'];
    $registry->issue($local,$before,['REMOTE_ADDR'=>'192.0.2.20']);
    $registry->issue($oidc,$before,['REMOTE_ADDR'=>'192.0.2.21']);
    ok116($registry->valid($local,$before) && $registry->valid($oidc,$before),'two active sessions before recovery');
    $service=new RecoveryService($app);
    $time=time();
    $token=$service->issue($time);
    ok116(strlen($token)===64 && ctype_xdigit($token),'secure token');
    $raw=file_get_contents($root.'/storage/auth/recovery.json');
    ok116(!str_contains($raw,$token) && (fileperms($root.'/storage/auth/recovery.json')&0077)===0,'token stored hashed in private file');
    $status=$service->status($time);
    ok116($status['active'] && $status['attempts_remaining']===5 && $status['expires']===$time+600,'short-lived status');
    reject116(fn()=> $service->complete(str_repeat('a',64),$newPassword,$time+1),'wrong token');
    ok116($service->status($time+1)['attempts_remaining']===4,'bad attempts persisted');
    reject116(fn()=> $service->complete($token,'short',$time+2),'short password');
    reject116(fn()=> $service->complete($token,$oldPassword,$time+2),'same password');
    ok116($app->load()['identity']['owner']===$owner && $registry->valid($local,$before),'rejected recovery must not mutate site or sessions');
    $service->complete($token,$newPassword,$time+3);
    $after=$app->load();
    ok116($after['identity']['owner']===null && $after['identity']['generation']!==$before['identity']['generation'],'owner detached and generation rotated');
    ok116($app->login($newPassword) && !$app->login($oldPassword),'recovered password live');
    ok116($after['schema']===12 && $after['pages']===$before['pages'] && $after['settings']===$before['settings'] &&
        $after['integrations']===$before['integrations'],'content and integrations preserved');
    ok116(!$registry->valid($local,$after) && !$registry->valid($oidc,$after),'all current sessions revoked');
    reject116(fn()=> $service->complete($token,'AnotherPassword!456',$time+4),'replay token');
    ok116(!$service->status($time+4)['active'],'grant consumed');
    $audit=file_get_contents($root.'/storage/auth/recovery-audit.jsonl');
    ok116(str_contains($audit,'"completed"') && !str_contains($audit,$token) &&
        !str_contains($audit,$newPassword) && !str_contains($audit,$owner['sub']),'redacted durable security audit');
    $app->backups()->restore($preRestore);
    $restored=$app->load();
    ok116($restored['identity']['owner']===null && $restored['identity']['generation']===$after['identity']['generation'],
        'restoring old backup must not undo recovery owner');
    ok116($app->login($newPassword) && !$app->login($oldPassword),'restoring old backup must not undo recovery password');
    // After a fresh, verified rebind, a restore preserves the new current owner.
    $newOwner=['iss'=>OidcClient::ISSUER,'sub'=>'replacement-account'];
    $app->change(fn(array $state):array => OwnerIdentity::bind($state,$newOwner));
    $app->backups()->restore($preRestore);
    ok116($app->load()['identity']['owner']===$newOwner,'new owner survives content restore');
    // Damaged live state restores content but never credentials from the old snapshot.
    file_put_contents($root.'/storage/site.json','{broken');
    $app->backups()->restore($preRestore);
    ok116($app->load()['identity']['owner']===null && $app->login($newPassword),
        'damaged state restoration uses protected recovered credentials');
    $grant2=$service->issue($time+30);
    $service->cancel($time+31);
    reject116(fn()=> $service->complete($grant2,'AnotherPassword!456',$time+31),'cancelled token');
    $grant3=$service->issue($time+50);
    reject116(fn()=> $service->complete($grant3,'AnotherPassword!456',$time+651),'expired token');
    $grant4=$service->issue($time+70);
    $app->change(fn(array $state):array => OwnerIdentity::bind($state,$newOwner));
    reject116(fn()=> $service->complete($grant4,'AnotherPassword!456',$time+71),'stale generation');
    $grant5=$service->issue($time+90);
    for($i=1;$i<=5;$i++) reject116(fn()=> $service->complete(str_repeat('f',64),'AnotherPassword!456',$time+90+$i),'wrong attempt '.$i);
    reject116(fn()=> $service->complete($grant5,'AnotherPassword!456',$time+100),'locked after 5 attempts');
    // CLI file reader requires non-public, private regular credential files.
    $secret=$root.'/token.txt'; file_put_contents($secret,$grant5."\n"); chmod($secret,0600);
    ok116(RecoveryService::readInput($root,$secret,128)===$grant5,'private file input');
    chmod($secret,0644);
    reject116(fn()=> RecoveryService::readInput($root,$secret,128),'world-readable input rejected');
    chmod($secret,0600);
    file_put_contents($root.'/public/secret.txt',$grant5); chmod($root.'/public/secret.txt',0600);
    reject116(fn()=> RecoveryService::readInput($root,$root.'/public/secret.txt',128),'web-accessible input rejected');
    // Symlinks and permissions fail closed for recovery grant and audit files.
    $grant6=$service->issue($time+200);
    $grantPath=$root.'/storage/auth/recovery.json';
    chmod($grantPath,0644);
    reject116(fn()=> $service->status($time+201),'world readable recovery grant rejected');
    chmod($grantPath,0600);
    unlink($grantPath); symlink($root.'/storage/site.json',$grantPath);
    reject116(fn()=> $service->status($time+201),'symlink recovery grant rejected');
} finally { wipe116($root); }
echo "Lite 1.1.6 CLI recovery, one-time grants, session revocation and backup safety OK\n";
