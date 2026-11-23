<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Storage\SiteState;
use CajeerEngine\Integrations\{GA4Config,MetricaConfig};
use CajeerEngine\Update\OperationFiles;

function test105(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function reject105(callable $cb): void {
    try { $cb(); } catch (InvalidArgumentException|RuntimeException) { return; }
    throw new RuntimeException('An invalid Metrica setting was accepted');
}
function cleanup105(string $path): void {
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) cleanup105($entry->getPathname());
    rmdir($path);
}
$root=sys_get_temp_dir().'/lite-metrica-'.bin2hex(random_bytes(8)); mkdir($root,0700);
try {
    $app=new Lite($root);
    $app->install('StrongPassword123','https://example.org','Metrica test');
    $s=$app->load();
    test105(version_compare(Lite::VERSION,'1.2.2','>=') && SiteState::SCHEMA===14 && $s['schema']===14,'version/schema');
    test105($s['integrations']['ga4']===GA4Config::DEFAULTS && $s['integrations']['metrica']===MetricaConfig::DEFAULTS,'new install defaults');
    test105(!str_contains((new Lite(dirname(__DIR__)))->render($s,$s['pages']['/']), '/assets/metrica.js'),'disabled embed rendered');
    foreach ([
      ['enabled'=>'1','counter_id'=>''],
      ['enabled'=>'1','counter_id'=>'0'],
      ['enabled'=>'1','counter_id'=>'99<script>'],
      ['enabled'=>'1','counter_id'=>[]],
      ['enabled'=>'0','counter_id'=>'1234'],
      ['enabled'=>'1','counter_id'=>'1234','webvisor'=>[]],
      ['enabled'=>'1','counter_id'=>'1234','contact_goals'=>'1','email_goal'=>'','phone_goal'=>'contact_phone'],
      ['enabled'=>'1','counter_id'=>'1234','contact_goals'=>'1','email_goal'=>'foo"','phone_goal'=>'contact_phone'],
    ] as $bad) {
       $data = array_replace(['email_goal'=>'contact_email','phone_goal'=>'contact_phone'], $bad);
       reject105(fn()=>MetricaConfig::fromPost($data));
    }
    $values=['enabled'=>'1','counter_id'=>' 12345678 ', 'page_views'=>'1','accurate_bounce'=>'1',
       'clickmap'=>'1','webvisor'=>'1','contact_goals'=>'1','email_goal'=>' email_goal ','phone_goal'=>'phone_goal'];
    $m=MetricaConfig::fromPost($values);
    test105($m['counter_id']==='12345678' && $m['email_goal']==='email_goal' && $m['enabled'] && $m['webvisor'], 'canonicalized inputs');
    $app->change(static function(array $s)use($m): array {$s['integrations']['metrica']=$m; return $s;});
    $s=$app->load();
    $view=(new Lite(dirname(__DIR__)))->render($s,$s['pages']['/']);
    test105(str_contains($view,'src="/assets/metrica.js?v=110"') && str_contains($view,'data-metrica-id="12345678"'), 'runtime missing');
    $s['consent']['enabled']=false;
    test105(!str_contains((new Lite(dirname(__DIR__)))->render($s,$s['pages']['/']),'/assets/metrica.js'),'disabled consent failed closed');
    $s['consent']['enabled']=true;
    $before=file_get_contents($app->file());
    $bad=$s; $bad['integrations']['metrica']['counter_id']='<script>';
    reject105(fn()=> $app->change(static fn()=> $bad));
    test105(file_get_contents($app->file())===$before,'invalid Metrica value persisted');

    // Real 1.0.4 schema 4 backup, including configured GA4, pages and consent.
    $old=$s;
    $old['schema']=4;
    unset($old['integrations']['metrica'], $old['integrations']['gtm'], $old['integrations']['clarity'], $old['integrations']['matomo']);
    $old['integrations']['ga4']=GA4Config::fromPost([
       'enabled'=>'1','measurement_id'=>'G-1234567890','page_views'=>'1','contact_clicks'=>'1']);
    $raw=SiteState::encode($old);
    OperationFiles::write($app->file(),$raw,0600);
    test105($app->migrate(),'schema 4 migration not executed');
    $updated=$app->load();
    test105($updated['schema']===14 && $updated['integrations']['metrica']===MetricaConfig::DEFAULTS,'new Metrica defaults');
    foreach (['settings','pages','password','login','modules','consent'] as $key) test105($updated[$key]===$old[$key],'migration modified '.$key);
    test105($updated['integrations']['ga4']===$old['integrations']['ga4'],'GA4 settings lost');
    $backup=null;
    foreach ($app->backups()->names() as $file) {
       $info=$app->backups()->verify($file);
       if ($info['reason']==='migration' && $info['schema']===4) $backup=$file;
    }
    test105(is_string($backup),'migration backup absent');
    test105(str_contains((string)file_get_contents($root.'/storage/backups/'.$backup),base64_encode($raw)),'original bytes not preserved');
    $app->backups()->restore($backup);
    test105($app->load()['schema']===14 && $app->load()['integrations']['ga4']===$old['integrations']['ga4'],'restoring 1.0.4 failed');
    test105(!$app->migrate(),'no-op migration was not a no-op');
    echo "Lite 1.0.5 Metrica core/migration regression OK\n";
} finally { cleanup105($root); }
