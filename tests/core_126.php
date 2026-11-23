<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\AI\{AIConfig,OpenAIClient,UsageLimiter,AnalyticsInsights,AnalyticsInsightStore,AnalyticsInsightView};
use CajeerEngine\Analytics\{ReportCache,ReportService};
use CajeerEngine\Storage\SiteState;
function check126(bool $ok,string $msg):void {if(!$ok)throw new RuntimeException('AI analytics 1.2.6: '.$msg);}
function deny126(callable $f,string $msg):void {try{$f();}catch(RuntimeException|InvalidArgumentException $e){return;}throw new RuntimeException('Accepted unsafe analytics: '.$msg);}
function clean126(string $dir):void {if(is_link($dir)||is_file($dir)){unlink($dir);return;}if(is_dir($dir)){foreach(new FilesystemIterator($dir) as $p)clean126($p->getPathname());rmdir($dir);}}
check126(version_compare(Lite::VERSION,'1.2.6','>=') && SiteState::SCHEMA===14,'release and unchanged schema');
$root=sys_get_temp_dir().'/cajeer-ai-analytics-'.bin2hex(random_bytes(7));
mkdir($root.'/templates/default',0700,true);
copy(dirname(__DIR__).'/templates/default/page.cjr',$root.'/templates/default/page.cjr');
try {
    $app=new Lite($root);$app->install('RealTestPassword-126','https://example.test','AI Demo');
    $config=AIConfig::DEFAULTS;$config['enabled']=true;$config['daily_requests']=20;$config['daily_tokens']=200000;
    $app->change(static function(array $state)use($config):array {$state['ai']=$config;return $state;});
    $site=$app->load();$to=gmdate('Y-m-d',time()-86400);
    $from=(new DateTimeImmutable($to))->modify('-6 days')->format('Y-m-d');
    $report=['from'=>$from,'to'=>$to,'days'=>7,'generated_at'=>gmdate('c'),
        'providers'=>[
            'ga4'=>['status'=>'ok','summary'=>['sessions'=>123,'users'=>111,'pageviews'=>190,'conversions'=>8,'email'=>'should never leak'],
                'goals'=>[['name'=>'private@example.test','events'=>12,'conversions'=>4]]],
            'metrica'=>['status'=>'ok','summary'=>['visits'=>135,'users'=>104,'pageviews'=>185,'goal_reaches'=>9,'goal_conversion_rate_percent'=>6.5]],
            'matomo'=>['status'=>'unconfigured','message'=>'token SECRET_CREDENTIAL_DO_NOT_SEND'],
            'clarity'=>['status'=>'ok','summary'=>['sessions'=>57], 'note'=>'unknown raw session url'],
            'gsc'=>['status'=>'ok','summary'=>['clicks'=>31,'impressions'=>510,'ctr_percent'=>6.08,'avg_position'=>8.7]],
            'yandex_webmaster'=>['status'=>'error','message'=>'private provider error SECRET_DO_NOT_SEND']]];
    $cache=new ReportCache($root);$cache->save($site,7,$report);
    $cached=$cache->read($site,7);
    check126($cached!==null && $cached['from']===$from,'real analytics cache integration');
    $facts=AnalyticsInsights::facts($cached);
    check126($facts['providers']['ga4']['metrics']['conversions']===8 &&
        !isset($facts['providers']['ga4']['metrics']['email']),'only allowlisted numbers');
    check126($facts['providers']['ga4']['goals'][0]['id']==='goal_1' &&
        !isset($facts['providers']['ga4']['goals'][0]['name']),'no goal labels or personal data');
    check126($facts['providers']['matomo']['status']==='unconfigured' &&
        !isset($facts['providers']['matomo']['message']),'no error text sent to model');
    $usage=new UsageLimiter($root);
    $called=0;
    $driver=static function(string $url,string $method,array $headers,?string $body)use(&$called):array {
        $called++;
        check126($url==='https://api.openai.com/v1/responses' && $method==='POST','real Responses API route');
        $request=json_decode((string)$body,true,32,JSON_THROW_ON_ERROR);
        check126(($request['store']??null)===false && !isset($request['tools']),'stateless model, no autonomous actions');
        check126(($request['text']['format']['name']??null)==='cajeer_analytics_insights','strict structured schema');
        check126(($request['text']['format']['schema']['required']??[])===['summary','hypotheses','recommendations','limitations'],'four output fields');
        check126(str_contains($request['input'],'"conversions":8') && str_contains($request['input'],'"ctr_percent":6.08'),'true cached conversion/search metrics');
        foreach (['private@example.test','SECRET','unknown raw session url','should never leak'] as $private) {
            check126(!str_contains($request['input'],$private),'no sensitive or untrusted context: '.$private);
        }
        $fields=['summary'=>'По данным GA4 и Метрики есть зарегистрированные конверсии, однако показатели не следует суммировать.',
            'hypotheses'=>'Возможно, страницы с поисковыми показами нуждаются в улучшении описаний.',
            'recommendations'=>'Проверить посадочные страницы и измерение событий в соответствующих системах.',
            'limitations'=>'Невозможно установить динамику без сравнимого прошлого периода; Matomo недоступен.'];
        return [200,json_encode(['status'=>'completed','model'=>'gpt-5-mini',
            'output'=>[['type'=>'message','content'=>[['type'=>'output_text',
                'text'=>json_encode($fields,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]]]],
            'usage'=>['input_tokens'=>133,'output_tokens'=>79]],JSON_THROW_ON_ERROR)];
    };
    $client=new OpenAIClient($driver,'sk-ValidApiKeyForLocalTest123');
    $result=AnalyticsInsights::generate($cached,$site,$client,$usage);
    check126($called===1 && $result['input_tokens']===133 && $result['output_tokens']===79,'actual model and usage');
    $store=new AnalyticsInsightStore($root,$site['identity']['generation']);
    $store->save($cached,$result);
    check126($store->read($cached)['fields']===$result['fields'],'durable read-after-write');
    check126($usage->status()['requests']===1 && $usage->status()['tokens']===212,'quota charged');
    $files=glob($root.'/storage/ai/analytics-insights/*.json');
    check126(count($files)===1 && (fileperms($files[0])&0077)===0,'private AI insight cache');
    $html=AnalyticsInsightView::render($cached,$store->read($cached),str_repeat('a',64),7,true);
    check126(str_contains($html,'AI Analytics Insights') && str_contains($html,'name="action" value="generate"') &&
        str_contains($html,'1.2.6')===false && str_contains($html,'123') && str_contains($html,'6,08'),'display actual source facts');
    check126(!str_contains($html,'private@example.test') && !str_contains($html,'SECRET'),'no user identifiers or error disclosure');
    $malicious=$result;$malicious['fields']['summary']='<script>alert(1)</script>';
    $store->save($cached,$malicious);
    $html=AnalyticsInsightView::render($cached,$store->read($cached),str_repeat('a',64),7,true);
    check126(!str_contains($html,'<script>') && str_contains($html,'&lt;script&gt;'),'model output HTML-escaped');
    $updated=$cached;$updated['generated_at']='2026-11-22T21:00:00+00:00';
    check126($store->read($updated)===null,'no reusing stale insights against new snapshot');
    $another=new AnalyticsInsightStore($root,str_repeat('a',64));
    check126($another->read($cached)===null,'owner isolation');
    $reportNone=$cached;$reportNone['providers']=[];
    deny126(fn()=>AnalyticsInsights::generate($reportNone,$site,$client,$usage),'no successful provider sources');
    $bad=$cached;$bad['providers']['ga4']['summary']['conversions']=-1;
    deny126(fn()=>AnalyticsInsights::facts($bad),'invalid numeric data');
    $bad=$cached;$bad['days']=30;
    deny126(fn()=>AnalyticsInsights::facts($bad),'period mismatch');
    chmod($files[0],0644);
    deny126(fn()=> $store->read($cached),'world-readable cache');
    chmod($files[0],0600);
    $store->delete($cached);check126($store->read($cached)===null,'delete persisted insights');
    echo "Lite 1.2.6 real cached provider facts, private model context, structured insights, token accounting, source display, owner isolation and security OK\n";
} finally { clean126($root); }
