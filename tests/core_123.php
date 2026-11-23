<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\AI\{AIConfig,ContentGenerator,ContentView,OpenAIClient,UsageLimiter};
use CajeerEngine\Storage\SiteState;

function ok123(bool $good, string $message): void { if (!$good) throw new RuntimeException('Content 1.2.3: '.$message); }
function rejects123(callable $callback, string $name): void {
    try { $callback(); } catch (InvalidArgumentException|RuntimeException $e) { return; }
    throw new RuntimeException('Content 1.2.3 unexpectedly accepted: '.$name);
}
function rm123(string $path): void {
    if (is_file($path) || is_link($path)) {unlink($path);return;}
    if (is_dir($path)) {foreach(new FilesystemIterator($path) as $item)rm123($item->getPathname());rmdir($path);}
}
ok123(version_compare(Lite::VERSION, '1.2.3', '>=') && SiteState::SCHEMA === 14, 'version and schema');
$root = sys_get_temp_dir().'/lite-content123-'.bin2hex(random_bytes(8));
mkdir($root,0700);
mkdir($root.'/templates/default',0700,true);
copy(dirname(__DIR__).'/templates/default/page.cjr',$root.'/templates/default/page.cjr');
try {
    $app = new Lite($root);
    $app->install('ValidPasswordContent123','https://lite.example.test','Demo website');
    $config=AIConfig::DEFAULTS;
    $config['enabled']=true;$config['daily_requests']=20;$config['daily_tokens']=100000;
    $app->change(static function($s)use($config){$s['ai']=$config;return $s;});
    $site=$app->load();
    $calls=0;$mode='page';$force=null;
    $driver=static function($url,$method,$headers,$body)use(&$calls,&$mode,&$force):array {
        ++$calls;
        ok123($url==='https://api.openai.com/v1/responses' && $method==='POST','fixed OpenAI endpoint');
        ok123(count(array_filter($headers,static fn($h)=>str_starts_with($h,'Authorization: Bearer sk-')))===1,'key only in Authorization header');
        $data=json_decode($body,true,64,JSON_THROW_ON_ERROR);
        ok123($data['store']===false && $data['text']['format']['type']==='json_schema' &&
            $data['text']['format']['strict']===true && $data['text']['format']['schema']['additionalProperties']===false,
            'real Structured Outputs request');
        ok123($data['model']==='gpt-5-mini' && !isset($data['tools']),'model and no tool execution');
        $required=match($mode){'page'=>['title','description','body'],'title'=>['title'],'description'=>['description']};
        ok123($data['text']['format']['schema']['required']===$required,'mode schema');
        $fields=match($mode){'page'=>['title'=>'Новая тестовая страница','description'=>'Описание для тестовой страницы','body'=>'Полезный контент страницы, без HTML'],
            'title'=>['title'=>'Обновлённый заголовок'],'description'=>['description'=>'Новое SEO-описание']};
        if ($force!==null) $fields=$force;
        return [200,json_encode(['status'=>'completed','model'=>'gpt-5-mini',
            'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($fields,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]]]],
            'usage'=>['input_tokens'=>34,'output_tokens'=>72]],JSON_THROW_ON_ERROR)];
    };
    $client=new OpenAIClient($driver,'sk-validContent1234567890123');
    $quota=new UsageLimiter($root);
    $request=['mode'=>'page','path'=>'/new-article','topic'=>'Объяснить особенности сервиса',
        'audience'=>'Клиенты','keywords'=>'сервис, услуги','style'=>'professional','length'=>'normal','target'=>'/'];
    $preview=ContentGenerator::generate($request,$site,$client,$quota);
    ok123($calls===1 && !isset($app->load()['pages']['/new-article']),'generation is preview only');
    $html=ContentView::render($site,'validcsrf123',$preview,true);
    ok123(str_contains($html,'Новая тестовая страница') && str_contains($html,'Подтвердить и сохранить') &&
        str_contains($html,'name="action" value="apply"') && str_contains($html,'name="csrf" value="validcsrf123"'),'editable and protected preview');
    rejects123(fn()=>ContentGenerator::apply($app,$preview,['path'=>'/','title'=>'Bad','description'=>'Bad','body'=>'Bad']),'existing path collision');
    rejects123(fn()=>ContentGenerator::apply($app,$preview,['path'=>'/../escape','title'=>'Bad','description'=>'Bad','body'=>'Bad']),'path traversal');
    $path=ContentGenerator::apply($app,$preview,['path'=>'/new-article','title'=>'Заголовок с правкой администратора',
        'description'=>$preview['description'],'body'=>$preview['body']]);
    $created=$app->load()['pages'][$path];
    ok123($path==='/new-article' && $created['title']==='Заголовок с правкой администратора' &&
        $created['published']===false && $created['menu']===false,'draft creation and human edits');
    rejects123(fn()=>ContentGenerator::apply($app,$preview,['path'=>'/new-article','title'=>'Duplicate',
        'description'=>'desc','body'=>'body']),'replay cannot overwrite');
    $mode='title';
    $request['mode']='title';$request['target']='/';
    $title=ContentGenerator::generate($request,$app->load(),$client,$quota);
    ok123($title['title']==='Обновлённый заголовок' && $title['revision']!=='','generated title');
    $before=$app->load()['pages']['/'];
    $app->change(static function($d){$d['pages']['/']['body'].=' Concurrent edit';return $d;});
    rejects123(fn()=>ContentGenerator::apply($app,$title,['title'=>'Нельзя перезаписывать']),'stale revision blocked');
    $title=ContentGenerator::generate($request,$app->load(),$client,$quota);
    ContentGenerator::apply($app,$title,['title'=>'Заголовок <img src=x onerror=alert(1)>']);
    $updated=$app->load()['pages']['/'];
    ok123($updated['title']==='Заголовок <img src=x onerror=alert(1)>' &&
        $updated['body']===$before['body'].' Concurrent edit' && $updated['published']===true,
        'single-field edit preserves page state');
    $rendered=$app->render($app->load(),$updated);
    ok123(!str_contains($rendered,'<img src=x onerror=alert(1)>'),'public rendering escapes content');
    $mode='description';$request['mode']='description';
    $desc=ContentGenerator::generate($request,$app->load(),$client,$quota);
    ContentGenerator::apply($app,$desc,['description'=>'Описание администратора']);
    ok123($app->load()['pages']['/']['title']==='Заголовок <img src=x onerror=alert(1)>' &&
        $app->load()['pages']['/']['description']==='Описание администратора','description only');
    $expired=$desc;$expired['created']=time()-1801;
    rejects123(fn()=>ContentGenerator::apply($app,$expired,['description'=>'Bad']),'expired preview');
    $wrong=$desc;$wrong['owner_generation']=str_repeat('a',48);
    rejects123(fn()=>ContentGenerator::apply($app,$wrong,['description'=>'Bad']),'wrong owner');
    $mode='page';$force=['title'=>'','description'=>'ok','body'=>'ok'];
    rejects123(fn()=>ContentGenerator::generate(['mode'=>'page','path'=>'/bad','topic'=>'topic','audience'=>'','keywords'=>'',
        'style'=>'neutral','length'=>'short'],$app->load(),$client,$quota),'invalid model fields');
    $force=['title'=>'Title','description'=>'ok','body'=>'ok','unexpected'=>'extra'];
    rejects123(fn()=>ContentGenerator::generate(['mode'=>'page','path'=>'/bad','topic'=>'topic','audience'=>'','keywords'=>'',
        'style'=>'neutral','length'=>'short'],$app->load(),$client,$quota),'unexpected model field');
    $force=null;
    rejects123(fn()=>ContentGenerator::generate(['mode'=>'page','path'=>'/new-article','topic'=>'topic','audience'=>'',
        'keywords'=>'','style'=>'neutral','length'=>'short'],$app->load(),$client,$quota),'existing page');
    ok123($quota->status()['requests']===6,'all API attempts accounted for');
    echo "Lite 1.2.3 Structured Outputs, generated drafts, metadata edits, concurrency, HTML escaping and quotas OK\n";
} finally { rm123($root); }
