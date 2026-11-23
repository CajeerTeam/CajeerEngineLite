<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\{Lite};
use CajeerEngine\AI\{AIConfig,CostPolicy,DataProtection,OpenAIClient,UsageLimiter};
use CajeerEngine\Storage\SiteState;
function check128(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('AI 1.2.8: '.$label);}
function fail128(callable $run,string $label):void {try{$run();}catch(RuntimeException|InvalidArgumentException){return;}throw new RuntimeException('AI 1.2.8 accepted '.$label);}
function cleanup128(string $path):void {if(is_link($path)||is_file($path)){unlink($path);return;}if(is_dir($path)){foreach(new FilesystemIterator($path) as $item)cleanup128($item->getPathname());rmdir($path);}}
check128(version_compare(Lite::VERSION,'1.2.8','>=') && SiteState::SCHEMA===13,'compatible schema and version');
$root=sys_get_temp_dir().'/cajeer-ai128-'.bin2hex(random_bytes(8));mkdir($root,0700,true);
try {
    $app=new Lite($root);$app->install('TestAdminCostPassword128','https://example.test','Budget test');
    $site=$app->load();$before=file_get_contents($app->file());
    $policy=CostPolicy::fromPost(['cost_enabled'=>'1','cost_daily_cents'=>'1','cost_monthly_cents'=>'3',
        'cost_input_cents_per_million'=>'1000','cost_output_cents_per_million'=>'1000','cost_max_concurrent'=>'2']);
    CostPolicy::save($root,$policy);
    check128(CostPolicy::load($root)===$policy,'private persistent cost controls');
    check128((fileperms(CostPolicy::file($root))&0077)===0,'private cost permissions');
    check128(file_get_contents($app->file())===$before,'budget does not mutate site.json');
    fail128(fn()=>CostPolicy::fromPost(['cost_enabled'=>'1','cost_daily_cents'=>'5','cost_monthly_cents'=>'3',
        'cost_input_cents_per_million'=>'1000','cost_output_cents_per_million'=>'1000','cost_max_concurrent'=>'2']),
        'inconsistent monthly limit');
    $request="User email test@example.org; phone +1 (202) 555-0123; " .
        "token sk-superSecretTestKey123456789 and Bearer abcdefghij1234567890";
    $filtered=DataProtection::outbound($request);
    foreach (['test@example.org','202) 555-0123','sk-superSecretTestKey','abcdefg'] as $secret) {
        check128(!str_contains($filtered,$secret),'redact '.$secret);
    }
    check128(str_contains($filtered,'[REDACTED_API_KEY]') && str_contains($filtered,'[REDACTED_EMAIL]'),
        'redaction placeholders');
    $config=AIConfig::DEFAULTS;$config['enabled']=true;$config['daily_tokens']=100000;
    $config['daily_requests']=50;$config['max_output_tokens']=256;
    $calls=0;
    $driver=static function($url,$method,$headers,$body)use(&$calls):array {
        $calls++;
        $json=json_decode($body,true,64,JSON_THROW_ON_ERROR);
        check128(!str_contains($body,'test@example.org') && !str_contains($body,'superSecretTestKey') &&
            !str_contains($body,'202) 555-0123'),'sensitive data excluded from outbound JSON');
        check128(($json['store']??null)===false && !isset($json['tools']) &&
            isset($json['instructions']),'privacy and instruction hierarchy');
        return [200,json_encode(['status'=>'completed','model'=>'gpt-5-mini',
            'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>'Проверено']]]],
            'usage'=>['input_tokens'=>300,'output_tokens'=>100]],JSON_THROW_ON_ERROR)];
    };
    $client=new OpenAIClient($driver,'sk-ProductionCostTestKey12345');
    $limiter=new UsageLimiter($root);
    $first=$limiter->generate($client,$config,$request);
    check128($first['text']==='Проверено','client response');
    $s=$limiter->status();
    check128($s['requests']===1 && $s['tokens']===400 && $s['spent_microcents']===400000,
        'charge actual tokens and token rate in microcents');
    check128($s['monthly_spent_microcents']===400000 && $s['monthly_reserved_microcents']===0,
        'month tracking');
    $limiter->generate($client,$config,'Тест');
    fail128(fn()=>$limiter->generate($client,$config,'Тест'),'strict estimated daily budget');
    check128($calls===2,'blocked before paid HTTP request');
    // All generation paths must redact secrets before the transport sees JSON.
    $chatDriver=static function($url,$method,$headers,$body):array {
        check128(!str_contains($body,'reader@example.org') && !str_contains($body,'sk-privatePersonalToken99999'),
            'chat privacy filtering');
        $payload=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        check128(($payload['input'][0]['role']??null)==='user' && isset($payload['instructions']),
            'chat retains controlled roles and developer guidance');
        return [200,json_encode(['status'=>'completed','model'=>'gpt-5-mini',
            'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>'OK']]]],
            'usage'=>['input_tokens'=>10,'output_tokens'=>3]],JSON_THROW_ON_ERROR)];
    };
    $chat=new OpenAIClient($chatDriver,'sk-ProductionCostTestKey12345');
    check128($chat->chat([['role'=>'user','content'=>'reader@example.org sk-privatePersonalToken99999']],$config)['text']==='OK',
        'chat request privacy');
    $structuredDriver=static function($url,$method,$headers,$body):array {
        $payload=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        check128(!str_contains($payload['input'],'writer@example.org') &&
            str_contains($payload['input'],'[REDACTED_EMAIL]'),'structured generation privacy');
        return [200,json_encode(['status'=>'completed','model'=>'gpt-5-mini',
            'output'=>[['type'=>'message','content'=>[['type'=>'output_text',
                'text'=>json_encode(['title'=>'Safe title'],JSON_THROW_ON_ERROR)]]]],
            'usage'=>['input_tokens'=>10,'output_tokens'=>3]],JSON_THROW_ON_ERROR)];
    };
    $structured=new OpenAIClient($structuredDriver,'sk-ProductionCostTestKey12345');
    check128($structured->structuredContent('writer@example.org',$config,'title')['fields']['title']==='Safe title',
        'structured request privacy');
    // 1.2.7 on-disk daily usage ledgers must be read and upgraded without losing token counts.
    $oldRoot=$root.'/legacy';mkdir($oldRoot,0700);$old=new UsageLimiter($oldRoot);
    $oldLedger=$oldRoot.'/storage/ai/usage-'.gmdate('Y-m-d').'.json';
    file_put_contents($oldLedger,json_encode(['day'=>gmdate('Y-m-d'),'requests'=>3,
        'tokens'=>44,'pending'=>[]],JSON_THROW_ON_ERROR));chmod($oldLedger,0600);
    check128($old->status()['requests']===3 && $old->status()['tokens']===44 &&
        $old->status()['monthly_spent_microcents']===0,'read 1.2.7 quota ledger');
    $id=$old->reserve($config,'Upgrade quota');$old->finish($id,12);
    check128($old->status()['requests']===4 && $old->status()['tokens']===56,
        'legacy ledger upgraded transactionally');
    // Interrupted/failed transport must not free its reserved monetary amount.
    $failedRoot=$root.'/failed';mkdir($failedRoot,0700);
    CostPolicy::save($failedRoot,$policy);
    $failed=new UsageLimiter($failedRoot);
    $broken=new OpenAIClient(static function():array {throw new RuntimeException('Injected network failure');},
        'sk-ProductionCostTestKey12345');
    fail128(fn()=>$failed->generate($broken,$config,$request),'provider failure');
    check128($failed->status()['requests']===1 && $failed->status()['spent_microcents']>0 &&
        $failed->status()['active_requests']===0,'failed requests charge reserved estimate');
    // Monthly totals include earlier days; expired requests from an earlier day
    // are charged and retired instead of tying up the monthly budget indefinitely.
    $yesterday=gmdate('Y-m-d',time()-86400);
    if(substr($yesterday,0,7)===gmdate('Y-m')) {
        $monthlyRoot=$root.'/monthly';mkdir($monthlyRoot,0700);
        $monthlyPolicy=$policy;$monthlyPolicy['daily_cents']=1;$monthlyPolicy['monthly_cents']=1;
        CostPolicy::save($monthlyRoot,$monthlyPolicy);
        $monthly=new UsageLimiter($monthlyRoot);
        $ledger=$monthlyRoot.'/storage/ai/usage-'.$yesterday.'.json';
        file_put_contents($ledger,json_encode(['day'=>$yesterday,'requests'=>1,'tokens'=>10,
            'pending'=>['aabbccddeeff00112233445566778899'=>['tokens'=>100,'created'=>time()-1800,
                'cost_microcents'=>900000,'input_rate'=>1000,'output_rate'=>1000]],
            'cost_microcents'=>0],JSON_THROW_ON_ERROR));chmod($ledger,0600);
        $monthlyStatus=$monthly->status();
        check128($monthlyStatus['monthly_spent_microcents']===900000 &&
            $monthlyStatus['monthly_reserved_microcents']===0,'retire stale past-day reservations');
        fail128(fn()=>$monthly->reserve($config,'Monthly quota'),
            'past-day spend must count against current month');
    }
    // Future malicious files, symlinks and hardlinks must not be followed.
    $file=CostPolicy::file($root);unlink($file);symlink('/etc/passwd',$file);
    fail128(fn()=>CostPolicy::load($root),'cost policy symlink');
    fail128(fn()=>CostPolicy::save($root,$policy),'cost policy symlink overwrite');
    check128(file_get_contents($app->file())===$before,'site data unchanged');
    echo "Lite 1.2.8 cost controls, actual token settlement, monthly budget, outbound redaction, fail-closed transport and private storage OK\n";
} finally {cleanup128($root);}
