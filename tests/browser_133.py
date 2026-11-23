#!/usr/bin/env python3
"""Real Chromium interaction with JavaScript block editor and live preview."""
import json
import shutil
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parent.parent
php=r'''require 'bootstrap.php';
$p=['path'=>'/test','title'=>'Пример','description'=>'Описание','body'=>'Legacy text <script>bad()</script>','published'=>true,'menu'=>true];
$content=\CajeerEngine\Admin\BlockEditorView::render($p,str_repeat('a',64),true);
echo \CajeerEngine\Admin\AdminExperience::frame('Визуальный редактор',$content,'/admin/editor','pages',str_repeat('a',64));'''
html=subprocess.check_output(['php','-r',php],cwd=root,text=True)
css=(root/'public/assets/style.css').read_text()+'\n'+(root/'public/assets/admin.css').read_text()
page_html='<!doctype html><html lang="ru"><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>'+css+'</style></head><body class="admin-body">'+html+'</body></html>'
with sync_playwright() as pw:
    browser=pw.chromium.launch(executable_path=shutil.which('chromium') or pw.chromium.executable_path,headless=True,args=['--no-sandbox','--disable-gpu'])
    page=browser.new_page(viewport={'width':1280,'height':850})
    errors=[]
    page.on('pageerror',lambda error: errors.append(str(error)))
    page.set_content(page_html)
    page.add_script_tag(path=str(root/'public/assets/block-editor.js'))
    assert page.locator('#cms-save-button').is_enabled()
    assert page.locator('#cms-preview-button').is_enabled()
    assert page.locator('.cms-edit-block').count()==1
    assert page.locator('#cms-live-preview').get_by_text('Legacy text <script>bad()</script>').count()==1
    assert page.evaluate('Boolean(window.bad)') is False
    assert page.locator('#cms-live-preview script').count()==0
    page.locator('#cms-add-type').select_option('heading')
    page.locator('#cms-add-block').click()
    assert page.locator('.cms-edit-block').count()==2
    heading=page.locator('.cms-edit-block').nth(1)
    heading.locator('input').fill('Заголовок <img src=x onerror=alert(1)>')
    assert page.locator('#cms-live-preview h2').inner_text()=='Заголовок <img src=x onerror=alert(1)>'
    assert page.locator('#cms-live-preview img').count()==0
    heading.get_by_role('button',name='Переместить выше 2').click()
    assert page.locator('.cms-edit-block').first.locator('select').first.input_value()=='heading'
    page.locator('#cms-undo').click()
    assert page.locator('.cms-edit-block').first.locator('select').first.input_value()=='paragraph'
    page.locator('#cms-redo').click()
    assert page.locator('.cms-edit-block').first.locator('select').first.input_value()=='heading'
    page.locator('#cms-add-type').select_option('list');page.locator('#cms-add-block').click()
    page.locator('.cms-edit-block').last.locator('textarea').fill('One\nTwo')
    assert page.locator('#cms-live-preview li').count()==2
    page.locator('#cms-add-type').select_option('button');page.locator('#cms-add-block').click()
    page.locator('.cms-edit-block').last.locator('input').nth(1).fill('javascript:evil()')
    assert page.locator('#cms-live-preview a').count()==0
    assert page.locator('#cms-live-preview span.cms-button').count()==1
    page.locator('.cms-edit-block').last.locator('input').nth(1).fill('/good')
    # Form serializer contains current block order/content and no HTML templates.
    blocks=json.loads(page.locator('#cms-block-json').input_value())
    assert blocks[0]['type']=='heading' and blocks[1]['type']=='paragraph'
    assert blocks[-1]['url']=='/good'
    assert page.locator('body').evaluate('(el)=>el.scrollWidth<=window.innerWidth+1')
    page.set_viewport_size({'width':390,'height':850})
    assert page.locator('.admin-sidebar').is_hidden()
    assert page.locator('.cms-editor-compose').is_visible()
    assert page.locator('.cms-editor-preview').is_visible()
    assert page.locator('body').evaluate('(el)=>el.scrollWidth<=window.innerWidth+1'), 'mobile overflow'
    assert errors==[],errors
    browser.close()
print('Enhanced Content Editor 1.3.3 Chromium: real block interactions, safe live preview, reorder, undo/redo, serialization and mobile OK')
