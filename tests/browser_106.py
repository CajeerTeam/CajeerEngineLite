#!/usr/bin/env python3
"""Real PHP template and actual GTM/consent JS in Chromium.

The GTM response, document.cookie, and location.reload call are emulated
because the sandbox cannot reliably navigate to external or localhost URLs.
This does not assert data delivery to a real Google container.
"""
import os
import pathlib
import re
import shutil
import subprocess
import tempfile
from playwright.sync_api import sync_playwright

SOURCE = pathlib.Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='lite-106-gtm-') as folder:
    root = pathlib.Path(folder) / 'site'
    shutil.copytree(SOURCE, root)
    subprocess.run(['php', 'bin/cajeer', 'install', 'https://lite.example.org', 'GTM test'],
                   cwd=root, env={**os.environ, 'CAJEER_ADMIN_PASSWORD': 'TestPassword123'},
                   check=True, capture_output=True)
    program = r'''require "bootstrap.php";
        $app=new \CajeerEngine\Lite(getcwd());
        $app->change(static function(array $s): array {
           $s['integrations']['gtm']=\CajeerEngine\Integrations\GTMConfig::fromPost([
                'enabled'=>'1','container_id'=>'GTM-ABCD123','contact_events'=>'1']);
           $s['integrations']['ga4']=\CajeerEngine\Integrations\GA4Config::fromPost([
                'enabled'=>'1','measurement_id'=>'G-1234567890','page_views'=>'1']);
           $s['settings']['email']='secret@example.org';
           $s['settings']['phone']='+12025550100';
           return $s;
        });
        $s=$app->load(); echo $app->render($s,$s['pages']['/']);'''
    html = subprocess.check_output(['php','-r',program],cwd=root,text=True)
    assert '/assets/ga4.js' not in html, 'GTM + direct GA4 would double fire'
    match = re.search(r'<script defer src="/assets/gtm.js\?v=110" ([^>]*)></script>',html)
    assert match, 'GTM loader missing from PHP render'
    attrs = dict(re.findall(r'data-gtm-([a-z-]+)="([^"]*)"', match.group(1)))
    assert attrs == {'id':'GTM-ABCD123','contacts':'1'},attrs
    html = re.sub(r'<script defer src="/assets/(?:consent|ga4|metrica|gtm).js\?v=\d+"[^>]*></script>', '', html)
    consent = (SOURCE/'public/assets/consent.js').read_text()
    gtm = (SOURCE/'public/assets/gtm.js').read_text()
    # Replace only the actual browser navigation with a test counter; script's
    # full runtime and load/consent/event logic is otherwise unchanged.
    assert gtm.count('location.reload();') == 1
    gtm = gtm.replace('location.reload();','window.__gtmReloadCalls=(window.__gtmReloadCalls||0)+1;')
    remote=[]; errors=[]

    def install(page, initial_mask=None):
        page.on('pageerror',lambda error:errors.append(str(error)))
        page.evaluate('''() => {
          const jar = new Map();
          Object.defineProperty(document,'cookie',{
            configurable:true,
            get() {return Array.from(jar,([k,v])=>`${k}=${v}`).join('; ');},
            set(raw) {
              const part=raw.split(';')[0]; const i=part.indexOf('=');
              if (i<0) return;
              const key=part.slice(0,i), value=part.slice(i+1);
              if (/Max-Age=0/i.test(raw)) jar.delete(key);
              else jar.set(key,value);
            }
          });
        }''')
        if initial_mask is not None:
            page.evaluate('''mask => {
              document.cookie = `cajeer_lite_consent=v1.1.${Math.floor(Date.now()/1000)}.${mask}; Path=/`;
            }''',initial_mask)
        page.set_content(html)
        page.add_script_tag(content=consent)
        page.evaluate('''({source, attrs}) => {
            const s=document.createElement('script');
            s.dataset.gtmId=attrs.id; s.dataset.gtmContacts=attrs.contacts;
            s.textContent=source; document.body.appendChild(s);
        }''', {'source':gtm,'attrs':attrs})

    with sync_playwright() as p:
        executable = shutil.which('chromium') or p.chromium.executable_path
        browser = p.chromium.launch(executable_path=executable,headless=True,
                                    args=['--no-sandbox','--disable-gpu'])
        context = browser.new_context()
        def capture(route):
            remote.append(route.request.url)
            route.fulfill(status=200, content_type='application/javascript',
                          body='window.__fakeGTM = true;')
        context.route('https://www.googletagmanager.com/gtm.js*',capture)
        page = context.new_page()
        install(page)
        assert page.locator('#cookie-consent').is_visible()
        assert not remote, 'loaded GTM without consent'
        assert page.evaluate('CajeerGTM.status().initialized') is False
        assert page.evaluate('CajeerGTM.track("cajeer_signup")') is False
        initial=page.evaluate('() => window.dataLayer.map(entry => (typeof entry.length === "number" ? Array.from(entry) : [entry]))')
        assert initial[0][:2]==['consent','default'],initial
        assert all(x=='denied' for x in initial[0][2].values())
        page.locator('[data-consent-action="reject"]').first.click()
        assert not remote, 'GTM requested after rejection'
        page.locator('[data-consent-open]').click()
        page.locator('[data-consent-category="analytics"]').check()
        page.locator('[data-consent-action="save"]').click()
        page.wait_for_function('window.__fakeGTM === true')
        assert len(remote)==1 and remote[0].endswith('gtm.js?id=GTM-ABCD123')
        entries=page.evaluate('() => window.dataLayer.map(entry => (typeof entry.length === "number" ? Array.from(entry) : [entry]))')
        assert entries[0][:2]==['consent','default']
        assert entries[1][:2]==['consent','update']
        assert entries[1][2]['analytics_storage']=='granted'
        assert entries[1][2]['ad_storage']=='denied'
        assert entries[1][2]['ad_user_data']=='denied'
        assert entries[1][2]['ad_personalization']=='denied'
        assert entries[2][0]['event']=='cajeer_consent_update'
        assert entries[3][0]['event']=='gtm.js'
        assert sum(1 for entry in entries if entry and isinstance(entry[0],dict)
                   and entry[0].get('event')=='gtm.js')==1
        assert page.evaluate('CajeerGTM.track("cajeer_signup",{placement:"footer",count:2})') is True
        assert page.evaluate('CajeerGTM.track("cajeer_signup",{email:"private@example.org"})') is False
        assert page.evaluate('CajeerGTM.track("cajeer_signup",{placement:"private@example.org"})') is False
        assert page.evaluate('CajeerGTM.track("cajeer_signup",{phone:"1234567890"})') is False
        assert page.evaluate('CajeerGTM.track("cajeer_signup",{},"marketing")') is False
        assert page.evaluate('CajeerGTM.track("cajeer_consent_update")') is False
        page.evaluate('''() => {
            const a=document.createElement('a');a.href='mailto:private@example.org';
            document.body.appendChild(a);a.addEventListener('click',ev=>ev.preventDefault());
            a.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true}));
        }''')
        entries=page.evaluate('() => window.dataLayer.map(x => (typeof x.length === "number" ? Array.from(x) : [x]))')
        assert any(x and isinstance(x[0],dict) and x[0].get('event')=='cajeer_contact_click'
                   and x[0].get('contact_type')=='email' for x in entries)
        assert 'private@example.org' not in str(entries), 'PII leaked to dataLayer'
        page.evaluate('document.cookie="_ga=GA1.1.secret; Path=/"')
        page.evaluate('CajeerConsent.revoke()')
        assert page.evaluate('window.__gtmReloadCalls')==1
        assert page.evaluate('CajeerGTM.status().reloading') is True
        assert page.evaluate('CajeerGTM.track("cajeer_signup")') is False
        assert page.evaluate('document.cookie.includes("_ga=")') is False
        assert len(remote)==1, 'GTM fetched again on revoke'
        # New navigation with marketing-only permission has distinct v2 fields.
        page2=context.new_page()
        install(page2,initial_mask=2)
        page2.wait_for_function('window.__fakeGTM === true')
        assert len(remote)==2
        entries=page2.evaluate('() => window.dataLayer.map(x=>(typeof x.length === "number" ? Array.from(x) : [x]))')
        assert entries[1][2]['analytics_storage']=='denied'
        assert entries[1][2]['ad_storage']=='granted'
        assert entries[1][2]['ad_user_data']=='granted'
        assert entries[1][2]['ad_personalization']=='granted'
        assert page2.evaluate('CajeerGTM.track("cajeer_conversion",{},"marketing")') is True
        assert page2.evaluate('CajeerGTM.track("cajeer_signup")') is False
        page2.locator('[data-consent-open]').click()
        page2.locator('[data-consent-category="marketing"]').uncheck()
        page2.locator('[data-consent-category="analytics"]').check()
        page2.locator('[data-consent-action="save"]').click()
        assert page2.evaluate('window.__gtmReloadCalls')==1
        assert page2.evaluate('CajeerGTM.track("cajeer_conversion",{},"marketing")') is False
        assert not errors, errors
        browser.close()
print('GTM 1.0.6 Chromium consent/queue/events/revocation OK (provider/navigation emulated)')
