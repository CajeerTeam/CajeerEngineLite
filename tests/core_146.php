<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Storage\{SiteState,SiteRepository,BackupService};
use CajeerEngine\Update\OperationFiles;
use CajeerEngine\Compatibility\{CompatibilityAssurance,TrustedProxy};
use CajeerEngine\Security\{AdminRequestGuard,RequestRejected};
function ensure146(bool $yes,string $message): void {if(!$yes)throw new RuntimeException('1.4.6: '.$message);}
function rejected146(callable $fn,string $description): void {
    try { $fn(); } catch(Throwable) {return;}
    throw new RuntimeException('Expected rejection: '.$description);
}
function legacy146(array $site,int $version): array {
    $site['schema']=$version;
    unset($site['seo']);
    if ($version<14) foreach($site['pages'] as &$page) {unset($page['blocks'],$page['seo']);} unset($page);
    if ($version<13) unset($site['ai']);
    if ($version<12) unset($site['auth_policy']);
    if ($version<11 && isset($site['identity'])) unset($site['identity']['generation']);
    if ($version<10) unset($site['identity']);
    if ($version<9) unset($site['reporting']);
    if ($version<8) unset($site['integrations']['matomo']);
    if ($version<7) unset($site['integrations']['clarity']);
    if ($version<6) unset($site['integrations']['gtm']);
    if ($version<5) unset($site['integrations']['metrica']);
    if ($version<4) unset($site['integrations']);
    if ($version<3) unset($site['consent']);
    if ($version<2) unset($site['modules']);
    SiteState::validate($site);
    return $site;
}
function rm146(string $dir): void {
    if (!is_dir($dir)) return;
    $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($items as $item) $item->isDir()&&!$item->isLink()?rmdir($item->getPathname()):unlink($item->getPathname());
    rmdir($dir);
}
ensure146(version_compare(Lite::VERSION,'1.4.6','>='),'version');
$root=sys_get_temp_dir().'/cajeer-compat-146-'.bin2hex(random_bytes(6));
mkdir($root,0700);
try {
    mkdir($root.'/public',0755);
    copy(dirname(__DIR__).'/public/index.php',$root.'/public/index.php');
    file_put_contents($root.'/VERSION',Lite::VERSION."\n");
    $cms=new Lite($root);
    $cms->install('CompatStrongPassword146!','https://test.example.org','Legacy-compatible site');
    $current=$cms->load();
    $current['pages']['/']['body']='Preserve all published content!';
    $current['pages']['/']['blocks']=[['type'=>'paragraph','text'=>'Preserve all published content!']];
    // A fresh site fixture is used for each historical schema.
    foreach (range(1,13) as $version) {
        $legacy=legacy146($current,$version);
        OperationFiles::write($root.'/storage/site.json',json_encode($legacy,JSON_THROW_ON_ERROR)."\n",0600);
        $original=file_get_contents($root.'/storage/site.json');
        $preview=CompatibilityAssurance::preview($root);
        ensure146($preview['source_schema']===$version && $preview['target_schema']===14,'migration preview '.$version);
        ensure146($original===file_get_contents($root.'/storage/site.json'),'dry-run immutable '.$version);
        rejected146(fn()=>CompatibilityAssurance::apply($root),'migration confirmation required');
        ensure146(file_get_contents($root.'/storage/site.json')===$original,'rejected migration immutable '.$version);
        putenv('CAJEER_COMPATIBILITY_CONFIRM=MIGRATE');
        $result=CompatibilityAssurance::apply($root);
        putenv('CAJEER_COMPATIBILITY_CONFIRM');
        ensure146($result['changed']===true && $result['after']['source_schema']===14,'applied migration '.$version);
        $migrated=(new SiteRepository($root))->read();
        ensure146($migrated['settings']===$legacy['settings'] &&
            $migrated['pages']===$legacy['pages'] && $migrated['password']===$legacy['password'],
            'page, origin and password unchanged '.$version);
        $backups=(new BackupService(new SiteRepository($root)))->names();
        $found=false;
        foreach($backups as $backup) {
            $info=(new BackupService(new SiteRepository($root)))->verify($backup);
            if($info['reason']==='migration' && $info['schema']===$version) {$found=true;break;}
        }
        ensure146($found,'validated original schema snapshot '.$version);
        $after=file_get_contents($root.'/storage/site.json');
        putenv('CAJEER_COMPATIBILITY_CONFIRM=MIGRATE');
        $noop=CompatibilityAssurance::apply($root);
        putenv('CAJEER_COMPATIBILITY_CONFIRM');
        ensure146(!$noop['changed'] && file_get_contents($root.'/storage/site.json')===$after,'idempotent migration '.$version);
    }
    $stale=legacy146($current,13);
    OperationFiles::write($root.'/storage/site.json',json_encode($stale,JSON_THROW_ON_ERROR)."\n",0600);
    $oldHash=CompatibilityAssurance::preview($root)['source_sha256'];
    OperationFiles::write($root.'/storage/site.json',json_encode($stale,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n",0600);
    rejected146(fn()=>(new SiteRepository($root))->migrate($oldHash),'stale legacy migration preview');
    ensure146((new SiteRepository($root))->read()['schema']===13,'concurrent update must not be migrated');
    $bad=legacy146($current,1);$bad['pages']['/']['published']=false;
    OperationFiles::write($root.'/storage/site.json',json_encode($bad,JSON_THROW_ON_ERROR),0600);
    $before=file_get_contents($root.'/storage/site.json');
    rejected146(fn()=>CompatibilityAssurance::preview($root),'invalid legacy source');
    putenv('CAJEER_COMPATIBILITY_CONFIRM=MIGRATE');
    rejected146(fn()=>CompatibilityAssurance::apply($root),'invalid legacy never applied');
    putenv('CAJEER_COMPATIBILITY_CONFIRM');
    ensure146(file_get_contents($root.'/storage/site.json')===$before,'failed migration does not mutate state');
    OperationFiles::write($root.'/storage/site.json',SiteState::encode($current),0600);
    $report=CompatibilityAssurance::check($root,$root.'/public');
    ensure146($report['ok'] && !$report['manual_required'],'complete local compatibility report');
    $report=CompatibilityAssurance::check($root,$root);
    ensure146(!$report['ok'],'wrong DocumentRoot fails');
    putenv('CAJEER_TRUSTED_PROXY_IPS=127.0.0.1,::1');
    ensure146(count(TrustedProxy::addresses())===2,'trust exact IPv4+IPv6 peers');
    $request=['REMOTE_ADDR'=>'127.0.0.1','HTTP_HOST'=>'backend.internal',
        'HTTPS'=>'off','HTTP_X_FORWARDED_HOST'=>'test.example.org',
        'HTTP_X_FORWARDED_PROTO'=>'https'];
    $normalized=TrustedProxy::normalize('https://test.example.org',$request);
    ensure146($normalized['HTTPS']==='on' && $normalized['HTTP_HOST']==='test.example.org','trusted proxy TLS and Host');
    AdminRequestGuard::check('https://test.example.org',$normalized,'/admin','GET');
    $untrusted=$request;$untrusted['REMOTE_ADDR']='192.0.2.10';
    rejected146(fn()=>AdminRequestGuard::check('https://test.example.org',TrustedProxy::normalize('https://test.example.org',$untrusted),'/admin','GET'),'untrusted peer cannot force TLS');
    foreach(['test.example.org.evil.test','test.example.org,evil.test','test.example.org:443@evil.test',
        'backend.internal','test.example.org:8443'] as $host) {
        $bad=$request;$bad['HTTP_X_FORWARDED_HOST']=$host;
        rejected146(fn()=>TrustedProxy::normalize('https://test.example.org',$bad),'forwarded host '.$host);
    }
    foreach(['http','https,http',' HTTPS','javascript',''] as $proto) {
        $bad=$request;$bad['HTTP_X_FORWARDED_PROTO']=$proto;
        rejected146(fn()=>TrustedProxy::normalize('https://test.example.org',$bad),'forwarded scheme '.$proto);
    }
    $bad=$request;unset($bad['HTTP_X_FORWARDED_HOST']);
    rejected146(fn()=>TrustedProxy::normalize('https://test.example.org',$bad),'incomplete trusted headers');
    $bad=$request;$bad['HTTP_X_FORWARDED_PROTO']='https';$bad['HTTP_HOST']='other.local';
    AdminRequestGuard::check('https://test.example.org',TrustedProxy::normalize('https://test.example.org',$bad),'/admin','GET');
    $bad=$request;$bad['HTTPS']='on';$bad['HTTP_X_FORWARDED_PROTO']='http';
    rejected146(fn()=>TrustedProxy::normalize('http://test.example.org',$bad),'contradictory TLS signal');
    putenv('CAJEER_TRUSTED_PROXY_IPS=0.0.0.0/0');
    rejected146(fn()=>TrustedProxy::addresses(),'CIDR trust-all denied');
    putenv('CAJEER_TRUSTED_PROXY_IPS=127.0.0.1,127.0.0.1');
    rejected146(fn()=>TrustedProxy::addresses(),'duplicate peers denied');
    putenv('CAJEER_TRUSTED_PROXY_IPS');
    echo "Compatibility Assurance 1.4.6: schemas 1–13 actual migrations, rollback snapshots, malformed data, exact trusted proxy TLS/Host, environment checks OK\n";
} finally { putenv('CAJEER_COMPATIBILITY_CONFIRM');putenv('CAJEER_TRUSTED_PROXY_IPS');rm146($root); }
