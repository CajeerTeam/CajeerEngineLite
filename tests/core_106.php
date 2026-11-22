<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Storage\SiteState;
use CajeerEngine\Integrations\{GA4Config,MetricaConfig,GTMConfig};
use CajeerEngine\Update\OperationFiles;

function check106(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function reject106(callable $fn): void {
    try { $fn(); } catch (InvalidArgumentException|RuntimeException) { return; }
    throw new RuntimeException('Invalid GTM settings accepted');
}
function clean106(string $path): void {
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) clean106($entry->getPathname());
    rmdir($path);
}
$root = sys_get_temp_dir().'/lite-gtm-'.bin2hex(random_bytes(8));
mkdir($root,0700);
try {
    $app = new Lite($root);
    $app->install('StrongPassword123','https://example.org','GTM test');
    $s = $app->load();
    check106(Lite::VERSION === '1.1.6' && SiteState::SCHEMA === 11 && $s['schema'] === 11,'version/schema');
    check106($s['integrations']['gtm'] === GTMConfig::DEFAULTS,'default GTM config');
    check106(!str_contains((new Lite(dirname(__DIR__)))->render($s,$s['pages']['/']),'/assets/gtm.js'),'disabled GTM loaded');
    foreach ([
        ['enabled'=>'1','container_id'=>''],
        ['enabled'=>'1','container_id'=>'G-1234567890'],
        ['enabled'=>'1','container_id'=>'GTM-<script>'],
        ['enabled'=>'1','container_id'=>[]],
        ['enabled'=>'0','container_id'=>'GTM-1234567'],
        ['enabled'=>'1','container_id'=>'GTM-1234567','contact_events'=>'0'],
        ['enabled'=>'1','container_id'=>'GTM-1234567','contact_events'=>[]],
    ] as $bad) reject106(fn()=>GTMConfig::fromPost($bad));
    $valid = GTMConfig::fromPost(['enabled'=>'1','container_id'=>'  gtm-abcd123  ','contact_events'=>'1']);
    check106($valid === ['enabled'=>true,'container_id'=>'GTM-ABCD123','contact_events'=>true],'normalization');
    $ga4 = GA4Config::fromPost(['enabled'=>'1','measurement_id'=>'G-1234567890','page_views'=>'1']);
    $metrica = MetricaConfig::fromPost(['enabled'=>'1','counter_id'=>'1234567','email_goal'=>'contact_email','phone_goal'=>'contact_phone']);
    $app->change(static function(array $s) use ($valid,$ga4,$metrica): array {
        $s['integrations']['gtm']=$valid;
        $s['integrations']['ga4']=$ga4;
        $s['integrations']['metrica']=$metrica;
        return $s;
    });
    $s=$app->load();
    $page=(new Lite(dirname(__DIR__)))->render($s,$s['pages']['/']);
    check106(str_contains($page,'src="/assets/gtm.js?v=110"') &&
        str_contains($page,'data-gtm-id="GTM-ABCD123"'),'GTM loader not rendered');
    check106(!str_contains($page,'/assets/ga4.js'),'direct GA4 loaded alongside GTM');
    check106(str_contains($page,'/assets/metrica.js'),'Metrica unexpectedly disabled');
    $s['consent']['enabled']=false;
    $page=(new Lite(dirname(__DIR__)))->render($s,$s['pages']['/']);
    check106(!str_contains($page,'/assets/gtm.js'),'disabled consent loads GTM');
    $s['consent']['enabled']=true;
    $before=file_get_contents($app->file());
    $bad=$s; $bad['integrations']['gtm']['container_id']='GTM-<img>';
    reject106(fn()=>$app->change(static fn()=> $bad));
    check106(file_get_contents($app->file())===$before,'invalid GTM state persisted');

    // Upgrade a real schema 5 site from Lite 1.0.5; preserve exact old bytes
    // and all configured integrations and content before committing schema 6.
    $old=$s;
    $old['schema']=5;
    unset($old['integrations']['gtm'], $old['integrations']['clarity'], $old['integrations']['matomo']);
    $raw=SiteState::encode($old);
    OperationFiles::write($app->file(),$raw,0600);
    check106($app->migrate(),'schema 5 migration skipped');
    $updated=$app->load();
    check106($updated['schema']===11 && $updated['integrations']['gtm']===GTMConfig::DEFAULTS,'migration defaults');
    foreach (['settings','pages','password','login','modules','consent'] as $key)
        check106($updated[$key]===$old[$key], 'migration changed '.$key);
    check106($updated['integrations']['ga4']===$old['integrations']['ga4'] &&
        $updated['integrations']['metrica']===$old['integrations']['metrica'],'existing tracking integrations lost');
    $backup=null;
    foreach ($app->backups()->names() as $name) {
        $info=$app->backups()->verify($name);
        if ($info['reason']==='migration' && $info['schema']===5) $backup=$name;
    }
    check106(is_string($backup),'schema 5 backup missing');
    check106(str_contains((string)file_get_contents($root.'/storage/backups/'.$backup),base64_encode($raw)),'source backup bytes changed');
    $app->backups()->restore($backup);
    check106($app->load()['schema']===11 && $app->load()['integrations']['ga4']===$old['integrations']['ga4'] &&
        $app->load()['integrations']['metrica']===$old['integrations']['metrica'],'backup restore lost existing integrations');
    check106(!$app->migrate(),'no-op migration modified current schema');
    echo "Lite 1.0.6 GTM core/migration regression OK\n";
} finally { clean106($root); }
