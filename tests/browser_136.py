#!/usr/bin/env python3
"""SEO 1.3.6 Chromium: real controls, visible content, escaping and mobile layout."""
import shutil
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parent.parent
php=r'''require 'bootstrap.php';
$root=sys_get_temp_dir().'/seo136-browser-'.bin2hex(random_bytes(5));
mkdir($root);mkdir($root.'/storage');
try {
 $app=new \CajeerEngine\Lite($root);
 $app->install('BrowserSeo136Password!','https://example.com','Site & <unsafe>');
 $state=$app->load();
 $state['pages']['/'] ['description']='Description with <script>alert(1)</script>';
 echo \CajeerEngine\SEO\SEOControlView::render($state,str_repeat('f',64),'/');
} finally {
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}
 rmdir($root);
}'''
form=subprocess.check_output(['php','-r',php],cwd=root,text=True)
css=(root/'public/assets/style.css').read_text()+'\n'+(root/'public/assets/admin.css').read_text()
html=f'<!doctype html><html lang="ru"><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>{css}</style></head><body class="admin-body"><main class="admin-main">{form}</main></body></html>'
with sync_playwright() as playwright:
    browser=playwright.chromium.launch(executable_path=shutil.which('chromium') or playwright.chromium.executable_path,headless=True,args=['--no-sandbox','--disable-gpu'])
    page=browser.new_page(viewport={'width':1280,'height':900})
    page.set_content(html)
    assert page.locator('.seo-control').count()==1
    assert page.locator('form[method="post"][action="/admin/seo"]').count()==2
    assert page.locator('form[method="post"][action="/admin/seo"] input[name="revision"]').count()==2
    assert page.locator('input[name="canonical"]').count()==1
    assert page.locator('input[name="default_image"]').count()==1
    assert page.locator('select[name="changefreq"] option').count()==7
    assert page.locator('script').count()==0
    assert '<script>alert(1)</script>' not in page.locator('body').inner_html()
    assert page.locator('code').count()>0
    for width in (1280,768,390,320):
        page.set_viewport_size({'width':width,'height':900})
        assert page.locator('body').evaluate('(node)=>node.scrollWidth <= window.innerWidth+2'),f'horizontal overflow at {width}px'
        assert page.locator('input[name="canonical"]').is_visible()
        assert page.locator('form[method="post"][action="/admin/seo"]').first.locator('button').is_enabled()
    browser.close()
print('SEO Control Center 1.3.6 Chromium: real server-rendered controls, safe output, responsive 1280/768/390/320 OK')
