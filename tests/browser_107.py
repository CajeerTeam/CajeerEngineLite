#!/usr/bin/env python3
"""Microsoft Clarity consent behavior with the real PHP template + real JS.

Provider responses, cookie storage and reload() are emulated for sandbox
browser environments. Data delivery to a real Clarity project is NOT claimed.
"""
import os
import pathlib
import re
import shutil
import subprocess
import tempfile
from playwright.sync_api import sync_playwright

SOURCE = pathlib.Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='lite-107-clarity-') as folder:
    root = pathlib.Path(folder) / 'site'
    shutil.copytree(SOURCE, root)
    subprocess.run(['php','bin/cajeer','install','https://lite.example.org','Clarity test'],
                   cwd=root, env={**os.environ, 'CAJEER_ADMIN_PASSWORD':'StrongPassword123'},
                   check=True, capture_output=True)
    program = r'''require "bootstrap.php";
        $app=new \CajeerEngine\Lite(getcwd());
        $app->change(static function(array $s): array {
            $s['integrations']['clarity']=\CajeerEngine\Integrations\ClarityConfig::fromPost([
                'enabled'=>'1','project_id'=>'abc123xy','recordings'=>'1','contact_events'=>'1']);
            $s['settings']['email']='private@example.org';
            $s['settings']['phone']='+12025550100';
            return $s;
        });
        $s=$app->load(); echo $app->render($s,$s['pages']['/']);'''
    html = subprocess.check_output(['php', '-r', program],cwd=root,text=True)
    assert 'data-clarity-mask="true"' in html, 'sensitive content missing mask'
    match=re.search(r'<script defer src="/assets/clarity.js\?v=107" ([^>]*)></script>',html)
    assert match, 'Clarity module missing from rendered PHP HTML'
    attrs = dict(re.findall(r'data-clarity-([a-z-]+)="([^"]*)"',match.group(1)))
    assert attrs=={'id':'abc123xy','contacts':'1'},attrs
    html=re.sub(r'<script defer src="/assets/(?:consent|ga4|metrica|gtm|clarity).js\?v=\d+"[^>]*></script>','',html)
    consent=(SOURCE/'public/assets/consent.js').read_text()
    clarity=(SOURCE/'public/assets/clarity.js').read_text()
    assert clarity.count('location.reload();')==1
    clarity=clarity.replace('location.reload();','window.__clarityReloadCalls=(window.__clarityReloadCalls||0)+1;')
    requests=[]
    errors=[]

    with sync_playwright() as p:
        executable=shutil.which('chromium') or p.chromium.executable_path
        browser=p.chromium.launch(executable_path=executable,headless=True,args=['--no-sandbox','--disable-gpu'])
        context=browser.new_context()
        context.route('https://www.clarity.ms/tag/*',lambda route:(requests.append(route.request.url),
            route.fulfill(status=200,content_type='application/javascript',body='window.__fakeClarityLoaded=true;')))
        def setup(page, mask=None):
            page.on('pageerror',lambda error:errors.append(str(error)))
            page.evaluate('''() => {
              const jar=new Map();
              Object.defineProperty(document,'cookie',{
                configurable:true,
                get() {return Array.from(jar,([k,v]) => `${k}=${v}`).join('; ');},
                set(raw) {
                  const pair=raw.split(';')[0];const i=pair.indexOf('=');
                  if(i<0)return;
                  const name=pair.slice(0,i),value=pair.slice(i+1);
                  if(/Max-Age=0/i.test(raw)) jar.delete(name);
                  else jar.set(name,value);
                }
              });
            }''')
            if mask is not None:
                page.evaluate('''mask => {
                  document.cookie=`cajeer_lite_consent=v1.1.${Math.floor(Date.now()/1000)}.${mask}; Path=/`;
                }''',mask)
            page.set_content(html)
            page.add_script_tag(content=consent)
            page.evaluate('''({source,attrs}) => {
              const s=document.createElement('script');
              s.dataset.clarityId=attrs.id;s.dataset.clarityContacts=attrs.contacts;
              s.textContent=source; document.body.appendChild(s);
            }''',{'source':clarity,'attrs':attrs})
        def calls(page):
            return page.evaluate('''() => typeof window.clarity === 'function'
                ? (window.clarity.q || []).map(x => Array.from(x)) : []''')

        page=context.new_page()
        setup(page)
        assert not requests and calls(page)==[]
        assert page.evaluate('CajeerClarity.event("cajeer_conversion")') is False
        page.locator('[data-consent-action="reject"]').first.click()
        assert not requests
        page.locator('[data-consent-open]').click()
        page.locator('[data-consent-category="analytics"]').check()
        page.locator('[data-consent-action="save"]').click()
        assert not requests,'Clarity loaded on analytics consent without recordings'
        page.locator('[data-consent-open]').click()
        page.locator('[data-consent-category="analytics"]').uncheck()
        page.locator('[data-consent-category="recordings"]').check()
        page.locator('[data-consent-action="save"]').click()
        assert not requests,'Clarity loaded on recordings consent without analytics'
        page.locator('[data-consent-open]').click()
        page.locator('[data-consent-category="analytics"]').check()
        page.locator('[data-consent-action="save"]').click()
        page.wait_for_function('window.__fakeClarityLoaded === true')
        assert len(requests)==1 and requests[0].endswith('/tag/abc123xy'),requests
        history=calls(page)
        assert history[0]==['consentv2',{'analytics_Storage':'granted','ad_Storage':'denied'}],history
        assert page.evaluate('CajeerClarity.status().permitted') is True
        assert page.evaluate('CajeerClarity.event("cajeer_conversion")') is True
        assert page.evaluate('CajeerClarity.event("private@example.org")') is False
        assert page.evaluate('CajeerClarity.event("cajeer_1234567890")') is False
        page.evaluate('''() => {
           for(const href of ['mailto:private@example.org','tel:+12025550100']) {
             const a=document.createElement('a');a.href=href;document.body.appendChild(a);
             a.addEventListener('click',ev=>ev.preventDefault());
             a.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true}));
           }
        }''')
        history=calls(page)
        assert ['event','cajeer_contact_email'] in history and ['event','cajeer_contact_phone'] in history
        assert 'private@example.org' not in str(history) and '12025550100' not in str(history)
        page.locator('[data-consent-open]').click()
        page.locator('[data-consent-category="marketing"]').check()
        page.locator('[data-consent-action="save"]').click()
        assert calls(page)[-1]==['consentv2',{'analytics_Storage':'granted','ad_Storage':'granted'}]
        assert not page.evaluate('window.__clarityReloadCalls || 0'), 'marketing update triggered unnecessary reload'
        page.locator('[data-consent-open]').click()
        page.locator('[data-consent-category="marketing"]').uncheck()
        page.locator('[data-consent-action="save"]').click()
        assert calls(page)[-1]==['consentv2',{'analytics_Storage':'granted','ad_Storage':'denied'}]
        page.evaluate('document.cookie="_clck=private; Path=/";document.cookie="_clsk=secret; Path=/";document.cookie="site_setting=keep; Path=/";')
        page.evaluate('CajeerConsent.revoke()')
        assert page.evaluate('window.__clarityReloadCalls')==1
        assert page.evaluate('CajeerClarity.status().reloading') is True
        assert calls(page)[-1]==['consentv2',{'analytics_Storage':'denied','ad_Storage':'denied'}]
        assert page.evaluate('CajeerClarity.event("cajeer_conversion")') is False
        assert page.evaluate('document.cookie.includes("_clck=") || document.cookie.includes("_clsk=")') is False
        assert page.evaluate('document.cookie.includes("site_setting=keep")') is True
        assert len(requests)==1,'tag re-fetched after withdrawal'
        assert page.evaluate('window.CajeerConsent.get().saved && !window.CajeerConsent.get().recordings')

        # New navigation with explicit non-recording choice must not contact Microsoft.
        page2=context.new_page()
        setup(page2,mask=1)
        assert len(requests)==1
        assert page2.evaluate('CajeerClarity.status().initialized') is False
        # A returning visitor with both consent categories immediately starts.
        page3=context.new_page()
        setup(page3,mask=7)
        page3.wait_for_function('window.__fakeClarityLoaded === true')
        assert len(requests)==2
        assert calls(page3)[0]==['consentv2',{'analytics_Storage':'granted','ad_Storage':'granted'}]
        assert not errors, errors
        browser.close()
print('Lite 1.0.7 Clarity Chromium consent/recordings/revocation OK (provider/navigation emulated)')
