#!/usr/bin/env python3
"""SEO audit and review UI: real PHP rendering, real Chromium, hostile AI text."""
import subprocess
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parent.parent
php = r'''require 'bootstrap.php';
$s=['settings'=>['url'=>'https://example.test','name'=>'Test'],
  'modules'=>['navigation'=>true,'robots'=>false,'sitemap'=>false],
  'ai'=>['enabled'=>true],
  'pages'=>['/'=>['path'=>'/','title'=>'Главная','description'=>'Описание','body'=>'Важный текст','published'=>true,'menu'=>true]]];
$r=\CajeerEngine\AI\SEOAudit::run($s);
$p=['id'=>str_repeat('a',32),'created'=>time(),'owner_generation'=>str_repeat('c',48),
  'path'=>'/','scope'=>'all','revision'=>str_repeat('d',64),
  'original'=>$s['pages']['/'],
  'suggested'=>['path'=>'/','title'=>'<script>window.hacked=1</script>',
    'description'=>'<img src=x onerror=window.hacked=1>','body'=>'Text <script>window.hacked=1</script>',
    'published'=>true,'menu'=>true],
  'model'=>'gpt-5-mini','input_tokens'=>30,'output_tokens'=>60];
echo \CajeerEngine\AI\SEOView::render($s,str_repeat('f',64),$r,$p,'/',true);'''
html = subprocess.check_output(['php', '-r', php], cwd=root, text=True)
assert '&lt;script&gt;' in html
with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path=shutil.which('chromium') or pw.chromium.executable_path,
                                 headless=True, args=['--no-sandbox', '--disable-gpu'])
    page = browser.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.set_content('<!doctype html><html lang="ru"><body>'+html+'</body></html>')
    assert page.locator('#seo-audit').count() == 1
    assert page.locator('#seo-preview').count() == 1
    assert page.locator('.ai-edit-columns').count() == 3
    assert page.locator('script').count() == 0
    assert page.locator('img').count() == 0
    assert page.locator('input[name="title"]').input_value() == '<script>window.hacked=1</script>'
    assert page.locator('textarea[name="body"]').input_value() == 'Text <script>window.hacked=1</script>'
    assert page.locator('input[name="confirm"]').count() == 1
    assert not page.evaluate('Boolean(window.hacked)')
    assert not errors, errors
    browser.close()
print('Lite 1.2.5 Chromium escaped SEO audit, editable preview and approval UI OK')
