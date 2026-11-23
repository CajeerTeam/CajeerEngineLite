#!/usr/bin/env python3
"""Browser regression for the real server-rendered 1.3.1 desktop/mobile admin layout.

HTTP authentication and real form posts are covered by admin_experience_http.sh.
"""
from pathlib import Path
import shutil
import subprocess
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parent.parent
php = r'''require 'bootstrap.php';
$app=new \CajeerEngine\Lite(sys_get_temp_dir().'/no-write');
$state=['pages'=>[
 '/'=>['path'=>'/','title'=>'Home <script>window.bad131=1</script>','body'=>'secret body','description'=>'','published'=>true,'menu'=>true],
 '/draft'=>['path'=>'/draft','title'=>'Draft','body'=>'','description'=>'','published'=>false,'menu'=>false]],
 'integrations'=>['ga4'=>['enabled'=>true],'metrica'=>['enabled'=>false],
 'gtm'=>['enabled'=>false],'clarity'=>['enabled'=>false],'matomo'=>['enabled'=>false]],
 'identity'=>['owner'=>null], 'auth_policy'=>['mode'=>'hybrid'],
 'ai'=>['enabled'=>false],'consent'=>['enabled'=>true]];
$overview=\CajeerEngine\Admin\AdminExperience::dashboard($state,1,true,false);
$sections=\CajeerEngine\Admin\AdminExperience::sections(['pages'=>'<section><h2>Pages editor</h2><form method="post" action="/admin"><input name="csrf" value="token"/><button>Save</button></form></section>'],
 'dashboard',$overview);
echo \CajeerEngine\Admin\AdminExperience::frame('Управление сайтом',$sections,'/admin','dashboard',str_repeat('a',64));'''
html=subprocess.check_output(['php','-r',php],cwd=root,text=True)
style=(root/'public/assets/admin.css').read_text()
common=(root/'public/assets/style.css').read_text()
assert '&lt;script&gt;' in html and '<script>window.bad131' not in html
with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path=shutil.which('chromium') or pw.chromium.executable_path,
                                 headless=True, args=['--no-sandbox','--disable-gpu'])
    page=browser.new_page(viewport={'width':1280,'height':900})
    errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.set_content('<!doctype html><html lang="ru"><head><style>'+common+'\n'+style+
        '</style></head><body class="admin-body">'+html+'</body></html>')
    assert page.locator('.admin-sidebar').is_visible()
    assert page.locator('.admin-mobile-menu').is_hidden()
    assert page.get_by_text('Dashboard — обзор сайта').is_visible()
    assert page.locator('#admin-view-dashboard').is_visible()
    assert page.locator('#admin-view-pages').is_hidden()
    assert page.locator('.admin-metric').count()==4
    assert page.get_by_text('Home <script>window.bad131=1</script>').count()==1
    assert page.locator('script').count()==0
    assert page.evaluate('Boolean(window.bad131)') is False
    assert page.locator('a[href="/admin?view=pages"]').count()>=1
    assert page.locator('.admin-sidebar a[aria-current="page"][href="/admin?view=dashboard"]').count()==1
    assert page.locator('.admin-logout input[name="csrf"]').count()==1
    page.set_viewport_size({'width':390,'height':844})
    assert page.locator('.admin-sidebar').is_hidden()
    assert page.locator('.admin-mobile-menu summary').is_visible()
    page.locator('.admin-mobile-menu summary').click()
    assert page.locator('.admin-mobile-menu .admin-nav').is_visible()
    assert page.locator('.admin-mobile-menu a[href="/admin?view=security"]').is_visible()
    assert page.locator('body').evaluate('(e)=>e.scrollWidth<=window.innerWidth+1')
    assert not errors, errors
    browser.close()
print('Admin Experience 1.3.1 Chromium server HTML, dashboard, XSS, responsive navigation and accessible controls OK')
