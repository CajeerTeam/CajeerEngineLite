#!/usr/bin/env python3
"""Chromium acceptance of server-rendered generation/approval forms; no provider calls."""
import json
import shutil
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parent.parent
php=r'''require 'bootstrap.php';
$site=['ai'=>['enabled'=>true], 'pages'=>['/'=>['path'=>'/','title'=>'Начало','description'=>'','body'=>'','published'=>true,'menu'=>true]]];
$p=['id'=>str_repeat('a',32),'created'=>time(),'mode'=>'page', 'path'=>'/draft', 'target'=>'','revision'=>'',
  'title'=>'Безопасный <script>window.xss=1</script> заголовок',
  'description'=>'Тестовое <img src=x onerror=window.xss=1> описание',
  'body'=>'Контент <script>window.xss=1</script>','model'=>'gpt-5-mini','input_tokens'=>12,'output_tokens'=>34];
echo \CajeerEngine\AI\ContentView::render($site,str_repeat('b',64),$p,true);'''
html=subprocess.check_output(['php','-r',php],cwd=root,text=True)
assert '&lt;script&gt;' in html and '<script>window.xss' not in html
with sync_playwright() as pw:
    browser=pw.chromium.launch(executable_path=shutil.which('chromium') or pw.chromium.executable_path,
        headless=True,args=['--no-sandbox','--disable-gpu'])
    page=browser.new_page()
    errors=[]
    page.on('pageerror',lambda error:errors.append(str(error)))
    page.set_content('<!doctype html><html lang="ru"><body>'+html+'</body></html>')
    assert page.locator('#ai-content-preview').count()==1
    assert page.locator('script').count()==0
    assert page.locator('img').count()==0
    assert page.locator('form[action="/admin/ai/content"]').count()==3
    assert page.locator('textarea[name="body"]').input_value()=='Контент <script>window.xss=1</script>'
    assert page.locator('input[name="title"]').input_value()=='Безопасный <script>window.xss=1</script> заголовок'
    assert page.locator('input[name="csrf"]').count()==3
    assert not page.evaluate('Boolean(window.xss)')
    assert not errors,errors
    browser.close()
print('Lite 1.2.3 Chromium editable generation preview, CSRF and safe text rendering OK')
