<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Admin\PagesManagement as P;
use CajeerEngine\Lite;
use CajeerEngine\Storage\SiteState;
function check132(bool $value, string $message): void { if (!$value) throw new RuntimeException('Pages Management: '.$message); }
function reject132(callable $test, string $message): void {
    try { $test(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('Pages Management: expected rejection: '.$message);
}
check132(Lite::VERSION==='1.3.2' && SiteState::SCHEMA===13,'version and compatible schema');
$root=sys_get_temp_dir().'/lite-pages132-'.bin2hex(random_bytes(5));
mkdir($root);mkdir($root.'/storage');
try {
    $site=new Lite($root);
    $site->install('PagesTestPassword132!','http://localhost:9132','Pages CMS');
    $base=$site->load();
    $base['pages']['/archive']=['path'=>'/archive','title'=>'Архив','description'=>'старые данные','body'=>"unchanged  \n",'published'=>false,'menu'=>false];
    $base['pages']['/about']=['path'=>'/about','title'=>'О нас <script>x</script>','description'=>'Контакты','body'=>'A <b>raw</b>','published'=>true,'menu'=>true];
    $base['pages']['/news']=['path'=>'/news','title'=>'Новости','description'=>'Описание','body'=>'Новости  ','published'=>false,'menu'=>false];
    check132(P::listing($base['pages'],[])['total']===4,'default listing');
    $q=P::listing($base['pages'],['q'=>'АРХИВ','status'=>'draft']);
    check132($q['total']===1 && $q['rows'][0]['path']==='/archive','Unicode case-insensitive fallback');
    check132(P::listing($base['pages'],['q'=>'КОНТАКТЫ'])['rows'][0]['path']==='/about','description search');
    check132(P::listing($base['pages'],['status'=>'published'])['total']===2,'published filter');
    check132(P::listing($base['pages'],['status'=>'draft'])['total']===2,'draft filter');
    check132(P::listing($base['pages'],['sort'=>'draft'])['rows'][0]['published']===false,'draft-first sorting');
    check132(P::listing($base['pages'],['sort'=>'path','dir'=>'desc'])['rows'][0]['path']==='/news','descending path sort');
    $large=[];
    for ($i=0;$i<36;$i++) {
        $path='/item-'.$i;$large[$path]=['path'=>$path,'title'=>'Test '.$i,'description'=>'','body'=>'','published'=>false,'menu'=>false];
    }
    $page2=P::listing($large,['sort'=>'path','page'=>'2','per_page'=>'10']);
    check132($page2['total']===36 && count($page2['rows'])===10 && $page2['page']===2 && $page2['pages']===4,'real pagination');
    check132(P::listing($large,['page'=>'9999','per_page'=>'10'])['page']===4,'bounds pagination');
    foreach ([['status'=>'unknown'],['sort'=>'DROP TABLE'],['dir'=>'no'],['q'=>['x']],['page'=>'0'],['page'=>'-2'],['per_page'=>'1'],['per_page'=>'1000'],['q'=>str_repeat('x',121)]] as $params) {
        reject132(fn()=>P::listing($base['pages'],$params),'malformed query '.json_encode($params));
    }
    $html=P::render($base['pages'],['q'=>'<script>x</script>'],str_repeat('a',64));
    check132(str_contains($html,'&lt;script&gt;') && !str_contains($html,'<script>x</script>'),'escape search query');
    $html=P::render($base['pages'],[],str_repeat('a',64));
    check132(!str_contains($html,'<script>x</script>') && str_contains($html,'О нас &lt;script&gt;x&lt;/script&gt;'),'escape titles');
    check132(str_contains($html,'name="selected[]"') && str_contains($html,'name="action" value="pages_bulk"'),'working bulk form');
    $oldRev=P::revision($base['pages']['/archive']);
    $next=P::status($base,'/archive',$oldRev,true);
    check132($next['pages']['/archive']['published'] && !$base['pages']['/archive']['published'],'single publish immutable input');
    check132($next['pages']['/archive']['body']===$base['pages']['/archive']['body'],'unmodified body preserved byte-for-byte');
    reject132(fn()=>P::status($next,'/archive',$oldRev,false),'stale single revision');
    reject132(fn()=>P::status($base,'/',P::revision($base['pages']['/']),false),'homepage protected');
    $selected=['/archive','/news'];
    $revs=[];
    foreach($selected as $path) $revs[hash('sha256',$path)]=P::revision($base['pages'][$path]);
    $bulk=P::bulk($base,$selected,$revs,'publish');
    check132($bulk['pages']['/archive']['published'] && $bulk['pages']['/news']['published'],'atomic bulk publish');
    $draft=P::bulk($bulk,$selected,array_combine(array_map('hashPath132',$selected),array_map(static fn($path): string => P::revision($bulk['pages'][$path]),$selected)),'draft');
    check132(!$draft['pages']['/archive']['published'] && !$draft['pages']['/news']['published'],'atomic bulk unpublish');
    reject132(fn()=>P::bulk($base,['/archive','/news'],[$revs[hash('sha256','/archive')]=> 'bad'],'publish'),'missing revisions');
    reject132(fn()=>P::bulk($base,['/archive','/archive'],$revs,'publish'),'duplicate selected path');
    reject132(fn()=>P::bulk($base,['/'],$revs,'draft'),'home bulk protected');
    reject132(fn()=>P::bulk($base,['/archive','/bad'],$revs,'publish'),'no partial update on invalid path');
    reject132(fn()=>P::bulk($base,[],[],'publish'),'empty selection');
    reject132(fn()=>P::bulk($base,['/archive'],$revs,'delete'),'unknown operation');
    $copy=P::duplicate($base,'/archive',$oldRev);
    check132(isset($copy['pages']['/archive-copy']) && !$copy['pages']['/archive-copy']['published'] && !$copy['pages']['/archive-copy']['menu'],'duplicate creates private draft');
    check132($copy['pages']['/archive-copy']['body']===$base['pages']['/archive']['body'],'duplicate preserves body');
    $copy2=P::duplicate($copy,'/archive',$oldRev);
    check132(isset($copy2['pages']['/archive-copy-2']),'duplicate collision generates stable new path');
    $homeCopy=P::duplicate($base,'/',P::revision($base['pages']['/']));
    check132(isset($homeCopy['pages']['/home-copy']) && !$homeCopy['pages']['/home-copy']['published'],'homepage copy does not replace root');
    reject132(fn()=>P::duplicate($next,'/archive',$oldRev),'stale duplicate revision');
    $full=$base;
    for($i=0;count($full['pages'])<100;$i++) {$p='/f'.$i;$full['pages'][$p]=['path'=>$p,'title'=>'F','description'=>'','body'=>'','published'=>false,'menu'=>false];}
    reject132(fn()=>P::duplicate($full,'/archive',$oldRev),'page count limit');
    $site->change(static fn(array $_):array => $base);
    $site->change(static fn(array $d):array => P::bulk($d,$selected,$revs,'publish'));
    check132($site->load()['pages']['/archive']['published'],'real JSON write under lock');
    check132($site->load()['pages']['/archive']['body']==="unchanged  \n",'whitespace preserved after real JSON transaction');
    echo "Pages Management 1.3.2 filtering, UTF-8, pagination, form XSS, revisions, bulk, duplicate, 100 limit and atomic persistence OK\n";
} finally {
    foreach (glob($root.'/storage/*') ?: [] as $path) if(is_file($path)) unlink($path);
    @rmdir($root.'/storage');@rmdir($root);
}
function hashPath132(string $path): string {return hash('sha256',$path);}
