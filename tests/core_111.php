<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Storage\SiteState;
use CajeerEngine\Analytics\{ReportConfig,HttpClient,ReportService,ReportCache,GoogleAuth,DashboardView,ClarityQuota};
use CajeerEngine\Integrations\{GA4Config,MetricaConfig,GTMConfig,MatomoConfig,ClarityConfig};

function check111(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function remove111(string $path): void {
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $e) if (!$e->isDot()) remove111($e->getPathname());
    rmdir($path);
}
$root=sys_get_temp_dir().'/lite-analytics-'.bin2hex(random_bytes(6));
try {
    mkdir($root,0700,true);
    $app=new Lite($root);
    $app->install('ExtremelyStrongPassword123','https://example.org','Test analytics');
    $state=$app->load();
    check111(Lite::VERSION==='1.1.1' && $state['schema']===9 && SiteState::SCHEMA===9,'1.1.1 schema');
    check111($state['reporting']===ReportConfig::DEFAULTS,'reporting defaults');
    // A real 1.1.0 state migrates under lock; a verified snapshot of schema 8 remains.
    $legacy=$state;
    unset($legacy['reporting']); $legacy['schema']=8;
    $app->change(static function(array $s): array { return $s; });
    file_put_contents($app->file(),SiteState::encode($legacy));
    $upgraded=$app->load();
    check111($upgraded['schema']===9,'8 -> 9 migration');
    $backups=$app->backups()->names();
    check111(count($backups)>0,'automatic backup');
    $backup=$app->backups()->verify($backups[0]);
    check111($backup['schema']===8,'migration backup must preserve schema 8');
    $before=$app->load();
    foreach ([
        ['ga4_property_id'=>'not-a-number'], ['gsc_site_url'=>'http://internal/'],
        ['gsc_site_url'=>'sc-domain:<script>'], ['yandex_webmaster_host_id'=>'site/../../private'],
    ] as $invalid) {
        $candidate=array_replace(ReportConfig::DEFAULTS,$invalid);
        try { ReportConfig::validate($candidate); throw new RuntimeException('unsafe config accepted'); }
        catch (RuntimeException $ex) { if ($ex->getMessage()==='unsafe config accepted') throw $ex; }
    }
    check111($before['reporting']===ReportConfig::DEFAULTS,'failed config must not change JSON');
    $cfg=ReportConfig::fromPost([
        'ga4_property_id'=>'123456', 'metrica_goal_id'=>'77777','gsc_site_url'=>'sc-domain:example.org',
        'yandex_webmaster_user_id'=>'54321', 'yandex_webmaster_host_id'=>'https:example.org:443',
    ]);
    $app->change(static function(array $s)use($cfg): array {
        $s['reporting']=$cfg;
        $s['integrations']['ga4']=GA4Config::fromPost(['enabled'=>'1','measurement_id'=>'G-12345678']);
        $s['integrations']['metrica']=MetricaConfig::fromPost(['enabled'=>'1','counter_id'=>'87654321','email_goal'=>'','phone_goal'=>'']);
        $s['integrations']['matomo']=MatomoConfig::fromPost(['enabled'=>'1','mode'=>'onpremise','server_url'=>'https://stats.example.org/','site_id'=>'8']);
        $s['integrations']['clarity']=ClarityConfig::fromPost(['enabled'=>'1','project_id'=>'abc123','recordings'=>'1']);
        $s['integrations']['gtm']=GTMConfig::fromPost(['enabled'=>'1','container_id'=>'GTM-ABCDE123']);
        return $s;
    });
    putenv('CAJEER_REPORT_GOOGLE_TOKEN=some-google-access-token');
    putenv('CAJEER_REPORT_YANDEX_TOKEN=some-yandex-access-token');
    putenv('CAJEER_REPORT_MATOMO_TOKEN=some-matomo-api-token');
    putenv('CAJEER_REPORT_CLARITY_TOKEN=some-clarity-api-token');
    $calls=[];
    $mock=static function(string $url,string $method,array $headers,string $body)use(&$calls): array {
        $calls[]=[$url,$method,$headers,$body];
        if (str_contains($url,':runReport')) {
            if (str_contains($body,'eventName')) return [200,json_encode(['rows'=>[
                ['dimensionValues'=>[['value'=>'sign_up']],'metricValues'=>[['value'=>'13'],['value'=>'5']]],
                ['dimensionValues'=>[['value'=>'page_view']],'metricValues'=>[['value'=>'200'],['value'=>'0']]],
            ]])];
            return [200,json_encode(['rows'=>[['metricValues'=>[['value'=>'80'],['value'=>'60'],['value'=>'200'],['value'=>'5']]]]])];
        }
        if (str_contains($url,'api-metrika.yandex.net')) return [200,json_encode(['totals'=>[120,100,220,9,7.5],'sampled'=>false])];
        if (str_contains($url,'stats.example.org')) {
            if (str_contains($body,'VisitsSummary.get')) return [200,json_encode([
                'nb_visits'=>92,'nb_uniq_visitors'=>70,'nb_actions'=>180,'nb_visits_converted'=>8])];
            return [200,json_encode(['nb_conversions'=>11,'conversion_rate'=>'8.2%','goal_3_nb_conversions'=>4])];
        }
        if (str_contains($url,'clarity.ms')) return [200,json_encode([['metricName'=>'Traffic',
            'information'=>[['totalSessionCount'=>'55'],['totalSessionCount'=>'12']]]])];
        if (str_contains($url,'webmasters/v3')) return [200,json_encode(['rows'=>[
            ['clicks'=>10,'impressions'=>100,'position'=>4],['clicks'=>8,'impressions'=>100,'position'=>6]]])];
        if (str_contains($url,'webmaster.yandex.net')) return [200,json_encode(['indicators'=>[
            'TOTAL_SHOWS'=>[['date'=>'2026-01-01','value'=>900]],'TOTAL_CLICKS'=>[['date'=>'2026-01-01','value'=>90]]]])];
        return [404,'{}'];
    };
    $reports=(new ReportService(new HttpClient($mock),$root))->collect($app->load(),7);
    foreach (['ga4','metrica','matomo','clarity','gsc','yandex_webmaster'] as $provider) {
        check111($reports['providers'][$provider]['status']==='ok',$provider.' not parsed: '.json_encode($reports['providers'][$provider]));
    }
    check111($reports['providers']['ga4']['summary']['conversions']===5.,'GA4 key events');
    check111($reports['providers']['ga4']['goals'][0]['name']==='sign_up','GA4 event names');
    check111($reports['providers']['metrica']['summary']['goal_reaches']===9.,'Metrica goal metric');
    check111($reports['providers']['metrica']['summary']['goal_conversion_rate_percent']===7.5,'Metrica conversion rate');
    check111($reports['providers']['matomo']['summary']['conversion_rate_percent']===8.2,'Matomo conversion rate');
    check111($reports['providers']['matomo']['summary']['conversions']===11.,'Matomo conversions');
    check111($reports['providers']['matomo']['goals'][0]['conversions']===4.,'Matomo per-goal conversions');
    check111($reports['providers']['clarity']['summary']['sessions']===67.,'Clarity real sessions');
    check111($reports['providers']['gsc']['summary']['avg_position']===5.,'GSC weighted position');
    check111($reports['providers']['yandex_webmaster']['summary']['ctr_percent']===10.,'Yandex Webmaster CTR');
    check111(count($calls)===8,'provider endpoint count');
    foreach ($calls as $call) {
        check111(str_starts_with($call[0],'https://'),'non-TLS endpoint');
        check111(!str_contains($call[0],'some-matomo-api-token'),'Matomo credentials leaked in URL');
    }
    $cache=new ReportCache($root);
    $cache->save($app->load(),7,$reports);
    $read=$cache->read($app->load(),7);
    check111($read!==null && $read['providers']['ga4']['summary']['sessions']==80.,'cache read');
    check111($read['stale']===false,'fresh cache marked stale');
    $files=glob($root.'/storage/analytics-cache/*.json');
    check111(count($files)===2,'cache + Clarity quota should be private files');
    foreach ($files as $file) check111((fileperms($file)&0077)===0,'private cache permissions');
    $render=DashboardView::render($app->load(),$read,str_repeat('a',64),7);
    check111(str_contains($render,'Analytics Dashboard 1.1.1') && str_contains($render,'sign_up'), 'dashboard HTML');
    check111(!str_contains($render,'some-google-access-token'), 'token leaked to dashboard');
    $malicious=new HttpClient(static fn()=>[200,'{}']);
    try { $malicious->json('https://127.0.0.1/internal'); throw new RuntimeException('SSRF permitted'); }
    catch (RuntimeException $e) { if ($e->getMessage()==='SSRF permitted') throw $e; }
    try { $malicious->json('http://www.googleapis.com/'); throw new RuntimeException('plain HTTP permitted'); }
    catch (RuntimeException $e) { if ($e->getMessage()==='plain HTTP permitted') throw $e; }
    // Isolated external API failure never hides other providers' results.
    $broken=new HttpClient(static function($url)use($mock) { if (str_contains($url,'metrika.yandex.net')) return [401,'{"error":"secret"}']; return $mock($url,'GET',[],''); });
    $partial=(new ReportService($broken,$root))->collect($app->load(),7);
    check111($partial['providers']['metrica']['status']==='error','provider error not visible');
    check111($partial['providers']['gsc']['status']==='ok','other provider unavailable due to one failure');
    check111(!str_contains(json_encode($partial),'some-yandex-access-token'),'token exposed in error');
    // Exercise the production OAuth 2.0 JWT service account flow, not just a static token.
    putenv('CAJEER_REPORT_GOOGLE_TOKEN');
    $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
    if ($key===false || !openssl_pkey_export($key,$privateKey)) throw new RuntimeException('RSA test key');
    $public=openssl_pkey_get_details($key)['key'];
    $keyFile=$root.'/service-account.json';
    file_put_contents($keyFile,json_encode(['type'=>'service_account',
        'client_email'=>'reports@example.org','private_key'=>$privateKey],JSON_THROW_ON_ERROR));
    chmod($keyFile,0600);
    putenv('CAJEER_GOOGLE_SERVICE_ACCOUNT_FILE='.$keyFile);
    $oauthCalls=0;
    $oauth=new GoogleAuth(new HttpClient(static function($url,$method,$headers,$body)use(&$oauthCalls,$public) {
        check111($url==='https://oauth2.googleapis.com/token','unexpected OAuth endpoint');
        check111($method==='POST' && in_array('Content-Type: application/x-www-form-urlencoded',$headers,true),'bad OAuth grant');
        parse_str($body,$form);
        check111(($form['grant_type']??'')==='urn:ietf:params:oauth:grant-type:jwt-bearer','OAuth grant type');
        $parts=explode('.',$form['assertion']??'');
        check111(count($parts)===3,'JWT structure');
        $payload=json_decode(base64_decode(strtr($parts[1],'-_','+/')),true,512,JSON_THROW_ON_ERROR);
        check111(str_contains($payload['scope'],'analytics.readonly') && str_contains($payload['scope'],'webmasters.readonly'),'OAuth scopes');
        check111(openssl_verify($parts[0].'.'.$parts[1],base64_decode(strtr($parts[2],'-_','+/')),$public,OPENSSL_ALGO_SHA256)===1,'JWT signature');
        $oauthCalls++;
        return [200,'{"access_token":"issued-google-access-token"}'];
    }),$root);
    check111($oauth->token()==='issued-google-access-token' && $oauth->token()==='issued-google-access-token' && $oauthCalls===1,
        'OAuth grant or in-request caching');
    chmod($keyFile,0644);
    try { (new GoogleAuth(new HttpClient(),$root))->token(); throw new RuntimeException('insecure service file accepted'); }
    catch (RuntimeException $e) { if ($e->getMessage()==='insecure service file accepted') throw $e; }
    chmod($keyFile,0600);
    putenv('CAJEER_GOOGLE_SERVICE_ACCOUNT_FILE');
    // Clarity's published 10-requests/day limit is protected with a durable
    // per-project budget (8 Lite requests/day, leaving two for other clients).
    $quotaProject='quotaproject111';
    for ($i=0;$i<8;$i++) ClarityQuota::charge($root,$quotaProject);
    try { ClarityQuota::charge($root,$quotaProject); throw new RuntimeException('quota not enforced'); }
    catch (RuntimeException $e) { if ($e->getMessage()==='quota not enforced') throw $e; }
    putenv('CAJEER_REPORT_GOOGLE_TOKEN');
    putenv('CAJEER_GOOGLE_SERVICE_ACCOUNT_FILE');
    $offline=(new ReportService(new HttpClient($mock),$root))->collect($app->load(),7);
    check111($offline['providers']['ga4']['status']==='unconfigured','GA4 missing auth misreported');
    check111($offline['providers']['gsc']['status']==='unconfigured','GSC missing auth misreported');
    echo "Lite 1.1.1 reporting migration, API contracts, admin view and secrets/cache regression OK\n";
} finally {
    foreach (['CAJEER_REPORT_GOOGLE_TOKEN','CAJEER_REPORT_YANDEX_TOKEN','CAJEER_REPORT_MATOMO_TOKEN','CAJEER_REPORT_CLARITY_TOKEN'] as $env) putenv($env);
    remove111($root);
}
