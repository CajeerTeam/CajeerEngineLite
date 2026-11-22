#!/usr/bin/env python3
"""Chromium with the actual server-rendered page and runtime.

Cloud sandbox policy disallows any HTTP/file navigation. Test makes a real
execution of metrica.js and consent.js, mocks the Yandex tag response, and
replaces only location.reload() with a marker. Thus the reload invocation is
asserted; the real navigation is separately unverified in this environment.
"""
import os
import pathlib
import re
import shutil
import subprocess
import tempfile
from playwright.sync_api import sync_playwright

SOURCE=pathlib.Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='lite-105-ui-') as folder:
    root=pathlib.Path(folder)/'site'
    shutil.copytree(SOURCE,root)
    subprocess.run(['php','bin/cajeer','install','https://lite.example.org','Metrica test'],cwd=root,
                   env={**os.environ,'CAJEER_ADMIN_PASSWORD':'TestPassword123'},
                   check=True,capture_output=True)
    config='''require "bootstrap.php";
       $app=new \\CajeerEngine\\Lite(getcwd());
       $app->change(static function(array $s): array {
         $s['integrations']['metrica']=\\CajeerEngine\\Integrations\\MetricaConfig::fromPost([
           'enabled'=>'1','counter_id'=>'12345678','page_views'=>'1','accurate_bounce'=>'1',
           'webvisor'=>'1','clickmap'=>'1','contact_goals'=>'1',
           'email_goal'=>'contact_email','phone_goal'=>'contact_phone']);
         $s['settings']['email']='private@example.org';
         $s['settings']['phone']='+12025550100';
         return $s;
       });
       $s=$app->load(); echo $app->render($s,$s['pages']['/']);'''
    html=subprocess.check_output(['php','-r',config],cwd=root,text=True)
    match=re.search(r'<script defer src="/assets/metrica.js\?v=109" ([^>]*)></script>',html)
    assert match,'Yandex runtime missing from PHP render'
    attrs=dict(re.findall(r'data-metrica-([a-z-]+)="([^"]*)"',match.group(1)))
    assert attrs['id']=='12345678', attrs
    html=re.sub(r'<script defer src="/assets/(?:consent|ga4|metrica).js\?v=\d+"[^>]*></script>','',html)
    html=html.replace('<link rel="stylesheet" href="/assets/style.css">','')
    consent=(root/'public/assets/consent.js').read_text()
    original=(root/'public/assets/metrica.js').read_text()
    assert original.count('location.reload();')==1
    metrica=original.replace('location.reload();','window.__testReloads = (window.__testReloads || 0) + 1;')
    remote=[]; errors=[]

    def setup(page,mask=None):
        page.on('pageerror',lambda error:errors.append(str(error)))
        page.evaluate('''() => {
            const jar=new Map();
            Object.defineProperty(document,'cookie',{
              configurable:true,
              get() {return Array.from(jar,([k,v])=>`${k}=${v}`).join('; ');},
              set(value) {
                const pair=value.split(';')[0]; const idx=pair.indexOf('=');
                if (idx<0) return;
                const name=pair.slice(0,idx), val=pair.slice(idx+1);
                if (/Max-Age=0/i.test(value)) jar.delete(name);
                else jar.set(name,val);
              }
            });
        }''')
        if mask is not None:
            page.evaluate('''mask => {
              document.cookie = `cajeer_lite_consent=v1.1.${Math.floor(Date.now()/1000)}.${mask}; Path=/`;
            }''',mask)
        page.set_content(html)
        page.add_script_tag(content=consent)
        page.evaluate('''({src,attrs}) => {
          const script=document.createElement('script');
          for (const [key,value] of Object.entries(attrs)) script.setAttribute('data-metrica-'+key,value);
          script.textContent=src; document.body.appendChild(script);
        }''',{'src':metrica,'attrs':attrs})

    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=shutil.which('chromium') or p.chromium.executable_path,
                                  headless=True,args=['--no-sandbox','--disable-gpu'])
        context=browser.new_context()
        def capture(route):
            remote.append(route.request.url)
            route.fulfill(status=200,content_type='application/javascript',body='window.__fakeYandexLoaded=true;')
        context.route('https://mc.yandex.ru/metrika/tag.js',capture)
        page=context.new_page()
        setup(page)
        assert page.locator('#cookie-consent').is_visible()
        assert not remote,'tag loaded without consent'
        assert not page.evaluate('CajeerMetrica.goal("contact_email")')
        page.locator('[data-consent-action="reject"]').first.click()
        assert not remote,'tag requested on rejection'
        page.locator('[data-consent-open]').click()
        page.locator('[data-consent-category="recordings"]').check()
        page.locator('[data-consent-action="save"]').click()
        assert not remote,'recording consent bypassed analytics consent'
        page.locator('[data-consent-open]').click()
        page.locator('[data-consent-category="recordings"]').uncheck()
        page.locator('[data-consent-category="analytics"]').check()
        page.locator('[data-consent-action="save"]').click()
        page.wait_for_function('window.__fakeYandexLoaded===true')
        assert len(remote)==1,'one tag should be loaded'
        calls=page.evaluate('() => window.ym.a.map(x=>Array.from(x))')
        assert sum(x[1]=='init' for x in calls)==1
        opts=next(x[2] for x in calls if x[1]=='init')
        assert opts['webvisor'] is False and opts['clickmap'] is False
        assert opts['defer'] is True and opts['trackLinks'] is False
        assert sum(x[1]=='hit' for x in calls)==1
        assert page.evaluate('CajeerMetrica.goal("signup",{placement:"footer"})') is True
        assert page.evaluate('CajeerMetrica.goal("<script>")') is False
        assert page.evaluate('CajeerMetrica.goal("signup",{placement:"<script>"})') is False
        assert page.evaluate('CajeerMetrica.hit("/about")') is True
        assert page.evaluate('CajeerMetrica.hit("/about?private=1")') is False
        page.evaluate('''() => {
            for (const type of ['mailto:secret@example.org','tel:+12025550100']) {
              const a=document.createElement('a'); a.href=type; document.body.append(a);
              a.addEventListener('click',ev=>ev.preventDefault());
              a.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true}));
            }
        }''')
        calls=page.evaluate('() => window.ym.a.map(x=>Array.from(x))')
        assert any(x[1]=='reachGoal' and x[2]=='contact_email' for x in calls)
        assert any(x[1]=='reachGoal' and x[2]=='contact_phone' for x in calls)
        assert 'secret@example.org' not in str(calls) and '+12025550100' not in str(calls)
        page.locator('[data-consent-open]').click()
        page.locator('[data-consent-category="recordings"]').check()
        page.locator('[data-consent-action="save"]').click()
        assert page.evaluate('window.__testReloads')==1, 'recording opt-in must reload already running tag'
        assert page.evaluate('CajeerMetrica.status().reloading') is True
        assert page.evaluate('CajeerMetrica.goal("signup")') is False
        # New page with pre-granted analytics+recordings correctly starts Webvisor.
        page2=context.new_page()
        setup(page2,5)
        page2.wait_for_function('window.__fakeYandexLoaded===true')
        assert len(remote)==2
        calls2=page2.evaluate('() => window.ym.a.map(x=>Array.from(x))')
        opts2=next(x[2] for x in calls2 if x[1]=='init')
        assert opts2['webvisor'] is True and opts2['clickmap'] is True
        page2.evaluate('document.cookie="_ym_uid=test;Path=/"')
        page2.locator('[data-consent-open]').click()
        page2.locator('[data-consent-category="recordings"]').uncheck()
        page2.locator('[data-consent-action="save"]').click()
        assert page2.evaluate('window.__testReloads')==1, 'recording revocation must reload'
        assert not page2.evaluate('document.cookie.includes("_ym_uid=")'),'Yandex cookie not cleared'
        assert page2.evaluate('CajeerMetrica.status().reloading') is True
        # Full revocation also stops sending new events and requests navigation.
        page3=context.new_page()
        setup(page3,5)
        page3.wait_for_function('window.__fakeYandexLoaded===true')
        page3.locator('[data-consent-open]').click()
        page3.locator('[data-consent-action="reject"]').last.click()
        assert page3.evaluate('window.__testReloads')==1,'analytics withdrawal must unload tag'
        assert page3.evaluate('CajeerMetrica.goal("signup")') is False
        # Opt-out on a fresh page sends no traffic to Yandex.
        page4=context.new_page()
        setup(page4,0)
        assert page4.evaluate('CajeerMetrica.status().initialized') is False
        assert len(remote)==3
        assert not errors,'JavaScript errors: '+str(errors)
        browser.close()
print('Metrica Chromium consent/goals/Webvisor regression OK (mocked tag and reload intent)')
