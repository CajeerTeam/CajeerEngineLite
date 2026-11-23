<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use CajeerEngine\Lite;
use CajeerEngine\Developer\{DeveloperDiagnostics,TemplateManager,DeveloperEvents,DeveloperView};
use CajeerEngine\Storage\SiteState;

function require137(bool $yes,string $message):void { if (!$yes) throw new RuntimeException('Developer 1.3.7: '.$message); }
function rejects137(callable $cb,string $message):void {
    try { $cb(); } catch (Throwable) {return;}
    throw new RuntimeException('Developer 1.3.7: must reject '.$message);
}
function clean137(string $root):void {
    $iter=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iter as $item) { if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname()); else unlink($item->getPathname()); }
    rmdir($root);
}
$root=sys_get_temp_dir().'/developer137-'.bin2hex(random_bytes(6));
mkdir($root);mkdir($root.'/storage');mkdir($root.'/templates');mkdir($root.'/templates/default');mkdir($root.'/public');
copy(dirname(__DIR__).'/templates/default/page.cjr',$root.'/templates/default/page.cjr');
$previous=getenv('CAJEER_DEVELOPER_TEMPLATE_WRITE');
try {
    putenv('CAJEER_DEVELOPER_TEMPLATE_WRITE');
    $app=new Lite($root);
    $app->install('Developer137Password!','https://example.test','Company');
    $state=$app->load();
    require137(Lite::VERSION==='1.3.8' && SiteState::SCHEMA===14,'version and stable JSON schema');
    $tool=new TemplateManager($root);
    require137($tool->names()===['default/page.cjr'],'list existing templates');
    rejects137(fn()=>$tool->path('../storage/site.json'),'path traversal');
    rejects137(fn()=>$tool->path('default/missing.cjr'),'missing template');
    $template=$tool->read('default/page.cjr');
    require137(strlen($template['revision'])===64 && !$tool->writable(),'hash and safe default');
    $src=$template['source'];
    $new=str_replace('Работает на CajeerEngine Lite','DEVELOPER-137-TEST',$src);
    rejects137(fn()=>$tool->save('default/page.cjr',$new,$template['revision']),'writing disabled');
    $preview=$tool->preview($app,$state,'default/page.cjr',$new,'/');
    require137(str_contains($preview,'DEVELOPER-137-TEST'),'in-memory live preview');
    require137(file_get_contents($root.'/templates/default/page.cjr')===$src,'preview cannot change live template');
    rejects137(fn()=>$tool->preview($app,$state,'default/page.cjr',$new,'/draft'),'preview unpublished/missing');
    rejects137(fn()=>$tool->validateSource('<?php echo getenv("SECRET"); ?>'),'raw PHP forbidden');
    rejects137(fn()=>$tool->validateSource("@if (\$site)\nunclosed"),'invalid compiled syntax');
    rejects137(fn()=>$tool->validateSource(str_repeat('x',131073)),'source size');
    $report=DeveloperDiagnostics::run($root,$state);
    require137($report['ok'] && in_array('templates.render',array_column($report['checks'],'id'),true),'real diagnostics and page render');
    require137(str_contains(json_encode($report,JSON_THROW_ON_ERROR),'templates.syntax'),'JSON check export');
    require137(!str_contains(json_encode($report,JSON_THROW_ON_ERROR),'Developer137Password!'),'no secrets in report');
    $html=DeveloperView::render($root,$state,'csrf-token','diagnostics','');
    require137(str_contains($html,'runtime.php') && str_contains($html,'storage.private'),'visible checks');
    $html=DeveloperView::render($root,$state,'csrf-token','templates','default/page.cjr');
    require137(str_contains($html,'name="source"') && str_contains($html,'formaction="/admin/developer/preview"'),'working editor form');
    require137(!str_contains($html,'value="save"'),'save not rendered without enablement');
    putenv('CAJEER_DEVELOPER_TEMPLATE_WRITE=1');
    require137($tool->writable(),'explicit opt-in works');
    $tool->save('default/page.cjr',$new,$template['revision']);
    $later=$tool->read('default/page.cjr');
    require137($later['source']===$new && $later['revision']!==$template['revision'],'atomic save');
    $snapshots=$tool->history('default/page.cjr');
    require137(count($snapshots)===1,'backup written');
    rejects137(fn()=>$tool->save('default/page.cjr','invalid',$template['revision']),'stale revision');
    rejects137(fn()=>$tool->save('default/page.cjr','@if ($site)',$later['revision']),'syntax error');
    require137($tool->read('default/page.cjr')['source']===$new,'invalid save preserves site');
    $tool->restore('default/page.cjr',$snapshots[0],$later['revision']);
    require137($tool->read('default/page.cjr')['source']===$src,'rollback restores original');
    require137(count($tool->history('default/page.cjr'))===2,'rollback preserves the replaced version');
    DeveloperEvents::record($root,'template.saved');
    DeveloperEvents::record($root,'runtime.unhandled','error');
    $events=DeveloperEvents::recent($root);
    require137(count($events)===2 && $events[0]['event']==='runtime.unhandled' && strlen($events[0]['id'])===12,'structured event log');
    rejects137(fn()=>DeveloperEvents::record($root,'secret/value','error'),'event allowlist');
    $html=DeveloperView::render($root,$state,'csrf-token','events','');
    require137(str_contains($html,'runtime.unhandled') && !str_contains($html,'Developer137Password!'),'safe events view');
    $historyPath=$root.'/storage/app/developer/template-history/'.hash('sha256','default/page.cjr');
    require137((fileperms($historyPath)&0077)===0,'history directory private');
    require137((fileperms($historyPath.'/'.$tool->history('default/page.cjr')[0])&0077)===0,'history file private');
    echo "Developer Tools 1.3.7: real diagnostics, safe output, in-memory preview, file lock, CAS, rollback, event audit and security OK\n";
} finally {
    if ($previous===false) putenv('CAJEER_DEVELOPER_TEMPLATE_WRITE'); else putenv('CAJEER_DEVELOPER_TEMPLATE_WRITE='.$previous);
    clean137($root);
}
