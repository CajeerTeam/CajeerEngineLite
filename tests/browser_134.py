#!/usr/bin/env python3
"""Chromium checks of actual server-rendered media management, escaping and mobile layout."""
import shutil
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parent.parent
php=r'''require 'bootstrap.php';
$root=sys_get_temp_dir().'/ce-media-browser-'.bin2hex(random_bytes(6));mkdir($root);mkdir($root.'/storage');
try {
  $image=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGOUT9rBwMDAxMDAwMDAAAAOPQE9xgjZwQAAAABJRU5ErkJggg==');
  file_put_contents($root.'/img.png',$image);
  $library=new \CajeerEngine\Media\MediaLibrary($root);
  $library->ingest($root.'/img.png','Quoted & "title".png');
  file_put_contents($root.'/doc.pdf',"%PDF-1.4\n%%EOF\n");
  $library->ingest($root.'/doc.pdf','Прайс.pdf');
  $content=\CajeerEngine\Media\MediaView::render($library,str_repeat('a',64));
  echo \CajeerEngine\Admin\AdminExperience::frame('Медиатека',$content,'/admin/media','pages',str_repeat('a',64));
} finally {
  $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
  foreach($items as $item){if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());}
  rmdir($root);
}'''
html=subprocess.check_output(['php','-r',php],cwd=root,text=True)
css=(root/'public/assets/style.css').read_text()+'\n'+(root/'public/assets/admin.css').read_text()
source=f'<!doctype html><html lang="ru"><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>{css}</style></head><body class="admin-body">{html}</body></html>'
with sync_playwright() as pw:
    browser=pw.chromium.launch(executable_path=shutil.which('chromium') or pw.chromium.executable_path, headless=True,args=['--no-sandbox','--disable-gpu'])
    page=browser.new_page(viewport={'width':1280,'height':900})
    errors=[]
    page.on('pageerror',lambda error:errors.append(str(error)))
    page.set_content(source)
    assert page.locator('.cms-media-item').count()==2
    assert page.locator('.cms-media-item img').count()==1
    assert page.locator('.cms-media-item img').first.get_attribute('src').startswith('/media/')
    assert page.get_by_text('Quoted & "title".png').count()>=1
    assert page.locator('.cms-media-item script').count()==0
    assert page.locator('form.cms-media-upload').get_attribute('enctype')=='multipart/form-data'
    assert page.locator('form.cms-media-rename').count()==2
    assert page.locator('form.cms-media-delete').count()==2
    assert page.locator('form.cms-media-rename input[name=id]').count()==2
    assert page.locator('form.cms-media-filters').count()==1
    assert page.locator('.admin-nav a[href="/admin/media"]').first.get_attribute('aria-current')=='page'
    assert page.locator('body').evaluate('(el)=>el.scrollWidth<=window.innerWidth+1')
    page.set_viewport_size({'width':390,'height':844})
    assert page.locator('.admin-sidebar').is_hidden()
    assert page.locator('.cms-media-upload').is_visible()
    assert page.locator('.cms-media-item').first.is_visible()
    assert page.locator('body').evaluate('(el)=>el.scrollWidth<=window.innerWidth+1'),'mobile overflow'
    assert errors==[],errors
    browser.close()
print('Media Library 1.3.4 Chromium: real PHP gallery, escaping, form actions, accessible navigation and mobile layout OK')
