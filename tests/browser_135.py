#!/usr/bin/env python3
"""Real Chromium interactions with PHP-rendered media picker and visual block editor."""
import shutil
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parent.parent
php=r'''require 'bootstrap.php';
$root=sys_get_temp_dir().'/ce-media-picker-'.bin2hex(random_bytes(5));
mkdir($root);mkdir($root.'/storage');
try {
  $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGOUT9rBwMDAxMDAwMDAAAAOPQE9xgjZwQAAAABJRU5ErkJggg==');
  file_put_contents($root.'/img.png',$png);
  $lib=new \CajeerEngine\Media\MediaLibrary($root);
  $lib->ingest($root.'/img.png','Photo & "quoted".png');
  file_put_contents($root.'/doc.pdf',"%PDF-1.4\n%%EOF\n");
  $lib->ingest($root.'/doc.pdf','Guide.pdf');
  $page=['path'=>'/','title'=>'Media demo','description'=>'','body'=>'','published'=>true,'menu'=>false];
  echo \CajeerEngine\Admin\BlockEditorView::render($page,str_repeat('a',64),false,'',$lib->listing());
} finally {
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
  foreach($it as $item){if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());}
  rmdir($root);
}'''
form=subprocess.check_output(['php','-r',php],cwd=root,text=True)
css=(root/'public/assets/style.css').read_text()+'\n'+(root/'public/assets/admin.css').read_text()
js=(root/'public/assets/block-editor.js').read_text()
html=f'<!doctype html><html lang="ru"><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>{css}</style></head><body>{form}</body></html>'
with sync_playwright() as pw:
    browser=pw.chromium.launch(executable_path=shutil.which('chromium') or pw.chromium.executable_path,headless=True,args=['--no-sandbox','--disable-gpu'])
    page=browser.new_page(viewport={'width':1280,'height':900})
    errors=[]
    page.on('pageerror',lambda error:errors.append(str(error)))
    page.set_content(html)
    page.add_script_tag(content=js)
    assert page.locator('#cms-save-button').is_enabled()
    assert page.locator('.cms-edit-block').count()==1
    page.locator('#cms-add-type').select_option('image')
    page.locator('#cms-add-block').click()
    assert page.locator('.cms-edit-block').count()==2
    box=page.locator('.cms-edit-block').last
    image_select=box.locator('select').last if False else box.locator('select').nth(1)
    # Type selector is first, media picker second.
    options=image_select.locator('option').all()
    assert len(options)==2
    image_id=image_select.locator('option').last.get_attribute('value')
    assert len(image_id)==32
    image_select.select_option(image_id)
    box=page.locator('.cms-edit-block').last
    assert box.locator('.cms-edit-media-thumb').count()==1
    box.locator('textarea').first.fill('A <script>evil()</script>') if box.locator('textarea').count() else box.locator('input').first.fill('A <script>evil()</script>')
    # Input order: alt, caption (textarea). Use labels to avoid reaching other controls.
    box.get_by_label('Альтернативный текст (alt)').fill('Безопасный <script>alert(1)</script>')
    box.get_by_label('Подпись').fill('Подпись & <b>bold</b>')
    box.get_by_label('Размер').select_option('medium')
    preview=page.locator('#cms-live-preview')
    assert preview.locator('img').count()==1
    assert preview.locator('img').get_attribute('src')=='/media/'+image_id
    assert preview.locator('img').get_attribute('alt')=='Безопасный <script>alert(1)</script>'
    assert preview.locator('figcaption').inner_text()=='Подпись & <b>bold</b>'
    assert preview.locator('script').count()==0
    # Second media block is a PDF downloaded from the controlled /media route.
    page.locator('#cms-add-type').select_option('file')
    page.locator('#cms-add-block').click()
    box=page.locator('.cms-edit-block').last
    doc_select=box.locator('select').nth(1)
    assert doc_select.locator('option').count()==2
    pdf_id=doc_select.locator('option').last.get_attribute('value')
    doc_select.select_option(pdf_id)
    box=page.locator('.cms-edit-block').last
    box.get_by_label('Текст ссылки на PDF').fill('Открыть <svg onload=evil()>')
    pdf_link=preview.locator('.cms-media-document a')
    assert pdf_link.get_attribute('href')=='/media/'+pdf_id+'?download=1'
    assert pdf_link.inner_text()=='Открыть <svg onload=evil()>'
    assert preview.locator('svg,script').count()==0
    block_data=page.locator('#cms-block-json').input_value()
    import json
    blocks=json.loads(block_data)
    assert blocks[1]['type']=='image' and blocks[1]['media_id']==image_id and blocks[1]['size']=='medium'
    assert blocks[2]['type']=='file' and blocks[2]['media_id']==pdf_id
    page.locator('#cms-undo').click()
    assert preview.locator('.cms-media-document a').inner_text() != 'Открыть <svg onload=evil()>'
    page.locator('#cms-redo').click()
    assert preview.locator('.cms-media-document a').inner_text()=='Открыть <svg onload=evil()>'
    page.set_viewport_size({'width':390,'height':844})
    assert page.locator('body').evaluate('(el)=>el.scrollWidth<=window.innerWidth+2'),'Mobile overflow'
    assert errors==[],errors
    browser.close()
print('Media Content Integration 1.3.5 Chromium: real image/PDF selection, safe preview, serialized blocks, undo/redo and mobile layout OK')
