<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\AI\{AIConfig,OpenAIClient,UsageLimiter,ConversationStore,ContentGenerator,PageEditor,PageHistory,SEOAssistant,SEOAudit,AnalyticsInsights,AnalyticsInsightStore,TechnicalSupport,SupportDiagnostics,SupportStore,Acceptance};
use CajeerEngine\Analytics\ReportCache;
use CajeerEngine\Storage\SiteState;
function ok129(bool $ok,string $why):void {if (!$ok) throw new RuntimeException('AI 1.2.9 integration: '.$why);}
function denied129(callable $fn,string $why):void {
    try {$fn();} catch (InvalidArgumentException|RuntimeException) {return;}
    throw new RuntimeException('AI 1.2.9 accepted unsafe '.$why);
}
function clean129(string $path):void {
    if (is_file($path)||is_link($path)) {unlink($path);return;}
    if (is_dir($path)) {foreach(new FilesystemIterator($path) as $entry)clean129($entry->getPathname());rmdir($path);}
}
ok129(version_compare(Lite::VERSION,'1.2.9','>=') && SiteState::SCHEMA===14,'release version and storage compatibility');
$root=sys_get_temp_dir().'/lite-ai-acceptance-'.bin2hex(random_bytes(8));
mkdir($root.'/templates/default',0700,true);
copy(dirname(__DIR__).'/templates/default/page.cjr',$root.'/templates/default/page.cjr');
try {
    $app=new Lite($root);$app->install('AcceptancePassword129','https://example.test','Acceptance Demo');
    $cfg=AIConfig::DEFAULTS;$cfg['enabled']=true;$cfg['max_input_chars']=16000;
    $cfg['daily_requests']=60;$cfg['daily_tokens']=500000;
    $app->change(static function(array $state)use($cfg):array {
        $state['ai']=$cfg;
        // Older JSON can contain significant whitespace in fields that AI is not editing.
        $state['pages']['/']['body']='Исходный текст  \n  ';
        $state['pages']['/']['description']='Исходное описание  ';
        return $state;
    });
    $generation=$app->load()['identity']['generation'];
    $fields=[
        'page'=>['title'=>'Новая страница','description'=>'Описание новой страницы','body'=>'Содержимое новой страницы'],
        'title'=>['title'=>'Заголовок из OpenAI'],
        'description'=>['description'=>'Описание из OpenAI'],
        'edit_title'=>['title'=>'Заголовок после редакции'],
        'seo_metadata'=>['title'=>'SEO-заголовок','description'=>'Результат SEO-аудита'],
        'analytics_insights'=>['summary'=>'Только GA4 и поиск содержат сведения о конверсиях.','hypotheses'=>'Проверить поисковые посадочные страницы.',
            'recommendations'=>'Изучить точные отчёты GA4.','limitations'=>'Нет сопоставимых предыдущих периодов.'],
        'technical_support'=>['summary'=>'Проверены локальные статусы.','causes'=>'Причина не определена.',
            'steps'=>'Проверить локальную диагностику.','cautions'=>'Не публиковать секреты.'],
    ];
    $calls=0;$driver=static function(string $url,string $method,array $headers,string $body)use(&$calls,$fields):array {
        $calls++;
        ok129($url==='https://api.openai.com/v1/responses' && $method==='POST','fixed HTTPS endpoint');
        $payload=json_decode($body,true,64,JSON_THROW_ON_ERROR);
        ok129(($payload['store']??null)===false && !isset($payload['tools']) &&
            $payload['model']==='gpt-5-mini','provider request boundaries');
        ok129(!str_contains($body,'sk-secretUserToken123') && !str_contains($body,'visitor@example.org'),
            'secrets and contact info redacted');
        $mode=str_replace('cajeer_','',(string)($payload['text']['format']['name']??''));
        $text=$mode===''?'Ответ диалога':json_encode($fields[$mode]??throw new RuntimeException('Unexpected AI mode '.$mode),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $completed=['status'=>'completed','model'=>'gpt-5-mini','output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>$text]]]],
            'usage'=>['input_tokens'=>100,'output_tokens'=>40]];
        if (($payload['stream']??false)===true) {
            return [200,"data: ".json_encode(['type'=>'response.output_text.delta','delta'=>'Ответ '],JSON_UNESCAPED_UNICODE)."\n\n".
                "data: ".json_encode(['type'=>'response.output_text.delta','delta'=>'диалога'],JSON_UNESCAPED_UNICODE)."\n\n".
                "data: ".json_encode(['type'=>'response.completed','response'=>$completed],JSON_UNESCAPED_UNICODE)."\n\n".
                "data: [DONE]\n\n"];
        }
        return [200,json_encode($completed,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)];
    };
    $client=new OpenAIClient($driver,'sk-AcceptanceTestKey123456789');$quota=new UsageLimiter($root);
    $history=new PageHistory($root,$generation);
    $pageInput=['mode'=>'page','topic'=>'Проверенная страница','path'=>'/generated','style'=>'neutral','length'=>'normal',
        'audience'=>'Посетители','keywords'=>''];
    $draft=ContentGenerator::generate($pageInput,$app->load(),$client,$quota);
    ok129(!isset($app->load()['pages']['/generated']),'AI generation does not publish before confirmation');
    denied129(fn()=>ContentGenerator::apply($app,$draft,['path'=>'/generated']),'page without content');
    ContentGenerator::apply($app,$draft,['path'=>'/generated','title'=>$draft['title'],
        'description'=>$draft['description'],'body'=>$draft['body']]);
    ok129($app->load()['pages']['/generated']['published']===false,'approved page saved as unpublished draft');
    $baseline=$app->load()['pages']['/'];
    foreach (['title'=>'Правка заголовка','description'=>'Правка описания'] as $mode=>$topic) {
        $request=['mode'=>$mode,'topic'=>$topic,'target'=>'/','style'=>'neutral','length'=>'normal'];
        $preview=ContentGenerator::generate($request,$app->load(),$client,$quota);
        ContentGenerator::apply($app,$preview,[$mode=>$preview[$mode]]);
        $current=$app->load()['pages']['/'];
        ok129($current['body']===$baseline['body'],'metadata-only generation preserves original body bytes');
        if ($mode==='title') ok129($current['description']===$baseline['description'],'title-only generation preserves description bytes');
    }
    $request=['path'=>'/','scope'=>'title','operation'=>'proofread','instruction'=>'Проверить заголовок'];
    $preview=PageEditor::prepare($request,$app->load(),$client,$quota);
    ok129($preview['suggested']['body']===$baseline['body'] &&
        $preview['suggested']['description']===$app->load()['pages']['/']['description'],
        'editor preview preserves nonselected fields byte-for-byte');
    denied129(fn()=>PageEditor::apply($app,$history,$preview,['title'=>'Без подтверждения']),'unapproved page edit');
    PageEditor::apply($app,$history,$preview,['title'=>'Редакторский заголовок','confirm'=>'1']);
    ok129($app->load()['pages']['/']['body']===$baseline['body'],'applied title edit preserves body');
    $after=$app->load()['pages']['/'];
    denied129(fn()=>PageEditor::apply($app,$history,$preview,['title'=>'Повторно','confirm'=>'1']),'stale edit replay');
    $prior=$history->all('/');ok129(count($prior)===1,'real private pre-edit snapshot');
    $history->restore($app,'/',$prior[0]['id'],PageEditor::revision($after));
    ok129($app->load()['pages']['/']['title']===$preview['original']['title'],'page rollback restores original title');
    ok129($app->load()['pages']['/']['body']===$baseline['body'],'rollback preserves body');
    $audit=SEOAudit::run($app->load());ok129(isset($audit['pages']['/']),'SEO audit on same installation');
    $seo=SEOAssistant::prepare(['path'=>'/','scope'=>'metadata'],$app->load(),$client,$quota);
    SEOAssistant::apply($app,$history,$seo,['title'=>'SEO готов','description'=>'Нормальное описание','confirm'=>'1']);
    ok129($app->load()['pages']['/']['body']===$baseline['body'],'SEO metadata edit preserves body');
    $chats=new ConversationStore($root,$generation);$chat=$chats->create();
    $pending=$chats->begin($chat['id'],'Ответить читателю visitor@example.org и не раскрывать sk-secretUserToken123',$cfg['max_input_chars']);
    $deltas=[];
    $reply=$quota->chat($client,$cfg,$pending['messages'],true,static function(string $delta)use(&$deltas):void{$deltas[]=$delta;});
    ok129(implode('',$deltas)==='Ответ диалога' && $reply['text']==='Ответ диалога','actual SSE parser and streamed deltas');
    $chats->complete($chat['id'],$pending['token'],$reply['text']);
    ok129(count($chats->get($chat['id'])['messages'])===2,'durable multi-turn chat');
    ok129((new ConversationStore($root,str_repeat('a',48)))->all()===[],'owner-revision chat isolation');
    $to=gmdate('Y-m-d',time()-86400);
    $from=(new DateTimeImmutable($to))->modify('-6 days')->format('Y-m-d');
    $report=['from'=>$from,'to'=>$to,'days'=>7,'generated_at'=>gmdate('c'),'providers'=>[
        'ga4'=>['status'=>'ok','summary'=>['sessions'=>150,'conversions'=>4,'personal'=>'visitor@example.org']],
        'gsc'=>['status'=>'ok','summary'=>['clicks'=>20,'impressions'=>500,'ctr_percent'=>4.0]],
        'matomo'=>['status'=>'unconfigured','message'=>'sk-secretUserToken123']]];
    $cache=new ReportCache($root);$cache->save($app->load(),7,$report);
    $savedReport=$cache->read($app->load(),7);
    ok129(is_array($savedReport),'real private reporting cache');
    $insight=AnalyticsInsights::generate($savedReport,$app->load(),$client,$quota);
    $insights=new AnalyticsInsightStore($root,$generation);$insights->save($savedReport,$insight);
    ok129($insights->read($savedReport)['fields']===$insight['fields'],'persisted source-bound analytics insights');
    $snapshot=SupportDiagnostics::collect($root,$app->load());
    $support=TechnicalSupport::generate('ai',$snapshot,$app->load(),$client,$quota);
    $supportStore=new SupportStore($root,$generation);$supportStore->save('ai',$snapshot,$support);
    ok129($supportStore->read('ai',$snapshot)['fields']===$support['fields'],'real local diagnostic recommendations');
    $usage=$quota->status();
    ok129($usage['requests']===$calls && $usage['tokens']===140*$calls && $usage['active_requests']===0,
        'all six AI features share quota accounting, zero unfinished requests');
    $acceptance=Acceptance::run($root,$app->load());
    ok129(!$acceptance['automated_ok'] && !$acceptance['production_accepted'] && count($acceptance['manual'])>0,
        'release check never fabricates production acceptance without real key');
    ok129($app->load()['schema']===14 && $app->load()['pages']['/']['published']===true,'site stays valid');
    echo "Lite 1.2.9 integrated page generation, metadata preservation, editor/history/undo, SEO, SSE chat, cached analytics, local support, shared quota and release-gate OK\n";
} finally {clean129($root);}
