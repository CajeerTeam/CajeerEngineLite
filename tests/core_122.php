<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\{Lite};
use CajeerEngine\AI\{AIConfig,OpenAIClient,UsageLimiter,ConversationStore,ChatView};
use CajeerEngine\Auth\OwnerIdentity;
use CajeerEngine\Storage\SiteState;

function c122(bool $good,string $why):void{if(!$good)throw new RuntimeException('Chat 1.2.2: '.$why);}
function fail122(callable $fn,string $why):void{try{$fn();}catch(RuntimeException|InvalidArgumentException){return;}throw new RuntimeException('Unexpected chat success: '.$why);}
function rm122(string $path):void{if(is_link($path)||is_file($path)){unlink($path);return;}if(is_dir($path)){foreach(new FilesystemIterator($path) as $f)rm122($f->getPathname());rmdir($path);}}
c122(Lite::VERSION==='1.2.2' && SiteState::SCHEMA===13,'version/schema');
$root=sys_get_temp_dir().'/cajeer-chat122-'.bin2hex(random_bytes(8));mkdir($root,0700);
try {
    $app=new Lite($root);$app->install('TestPassword122ABC!','https://lite.example.test','Chat test');
    $config=AIConfig::DEFAULTS;$config['enabled']=true;$config['daily_requests']=5;
    $app->change(static function($d)use($config){$d['ai']=$config;return $d;});
    $gen=$app->load()['identity']['generation'];$store=new ConversationStore($root,$gen);
    $chat=$store->create();$id=$chat['id'];
    c122(count($store->all())===1 && $store->get($id)['messages']===[],'create/list');
    fail122(fn()=>$store->get('../hack'),'id traversal');
    fail122(fn()=>$store->begin($id,str_repeat('A',3001),3000),'message limit');
    $initial=$store->begin($id,'Привет',3000);
    c122($initial['messages']===[['role'=>'user','content'=>'Привет']],'first input');
    fail122(fn()=>$store->begin($id,'duplicate',3000),'parallel message blocked');
    $key='sk-testingChat122abcdefghijkl';
    $responses=0;
    $driver=function($url,$method,$headers,$body)use(&$responses):array {
        $responses++;
        c122($url==='https://api.openai.com/v1/responses' && $method==='POST','fixed origin');
        $payload=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        c122($payload['store']===false && $payload['input'][array_key_last($payload['input'])]['role']==='user','input roles');
        $out=['id'=>'resp_chat','status'=>'completed','model'=>'gpt-5-mini',
            'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>'Ответ '.$responses]]]],
            'usage'=>['input_tokens'=>33,'output_tokens'=>17]];
        if (($payload['stream']??false)===true) {
            $text='Ответ '.$responses;
            $frames=[];
            foreach ([$text] as $delta) $frames[]="data: ".json_encode(['type'=>'response.output_text.delta','delta'=>$delta],JSON_THROW_ON_ERROR)."\n\n";
            $frames[]="data: ".json_encode(['type'=>'response.completed','response'=>$out],JSON_THROW_ON_ERROR)."\n\n";
            $frames[]="data: [DONE]\n\n";
            return [200,implode('',$frames)];
        }
        return [200,json_encode($out,JSON_THROW_ON_ERROR)];
    };
    $client=new OpenAIClient($driver,$key);$quota=new UsageLimiter($root);
    $result=$quota->chat($client,$config,$initial['messages']);
    $store->complete($id,$initial['token'],$result['text']);
    c122($store->get($id)['messages'][1]['content']==='Ответ 1','first answer persisted');
    $next=$store->begin($id,'Второй вопрос',3000);
    c122(count($next['messages'])===3 && $next['messages'][1]['role']==='assistant','multi-turn context');
    $chunks=[];
    $streamed=$quota->chat($client,$config,$next['messages'],true,static function($delta)use(&$chunks){$chunks[]=$delta;});
    c122(implode('',$chunks)==='Ответ 2' && $streamed['text']==='Ответ 2','real streaming parser');
    $store->complete($id,$next['token'],$streamed['text']);
    c122(count($store->get($id)['messages'])===4 && $quota->status()['requests']===2 && $quota->status()['tokens']===100,'quota and history');
    $pending=$store->begin($id,'Буду отменён',3000);
    $store->cancel($id,$pending['token']);
    fail122(fn()=>$store->complete($id,$pending['token'],'should not commit'),'cancelled generation');
    c122(count($store->get($id)['messages'])===4,'cancel rolls back pending user');
    $html=ChatView::render($store->all(),$store->get($id),'123',$config,true);
    c122(str_contains($html,'Ответ 2') && str_contains($html,'/admin/ai') && str_contains($html,'ai-chat-send'),'render');
    // Owner replacement creates an independent namespace, not a readable copy of old messages.
    $other=new ConversationStore($root,OwnerIdentity::generation());
    c122($other->all()===[],'different owner isolation');
    $file=$root.'/storage/ai/conversations/'.$gen.'/'.$id.'.json';
    c122((fileperms($file)&0077)===0,'private history');
    $store->delete($id);c122($store->all()===[],'deletion');
    $fresh=$store->create();$store->clear();c122($store->all()===[],'history clear');
    $unsafe=$root.'/storage/ai/conversations/'.$gen.'/'.$fresh['id'].'.json';
    symlink('/etc/passwd',$unsafe);
    fail122(fn()=>$store->get($fresh['id']),'symlink');
    echo "Lite 1.2.2 private conversations, multi-turn, streaming, quotas, cancellation and owner isolation OK\n";
} finally {rm122($root);}
