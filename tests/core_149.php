<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Analytics\{HttpClient,ReportService};
use CajeerEngine\Release\ReleaseCandidate;

function check149(bool $condition,string $message):void { if (!$condition) throw new RuntimeException($message); }
function rejects149(callable $operation):void {
    try { $operation(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('Unsafe provider selection accepted');
}
function cleanup149(string $p):void {
    if (is_file($p)||is_link($p)) { unlink($p);return; }
    if (!is_dir($p)) return;
    foreach (new DirectoryIterator($p) as $entry) if (!$entry->isDot()) cleanup149($entry->getPathname());
    rmdir($p);
}
$root=sys_get_temp_dir().'/cajeer-149-'.bin2hex(random_bytes(8));mkdir($root,0700);
$old=getenv('CAJEER_REPORT_YANDEX_TOKEN');$confirm=getenv('CAJEER_RC_STAGING_CONFIRM');$paid=getenv('CAJEER_AI_PAID_PROBE_CONFIRM');
try {
    putenv('CAJEER_RC_STAGING_CONFIRM');putenv('CAJEER_AI_PAID_PROBE_CONFIRM');
    $app=new Lite($root);$app->install('RCTest149VeryStrongPassword!','https://rc.example.org','RC');
    $site=$app->load();
    rejects149(fn()=>ReleaseCandidate::run($root,$site,false,['oidc']));
    rejects149(fn()=>ReleaseCandidate::run($root,$site,true,['oidc','oidc']));
    rejects149(fn()=>ReleaseCandidate::run($root,$site,true,['unknown']));
    rejects149(fn()=>ReleaseCandidate::run($root,$site,true,[]));
    putenv('CAJEER_RC_STAGING_CONFIRM=STAGING');
    rejects149(fn()=>ReleaseCandidate::run($root,$site,true,['openai'],true));
    rejects149(fn()=>ReleaseCandidate::run($root,$site,true,['oidc'],true));
    // Selected reporting probe performs real transport dispatch only for the selected provider.
    // The injected driver is a deterministic regression, not evidence of live provider access.
    putenv('CAJEER_REPORT_YANDEX_TOKEN=SafeSyntheticToken149');
    $site['integrations']['metrica']['counter_id']='12345678';$calls=0;
    $service=new ReportService(new HttpClient(static function($url,$method,$headers,$body)use(&$calls):array {
        ++$calls;
        check149(str_starts_with($url,'https://api-metrika.yandex.net/stat/v1/data?')&&$method==='GET','Unexpected provider request');
        parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        check149((new DateTimeImmutable($query['date1']))->diff(new DateTimeImmutable($query['date2']))->days===6,'Wrong probe window');
        return [200,'{"totals":[7,6,10]}'];
    }),$root);
    $result=$service->probe($site,'metrica');
    check149($result['status']==='ok'&&$result['summary']['visits']===7.0&&$calls===1,'Single-provider report failed');
    check149($service->probe($site,'ga4')['status']==='unconfigured'&&$calls===1,'Unconfigured provider contacted');
    rejects149(fn()=>$service->probe($site,'gtm'));
    check149($calls===1,'Unexpected provider traffic');
    echo "RC 1.4.9: provider selection, opt-in, paid consent, isolated report transport OK\n";
} finally {
    foreach (['CAJEER_REPORT_YANDEX_TOKEN'=>$old,'CAJEER_RC_STAGING_CONFIRM'=>$confirm,'CAJEER_AI_PAID_PROBE_CONFIRM'=>$paid] as $key=>$value) putenv($value===false?$key:$key.'='.$value);
    cleanup149($root);
}
