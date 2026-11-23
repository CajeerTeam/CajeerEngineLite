<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\AI\{AIConfig,OpenAIClient,UsageLimiter,PageEditor,PageEditorView,PageHistory};
use CajeerEngine\Storage\SiteState;

function assert124(bool $condition,string $message):void {if (!$condition)throw new RuntimeException('1.2.4: '.$message);}
function rejects124(callable $run,string $message):void {try{$run();}catch(InvalidArgumentException|RuntimeException $e){return;}throw new RuntimeException('Accepted: '.$message);}
function rm124(string $path):void {if(is_file($path)||is_link($path)){unlink($path);return;}if(is_dir($path)){foreach(new FilesystemIterator($path) as $item)rm124($item->getPathname());rmdir($path);}}
assert124(version_compare(Lite::VERSION,'1.2.4','>=') && SiteState::SCHEMA===14,'version and schema');
$root=sys_get_temp_dir().'/ce124-'.bin2hex(random_bytes(8));
mkdir($root.'/templates/default',0700,true);
copy(dirname(__DIR__).'/templates/default/page.cjr',$root.'/templates/default/page.cjr');
try {
    $app=new Lite($root);
    $app->install('EnoughPasswordForEditor124','https://lite.example.test','Test website');
    $ai=AIConfig::DEFAULTS;$ai['enabled']=true;$ai['max_input_chars']=12000;
    $ai['daily_requests']=20;$ai['daily_tokens']=200000;
    $app->change(static function(array $s)use($ai):array{$s['ai']=$ai;return $s;});
    $quota=new UsageLimiter($root);$fields=['body'=>'Новый безопасный текст <script>alert(1)</script>'];
    $driver=static function(string $url,string $method,array $headers,?string $body)use(&$fields):array {
        assert124($url==='https://api.openai.com/v1/responses' && $method==='POST','fixed endpoint');
        $payload=json_decode($body,true,64,JSON_THROW_ON_ERROR);
        assert124($payload['store']===false && !isset($payload['tools']) && $payload['text']['format']['strict']===true,'structured request, no tools');
        assert124($payload['text']['format']['schema']['required']===array_keys($fields),'scope schema');
        return [200,json_encode(['status'=>'completed','model'=>'gpt-5-mini',
            'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($fields,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]]]],
            'usage'=>['input_tokens'=>42,'output_tokens'=>83]],JSON_THROW_ON_ERROR)];
    };
    $client=new OpenAIClient($driver,'sk-validPageEditorKey123456789');
    $history=new PageHistory($root,$app->load()['identity']['generation']);
    $request=['path'=>'/','scope'=>'body','operation'=>'proofread','instruction'=>'Исправить опечатки'];
    $original=$app->load()['pages']['/'];
    $preview=PageEditor::prepare($request,$app->load(),$client,$quota);
    assert124($app->load()['pages']['/']===$original,'no write before approval');
    assert124($preview['suggested']['body']===$fields['body'] && $preview['original']===$original,'preview data');
    $html=PageEditorView::render($app->load(),str_repeat('a',64),$preview,[],true,'/');
    assert124(str_contains($html,'Сравнение и предварительный просмотр') && str_contains($html,'Исходная версия') &&
        str_contains($html,'AI-предложение') && str_contains($html,'name="confirm"') &&
        !str_contains($html,'<script>alert(1)</script>'),'editable safe comparison');
    rejects124(fn()=>PageEditor::apply($app,$history,$preview,['body'=>'Правка без подтверждения']),'approval mandatory');
    $other=$preview;$other['created']=time()-1801;
    rejects124(fn()=>PageEditor::apply($app,$history,$other,['body'=>'X','confirm'=>'1']),'expired preview');
    $other=$preview;$other['owner_generation']=str_repeat('a',48);
    rejects124(fn()=>PageEditor::apply($app,$history,$other,['body'=>'X','confirm'=>'1']),'owner mismatch');
    $app->change(static function(array $s):array{$s['pages']['/']['body'].=' simultaneous edit';return $s;});
    rejects124(fn()=>PageEditor::apply($app,$history,$preview,['body'=>'Wrong','confirm'=>'1']),'concurrent page edit');
    assert124($history->all('/')===[],'no history on conflict');
    $original=$app->load()['pages']['/'];
    $preview=PageEditor::prepare($request,$app->load(),$client,$quota);
    $newText='Одобренный текст <img src=x onerror=alert(1)>';
    PageEditor::apply($app,$history,$preview,['body'=>$newText,'confirm'=>'1']);
    $saved=$app->load()['pages']['/'];
    assert124($saved['body']===$newText && $saved['published']===true && $saved['menu']===true &&
        $saved['path']==='/' && $saved['title']===$original['title'],'approved body edit, metadata unchanged');
    assert124(!str_contains($app->render($app->load(),$saved),'<img src=x onerror=alert(1)>'),'HTML escaped publicly');
    $snapshots=$history->all('/');
    assert124(count($snapshots)===1 && $snapshots[0]['page']===$original,'durable pre-edit snapshot');
    rejects124(fn()=>PageEditor::apply($app,$history,$preview,['body'=>'replay','confirm'=>'1']),'preview replay');
    rejects124(fn()=> $history->restore($app,'/',$snapshots[0]['id'],str_repeat('a',64)),'stale restore');
    $history->restore($app,'/',$snapshots[0]['id'],PageEditor::revision($saved));
    assert124($app->load()['pages']['/']===$original,'restoration of original');
    assert124(count($history->all('/'))===2,'restore itself undoable');
    // Explicit title edit must preserve text and description.
    $fields=['title'=>'Новый заголовок'];$request['scope']='title';$request['operation']='improve';
    $titlePreview=PageEditor::prepare($request,$app->load(),$client,$quota);
    PageEditor::apply($app,$history,$titlePreview,['title'=>'Проверенный заголовок','confirm'=>'1']);
    assert124($app->load()['pages']['/']['title']==='Проверенный заголовок' &&
        $app->load()['pages']['/']['body']===$original['body'],'title scope only');
    // Full rewrite can modify content/metadata but must not publish or rename.
    $fields=['title'=>'Все заголовки','description'=>'Описание после редакции','body'=>'Полный новый текст'];
    $request['scope']='all';$request['operation']='rewrite';
    $allPreview=PageEditor::prepare($request,$app->load(),$client,$quota);
    PageEditor::apply($app,$history,$allPreview,['title'=>$fields['title'],'description'=>$fields['description'],
        'body'=>$fields['body'],'path'=>'/new-path','published'=>'', 'confirm'=>'1']);
    assert124($app->load()['pages']['/']['path']==='/' && $app->load()['pages']['/']['published']===true &&
        $app->load()['pages']['/']['body']==='Полный новый текст','publication and path immutable');
    rejects124(fn()=>PageEditor::prepare(['path'=>'/missing','scope'=>'body','operation'=>'improve'],
        $app->load(),$client,$quota),'missing page');
    rejects124(fn()=>PageEditor::prepare(['path'=>'/','scope'=>'body','operation'=>'custom'],
        $app->load(),$client,$quota),'custom requires instruction');
    $tiny=$app->load();$tiny['ai']['max_input_chars']=100;
    rejects124(fn()=>PageEditor::prepare($request,$tiny,$client,$quota),'no silent truncation');
    $file=glob($root.'/storage/ai/page-history/*/*/*.json')[0]??null;
    assert124(is_string($file) && (fileperms($file)&0077)===0,'history is private');
    $foreign=new PageHistory($root,str_repeat('b',48));
    assert124($foreign->all('/')===[],'owner generation isolation');
    $fakeId=bin2hex(random_bytes(16));
    $unsafe=dirname($file).'/'.$fakeId.'.json';
    if (function_exists('symlink') && symlink($file,$unsafe)) {
        rejects124(fn()=>$history->get('/',$fakeId),'reject symlink history entry');
        rejects124(fn()=>$history->all('/'),'reject linked file in history list');
        unlink($unsafe);
    }
    // Twenty newest immutable snapshots are retained; pruning does not truncate the site.
    for ($i=0;$i<21;$i++) $history->save($app->load()['pages']['/']);
    assert124(count($history->all('/'))===20,'retention limit');
    $quotaStatus=$quota->status();
    assert124($quotaStatus['requests']===4 && $quotaStatus['tokens']===500,'quota accounting');
    echo "Lite 1.2.4 page AI revisions, approval, safe preview, rollback, concurrency, owner isolation and quotas OK\n";
} finally {rm124($root);}
