#!/usr/bin/env python3
"""Combined Chromium safety acceptance of all six real server-rendered AI features.

Runs without API credentials; external provider completion is not implied.
"""
import shutil
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parent.parent
php = r'''require 'bootstrap.php';
$page=['path'=>'/','title'=>'Главная','description'=>'Описание','body'=>'Старый текст  ', 'published'=>true,'menu'=>true];
$site=['settings'=>['name'=>'Demo','url'=>'https://example.test'],
 'modules'=>['navigation'=>true,'robots'=>true,'sitemap'=>true],
 'ai'=>array_replace(\CajeerEngine\AI\AIConfig::DEFAULTS,['enabled'=>true]), 'pages'=>['/'=>$page]];
$badTitle='<script>window.xss129=1</script>';
$badBody='<img src=x onerror=window.xss129=1>';
$draft=['id'=>str_repeat('a',32),'created'=>time(),'mode'=>'page','path'=>'/new','target'=>'','revision'=>'',
 'title'=>$badTitle,'description'=>$badBody,'body'=>$badBody,'model'=>'gpt-5-mini','input_tokens'=>2,'output_tokens'=>3];
$edit=['id'=>str_repeat('b',32),'created'=>time(),'path'=>'/','scope'=>'all','operation'=>'rewrite',
 'owner_generation'=>str_repeat('c',48),'revision'=>str_repeat('d',64),'original'=>$page,
 'suggested'=>array_replace($page,['title'=>$badTitle,'body'=>$badBody]),
 'model'=>'gpt-5-mini','input_tokens'=>4,'output_tokens'=>7];
$seo=$edit;$seo['scope']='all';
$to=gmdate('Y-m-d',time()-86400);$from=(new DateTimeImmutable($to))->modify('-6 days')->format('Y-m-d');
$report=['days'=>7,'from'=>$from,'to'=>$to,'generated_at'=>gmdate('c'),'providers'=>[
 'ga4'=>['status'=>'ok','summary'=>['sessions'=>123,'conversions'=>9]],
 'matomo'=>['status'=>'error','message'=>$badBody]]];
$insights=['created'=>time(),'model'=>'gpt-5-mini','input_tokens'=>5,'output_tokens'=>6,
 'fields'=>['summary'=>$badTitle,'hypotheses'=>'Гипотеза','recommendations'=>$badBody,'limitations'=>'Источник недоступен']];
$checks=['cms.state'=>'pass','ai.integration'=>'warn','auth.session_registry'=>'pass','integration.asset.ga4'=>'fail'];
$snapshot=['version'=>'1.2.9','schema'=>13,'checks'=>$checks,'counts'=>['pass'=>2,'warn'=>1,'fail'=>1]];
$support=['fingerprint'=>\CajeerEngine\AI\SupportDiagnostics::fingerprint($snapshot),'category'=>'ai',
 'created'=>time(),'model'=>'gpt-5-mini','input_tokens'=>2,'output_tokens'=>3,
 'fields'=>['summary'=>$badTitle,'causes'=>$badBody,'steps'=>'Проверить','cautions'=>'Не раскрывать секреты']];
$chat=['id'=>str_repeat('e',32),'title'=>'Test','messages'=>[['role'=>'assistant','content'=>$badTitle,'at'=>time()]],'pending'=>null];
$csrf=str_repeat('f',64);
$views=[
 'content'=>\CajeerEngine\AI\ContentView::render($site,$csrf,$draft,true),
 'editor'=>\CajeerEngine\AI\PageEditorView::render($site,$csrf,$edit,[],true,'/'),
 'seo'=>\CajeerEngine\AI\SEOView::render($site,$csrf,\CajeerEngine\AI\SEOAudit::run($site),$seo,'/',true),
 'analytics'=>\CajeerEngine\AI\AnalyticsInsightView::render($report,$insights,$csrf,7,true),
 'support'=>\CajeerEngine\AI\SupportView::render('ai',$snapshot,$support,$csrf,true),
 'chat'=>\CajeerEngine\AI\ChatView::render([['id'=>$chat['id'],'title'=>'Test','updated'=>time()]],$chat,$csrf,$site['ai'],true),
];
foreach($views as $name=>$html)echo '<div data-feature="'.$name.'">'.$html.'</div>';
'''
html = subprocess.check_output(['php', '-r', php], cwd=root, text=True)
assert '&lt;script&gt;' in html and '&lt;img' in html
with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path=shutil.which('chromium') or pw.chromium.executable_path,
                                 headless=True, args=['--no-sandbox','--disable-gpu'])
    page = browser.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.set_content('<!doctype html><html lang="ru"><body>' + html + '</body></html>')
    for feature in ('content','editor','seo','analytics','support','chat'):
        assert page.locator(f'[data-feature="{feature}"]').count() == 1, feature
    assert page.locator('#ai-content-preview').count() == 1
    assert page.locator('#ai-editor-preview').count() == 1
    assert page.locator('#seo-preview').count() == 1
    assert page.locator('#ai-insights-result').count() == 1
    assert page.locator('#ai-support-result').count() == 1
    assert page.locator('.ai-message-assistant').count() == 1
    assert page.locator('script, img, iframe').count() == 0
    assert not page.evaluate('Boolean(window.xss129)')
    assert page.locator('[data-feature="content"] input[name="title"]').input_value() == '<script>window.xss129=1</script>'
    assert page.locator('[data-feature="editor"] textarea[name="body"]').input_value() == '<img src=x onerror=window.xss129=1>'
    assert page.locator('[data-feature="seo"] input[name="confirm"]').count() == 1
    assert page.locator('[data-feature="support"] form[action="/admin/ai/support"]').count() == 3
    assert page.locator('input[name="csrf"]').count() >= 8
    assert not errors, errors
    browser.close()
print('Lite 1.2.9 Chromium combined six AI features, editable approval, CSRF controls, provider gaps and hostile output escaping OK')
