<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\{Lite};
use CajeerEngine\AI\{AIConfig,OpenAIClient,UsageLimiter};
use CajeerEngine\Storage\SiteState;

function ok121(bool $condition,string $message): void {if (!$condition) throw new RuntimeException('AI 1.2.1: '.$message);}
function reject121(callable $run,string $message):void {try{$run();}catch(RuntimeException|InvalidArgumentException $e){return;} throw new RuntimeException('AI 1.2.1 accepted: '.$message);}
function remove121(string $path):void {if(is_link($path)||is_file($path)){unlink($path);return;}if(is_dir($path)){foreach(new FilesystemIterator($path) as $file) remove121($file->getPathname());rmdir($path);}}
ok121(version_compare(Lite::VERSION,'1.2.2','>=') && SiteState::SCHEMA===13,'version/schema');
$root=sys_get_temp_dir().'/cajeer-ai121-'.bin2hex(random_bytes(8)); mkdir($root,0700,true);
try {
    $app=new Lite($root);$app->install('TestPasswordA1B2C3!','https://example.org','AI test');
    $state=$app->load();ok121($state['schema']===13 && $state['ai']===AIConfig::DEFAULTS,'fresh install configuration');
    ok121(!str_contains(file_get_contents($app->file()),'sk-test'),'no API key in state');
    $original=$state;
    $state['schema']=12;unset($state['ai']);
    file_put_contents($app->file(),SiteState::encode($state));
    $before=file_get_contents($app->file());
    ok121($app->migrate(),'12 to 13 migration executed');
    $migrated=$app->load();
    ok121($migrated['ai']===AIConfig::DEFAULTS && $migrated['pages']===$original['pages'] &&
        $migrated['password']===$original['password'] && $migrated['auth_policy']===$original['auth_policy'],'non-AI state preserved');
    $backups=$app->backups()->names();ok121(count($backups)===1,'migration backup created');
    $backupFile=$root.'/storage/backups/'.$backups[0];
    $data=json_decode(file_get_contents($backupFile),true,512,JSON_THROW_ON_ERROR);
    ok121(base64_decode($data['payload'],true)===$before,'exact pre-migration snapshot');
    $config=AIConfig::fromPost(['ai_enabled'=>'1','ai_model'=>'gpt-5-mini',
       'ai_max_input_chars'=>'3000','ai_max_output_tokens'=>'768',
       'ai_daily_requests'=>'2','ai_daily_tokens'=>'5000']);
    $app->change(static function(array $state)use($config):array{$state['ai']=$config;return $state;});
    reject121(fn()=>AIConfig::fromPost(['ai_enabled'=>'0','ai_model'=>'gpt-5-mini']), 'bad form');
    $bad=$config;$bad['model']='../../evil';reject121(fn()=>AIConfig::validate($bad),'unsafe model');
    $bad=$config;$bad['daily_tokens']=1;reject121(fn()=>AIConfig::validate($bad),'invalid token limit');
    $calls=0;
    $transport=static function(string $url,string $method,array $headers,string $body)use(&$calls):array{
        ++$calls;
        ok121($url==='https://api.openai.com/v1/responses' && $method==='POST','fixed Responses API origin');
        ok121(count(array_filter($headers,fn($h)=>str_starts_with($h,'Authorization: Bearer sk-')) )===1,'Authorization header');
        $payload=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        ok121($payload['model']==='gpt-5-mini' && $payload['store']===false &&
            $payload['max_output_tokens']===768 && $payload['input']==='Тест','structured API request');
        return [200,json_encode(['id'=>'resp_test','status'=>'completed','model'=>'gpt-5-mini',
            'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>'<b>Текст</b>']]]],
            'usage'=>['input_tokens'=>33,'output_tokens'=>17]],JSON_THROW_ON_ERROR)];
    };
    $client=new OpenAIClient($transport,'sk-testingA123456789012');
    $limiter=new UsageLimiter($root);
    $res=$limiter->generate($client,$config,'Тест');
    ok121($res['text']==='<b>Текст</b>' && $res['input_tokens']===33 && $res['output_tokens']===17,'real parsed text/usage');
    ok121($limiter->status()['requests']===1 && $limiter->status()['tokens']===50,'usage persisted');
    $limiterAgain=new UsageLimiter($root);
    ok121($limiterAgain->generate($client,$config,'Тест')['text']==='<b>Текст</b>' && $calls===2,'2nd call works');
    reject121(fn()=> $limiterAgain->generate($client,$config,'Тест'),'daily request cap');
    ok121($calls===2,'quota stops paid network call');
    reject121(fn()=> $client->generate(str_repeat('X',3001),$config),'oversized prompt rejected');
    $key='sk-testingA123456789012';
    $error=new OpenAIClient(static fn()=>[401,json_encode(['error'=>['message'=>'key='.$key]])],$key);
    reject121(fn()=> $error->generate('Test',$config),'invalid credential handling');
    $badOutput=new OpenAIClient(static fn()=>[200,json_encode(['status'=>'incomplete','output'=>[]])],$key);
    reject121(fn()=> $badOutput->generate('Test',$config),'incomplete response');
    $badUsage=new OpenAIClient(static fn()=>[200,json_encode(['status'=>'completed','output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>'Hi']]]]])],$key);
    reject121(fn()=> $badUsage->generate('Test',$config),'missing usage');
    $modelsClient=new OpenAIClient(static function($url,$method,$headers,$body):array {
        ok121($url==='https://api.openai.com/v1/models' && $method==='GET','model retrieval');
        return [200,json_encode(['data'=>[['id'=>'gpt-5-mini'],['id'=>'text-embedding-4'],['id'=>'gpt-5.6']]])];
    },$key);
    ok121($modelsClient->models()===['gpt-5-mini','gpt-5.6'],'model filtering');
    $files=glob($root.'/storage/ai/usage-*.json');ok121(count($files)===1,'ledger stored');
    chmod($files[0],0600);
    ok121((fileperms($files[0])&0077)===0,'ledger permissions');
    unlink($files[0]);symlink('/etc/passwd',$files[0]);
    reject121(fn()=> $limiter->status(),'ledger symlink refused');
    echo "Lite 1.2.1 OpenAI API, migration, quota, models, security regression OK\n";
} finally {remove121($root);}
