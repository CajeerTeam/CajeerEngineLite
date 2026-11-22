<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Auth\{OwnerIdentity,SessionRegistry};
use CajeerEngine\Lite;
use CajeerEngine\Storage\SiteState;

function assert114(bool $pass,string $why): void { if (!$pass) throw new RuntimeException($why); }
function reject114(callable $fn,string $why): void {
    try {$fn();} catch (RuntimeException|InvalidArgumentException|JsonException $e) {return;}
    throw new RuntimeException('Allowed unsafe action: '.$why);
}
function clean114(string $path): void {
    if (is_link($path) || is_file($path)) {unlink($path);return;}
    if (is_dir($path)) {foreach (new FilesystemIterator($path) as $child) clean114($child->getPathname());rmdir($path);}
}
$root=sys_get_temp_dir().'/cajeer-session-'.bin2hex(random_bytes(8)); mkdir($root,0700,true);
try {
    $app=new Lite($root); $app->install('StrongPasswordForSessions123','https://lite.example.test','Sessions');
    $site=$app->load();
    assert114(Lite::VERSION==='1.2.1' && SiteState::SCHEMA===13 && $site['schema']===13,'release baseline, schema unchanged');
    $store=new SessionRegistry($root);
    $first=[]; $second=['oidc_identity'=>['iss'=>'https://id.skif4er.ru/realms/skif4er','sub'=>'test-sub']];
    $now=1800000000;
    assert114(!$store->valid($first,$site,$now),'legacy PHP-only sessions must fail closed');
    $id1=$store->issue($first,$site,['REMOTE_ADDR'=>'192.0.2.10','HTTP_USER_AGENT'=>'Firefox / test'], $now);
    $id2=$store->issue($second,$site,['REMOTE_ADDR'=>'2001:db8::1','HTTP_USER_AGENT'=>'Chrome test'], $now);
    assert114($id1!==$id2 && $id1===SessionRegistry::currentId($first),'unique opaque IDs');
    assert114($store->valid($first,$site,$now+3) && $store->valid($second,$site,$now+3),'two devices can authenticate');
    $items=$store->devices($first,$site,$now+3);
    assert114(count($items)===2 && $items[0]['current']!==$items[1]['current'],'device list and current marker');
    assert114(count(array_filter($items,static fn(array $row):bool=>$row['method']==='oidc'))===1,'OIDC session shown');
    $file=$root.'/storage/auth/sessions.json';
    assert114(is_file($file) && (fileperms($file)&0077)===0 &&
        (fileperms(dirname($file))&0077)===0 && (fileperms($root.'/storage')&0077)===0,'private storage');
    $raw=file_get_contents($file);
    assert114(!str_contains($raw,$first['cajeer_session_token']) && !str_contains($raw,$second['cajeer_session_token']),'no bearer tokens on disk');
    assert114($store->revoke($first,$site,'one',$id2,$now+4)===false,'revoke second device');
    assert114(!$store->valid($second,$site,$now+5) && $store->valid($first,$site,$now+5),'only selected device is revoked');
    reject114(fn()=> $store->revoke($first,$site,'one','../../etc/passwd',$now+5),'path-shaped ID');
    reject114(fn()=> $store->revoke($first,$site,'one',str_repeat('f',64),$now+5),'nonexistent session');
    reject114(fn()=> $store->revoke($second,$site,'all',null,$now+5),'revoked device cannot revoke other sessions');
    $store->issue($second,$site,[],$now+6);
    assert114(count($store->devices($first,$site,$now+7))===2,'second login available after old revoked');
    assert114(!$store->revoke($first,$site,'others',null,$now+8),'revoke other devices retains current');
    assert114($store->valid($first,$site,$now+9) && !$store->valid($second,$site,$now+9),'other-device revocation effective');
    assert114($store->revoke($first,$site,'all',null,$now+10),'revoke all includes current');
    assert114(!$store->valid($first,$site,$now+11),'all removed');

    $store->issue($first,$site,[],$now+50);
    assert114($store->valid($first,$site,$now+51),'new session after revoke all');
    assert114(!$store->valid($first,$site,$now+51+SessionRegistry::IDLE_SECONDS),'idle expiry');
    $store->issue($first,$site,[],$now+5000);
    for ($point=$now+5001;$point<$now+5000+SessionRegistry::MAX_SECONDS;$point+=1800) {
        assert114($store->valid($first,$site,$point),'alive before absolute expiry');
    }
    assert114(!$store->valid($first,$site,$now+5000+SessionRegistry::MAX_SECONDS),'absolute expiry despite activity');

    $store->issue($first,$site,[],$now+100000);
    $site['identity']['generation']=OwnerIdentity::generation();
    assert114(!$store->valid($first,$site,$now+100001),'owner generation change invalidates all sessions');
    $store->issue($first,$site,[],$now+100002);
    $site['password']=password_hash('ChangedPasswordForTest123',PASSWORD_DEFAULT);
    assert114(!$store->valid($first,$site,$now+100003),'password rotation invalidates sessions');

    $site=$app->load();
    // Restoring a backup must revoke even sessions with an unchanged owner generation.
    $backup=$app->backups()->create();
    $store->issue($first,$site,[],$now+100500);
    $app->backups()->restore($backup);
    assert114(!$store->valid($first,$app->load(),$now+100501),'restore must invalidate sessions');
    $store->issue($first,$site,[],$now+120000);
    $saved=file_get_contents($file);
    file_put_contents($file,'{"version":1,"items":{"invalid":"oops"}}');
    reject114(fn()=> $store->valid($first,$site,$now+120001),'corrupt registry must fail closed');
    file_put_contents($file,$saved);
    @chmod($file,0644);
    reject114(fn()=> $store->valid($first,$site,$now+120001),'world-readable registry refused');
    @chmod($file,0600);
    unlink($file); symlink($root.'/storage/site.json',$file);
    reject114(fn()=> $store->valid($first,$site,$now+120001),'symlink registry refused');
    unlink($file);
    $store->issue($first,$site,[],$now+120002);
    assert114($store->valid($first,$site,$now+120003),'operational recovery after bad storage removed');
} finally { clean114($root); }
echo "Lite 1.1.6 session lifecycle, revocations, TTL, generation and file security OK\n";
