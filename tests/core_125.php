<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\AI\{AIConfig,OpenAIClient,UsageLimiter,SEOAudit,SEOAssistant,SEOView,PageEditor,PageHistory};
use CajeerEngine\Storage\SiteState;
function yes125(bool $ok,string $message):void {if (!$ok)throw new RuntimeException('SEO 1.2.5: '.$message);}
function deny125(callable $run,string $message):void {try {$run();}catch (InvalidArgumentException|RuntimeException $e){return;}throw new RuntimeException('SEO accepted: '.$message);}
function cleanup125(string $path):void {if(is_link($path)||is_file($path)){unlink($path);return;}if(is_dir($path)){foreach(new FilesystemIterator($path) as $item)cleanup125($item->getPathname());rmdir($path);}}
yes125(Lite::VERSION==='1.2.5' && SiteState::SCHEMA===13,'version / unchanged schema');
$root=sys_get_temp_dir().'/cajeer-seo-'.bin2hex(random_bytes(7));
mkdir($root.'/templates/default',0700,true);
copy(dirname(__DIR__).'/templates/default/page.cjr',$root.'/templates/default/page.cjr');
try {
    $app=new Lite($root);
    $app->install('ValidSeoPassword-125','https://site.test','Demo Site');
    $config=AIConfig::DEFAULTS;$config['enabled']=true;$config['max_input_chars']=12000;
    $config['daily_requests']=20;$config['daily_tokens']=200000;
    $app->change(static function(array $s)use($config):array {
        $s['ai']=$config;
        $s['pages']['/']['description']='Повтор описания';
        $s['pages']['/']['body']=str_repeat('Это проверенный материал компании. ',20);
        $s['pages']['/offers']=Lite::page(['path'=>'/offers','title'=>$s['pages']['/']['title'],
            'description'=>'Повтор описания','body'=>str_repeat('Услуги и предложения. ',20),'published'=>'1','menu'=>'1']);
        $s['pages']['/draft']=Lite::page(['path'=>'/draft','title'=>'Черновая страница','description'=>'Повтор описания',
            'body'=>'Черновик','published'=>'','menu'=>'']);
        return $s;
    });
    $site=$app->load();$audit=SEOAudit::run($site);
    yes125($audit['published_count']===2 && $audit['issue_count']>0,'audit populated');
    $codes=array_column($audit['pages']['/']['issues'],'code');
    yes125(in_array('duplicate_title',$codes,true) && in_array('duplicate_description',$codes,true),'duplicate metadata');
    yes125(!in_array('duplicate_description',array_column($audit['pages']['/draft']['issues'],'code'),true),'draft excluded from published duplicate comparison');
    yes125($audit['pages']['/']['canonical']==='https://site.test/','canonical from actual site origin');
    $quota=new UsageLimiter($root);
    $fields=['title'=>'Полезные товары и услуги нашей компании','description'=>str_repeat('Описание реальных услуг с конкретной пользой. ',2)];
    $driver=static function(string $url,string $method,array $headers,?string $body)use(&$fields):array {
        yes125($url==='https://api.openai.com/v1/responses' && $method==='POST','API endpoint');
        $req=json_decode($body,true,64,JSON_THROW_ON_ERROR);
        yes125($req['store']===false && !isset($req['tools']),'no storing / tools');
        yes125($req['text']['format']['strict']===true,'real structured output request');
        yes125($req['text']['format']['schema']['required']===array_keys($fields),'correct fields');
        return [200,json_encode(['status'=>'completed','model'=>'gpt-5-mini',
            'output'=>[['type'=>'message','content'=>[['type'=>'output_text',
                'text'=>json_encode($fields,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]]]],
            'usage'=>['input_tokens'=>40,'output_tokens'=>60]],JSON_THROW_ON_ERROR)];
    };
    $client=new OpenAIClient($driver,'sk-longTestApiKey123456789');
    $history=new PageHistory($root,$site['identity']['generation']);
    $request=['path'=>'/','scope'=>'metadata','instruction'=>'Сделайте понятнее'];
    $before=$app->load()['pages']['/'];
    $preview=SEOAssistant::prepare($request,$site,$client,$quota);
    yes125($app->load()['pages']['/']===$before,'no changes before approval');
    yes125($preview['suggested']['body']===$before['body'] && $preview['suggested']['title']===$fields['title'],'metadata-only scope');
    $markup=SEOView::render($site,str_repeat('f',64),$audit,$preview,'/',true);
    yes125(str_contains($markup,'seo-preview') && str_contains($markup,'SEO-аудит сайта') && str_contains($markup,'name="confirm"'),'working review interface');
    deny125(fn()=>SEOAssistant::apply($app,$history,$preview,['title'=>'New','description'=>'New']),'confirmation mandatory');
    $expired=$preview;$expired['created']=time()-1801;
    deny125(fn()=>SEOAssistant::apply($app,$history,$expired,['title'=>'New','description'=>'New','confirm'=>'1']),'expires');
    $foreign=$preview;$foreign['owner_generation']=str_repeat('0',48);
    deny125(fn()=>SEOAssistant::apply($app,$history,$foreign,['title'=>'New','description'=>'New','confirm'=>'1']),'owner isolation');
    $app->change(static function(array $s):array{$s['pages']['/']['body'].=' NEW';return $s;});
    deny125(fn()=>SEOAssistant::apply($app,$history,$preview,['title'=>'New','description'=>'New','confirm'=>'1']),'concurrent edit blocked');
    yes125($history->all('/')===[],'no history on rejection');
    $before=$app->load()['pages']['/'];
    $preview=SEOAssistant::prepare($request,$app->load(),$client,$quota);
    SEOAssistant::apply($app,$history,$preview,['title'=>'SEO <script>alert(1)</script>',
        'description'=>'Проверенное описание','path'=>'/other','published'=>'','confirm'=>'1']);
    $saved=$app->load()['pages']['/'];
    yes125($saved['title']==='SEO <script>alert(1)</script>' && $saved['description']==='Проверенное описание' &&
        $saved['body']===$before['body'] && $saved['published'] && $saved['menu'] && $saved['path']==='/','metadata update immutable flags');
    yes125(!str_contains($app->render($app->load(),$saved),'<script>alert(1)</script>'),'HTML safe rendering');
    yes125(count($history->all('/'))===1 && $history->all('/')[0]['page']===$before,'durable page snapshot');
    deny125(fn()=>SEOAssistant::apply($app,$history,$preview,['title'=>'Repeat','description'=>'Repeat','confirm'=>'1']),'no replay');
    $history->restore($app,'/',$history->all('/')[0]['id'],PageEditor::revision($saved));
    yes125($app->load()['pages']['/']===$before,'actual rollback');
    $fields=['title'=>'Новый качественный заголовок для страницы','description'=>str_repeat('Достоверное SEO-описание без лишних обещаний. ',2),
        'body'=>'Улучшенный фактический текст и полезное описание товаров.'];
    $request['scope']='all';
    $preview=SEOAssistant::prepare($request,$app->load(),$client,$quota);
    SEOAssistant::apply($app,$history,$preview,['title'=>$fields['title'],'description'=>$fields['description'],
        'body'=>'Исправленная версия с другим текстом.','confirm'=>'1']);
    yes125($app->load()['pages']['/']['body']==='Исправленная версия с другим текстом.' &&
        $app->load()['pages']['/']['path']==='/' && $app->load()['pages']['/']['published'],'full content SEO edit');
    $unsafe=$preview;$unsafe['id']='bad';
    deny125(fn()=>SEOAssistant::apply($app,$history,$unsafe,['title'=>'A','description'=>'B','confirm'=>'1']),'proposal validation');
    deny125(fn()=>SEOAssistant::prepare(['path'=>'/nope','scope'=>'metadata'],$app->load(),$client,$quota),'missing page');
    deny125(fn()=>SEOAssistant::prepare(['path'=>'/','scope'=>'arbitrary'],$app->load(),$client,$quota),'bad scope');
    $small=$app->load();$small['ai']['max_input_chars']=100;
    deny125(fn()=>SEOAssistant::prepare($request,$small,$client,$quota),'no truncation / hidden model costs');
    $xss=$preview;$xss['suggested']['title']='<img src=x onerror=alert(1)>';
    $markup=SEOView::render($app->load(),str_repeat('f',64),SEOAudit::run($app->load()),$xss,'/',true);
    yes125(str_contains($markup,'&lt;img') && !str_contains($markup,'<img src=x'),'safe proposal UI');
    $usage=$quota->status();yes125($usage['requests']===3 && $usage['tokens']===300,'quota debiting');
    echo "Lite 1.2.5 SEO audit, structured generation, safe preview, approve/revoke, rollback, concurrency, escaping, and quotas OK\n";
} finally {cleanup125($root);}
