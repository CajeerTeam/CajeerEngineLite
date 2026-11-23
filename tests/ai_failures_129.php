<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\AI\{AIConfig,OpenAIClient,UsageLimiter,ConversationStore,CostPolicy,Acceptance};
use CajeerEngine\Lite;
function failure129(callable $call,string $name):void {
    try {$call();} catch (InvalidArgumentException|RuntimeException) {return;}
    throw new RuntimeException('Expected failure was accepted: '.$name);
}
function assertFailure129(bool $ok,string $name):void {if(!$ok)throw new RuntimeException('Failed negative integration: '.$name);}
function cleanupFailure129(string $p):void {
    if(is_file($p)||is_link($p)){unlink($p);return;}
    if(is_dir($p)){foreach(new FilesystemIterator($p) as $entry)cleanupFailure129($entry->getPathname());rmdir($p);}
}
$root=sys_get_temp_dir().'/lite-ai-faults-'.bin2hex(random_bytes(8));mkdir($root,0700);
try {
    $app=new Lite($root);$app->install('LongFailurePassword129','https://site.test','Failure Tests');
    $cfg=AIConfig::DEFAULTS;$cfg['enabled']=true;$cfg['daily_requests']=50;$cfg['daily_tokens']=200000;
    $app->change(static function(array $s)use($cfg):array {$s['ai']=$cfg;return $s;});
    $quota=new UsageLimiter($root);$calls=0;
    foreach ([401,403,429,500] as $status) {
        $client=new OpenAIClient(static function()use($status,&$calls):array {$calls++;return [$status,'{"error":{"message":"sk-secret-fake-key"}}'];},
            'sk-FailureIntegrationKey123456');
        failure129(fn()=>$quota->generate($client,$cfg,'Test request'),'HTTP '.$status);
    }
    $badResponses=['this is not JSON',json_encode(['status'=>'completed','output'=>[]],JSON_THROW_ON_ERROR),
        json_encode(['status'=>'incomplete','output'=>[],'usage'=>['input_tokens'=>1,'output_tokens'=>2]],JSON_THROW_ON_ERROR),
        json_encode(['status'=>'completed','output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>'OK']]]]],JSON_THROW_ON_ERROR)];
    foreach ($badResponses as $raw) {
        $client=new OpenAIClient(static function()use($raw,&$calls):array {$calls++;return [200,$raw];},
            'sk-FailureIntegrationKey123456');
        failure129(fn()=>$quota->generate($client,$cfg,'Test request'),'malformed provider response');
    }
    $schemaClient=new OpenAIClient(static function()use(&$calls):array {
        $calls++;
        $response=['status'=>'completed',
            'output'=>[['type'=>'message','content'=>[['type'=>'output_text',
                'text'=>'{"title":"Visible","extra":"injected"}']]]],
            'usage'=>['input_tokens'=>4,'output_tokens'=>5]];
        return [200,json_encode($response,JSON_THROW_ON_ERROR)];
    },'sk-FailureIntegrationKey123456');
    failure129(fn()=>$quota->structuredContent($schemaClient,$cfg,'Make a title','title'),'unexpected model fields');
    $interruptedStream=new OpenAIClient(static function()use(&$calls):array {
        $calls++;
        return [200,"data: ".json_encode(['type'=>'response.output_text.delta','delta'=>'Partial'],JSON_THROW_ON_ERROR)."\n\n".
            "data: [DONE]\n\n"];
    },'sk-FailureIntegrationKey123456');
    $deltas=[];
    try {$quota->chat($interruptedStream,$cfg,[['role'=>'user','content'=>'Stream?']],true,
        static function(string $delta)use(&$deltas):void{$deltas[]=$delta;});
        throw new RuntimeException('stream accepted without completion');
    } catch (RuntimeException $e) {
        assertFailure129(str_contains($e->getMessage(),'before completion'),'incomplete streaming response rejected');
    }
    assertFailure129($deltas===['Partial'],'stream exposed partial response before failure');
    $chat=new ConversationStore($root,$app->load()['identity']['generation']);
    $created=$chat->create();$pending=$chat->begin($created['id'],'Failed prompt',1000);
    $chat->cancel($created['id'],$pending['token']);
    assertFailure129($chat->get($created['id'])['messages']===[],'cancelled message removed');
    failure129(fn()=>$chat->complete($created['id'],$pending['token'],'Late result'),'late response after cancel');
    $usage=$quota->status();
    assertFailure129($usage['requests']===$calls && $usage['active_requests']===0 &&
        $usage['tokens']>0,'every failed attempt settled conservatively');
    // Deny symlinks in persistent usage data, even if they point to another valid JSON file.
    $ledger=$root.'/storage/ai/usage-'.gmdate('Y-m-d').'.json';
    $backup=$root.'/storage/ai/copy.txt';copy($ledger,$backup);unlink($ledger);
    symlink($backup,$ledger);
    failure129(fn()=>$quota->status(),'symlink usage ledger');
    unlink($ledger);rename($backup,$ledger);
    $policy=CostPolicy::DEFAULTS;
    $policy['enabled']=true;$policy['daily_cents']=1;$policy['monthly_cents']=2;
    $policy['input_cents_per_million']=1000;$policy['output_cents_per_million']=1000;
    CostPolicy::save($root,$policy);
    $result=Acceptance::run($root,$app->load());
    assertFailure129(!$result['automated_ok'] && !$result['production_accepted'],'no key is not a production pass');
    $json=json_encode($result,JSON_THROW_ON_ERROR);
    assertFailure129(!str_contains($json,'FailureIntegrationKey') && !str_contains($json,$root),
        'acceptance report contains no API secrets or private paths');
    echo "Lite 1.2.9 provider HTTP errors, malformed and refused output, SSE failure, cancelled chat, quota settlement, symlink storage and release-gate negatives OK\n";
} finally {cleanupFailure129($root);}
