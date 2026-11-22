<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Integrations\{ProductionAcceptance,GA4Config,MetricaConfig,GTMConfig,ClarityConfig,MatomoConfig};
use CajeerEngine\Storage\SiteState;

function assert110(bool $valid, string $message): void {
    if (!$valid) throw new RuntimeException($message);
}
function clean110(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $item) if (!$item->isDot()) clean110($item->getPathname());
    rmdir($path);
}
$root = sys_get_temp_dir().'/lite-production-'.bin2hex(random_bytes(8));
mkdir($root.'/public/assets',0755,true);
try {
    foreach (['consent','ga4','metrica','gtm','clarity','matomo'] as $asset) {
        copy(dirname(__DIR__).'/public/assets/'.$asset.'.js',$root.'/public/assets/'.$asset.'.js');
        chmod($root.'/public/assets/'.$asset.'.js',0644);
    }
    $app = new Lite($root);
    $app->install('ExtremelyStrongPassword123','https://lite.example.org','Production acceptance');
    assert110(version_compare(Lite::VERSION,'1.2.2','>=') && SiteState::SCHEMA===13,'unexpected release baseline');
    $report=ProductionAcceptance::run($root,$app->load());
    assert110($report['ok'] && $report['manual']===[], 'inactive install readiness');

    $app->change(static function(array $d): array {
        $d['integrations']['ga4']=GA4Config::fromPost(['enabled'=>'1','measurement_id'=>'G-1234567890']);
        $d['integrations']['metrica']=MetricaConfig::fromPost(['enabled'=>'1','counter_id'=>'12345678','webvisor'=>'1',
           'email_goal'=>'contact_email','phone_goal'=>'contact_phone']);
        $d['integrations']['gtm']=GTMConfig::fromPost(['enabled'=>'1','container_id'=>'GTM-ABCDE123']);
        $d['integrations']['clarity']=ClarityConfig::fromPost(['enabled'=>'1','project_id'=>'abc123','recordings'=>'1']);
        $d['integrations']['matomo']=MatomoConfig::fromPost(['enabled'=>'1','mode'=>'onpremise',
           'server_url'=>'https://stats.example.org/analytics/','site_id'=>'9']);
        return $d;
    });
    $report=ProductionAcceptance::run($root,$app->load());
    assert110(!$report['ok'] && in_array('fail',array_column($report['checks'],'status'),true),
        'missing privacy policy should block release');
    assert110(count($report['manual'])>=5,'live provider verification should not be auto-claimed');
    $app->change(static function(array $d): array {
        $d['pages']['/privacy']=Lite::page(['path'=>'/privacy','title'=>'Privacy',
             'body'=>'Tracking and consent policy','published'=>'1','menu'=>'1']);
        $d['consent']['privacy_url']='/privacy';
        $d['consent']['revision']++;
        return $d;
    });
    $state=$app->load();
    $report=ProductionAcceptance::run($root,$state);
    assert110($report['ok'] && count($report['manual'])>=5,'five-provider readiness failed');
    assert110($report['providers']['ga4']==='blocked' && $report['providers']['gtm']==='active',
        'double GA4 protection lost');
    assert110(count(array_filter($report['checks'],static fn(array $c): bool =>
       $c['id']==='analytics.ga4-gtm' && $c['status']==='warn'))===1,'missing GTM double-tag warning');
    chmod($root.'/public/assets/ga4.js', 0664);
    $failure=ProductionAcceptance::run($root,$app->load());
    assert110(!$failure['ok'],'group-writable analytics loader passed production gate');
    chmod($root.'/public/assets/ga4.js',0644);
    assert110(ProductionAcceptance::run($root,$app->load())['ok'],'cannot recover after fixing permissions');
    $raw=file_get_contents($app->file());
    $snapshot=$app->backups()->create();
    assert110($app->backups()->verify($snapshot)['schema']===13,'backup not verified');
    $app->change(static function(array $s): array { $s['integrations']['matomo']['enabled']=false; return $s; });
    assert110($app->load()['integrations']['matomo']['enabled']===false,'mutation failed');
    $app->backups()->restore($snapshot);
    assert110(file_get_contents($app->file())===$raw,'backup restore changed stored settings');

    $http=$app->load(); $http['settings']['url']='http://example.org';
    $fail=ProductionAcceptance::run($root,$http);
    assert110(!$fail['ok'] && in_array('deployment.https',array_column(array_filter($fail['checks'],
      static fn(array $c): bool => $c['status']==='fail'),'id'),true), 'non-HTTPS release accepted');
    $noConsent=$app->load(); $noConsent['consent']['enabled']=false;
    $fail=ProductionAcceptance::run($root,$noConsent);
    assert110(!$fail['ok'],'analytics release without consent accepted');

    assert110($app->load()['schema']===13 && !$app->migrate(),'release changed JSON schema');
    echo "Lite 1.1.0 production acceptance/backup/configuration regression OK\n";
} finally { clean110($root); }
