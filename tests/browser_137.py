#!/usr/bin/env python3
"""Developer Tools 1.3.7: responsive template editor and escaped data (Chromium)."""
import shutil
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parent.parent
php=r'''require 'bootstrap.php';
$root=sys_get_temp_dir().'/developer137-browser-'.bin2hex(random_bytes(5));
mkdir($root);mkdir($root.'/storage');mkdir($root.'/public');mkdir($root.'/templates');mkdir($root.'/templates/default');
copy('templates/default/page.cjr',$root.'/templates/default/page.cjr');
try {
 $app=new \CajeerEngine\Lite($root);
 $app->install('BrowserDev137Password!','http://127.0.0.1:8080','Site & <unsafe>');
 putenv('CAJEER_DEVELOPER_TEMPLATE_WRITE=1');
 $site=$app->load();
 $draft="</textarea><script>alert(1)</script>";
 echo \CajeerEngine\Developer\DeveloperView::render($root,$site,str_repeat('f',64),'templates','default/page.cjr','','',$draft);
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
    assert page.locator('.dev-tools').count()==1
    assert page.locator('.dev-tabs a').evaluate_all('(links) => links.map(a => a.getAttribute("href"))') == [
        '/admin/developer?tab=diagnostics', '/admin/developer?tab=templates',
        '/admin/developer?tab=events', '/admin/developer?tab=monitoring',
    ]
    assert page.locator('.dev-tabs a[aria-current=page]').get_attribute('href') == '/admin/developer?tab=templates'
    assert page.locator('form.dev-template-form').count()==1
    assert page.locator('textarea[name="source"]').input_value()=='</textarea><script>alert(1)</script>'
    assert page.locator('script').count()==0
    assert page.locator('button[name="action"][value="save"]').count()==1
    assert page.locator('button[name="action"][value="preview"]').count()==1
    for width in (1280,768,390,320):
        page.set_viewport_size({'width':width,'height':900})
        assert page.locator('body').evaluate('(node)=>node.scrollWidth <= window.innerWidth+2'),f'horizontal overflow at {width}px'
        assert page.locator('textarea[name="source"]').is_visible()
        assert page.locator('button[name="action"][value="preview"]').is_visible()
    browser.close()
print('Developer Tools 1.3.7 Chromium: escaped draft, forms, responsive 1280/768/390/320 OK')
