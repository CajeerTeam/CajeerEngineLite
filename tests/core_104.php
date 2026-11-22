<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Integrations\GA4Config;
use CajeerEngine\Storage\SiteState;
use CajeerEngine\Update\OperationFiles;
function assert104(bool $value,string $why): void { if (!$value) throw new RuntimeException($why); }
function reject104(callable $fn,string $why): void {
    try { $fn(); } catch (InvalidArgumentException|RuntimeException) { return; }
    throw new RuntimeException('Expected failure: '.$why);
}
function remove104(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) remove104($entry->getPathname());
    rmdir($path);
}
$root=sys_get_temp_dir().'/lite-ga4-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    $app=new Lite($root);
    $app->install('TestPassword123','https://example.org','GA4 example');
    $d=$app->load();
    assert104(Lite::VERSION==='1.0.5' && SiteState::SCHEMA===5 && $d['schema']===5,'version/schema');
    assert104($d['integrations']['ga4']===GA4Config::DEFAULTS,'default GA4 settings');
    assert104(!str_contains((new Lite(dirname(__DIR__)))->render($d,$d['pages']['/']),'/assets/ga4.js'),'disabled GA4 rendered');
    reject104(fn()=>GA4Config::fromPost(['enabled'=>'1','measurement_id'=>'G-NOT!SAFE']), 'injection ID');
    reject104(fn()=>GA4Config::fromPost(['enabled'=>'1','measurement_id'=>'']), 'empty ID on enable');
    reject104(fn()=>GA4Config::fromPost(['enabled'=>'0','measurement_id'=>'G-1234567890']), 'invalid checkbox');
    reject104(fn()=>GA4Config::fromPost(['measurement_id'=>[]]), 'array ID');
    reject104(fn()=>GA4Config::fromPost(['enabled'=>'1','measurement_id'=>'G-1234567890','debug_mode'=>[]]), 'array flag');
    $config=GA4Config::fromPost(['enabled'=>'1', 'measurement_id'=>' g-1234567890 ', 'page_views'=>'1','contact_clicks'=>'1']);
    assert104($config['measurement_id']==='G-1234567890' && $config['enabled'] && !$config['debug_mode'], 'GA4 config canonicalization');
    $app->change(static function(array $d)use($config): array {$d['integrations']['ga4']=$config;return $d;});
    $state=$app->load();
    $_COOKIE['cajeer_lite_consent']='v1.1.'.time().'.0';
    $html=(new Lite(dirname(__DIR__)))->render($state,$state['pages']['/']);
    assert104(str_contains($html, '/assets/ga4.js?v=104') && str_contains($html,'data-ga4-id="G-1234567890"'), 'ga4 script not rendered');
    unset($_COOKIE['cajeer_lite_consent']);
    $state['consent']['enabled']=false;
    assert104(!str_contains((new Lite(dirname(__DIR__)))->render($state,$state['pages']['/']), '/assets/ga4.js'), 'disabled consent must block GA4');
    $state['consent']['enabled']=true;
    $bytes=file_get_contents($app->file());
    $bad=$state; $bad['integrations']['ga4']['measurement_id']='<script>'; 
    reject104(fn()=>$app->change(static fn()=> $bad),'durable config injection');
    assert104(file_get_contents($app->file())===$bytes,'bad config was persisted');

    // Simulate the user-provided 1.0.3 schema 3 site and verify migration/backup.
    $old=$state;
    $old['schema']=3;
    unset($old['integrations']);
    $raw=SiteState::encode($old);
    OperationFiles::write($app->file(),$raw,0600);
    assert104($app->migrate(),'schema 3->5 migration missing');
    $up=$app->load();
    assert104($up['schema']===5 && $up['integrations']['ga4']===GA4Config::DEFAULTS,'3->5 defaults');
    foreach (['settings','pages','password','login','modules','consent'] as $key) assert104($up[$key]===$old[$key], 'migration changed '.$key);
    $backupName=null;
    foreach ($app->backups()->names() as $name) {
        if ($app->backups()->verify($name)['reason']==='migration' && $app->backups()->verify($name)['schema']===3) $backupName=$name;
    }
    assert104($backupName!==null,'original schema 3 not backed up');
    assert104(str_contains(file_get_contents($root.'/storage/backups/'.$backupName),base64_encode($raw)), 'backup did not preserve original JSON');
    $app->backups()->restore($backupName);
    assert104($app->load()['schema']===5 && $app->load()['integrations']['ga4']===GA4Config::DEFAULTS,'restore schema 3 failed');
    assert104(!$app->migrate(),'same schema migration should be no-op');
    echo "Lite 1.0.4 GA4 core regression OK\n";
} finally { remove104($root); }
