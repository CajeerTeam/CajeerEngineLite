#!/usr/bin/env python3
"""Real PHP-rendered technical-support screen in Chromium; hostile model output is text, never code."""
import shutil
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parent.parent
php=r'''require 'bootstrap.php';
$checks=['ai.credentials'=>'warn','auth.session_registry'=>'pass','cms.schema'=>'pass','integration.asset.ga4'=>'fail'];
$s=['version'=>'1.2.7','schema'=>13,'checks'=>$checks,'counts'=>['pass'=>2,'warn'=>1,'fail'=>1]];
$i=['fingerprint'=>\CajeerEngine\AI\SupportDiagnostics::fingerprint($s),'category'=>'auth','created'=>time(),
    'model'=>'gpt-5-mini','input_tokens'=>33,'output_tokens'=>44,
    'fields'=>['summary'=>'<script>window.hacked=1</script>',
      'causes'=>'<img src=x onerror=window.hacked=1>',
      'steps'=>'Проверить сеансы в админке','cautions'=>'Не передавать пароли']];
echo \CajeerEngine\AI\SupportView::render('auth',$s,$i,str_repeat('f',64),true);'''
html=subprocess.check_output(['php','-r',php],cwd=root,text=True)
assert '&lt;script&gt;' in html and '&lt;img' in html
with sync_playwright() as pw:
    browser=pw.chromium.launch(executable_path=shutil.which('chromium') or pw.chromium.executable_path,
        headless=True,args=['--no-sandbox','--disable-gpu'])
    page=browser.new_page()
    page.set_content('<!doctype html><html lang="ru"><body>'+html+'</body></html>')
    assert page.locator('#ai-support').count()==1
    assert page.locator('#ai-support-result').count()==1
    assert page.get_by_text('FAIL',exact=True).count()>=1
    assert page.locator('form[method="post"][action="/admin/ai/support"]').count()==2
    assert page.locator('a[href*="format=json"]').count()==1
    assert page.locator('script,img').count()==0
    assert not page.evaluate('Boolean(window.hacked)')
    page.locator('select[name="category"]').select_option('storage')
    assert page.locator('select[name="category"]').input_value()=='storage'
    browser.close()
print('Lite 1.2.7 Chromium escaped technical support recommendations, diagnostic statuses, category controls and export OK')
