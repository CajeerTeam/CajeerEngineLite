<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Storage\SiteState;
use CajeerEngine\Integrations\{ClarityConfig,GA4Config,MetricaConfig,GTMConfig};
use CajeerEngine\Update\OperationFiles;

function check107(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function reject107(callable $fn): void {
    try { $fn(); } catch (InvalidArgumentException|RuntimeException) { return; }
    throw new RuntimeException('Invalid Clarity input/state accepted');
}
function clean107(string $path): void {
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) clean107($entry->getPathname());
    rmdir($path);
}
$root=sys_get_temp_dir().'/lite-clarity-'.bin2hex(random_bytes(8));
mkdir($root,0700);
try {
    $app=new Lite($root);
    $app->install('StrongPassword123','https://example.org','Clarity test');
    $s=$app->load();
    check107(Lite::VERSION==='1.1.3' && SiteState::SCHEMA===11 && $s['schema']===11,'version/schema');
    check107($s['integrations']['clarity']===ClarityConfig::DEFAULTS,'defaults');
    check107(!str_contains((new Lite(dirname(__DIR__)))->render($s,$s['pages']['/']),'/assets/clarity.js'),'disabled tracker rendered');
    foreach ([
        ['enabled'=>'1','project_id'=>''],
        ['enabled'=>'1','project_id'=>'<script>','recordings'=>'1'],
        ['enabled'=>'1','project_id'=>'https://www.clarity.ms/tag/abc','recordings'=>'1'],
        ['enabled'=>'1','project_id'=>[],'recordings'=>'1'],
        ['enabled'=>'0','project_id'=>'abc','recordings'=>'1'],
        ['enabled'=>'1','project_id'=>'abc','recordings'=>'0'],
        ['enabled'=>'1','project_id'=>'abc','recordings'=>[]],
        ['enabled'=>'1','project_id'=>'abc','recordings'=>'1','contact_events'=>'0'],
        ['enabled'=>'1','project_id'=>str_repeat('a',41),'recordings'=>'1'],
    ] as $bad) reject107(fn()=>ClarityConfig::fromPost($bad));
    $valid=ClarityConfig::fromPost(['enabled'=>'1','project_id'=>'  AbC0123  ','recordings'=>'1','contact_events'=>'1']);
    check107($valid === ['enabled'=>true,'project_id'=>'abc0123','recordings'=>true,'contact_events'=>true],'normalization');
    $app->change(static function(array $s) use ($valid): array {
        $s['integrations']['clarity']=$valid;
        $s['integrations']['ga4']=GA4Config::fromPost(['enabled'=>'1','measurement_id'=>'G-1234567890','page_views'=>'1']);
        $s['integrations']['gtm']=GTMConfig::fromPost(['enabled'=>'1','container_id'=>'GTM-ABC123','contact_events'=>'1']);
        $s['integrations']['metrica']=MetricaConfig::fromPost(['enabled'=>'1','counter_id'=>'123456','email_goal'=>'contact_email','phone_goal'=>'contact_phone']);
        return $s;
    });
    $s=$app->load();
    $page=(new Lite(dirname(__DIR__)))->render($s,$s['pages']['/']);
    check107(str_contains($page,'src="/assets/clarity.js?v=110"') && str_contains($page,'data-clarity-id="abc0123"'),'tag missing');
    check107(str_contains($page,'data-clarity-mask="true"'),'sensitive content not masked');
    check107(str_contains($page,'/assets/gtm.js') && str_contains($page,'/assets/metrica.js') && !str_contains($page,'/assets/ga4.js'),'earlier integrations changed');
    $s['integrations']['clarity']['recordings']=false;
    check107(!str_contains((new Lite(dirname(__DIR__)))->render($s,$s['pages']['/']),'/assets/clarity.js'),'recordings flag did not disable Clarity');
    $s['integrations']['clarity']['recordings']=true;
    $s['consent']['enabled']=false;
    check107(!str_contains((new Lite(dirname(__DIR__)))->render($s,$s['pages']['/']),'/assets/clarity.js'),'disabled consent did not fail closed');
    $s['consent']['enabled']=true;
    $before=file_get_contents($app->file());
    $bad=$s; $bad['integrations']['clarity']['project_id']='<script>';
    reject107(fn()=> $app->change(static fn()=> $bad));
    check107(file_get_contents($app->file())===$before,'invalid state was written');

    // Take a real 1.0.6 schema-6 site with configured GA4, GTM and Metrica.
    $old=$s;
    $old['schema']=6;
    unset($old['integrations']['clarity'], $old['integrations']['matomo']);
    $raw=SiteState::encode($old);
    OperationFiles::write($app->file(),$raw,0600);
    check107($app->migrate(),'schema 6 upgrade skipped');
    $migrated=$app->load();
    check107($migrated['schema']===11 && $migrated['integrations']['clarity']===ClarityConfig::DEFAULTS,'migrate defaults');
    foreach (['settings','pages','password','login','modules','consent'] as $key) {
        check107($migrated[$key]===$old[$key], 'migration changed '.$key);
    }
    foreach (['ga4','gtm','metrica'] as $key) {
        check107($migrated['integrations'][$key]===$old['integrations'][$key], 'migration changed '.$key);
    }
    $snapshot=null;
    foreach($app->backups()->names() as $filename) {
        $info=$app->backups()->verify($filename);
        if($info['reason']==='migration' && $info['schema']===6) $snapshot=$filename;
    }
    check107(is_string($snapshot),'backup missing');
    check107(str_contains((string)file_get_contents($root.'/storage/backups/'.$snapshot),base64_encode($raw)),'source bytes changed');
    $app->backups()->restore($snapshot);
    $restored=$app->load();
    check107($restored['schema']===11 && $restored['integrations']['clarity']===ClarityConfig::DEFAULTS,'restore failed');
    foreach(['ga4','gtm','metrica'] as $key) check107($restored['integrations'][$key]===$old['integrations'][$key],'restore changed '.$key);
    check107(!$app->migrate(),'no-op migration changed state');
    echo "Lite 1.0.7 Clarity core/migration regression OK\n";
} finally { clean107($root); }
