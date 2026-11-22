#!/usr/bin/env python3
"""Actual server-rendered AI insights, exact source figures and hostile model HTML in Chromium."""
import shutil
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parent.parent
php=r'''require 'bootstrap.php';
$to=gmdate('Y-m-d',time()-86400);$from=(new DateTimeImmutable($to))->modify('-6 days')->format('Y-m-d');
$r=['days'=>7,'from'=>$from,'to'=>$to,'generated_at'=>gmdate('c'),'stale'=>false,
'providers'=>['ga4'=>['status'=>'ok','summary'=>['sessions'=>123,'conversions'=>9,'untrusted'=>'<img src=x onerror=window.hacked=1>']],
'gsc'=>['status'=>'ok','summary'=>['clicks'=>45,'impressions'=>900,'ctr_percent'=>5.0]],
'matomo'=>['status'=>'error','message'=>'<script>window.hacked=1</script>']]];
$i=['created'=>time(),'model'=>'gpt-5-mini','input_tokens'=>12,'output_tokens'=>15,
'fields'=>['summary'=>'<script>window.hacked=1</script>',
'hypotheses'=>'Гипотеза: проверить поисковый трафик',
'recommendations'=>'<img src=x onerror=window.hacked=1>',
'limitations'=>'Нельзя смешивать разные метрики']];
echo \CajeerEngine\AI\AnalyticsInsightView::render($r,$i,str_repeat('f',64),7,true);'''
html=subprocess.check_output(['php','-r',php],cwd=root,text=True)
assert '&lt;script&gt;' in html and '&lt;img' in html
with sync_playwright() as pw:
    browser=pw.chromium.launch(executable_path=shutil.which('chromium') or pw.chromium.executable_path,
                               headless=True,args=['--no-sandbox','--disable-gpu'])
    page=browser.new_page()
    page.set_content('<!doctype html><html lang="ru"><body>'+html+'</body></html>')
    assert page.locator('#ai-insights').count()==1
    assert page.locator('#ai-insights-result').count()==1
    assert page.get_by_text('123',exact=True).count()==1
    assert page.locator('script').count()==0 and page.locator('img').count()==0
    assert page.locator('form[method="post"][action="/admin/ai/analytics"]').count()==2
    assert page.get_by_text('Нет достоверных показателей',exact=False).count()>=1
    assert not page.evaluate('Boolean(window.hacked)')
    browser.close()
print('Lite 1.2.6 Chromium source-attributed AI insights, no script execution, provider gaps and authenticated POST forms OK')
