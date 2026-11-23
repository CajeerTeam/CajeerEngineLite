#!/usr/bin/env python3
"""Chromium accessibility, responsive, XSS and form-association checks for 1.3.2."""
import json
import shutil
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parent.parent
php=r'''require 'bootstrap.php';
$pages=['/'=>['path'=>'/','title'=>'Главная','description'=>'','body'=>'secret','published'=>true,'menu'=>true]];
for($i=0;$i<34;$i++) { $path='/item-'.$i; $pages[$path]=['path'=>$path,'title'=>'Страница '.$i,
  'description'=>'Тестовое описание', 'body'=>'private', 'published'=>($i%2===0), 'menu'=>false]; }
$pages['/item-3']['title']='Название <script>window.bad132=1</script>';
$query=json_decode($argv[1],true,64,JSON_THROW_ON_ERROR);
$content=\CajeerEngine\Admin\PagesManagement::render($pages,$query,str_repeat('a',64));
$sections=\CajeerEngine\Admin\AdminExperience::sections(['pages'=>$content],'pages','<p>Dashboard</p>');
echo \CajeerEngine\Admin\AdminExperience::frame('Управление сайтом',$sections,'/admin','pages',str_repeat('a',64));'''
style=(root/'public/assets/admin.css').read_text()
common=(root/'public/assets/style.css').read_text()
def content(query):
    html=subprocess.check_output(['php','-r',php,json.dumps(query,ensure_ascii=False)],cwd=root,text=True)
    return f'<!doctype html><html lang="ru"><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>{common}\n{style}</style></head><body class="admin-body">{html}</body></html>'
with sync_playwright() as pw:
    browser=pw.chromium.launch(executable_path=shutil.which('chromium') or pw.chromium.executable_path,headless=True,args=['--no-sandbox','--disable-gpu'])
    page=browser.new_page(viewport={'width':1280,'height':900})
    errors=[]
    page.on('pageerror',lambda error:errors.append(str(error)))
    page.set_content(content({'per_page':'10','page':'2'}))
    assert page.locator('.admin-pages').is_visible()
    assert page.locator('.admin-pages-table tbody tr').count()==10
    assert page.locator('.admin-pages-pagination').get_by_text('Страница 2 из 4').count()==1
    assert page.locator('.admin-pages-pagination a').count()==2
    assert page.locator('.admin-pages-filters').is_visible()
    assert page.locator('.admin-pages-filters input[name=q]').count()==1
    assert page.locator('.admin-pages-filters select[name=status]').count()==1
    assert page.locator('.admin-pages-filters select[name=sort]').count()==1
    assert page.locator('form#admin-pages-bulk').count()==1
    for row in page.locator('.admin-pages-table tbody tr').all():
        assert row.locator('input[name="selected[]"]').evaluate('(e)=>e.form?.id')=='admin-pages-bulk'
        assert row.locator('input[name^="revisions["]').evaluate('(e)=>e.form?.id')=='admin-pages-bulk'
        assert row.locator('form input[name="csrf"]').count()==2
        assert row.locator('form[action="/admin"] button').count()==2
    page.set_content(content({'q':'НАZВАНИЕ'}))
    assert page.locator('.admin-pages-results').inner_text().endswith('0')
    assert page.locator('.admin-pages-table tbody tr').count()==1
    page.set_content(content({'q':'название'}))
    assert page.locator('.admin-pages-results').inner_text().endswith('1')
    assert page.locator('.admin-pages-table tbody tr').count()==1
    assert page.locator('script').count()==0
    assert page.get_by_text('Название <script>window.bad132=1</script>').count()==1
    assert page.evaluate('Boolean(window.bad132)') is False
    page.set_content(content({}))
    page.set_viewport_size({'width':390,'height':844})
    assert page.locator('.admin-sidebar').is_hidden()
    assert page.locator('.admin-mobile-menu summary').is_visible()
    assert page.locator('body').evaluate('(el)=>el.scrollWidth<=window.innerWidth+1'), 'horizontal page overflow'
    assert page.locator('.admin-pages-table-wrap').evaluate('(el)=>el.scrollWidth>el.clientWidth')
    page.locator('.admin-mobile-menu summary').click()
    assert page.locator('.admin-mobile-menu .admin-nav').is_visible()
    assert errors==[],errors
    browser.close()
print('Pages Management 1.3.2 Chromium: filtering, pagination, XSS, associated bulk forms, keyboard/mobile layout OK')
