<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Integrations\{PublicCsp,IntegrationDiagnostics,GA4Config,GTMConfig,MetricaConfig,ClarityConfig,MatomoConfig};
use CajeerEngine\Storage\SiteState;

function ok109(bool $v, string $message): void { if (!$v) throw new RuntimeException($message); }
function remove109(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) remove109($entry->getPathname());
    rmdir($path);
}
$root = sys_get_temp_dir().'/lite-109-'.bin2hex(random_bytes(8));
mkdir($root.'/public/assets',0700,true);
try {
    foreach (['consent','ga4','metrica','gtm','clarity','matomo'] as $id) {
        copy(dirname(__DIR__).'/public/assets/'.$id.'.js', $root.'/public/assets/'.$id.'.js');
        chmod($root.'/public/assets/'.$id.'.js', 0644);
    }
    $app = new Lite($root);
    $app->install('SomeVeryStrongPassword123', 'https://lite.example.org', 'Security regression');
    $d = $app->load();
    ok109(Lite::VERSION === '1.1.4' && $d['schema'] === SiteState::SCHEMA, 'version mismatch');
    $report=IntegrationDiagnostics::run($root,$d);
    ok109($report['ok'] && count($report['checks']) >= 10, 'clean install diagnostics failed');
    ok109(!str_contains(PublicCsp::headers($d)['policy'],'https://www.googletagmanager.com'), 'disabled Google source exposed');

    $app->change(static function(array $s): array {
        $s['integrations']['ga4']=GA4Config::fromPost(['enabled'=>'1','measurement_id'=>'G-1234567890']);
        $s['integrations']['metrica']=MetricaConfig::fromPost(['enabled'=>'1','counter_id'=>'12345678','webvisor'=>'1',
            'email_goal'=>'contact_email','phone_goal'=>'contact_phone']);
        $s['integrations']['gtm']=GTMConfig::fromPost(['enabled'=>'1','container_id'=>'GTM-ABCD123']);
        $s['integrations']['clarity']=ClarityConfig::fromPost(['enabled'=>'1','project_id'=>'abc123','recordings'=>'1']);
        $s['integrations']['matomo']=MatomoConfig::fromPost(['enabled'=>'1','mode'=>'onpremise',
            'server_url'=>'https://stats.example.org/analytics/','site_id'=>'7']);
        return $s;
    });
    $d = $app->load();
    $report=IntegrationDiagnostics::run($root,$d);
    ok109($report['ok'], 'five-provider diagnostics failed');
    ok109($report['providers'] === ['ga4'=>'blocked','metrica'=>'active','gtm'=>'active',
        'clarity'=>'active','matomo'=>'active'], 'GTM suppresses direct GA4');
    ok109(in_array('warn',array_column($report['checks'],'status'),true), 'GA4/GTM conflict not warned');
    $csp=PublicCsp::headers($d);
    foreach (['https://www.googletagmanager.com','https://mc.yandex.ru',
        'https://www.clarity.ms','https://stats.example.org','script-src-attr \'none\''] as $source) {
        ok109(str_contains($csp['policy'],$source), 'missing CSP source '.$source);
    }
    ok109($csp['webvisor_frames'], 'Webvisor frame exception missing');
    ok109(!str_contains($csp['policy'],"'unsafe-inline'") &&
        !str_contains($csp['policy'],"'unsafe-eval'"), 'unsafe CSP');
    $d['integrations']['metrica']['webvisor']=false;
    $other=PublicCsp::headers($d);
    ok109(!$other['webvisor_frames'] && str_contains($other['policy'],"frame-ancestors 'none'"),
        'Webvisor frame rights leaked when feature disabled');
    $d['consent']['enabled']=false;
    $off=PublicCsp::headers($d);
    foreach (['googletagmanager.com','clarity.ms','mc.yandex.ru','stats.example.org'] as $host) {
        ok109(!str_contains($off['policy'],$host), 'CSP allowed provider with consent UI disabled');
    }
    ok109(!$off['webvisor_frames'], 'consent-off framing allowed');
    $app->change(static function(array $s): array { $s['consent']['enabled']=false; return $s; });
    $report=IntegrationDiagnostics::run($root,$app->load());
    ok109($report['ok'] && $report['providers']['gtm']==='blocked', 'disabled consent not reported');

    // A tampered file/symlink is not silently accepted as a working integration.
    $asset=$root.'/public/assets/matomo.js';
    chmod($asset,0666);
    ok109(!IntegrationDiagnostics::run($root,$app->load())['ok'], 'world-writable JS accepted');
    chmod($asset,0644);
    unlink($asset); symlink(dirname(__DIR__).'/public/assets/matomo.js',$asset);
    ok109(!IntegrationDiagnostics::run($root,$app->load())['ok'], 'symlink JS accepted');
    unlink($asset);
    copy(dirname(__DIR__).'/public/assets/matomo.js',$asset);
    chmod($asset,0644);
    ok109(IntegrationDiagnostics::run($root,$app->load())['ok'], 'repaired JS still rejected');
    chmod($app->file(),0644);
    ok109(!IntegrationDiagnostics::run($root,$app->load())['ok'], 'readable private JSON accepted');
    chmod($app->file(),0600);
    ok109(IntegrationDiagnostics::run($root,$app->load())['ok'], 'repaired JSON rejected');
    echo "Lite 1.0.9 integration diagnostics/CSP/filesystem security regression OK\n";
} finally { remove109($root); }
