#!/usr/bin/env python3
"""Browser acceptance of editable AI page proposals, comparison and durable undo UI."""
import subprocess
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parent.parent
php=r'''require 'bootstrap.php';
$s=['ai'=>['enabled'=>true],'pages'=>['/'=>['path'=>'/','title'=>'Главная','description'=>'Описание',
  'body'=>'Привет\nОригинальный текст','published'=>true,'menu'=>true]]];
$p=['id'=>str_repeat('a',32),'created'=>time(),'owner_generation'=>str_repeat('b',48),
  'path'=>'/','revision'=>str_repeat('c',64),'scope'=>'all','operation'=>'rewrite',
  'original'=>$s['pages']['/'],
  'suggested'=>['path'=>'/','title'=>'Заголовок <script>window.hack=1</script>',
    'description'=>'Описание <img src=x onerror=window.hack=1>',
    'body'=>'Новая редакция <script>window.hack=1</script>','published'=>true,'menu'=>true],
  'model'=>'gpt-5-mini','input_tokens'=>22,'output_tokens'=>48];
$history=[['id'=>str_repeat('d',32),'created'=>time(),'generation'=>str_repeat('b',48),
  'path'=>'/','revision'=>str_repeat('e',64),'page'=>$s['pages']['/']]];
echo \CajeerEngine\AI\PageEditorView::render($s,str_repeat('f',64),$p,$history,true,'/');'''
html=subprocess.check_output(['php','-r',php],cwd=root,text=True)
assert '&lt;script&gt;' in html and '<script>window.hack=1</script>' not in html
with sync_playwright() as pw:
    browser=pw.chromium.launch(executable_path=shutil.which('chromium') or pw.chromium.executable_path,
        headless=True,args=['--no-sandbox','--disable-gpu'])
    page=browser.new_page()
    errors=[]
    page.on('pageerror',lambda error:errors.append(str(error)))
    page.set_content('<!doctype html><html lang="ru"><body>'+html+'</body></html>')
    assert page.locator('script').count()==0
    assert page.locator('img').count()==0
    assert page.locator('#ai-editor-preview').count()==1
    assert page.locator('#ai-editor-history details').count()==1
    assert page.locator('.ai-edit-columns').count()==3
    assert page.locator('textarea[name="body"]').input_value()=='Новая редакция <script>window.hack=1</script>'
    assert page.locator('input[name="title"]').input_value()=='Заголовок <script>window.hack=1</script>'
    assert page.locator('form[action="/admin/ai/editor"]').count()==5
    assert page.locator('input[name="csrf"]').count()==4
    assert page.locator('input[name="confirm"]').count()==1
    assert page.locator('input[name="confirm_restore"]').count()==1
    page.locator('#ai-editor-history summary').click()
    assert page.get_by_text('Восстановить версию').count()==1
    assert not page.evaluate('Boolean(window.hack)')
    assert not errors,errors
    browser.close()
print('Lite 1.2.4 Chromium safe side-by-side edit, editable changes and history restore UI OK')
