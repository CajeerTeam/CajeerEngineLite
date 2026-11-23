<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use CajeerEngine\Lite;
use CajeerEngine\AI\{AIConfig,CostPolicy,OpenAIClient,ProductionAIAcceptance,UsageLimiter};
use CajeerEngine\Storage\SiteState;

function check130(bool $value,string $message):void {
    if (!$value) throw new RuntimeException('AI stable 1.3.0: '.$message);
}
function delete130(string $path):void {
    if (is_link($path)||is_file($path)) {unlink($path);return;}
    if (is_dir($path)) {foreach (new FilesystemIterator($path) as $item) delete130($item->getPathname());rmdir($path);}
}
function output130(string $text,int $input=12,int $out=8):array {
    return ['status'=>'completed','model'=>'gpt-5-mini',
        'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>$text]]]],
        'usage'=>['input_tokens'=>$input,'output_tokens'=>$out]];
}

check130(version_compare(Lite::VERSION,'1.3.0','>=') && SiteState::SCHEMA===13,'stable version and unchanged JSON schema');
$root=sys_get_temp_dir().'/lite-ai-stable130-'.bin2hex(random_bytes(8));
mkdir($root.'/templates/default',0700,true);
copy(dirname(__DIR__).'/templates/default/page.cjr',$root.'/templates/default/page.cjr');
$previousKey=getenv('CAJEER_OPENAI_API_KEY');
$previousConfirm=getenv('CAJEER_AI_PAID_PROBE_CONFIRM');
try {
    $app=new Lite($root);
    $app->install('ReleaseTestPassword130!','https://lite.example.test','Production Gate Test');
    $app->change(static function(array $site):array {
        $site['ai']['enabled']=true;
        $site['ai']['daily_requests']=20;
        $site['ai']['daily_tokens']=200000;
        return $site;
    });
    $policy=CostPolicy::DEFAULTS;
    $policy['enabled']=true;
    $policy['input_cents_per_million']=100;
    $policy['output_cents_per_million']=500;
    $policy['max_concurrent']=3;
    CostPolicy::save($root,$policy);
    $original=file_get_contents($app->file());
    putenv('CAJEER_OPENAI_API_KEY=sk-FakeProductionGateKey123456');
    putenv('CAJEER_AI_PAID_PROBE_CONFIRM');
    $generationCalls=0;$modelCalls=0;
    $driver=static function(string $url,string $method,array $headers,string $body)
        use (&$generationCalls,&$modelCalls):array {
        check130(str_starts_with($url,'https://api.openai.com/v1/') &&
            str_contains(implode('|',$headers),'Authorization: Bearer sk-FakeProductionGateKey123456'),
            'requests use fixed HTTPS origin and server credentials');
        if ($url==='https://api.openai.com/v1/models') {
            ++$modelCalls;
            check130($method==='GET','models probe uses GET');
            return [200,json_encode(['data'=>[['id'=>'gpt-5-mini']]],JSON_THROW_ON_ERROR)];
        }
        ++$generationCalls;
        check130($method==='POST' && $url==='https://api.openai.com/v1/responses','Responses API request');
        $request=json_decode($body,true,64,JSON_THROW_ON_ERROR);
        check130($request['model']==='gpt-5-mini' && $request['store']===false &&
            !isset($request['tools']),'bounded confidential production requests');
        if (($request['stream']??false)===true) {
            $full=output130('Тестовый потоковый ответ.',16,9);
            $frames=[
                ['type'=>'response.output_text.delta','delta'=>'Тестовый '],
                ['type'=>'response.output_text.delta','delta'=>'потоковый ответ.'],
                ['type'=>'response.completed','response'=>$full],
            ];
            $raw='';foreach($frames as $frame) $raw.='data: '.json_encode($frame,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n\n";
            return [200,$raw."data: [DONE]\n\n"];
        }
        if (isset($request['text']['format'])) {
            check130(($request['text']['format']['type']??null)==='json_schema',
                'live structured output uses provider schema');
            return [200,json_encode(output130('{"title":"Тестовый заголовок"}',13,7),JSON_THROW_ON_ERROR)];
        }
        return [200,json_encode(output130('Короткий тестовый ответ.',11,6),JSON_THROW_ON_ERROR)];
    };
    $client=new OpenAIClient($driver,'sk-FakeProductionGateKey123456');
    $initial=ProductionAIAcceptance::run($root,$app->load(),false,false,$client);
    check130($initial['automated_ok'] && !$initial['paid_smoke_passed'] &&
        !$initial['production_accepted'] && $generationCalls===0,
        'read-only gate does not incur charges');
    $noApproval=ProductionAIAcceptance::run($root,$app->load(),true,true,$client);
    check130(!$noApproval['automated_ok'] && !$noApproval['paid_smoke_passed'] &&
        $generationCalls===0,'paid probe is blocked without explicit operator consent');
    putenv('CAJEER_AI_PAID_PROBE_CONFIRM=I_ACCEPT_API_CHARGES');
    $result=ProductionAIAcceptance::run($root,$app->load(),true,true,$client);
    check130($result['automated_ok'] && $result['paid_smoke_passed'] &&
        !$result['production_accepted'] && $result['usage']['requests']===3 &&
        $result['usage']['input_tokens']===40 && $result['usage']['output_tokens']===22 &&
        $generationCalls===3 && $modelCalls>=1,'full paid smoke including structured and streaming');
    $ledger=(new UsageLimiter($root))->status();
    check130($ledger['requests']===3 && $ledger['tokens']===62 &&
        $ledger['active_requests']===0 && $ledger['spent_microcents']>0,
        'real provider token amounts recorded in durable budget ledger');
    check130(file_get_contents($app->file())===$original,
        'paid probes do not modify site content or credentials');
    $render=json_encode($result,JSON_THROW_ON_ERROR);
    check130(!str_contains($render,'sk-FakeProductionGateKey123456') &&
        !str_contains($render,$root) && !str_contains($render,'Тестовый заголовок'),
        'reports contain no secrets, private paths or AI response content');
    // On failure the release must report a failure and settle conservative cost.
    $failClient=new OpenAIClient(static function(string $url):array {
        if (str_ends_with($url,'/models')) return [200,json_encode(['data'=>[['id'=>'gpt-5-mini']]])];
        return [429,'{"error":"rate limited"}'];
    },'sk-FakeProductionGateKey123456');
    $failed=ProductionAIAcceptance::run($root,$app->load(),true,true,$failClient);
    check130(!$failed['automated_ok'] && !$failed['paid_smoke_passed'] &&
        (new UsageLimiter($root))->status()['active_requests']===0,
        'rate limiting fails closed without orphaning pending reservations');
    $policy['enabled']=false;CostPolicy::save($root,$policy);
    $before=$generationCalls;
    $blocked=ProductionAIAcceptance::run($root,$app->load(),true,true,$client);
    check130(!$blocked['paid_smoke_passed'] && $generationCalls===$before,
        'paid probe requires enabled monetary protection');
    echo "Cajeer AI 1.3.0 paid opt-in, live models, real API payloads, SSE, structured output, quota accounting, failures and manual signoff OK\n";
} finally {
    if ($previousKey===false) putenv('CAJEER_OPENAI_API_KEY');
    else putenv('CAJEER_OPENAI_API_KEY='.$previousKey);
    if ($previousConfirm===false) putenv('CAJEER_AI_PAID_PROBE_CONFIRM');
    else putenv('CAJEER_AI_PAID_PROBE_CONFIRM='.$previousConfirm);
    delete130($root);
}
