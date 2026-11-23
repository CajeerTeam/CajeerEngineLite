#!/usr/bin/env python3
"""CMS Operations 1.3.8: actual PHP-rendered controls and responsive layout in Chromium."""
import shutil
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parent.parent
php=r'''require 'bootstrap.php';
$root=sys_get_temp_dir().'/operations138-browser-'.bin2hex(random_bytes(5));
mkdir($root);mkdir($root.'/storage');
try {
 $app=new \CajeerEngine\Lite($root);
 $app->install('BrowserOps138Password!','http://127.0.0.1:8080','Unsafe <site>');
 $ops=new \CajeerEngine\Operations\CmsOperations($root);
 $ops->createBackup();
 echo \CajeerEngine\Operations\OperationsView::render($ops,str_repeat('f',64),'','Saved <script>alert(1)</script>');
} finally {
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}
 rmdir($root);
}'''
form=subprocess.check_output(['php','-r',php],cwd=root,text=True)
css=(root/'public/assets/style.css').read_text()+'\n'+(root/'public/assets/admin.css').read_text()
html=f'<!doctype html><html lang="ru"><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>{css}</style></head><body class="admin-body"><main class="admin-main">{form}</main></body></html>'
with sync_playwright() as playwright:
    browser=playwright.chromium.launch(executable_path=shutil.which('chromium') or playwright.chromium.executable_path,headless=True,args=['--no-sandbox','--disable-gpu'])
    page=browser.new_page(viewport={'width':1280,'height':900})
    page.set_content(html)
    assert page.locator('.cms-operations').count()==1
    assert page.locator('input[name="csrf"]').count()>=5
    assert page.locator('form input[name="action"][value="create"]').count()==1
    assert page.locator('form input[name="action"][value="download"]').count()==1
    assert page.locator('form input[name="action"][value="import"]').count()==1
    assert page.locator('form input[name="action"][value="prune"]').count()==1
    assert page.locator('script').count()==0
    assert '<script>alert(1)</script>' not in page.locator('body').inner_html()
    for width in (1280,768,390,320):
        page.set_viewport_size({'width':width,'height':900})
        assert page.locator('body').evaluate('(node)=>node.scrollWidth <= window.innerWidth+2'),f'horizontal overflow at {width}px'
        assert page.get_by_text('Резервные копии',exact=True).is_visible()
        assert page.get_by_text('Создать резервную копию').is_visible()
    browser.close()
print('CMS Operations 1.3.8 Chromium: actual forms, escaped feedback, responsive 1280/768/390/320 OK')
