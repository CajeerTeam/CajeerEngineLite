<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Content\Blocks;
use CajeerEngine\Admin\{BlockEditor,PagesManagement,BlockEditorView};
use CajeerEngine\AI\{PageEditor,PageHistory};
use CajeerEngine\Storage\SiteState;
use CajeerEngine\Lite;
function check133(bool $good,string $reason):void {if(!$good)throw new RuntimeException('Block editor: '.$reason);}
function reject133(callable $run,string $label):void {
    try{$run();}catch(InvalidArgumentException|RuntimeException){return;}
    throw new RuntimeException('Expected invalid block: '.$label);
}
check133(version_compare(Lite::VERSION,'1.3.3','>=')&&SiteState::SCHEMA===14,'version/schema');
$blocks=[
    ['type'=>'heading','level'=>2,'text'=>'Заголовок <script>alert(1)</script>'],
    ['type'=>'paragraph','text'=>"Безопасный текст  \nВторая строка"],
    ['type'=>'list','ordered'=>true,'items'=>['первый','<img src=x onerror=alert(1)>']],
    ['type'=>'quote','text'=>'Цитата'],
    ['type'=>'link','text'=>'Наш раздел','url'=>'/about'],
    ['type'=>'button','text'=>'Документация','url'=>'https://example.org/docs'],
    ['type'=>'divider'],
];
$json=json_encode($blocks,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
check133(Blocks::decode($json)===$blocks,'round trip blocks');
$markup=Blocks::html($blocks);
check133(!str_contains($markup,'<script>')&&!str_contains($markup,'<img src=x'),'server-side HTML escaping');
check133(str_contains($markup,'&lt;script&gt;')&&str_contains($markup,'<h2>')&&str_contains($markup,'<ol')&&str_contains($markup,'href="/about"'),'typed markup');
foreach (['javascript:alert(1)','data:text/html,evil','//evil.example','http://unsecured.test','https://user:pass@example.org','/../config'] as $url) {
    reject133(fn()=>Blocks::validate([['type'=>'link','text'=>'x','url'=>$url]]),'unsafe URL '.$url);
}
reject133(fn()=>Blocks::decode('{malformed'),'invalid JSON');
reject133(fn()=>Blocks::validate([['type'=>'paragraph','text'=>'okay','evil'=>'x']]),'unknown attributes');
reject133(fn()=>Blocks::validate([['type'=>'heading','level'=>1,'text'=>'no']]),'heading h1 forbidden');
reject133(fn()=>Blocks::validate([['type'=>'paragraph','text'=>"bad\0text"]]),'nul bytes');
reject133(fn()=>Blocks::validate(array_fill(0,121,['type'=>'divider'])),'block limit');
reject133(fn()=>Blocks::validate([['type'=>'paragraph','text'=>str_repeat('x',60001)]]),'body limit');
$base=sys_get_temp_dir().'/ce-block-133-'.bin2hex(random_bytes(5));mkdir($base);mkdir($base.'/storage');mkdir($base.'/templates');mkdir($base.'/templates/default');copy(dirname(__DIR__).'/templates/default/page.cjr',$base.'/templates/default/page.cjr');
try {
    $app=new Lite($base);$app->install('BlockEditorPassword133!','http://localhost:9133','Block Demo');
    check133($app->load()['schema']===14,'fresh schema');
    $home=$app->load()['pages']['/'];$home['body']="untouched  \n";
    $app->change(static function($d)use($home){$d['pages']['/']=$home;return $d;});
    $legacy=$app->load();$old=$legacy;$old['schema']=13;
    check133(SiteState::migrate($old)['pages']['/']['body']==="untouched  \n",'migration preserves old whitespace');
    $candidate=BlockEditor::candidate(['path'=>'/about','title'=>'О нас','description'=>'Наш сайт',
        'blocks_json'=>$json,'menu'=>'1','published'=>'1']);
    check133($candidate['body']===Blocks::plain($blocks),'body mirror');
    $saved=BlockEditor::save($app,['path'=>'/about','title'=>'О нас','description'=>'Наш сайт',
        'blocks_json'=>$json,'menu'=>'1','published'=>'1','old'=>'','revision'=>'']);
    check133($saved==='/about','created path');
    $stored=$app->load()['pages']['/about'];
    check133($stored['blocks']===$blocks&&$stored['body']===Blocks::plain($blocks),'persistent blocks with body');
    $html=$app->render($app->load(),$stored);
    check133(str_contains($html,'&lt;script&gt;')&&!str_contains($html,'<script>alert(1)</script>'),'template renders escaped blocks');
    $oldRev=PagesManagement::revision($stored);
    $post=['path'=>'/about','title'=>'Новый заголовок','description'=>'Описание','blocks_json'=>$json,
        'menu'=>'1','published'=>'1','old'=>'/about','revision'=>$oldRev];
    BlockEditor::save($app,$post);
    reject133(fn()=>BlockEditor::save($app,$post),'stale optimistic revision');
    $after=$app->load();check133($after['pages']['/about']['title']==='Новый заголовок','saved update');
    $history=new PageHistory($base,$after['identity']['generation']);
    $snapshots=$history->all('/about');
    check133(count($snapshots)>=1&&$snapshots[0]['page']['blocks']===$blocks,'history retains block version');
    $history->restore($app,'/about',$snapshots[0]['id'],PagesManagement::revision($after['pages']['/about']));
    check133($app->load()['pages']['/about']===$stored,'history restore exact blocks');
    // Near-limit multibyte JSON snapshots must remain undoable.
    $largeJson=json_encode([['type'=>'paragraph','text'=>str_repeat("\n",58000)]],JSON_THROW_ON_ERROR);
    BlockEditor::save($app,['path'=>'/large','title'=>'Large','description'=>'','blocks_json'=>$largeJson,
        'old'=>'','revision'=>'']);
    $large=$app->load()['pages']['/large'];
    BlockEditor::save($app,['path'=>'/large','title'=>'Large edited','description'=>'','blocks_json'=>$largeJson,
        'old'=>'/large','revision'=>PagesManagement::revision($large)]);
    $largeSnapshots=$history->all('/large');
    check133($largeSnapshots[0]['page']['blocks']===$large['blocks'], 'large block snapshot can be read');
    $updated=$stored;$updated['title']='Another';
    check133(Blocks::reconcile($stored,$updated)['blocks']===$blocks,'metadata keeps blocks');
    $updated['body']='Rewritten by AI';
    check133(!isset(Blocks::reconcile($stored,$updated)['blocks']),'AI body rewrite removes blocks');
    reject133(fn()=>BlockEditor::save($app,['path'=>'/','title'=>'Home','description'=>'',
        'blocks_json'=>json_encode([['type'=>'paragraph','text'=>'Home']]),'old'=>'/','revision'=>PagesManagement::revision($home)]),'cannot unpublish home');
    $editor=BlockEditorView::render($stored,str_repeat('a',64),true);
    check133(str_contains($editor,'cms-editor-form')&&str_contains($editor,'cms-server-preview')&&
        !str_contains($editor,'<script>alert(1)</script>'),'editor form and safe initial data');
    echo "Enhanced Content Editor 1.3.3: schema14 migration, safe blocks, rendering, atomic save, conflicts, history and AI reconciliation OK\n";
}finally {
    $iter=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iter as $item){if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}
    rmdir($base);
}
