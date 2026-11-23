<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Content\{Blocks,MediaReferences};
use CajeerEngine\Media\{MediaLibrary,MediaView};
use CajeerEngine\Admin\{BlockEditor,BlockEditorView,PagesManagement};
use CajeerEngine\AI\PageHistory;

function ck135(bool $ok,string $label): void {if(!$ok) throw new RuntimeException('Failure: '.$label);}
function rej135(callable $run,string $label): void {
    try{$run();}catch(InvalidArgumentException|RuntimeException){return;}
    throw new RuntimeException('Expected rejection: '.$label);
}
function del135(string $dir): void {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}
    rmdir($dir);
}
ck135(Lite::VERSION==='1.3.5','version');
$root=sys_get_temp_dir().'/lite135-'.bin2hex(random_bytes(5));
mkdir($root);mkdir($root.'/storage');mkdir($root.'/templates');mkdir($root.'/templates/default');
copy(dirname(__DIR__).'/templates/default/page.cjr',$root.'/templates/default/page.cjr');
try {
    $app=new Lite($root);
    $app->install('TestMedia135Password!','http://localhost:9135','Media Integration');
    $lib=new MediaLibrary($root);
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGOUT9rBwMDAxMDAwMDAAAAOPQE9xgjZwQAAAABJRU5ErkJggg==',true);
    file_put_contents($root.'/img.png',$png);$image=$lib->ingest($root.'/img.png','Picture & "quoted".png');
    file_put_contents($root.'/test.pdf',"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    $pdf=$lib->ingest($root.'/test.pdf','Guide.pdf');
    $imageBlock=['type'=>'image','media_id'=>$image['id'],'alt'=>'Alt <script>alert(1)</script>',
        'caption'=>'Caption & <img onerror=alert(1)>','size'=>'medium'];
    $fileBlock=['type'=>'file','media_id'=>$pdf['id'],'label'=>'PDF & <script>alert(2)</script>'];
    $blocks=[$imageBlock,$fileBlock,['type'=>'paragraph','text'=>'After media']];
    ck135(Blocks::validate($blocks)===$blocks,'valid image and file blocks');
    ck135(Blocks::plain($blocks)==="Caption & <img onerror=alert(1)>\n\nPDF & <script>alert(2)</script>\n\nAfter media",'plain search mirror');
    $html=Blocks::html($blocks);
    ck135(str_contains($html,'src="/media/'.$image['id'].'"') && str_contains($html,'cms-media-size-medium'),'render image URL and size');
    ck135(str_contains($html,'href="/media/'.$pdf['id'].'?download=1"'),'PDF download link');
    ck135(!str_contains($html,'<script>') && !str_contains($html,'<img onerror') &&
        str_contains($html,'&lt;script&gt;') && str_contains($html,'&lt;img onerror='),'HTML escaped alt captions and label');
    $rejected=[
        [['type'=>'image','media_id'=>'/etc/passwd','alt'=>'','caption'=>'','size'=>'full']],'bad ID',
    ];
    rej135(fn()=>Blocks::validate([['type'=>'image','media_id'=>'/etc/passwd','alt'=>'','caption'=>'','size'=>'full']]),'media ID path');
    rej135(fn()=>Blocks::validate([['type'=>'image','media_id'=>$image['id'],'alt'=>'','caption'=>'','size'=>'evil']]),'image size injection');
    rej135(fn()=>Blocks::validate([['type'=>'file','media_id'=>$pdf['id'],'label'=>'']]),'empty PDF caption');
    rej135(fn()=>Blocks::validate([['type'=>'image','media_id'=>$image['id'],'alt'=>'','caption'=>'','size'=>'full','html'=>'evil']]),'extra HTML');
    rej135(fn()=>Blocks::validate([['type'=>'file','media_id'=>$pdf['id'],'label'=>'PDF','url'=>'javascript:evil()']]),'URL injection');
    rej135(fn()=>Blocks::validate([['type'=>'image','media_id'=>$image['id'],'alt'=>"bad\0",'caption'=>'','size'=>'full']]),'null byte');
    rej135(fn()=>MediaReferences::verify($lib,[['type'=>'image','media_id'=>$pdf['id']]]),'wrong MIME for image');
    rej135(fn()=>MediaReferences::verify($lib,[['type'=>'file','media_id'=>$image['id']]]),'wrong MIME for PDF');
    rej135(fn()=>MediaReferences::verify($lib,[['type'=>'image','media_id'=>str_repeat('a',32)]]),'missing image');
    rej135(fn()=>MediaReferences::verify($lib,[['type'=>'image','media_id'=>$image['id']],['type'=>'file','media_id'=>$image['id']]]),'conflicting use');
    $json=json_encode($blocks,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $saved=BlockEditor::save($app,['path'=>'/media-test','title'=>'Media Page','description'=>'Media',
        'blocks_json'=>$json,'published'=>'1','old'=>'','revision'=>'']);
    ck135($saved==='/media-test','saved media page');
    $page=$app->load()['pages']['/media-test'];
    ck135($page['blocks']===$blocks,'stored unchanged blocks');
    ck135(MediaReferences::pages($app->load()['pages'],$image['id'])===['/media-test'],'usage from current pages');
    ck135(str_contains($app->render($app->load(),$page),'cms-media-figure'),'public page renders image');
    $view=BlockEditorView::render($page,str_repeat('a',64),true,'',$lib->listing());
    ck135(str_contains($view,'cms-editor-media-json')&&str_contains($view,$image['id'])&&str_contains($view,$pdf['id']), 'picker offers actual media');
    ck135(!str_contains($view,'Picture & "quoted".png'),'XSS-safe editor media data');
    $gallery=MediaView::render($lib,str_repeat('a',64),'','all','',$app->load()['pages']);
    ck135(str_contains($gallery,'Используется на 1 страницах')&&str_contains($gallery,'disabled title="Файл используется на страницах"'),'usage visible and deletion disabled');
    rej135(fn()=>$lib->delete($image['id']),'cannot delete image in published page');
    rej135(fn()=>$lib->delete($pdf['id']),'cannot delete PDF in published page');
    $rejectedBlocks=[['type'=>'image','media_id'=>str_repeat('f',32),'alt'=>'','caption'=>'','size'=>'full']];
    rej135(fn()=>BlockEditor::save($app,['path'=>'/missing','title'=>'Missing','blocks_json'=>json_encode($rejectedBlocks),
        'old'=>'','revision'=>'']),'cannot save dangling image');
    rej135(fn()=>BlockEditor::save($app,['path'=>'/wrong','title'=>'Wrong','blocks_json'=>json_encode([['type'=>'file','media_id'=>$image['id'],'label'=>'Wrong']]),
        'old'=>'','revision'=>'']),'cannot reference image as PDF');
    // A draft also protects used files. Moving a published page to draft does not drop references.
    $rev=PagesManagement::revision($page);
    $app->change(static fn($d)=>PagesManagement::status($d,'/media-test',$rev,false));
    rej135(fn()=>$lib->delete($image['id']),'draft still uses media');
    $snapshot=$app->load()['pages']['/media-test'];
    $textOnly=json_encode([['type'=>'paragraph','text'=>'No media']]);
    BlockEditor::save($app,['path'=>'/media-test','title'=>'Media Page','description'=>'',
        'blocks_json'=>$textOnly,'old'=>'/media-test','revision'=>PagesManagement::revision($snapshot)]);
    $history=new PageHistory($root,$app->load()['identity']['generation']);
    $versions=$history->all('/media-test');
    ck135(count($versions)>=1 && $versions[0]['page']['blocks']===$blocks,'media block history snapshots');
    $lib->delete($image['id']);$lib->delete($pdf['id']);
    ck135($lib->read($image['id'])===null,'unreferenced media removable');
    rej135(fn()=>$history->restore($app,'/media-test',$versions[0]['id'],PagesManagement::revision($app->load()['pages']['/media-test'])),
        'history cannot restore missing images');
    ck135($app->load()['pages']['/media-test']['body']==='No media','rejected restore preserves page');
    ck135($app->load()['schema']===14,'no new schema migration');
    echo "Media Content Integration 1.3.5: real media blocks, safe HTML, editor picker, atomic references, draft protection, deletion and history restore OK\n";
} finally {del135($root);}
