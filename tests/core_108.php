<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Storage\SiteState;
use CajeerEngine\Integrations\{MatomoConfig,ClarityConfig,GA4Config,GTMConfig,MetricaConfig};
use CajeerEngine\Update\OperationFiles;

function check108(bool $ok, string $msg): void { if (!$ok) throw new RuntimeException($msg); }
function reject108(callable $fn): void {
    try { $fn(); } catch (InvalidArgumentException|RuntimeException) { return; }
    throw new RuntimeException('Invalid Matomo config accepted');
}
function clean108(string $p): void {
    if (is_link($p) || is_file($p)) { unlink($p); return; }
    if (!is_dir($p)) return;
    foreach (new DirectoryIterator($p) as $item) if (!$item->isDot()) clean108($item->getPathname());
    rmdir($p);
}
$root = sys_get_temp_dir().'/lite-matomo-'.bin2hex(random_bytes(8));
mkdir($root,0700);
try {
    $app = new Lite($root);
    $app->install('VeryStrongPassword123', 'https://example.org', 'Matomo 1.0.8');
    $state = $app->load();
    check108(Lite::VERSION === '1.0.9' && SiteState::SCHEMA === 8 && $state['schema'] === 8, 'version/schema');
    check108($state['integrations']['matomo'] === MatomoConfig::DEFAULTS, 'missing defaults');
    check108(!str_contains((new Lite(dirname(__DIR__)))->render($state, $state['pages']['/']), '/assets/matomo.js'), 'disabled Matomo published');
    foreach ([
        ['enabled'=>'1','mode'=>'cloud','server_url'=>'http://site.matomo.cloud/','site_id'=>'1'],
        ['enabled'=>'1','mode'=>'cloud','server_url'=>'https://evil.example/','site_id'=>'1'],
        ['enabled'=>'1','mode'=>'cloud','server_url'=>'https://site.matomo.cloud/hi/','site_id'=>'1'],
        ['enabled'=>'1','mode'=>'cloud','server_url'=>'https://site.matomo.cloud/?bad=1','site_id'=>'1'],
        ['enabled'=>'1','mode'=>'onpremise','server_url'=>'https://user:secret@example.org/','site_id'=>'1'],
        ['enabled'=>'1','mode'=>'onpremise','server_url'=>'https://example.org/%2e%2e/','site_id'=>'1'],
        ['enabled'=>'1','mode'=>'onpremise','server_url'=>'https://example.org/../','site_id'=>'1'],
        ['enabled'=>'1','mode'=>'onpremise','server_url'=>'https://example.org\\evil.test/','site_id'=>'1'],
        ['enabled'=>'1','mode'=>'onpremise','server_url'=>'https://example.org/#fragment','site_id'=>'1'],
        ['enabled'=>'1','mode'=>'onpremise','server_url'=>'https://example.org/','site_id'=>'0'],
        ['enabled'=>'1','mode'=>'onpremise','server_url'=>'https://example.org/','site_id'=>[]],
        ['enabled'=>'1','mode'=>'onpremise','server_url'=>'','site_id'=>'1'],
        ['enabled'=>'0','mode'=>'onpremise','server_url'=>'https://example.org/','site_id'=>'1'],
        ['mode'=>'onpremise','server_url'=>'https://example.org/','site_id'=>'1','page_views'=>'0'],
        ['enabled'=>'1','mode'=>'auto','server_url'=>'https://example.org/','site_id'=>'1'],
    ] as $bad) reject108(fn()=>MatomoConfig::fromPost($bad));
    $cloud=MatomoConfig::fromPost(['enabled'=>'1','mode'=>'cloud','server_url'=>' https://SITE.matomo.cloud ','site_id'=>' 12 ','page_views'=>'1','contact_events'=>'1']);
    check108($cloud['server_url']==='https://site.matomo.cloud/' && $cloud['site_id']==='12', 'normalization');
    check108(MatomoConfig::scriptUrl($cloud)==='https://cdn.matomo.cloud/site.matomo.cloud/matomo.js', 'Cloud CDN');
    check108(MatomoConfig::trackerUrl($cloud)==='https://site.matomo.cloud/matomo.php', 'Cloud tracker URL');
    check108(MatomoConfig::canonicalUrl('https://[::1]/matomo/','onpremise')==='https://[::1]/matomo/', 'IPv6 On-Premise');
    $onprem=MatomoConfig::fromPost(['enabled'=>'1','mode'=>'onpremise','server_url'=>' https://stats.example.org:8443/matomo ','site_id'=>'5','page_views'=>'1']);
    check108(MatomoConfig::scriptUrl($onprem)==='https://stats.example.org:8443/matomo/matomo.js', 'Self-hosted script URL');
    check108(MatomoConfig::trackerUrl($onprem)==='https://stats.example.org:8443/matomo/matomo.php', 'Self-hosted endpoint');
    $app->change(static function(array $s) use($cloud): array {
        $s['integrations']['matomo']=$cloud;
        $s['integrations']['clarity']=ClarityConfig::fromPost(['enabled'=>'1','project_id'=>'abc123','recordings'=>'1']);
        $s['integrations']['ga4']=GA4Config::fromPost(['enabled'=>'1','measurement_id'=>'G-1234567890','page_views'=>'1']);
        $s['integrations']['gtm']=GTMConfig::fromPost(['enabled'=>'1','container_id'=>'GTM-ABCD123']);
        $s['integrations']['metrica']=MetricaConfig::fromPost(['enabled'=>'1','counter_id'=>'123456','email_goal'=>'contact_email','phone_goal'=>'contact_phone']);
        return $s;
    });
    $s=$app->load();
    $page=(new Lite(dirname(__DIR__)))->render($s,$s['pages']['/']);
    check108(str_contains($page, '/assets/matomo.js?v=108') && str_contains($page, 'data-matomo-site="12"') &&
        str_contains($page, 'data-matomo-mode="cloud"'), 'tag not rendered');
    check108(str_contains($page,'/assets/clarity.js') && str_contains($page,'/assets/gtm.js') &&
        str_contains($page,'/assets/metrica.js') && !str_contains($page,'/assets/ga4.js'), 'existing integrations changed');
    $s['consent']['enabled']=false;
    check108(!str_contains((new Lite(dirname(__DIR__)))->render($s,$s['pages']['/']),'/assets/matomo.js'),'disabled consent did not fail closed');
    $s['consent']['enabled']=true;
    $before=file_get_contents($app->file());
    $bad=$s; $bad['integrations']['matomo']['server_url']='https://evil.example/<script>';
    reject108(fn()=> $app->change(static fn()=> $bad));
    check108(file_get_contents($app->file())===$before,'invalid state persisted');

    // Exact real 1.0.7 JSON snapshot, not synthetic migration-only fixture.
    $old=$s; $old['schema']=7; unset($old['integrations']['matomo']);
    $raw=SiteState::encode($old);
    OperationFiles::write($app->file(),$raw,0600);
    check108($app->migrate(),'schema 7 migration was skipped');
    $migrated=$app->load();
    check108($migrated['schema']===8 && $migrated['integrations']['matomo']===MatomoConfig::DEFAULTS,'migration defaults');
    foreach (['settings','pages','password','login','modules','consent'] as $key) {
        check108($migrated[$key]===$old[$key], 'migration changed '.$key);
    }
    foreach (['ga4','metrica','gtm','clarity'] as $key) {
        check108($migrated['integrations'][$key]===$old['integrations'][$key], 'migration changed '.$key);
    }
    $snapshot=null;
    foreach ($app->backups()->names() as $name) {
        $info=$app->backups()->verify($name);
        if ($info['reason']==='migration' && $info['schema']===7) $snapshot=$name;
    }
    check108(is_string($snapshot),'pre-migration backup not created');
    check108(str_contains(file_get_contents($root.'/storage/backups/'.$snapshot),base64_encode($raw)),'snapshot lost original bytes');
    $app->backups()->restore($snapshot);
    $restored=$app->load();
    check108($restored['schema']===8 && $restored['integrations']['matomo']===MatomoConfig::DEFAULTS,'restore failed');
    foreach (['ga4','metrica','gtm','clarity'] as $key) check108($restored['integrations'][$key]===$old['integrations'][$key],'restore changed '.$key);
    check108(!$app->migrate(),'migration not idempotent');
    echo "Lite 1.0.8 Matomo core/migration/backup regression OK\n";
} finally { clean108($root); }
