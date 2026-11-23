<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use CajeerEngine\Lite;
use CajeerEngine\SEO\{SEOControl,SEOControlView};
use CajeerEngine\Admin\{PagesManagement,BlockEditor};
use CajeerEngine\Media\MediaLibrary;
use CajeerEngine\AI\{SEOAudit,PageHistory};
use CajeerEngine\Storage\SiteState;

function ok136(bool $value,string $message):void { if (!$value) throw new RuntimeException('SEO 1.3.6: '.$message); }
function bad136(callable $fn,string $message):void {
    try { $fn(); } catch (InvalidArgumentException|RuntimeException) {return;}
    throw new RuntimeException('SEO 1.3.6 expected failure: '.$message);
}
function clean136(string $root):void {
    $iter=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iter as $item) { if ($item->isDir()&&!$item->isLink()) rmdir($item->getPathname()); else unlink($item->getPathname()); }
    rmdir($root);
}
$root=sys_get_temp_dir().'/seo136-'.bin2hex(random_bytes(6));
mkdir($root);mkdir($root.'/storage');mkdir($root.'/templates');mkdir($root.'/templates/default');
copy(dirname(__DIR__).'/templates/default/page.cjr',$root.'/templates/default/page.cjr');
try {
    $app=new Lite($root);
    $app->install('RealSeo136Password!','https://example.org','Example Company');
    $state=$app->load();
    ok136(version_compare(Lite::VERSION,'1.3.6','>=') && SiteState::SCHEMA===14,'compatible release version/schema');
    ok136(SEOControl::site($state)===SEOControl::GLOBAL_DEFAULTS,'site defaults persisted');
    ok136(str_contains(SEOControl::sitemapXml($state),'<loc>https://example.org/</loc>'),'home in sitemap');
    ok136(str_contains(SEOControl::robotsText($state),'Sitemap: https://example.org/sitemap.xml'),'sitemap discovery');
    $legacy=$state;unset($legacy['seo']);
    SiteState::validate($legacy);
    ok136(SEOControl::site($legacy)===SEOControl::GLOBAL_DEFAULTS,'old schema 14 site compatible');
    $legacy['pages']['/']['seo']=SEOControl::PAGE_DEFAULTS;
    SiteState::validate($legacy);
    $state['pages']['/news']=Lite::page(['path'=>'/news','title'=>'News & Headlines','description'=>'Today details',
        'body'=>'Articles', 'published'=>'1','menu'=>'1']);
    $app->change(static fn(array $d):array=>[...$d,'pages'=>$state['pages']]);
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGOUT9rBwMDAxMDAwMDAAAAOPQE9xgjZwQAAAABJRU5ErkJggg==',true);
    file_put_contents($root.'/icon.png',$png);
    $lib=new MediaLibrary($root);
    $image=$lib->ingest($root.'/icon.png','icon.png');$imageId=$image['id'];
    ok136(strlen(SEOControl::revision($state))===64,'site global revision');
    $global=SEOControl::globalFromPost(['indexable'=>'1','title_suffix'=>'1','default_image'=>$imageId]);
    $page=SEOControl::pageFromPost(['index'=>'1','follow'=>'1','sitemap'=>'1','canonical'=>'/',
        'og_title'=>'Custom <title> & SEO','og_description'=>'Social <script>text</script>',
        'og_image'=>$imageId,'changefreq'=>'weekly','priority'=>'8']);
    $lib->guard(static function()use($lib,$app,$page,$global):void {
        SEOControl::verifyImage($lib,$global['default_image']);
        SEOControl::verifyImage($lib,$page['og_image']);
        $app->change(static function(array $d)use($page,$global):array {
            $d['seo']=$global;
            $d['pages']['/news']['seo']=$page;
            return $d;
        });
    });
    $state=$app->load();
    ok136(!str_contains(SEOControl::sitemapXml($state),'<loc>https://example.org/news</loc>'),'alternate canonical excluded');
    ok136(SEOControl::presentation($state,$state['pages']['/news'])['canonical']==='https://example.org/','canonical published internal');
    $html=$app->render($state,$state['pages']['/news']);
    ok136(str_contains($html,'<link rel="canonical" href="https://example.org/">'),'public canonical');
    ok136(str_contains($html,'content="Custom &lt;title&gt; &amp; SEO"'),'OG title HTML safe');
    ok136(str_contains($html,'content="Social &lt;script&gt;text&lt;/script&gt;"'),'OG description safe');
    ok136(str_contains($html,'content="https://example.org/media/'.$imageId.'"'),'OG media URL');
    ok136(!str_contains($html,'<script type="application/ld+json">'),'no duplicate structured data for alternate canonical');
    $homeHtml=$app->render($state,$state['pages']['/']);
    ok136(str_contains($homeHtml,'<script type="application/ld+json">') && !str_contains($homeHtml,'</script><script>'),'canonical public JSON-LD safe');
    ok136(str_contains($html,'twitter:card" content="summary_large_image"'),'twitter card image');
    ok136(in_array('/news',\CajeerEngine\Content\MediaReferences::pages($state['pages'],$imageId),true),'SEO media usage tracked');
    bad136(static fn()=>$lib->delete($imageId),'cannot delete referenced SEO image');
    $view=SEOControlView::render($state,'deadbeef','/news');
    ok136(str_contains($view,'SEO Control Center') && str_contains($view,'name="revision"'),'working admin UI with revisions');
    ok136(str_contains($view,'Custom &lt;title&gt; &amp; SEO'),'safe admin UI');
    ok136(in_array('alternate_canonical',array_column(SEOAudit::run($state)['pages']['/news']['issues'],'code'),true),'audit notes canonical');
    $oldRevision=PagesManagement::revision($state['pages']['/news']);
    $saved=BlockEditor::save($app,['path'=>'/news','old'=>'/news','revision'=>$oldRevision,
        'title'=>'New page title','description'=>'New description','blocks_json'=>'[{"type":"paragraph","text":"Updated copy"}]',
        'published'=>'1','menu'=>'1']);
    ok136($saved==='/news' && $app->load()['pages']['/news']['seo']===$page,'block editor retains SEO');
    $history=new PageHistory($root,$app->load()['identity']['generation']);
    $entries=$history->all('/news');
    ok136(count($entries)>0 && $entries[0]['page']['seo']===$page,'page history snapshots SEO');
    bad136(static fn()=>SEOControl::pageFromPost(['canonical'=>'//evil.com','priority'=>'5','changefreq'=>'daily']),'bad canonical');
    bad136(static fn()=>SEOControl::pageFromPost(['priority'=>'11','changefreq'=>'daily']),'bad priority');
    bad136(static fn()=>SEOControl::pageFromPost(['priority'=>'5','changefreq'=>'javascript:bad']),'bad frequency');
    bad136(static fn()=>SEOControl::globalFromPost(['default_image'=>str_repeat('f',32),'indexable'=>'evil']),'bad global checkbox');
    bad136(static fn()=>SEOControl::verifyImage($lib,str_repeat('f',32)),'missing image');
    $app->change(static function(array $s):array {$s['seo']['indexable']=false;return $s;});
    $state=$app->load();
    ok136(str_contains(SEOControl::robotsText($state),"Disallow: /\n"),'sitewide robots disabled');
    ok136(!str_contains(SEOControl::robotsText($state),'Sitemap:'),'sitewide sitemap disclosure off');
    ok136(!str_contains(SEOControl::sitemapXml($state),'<url>'),'sitewide sitemap empty');
    ok136(str_contains($app->render($state,$state['pages']['/news']),'name="robots" content="noindex, follow"'),'sitewide noindex meta');
    echo "SEO Control Center 1.3.6: persisted configuration, legacy sites, admin HTML, canonical, OG, JSON-LD, robots, sitemap, audit, media safety, editor and history OK\n";
} finally {clean136($root);}
