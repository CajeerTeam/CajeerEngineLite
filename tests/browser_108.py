#!/usr/bin/env python3
"""Matomo Cloud and On-Premise real PHP markup + JS + Consent UI.

Third-party Matomo responses and location.reload are emulated. This does NOT
verify network delivery to a user's production Matomo installation.
"""
import os
import pathlib
import re
import shutil
import subprocess
import tempfile
from playwright.sync_api import sync_playwright

SOURCE = pathlib.Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='lite-108-matomo-') as folder:
    root = pathlib.Path(folder) / 'site'
    shutil.copytree(SOURCE, root)
    subprocess.run(['php','bin/cajeer','install','https://lite.example.org','Matomo test'],
                   cwd=root, env={**os.environ, 'CAJEER_ADMIN_PASSWORD':'StrongPassword123'},
                   check=True, capture_output=True)
    def html_for(mode, url, site):
        program = r'''require "bootstrap.php";
            $app=new \CajeerEngine\Lite(getcwd());
            $app->change(static function(array $s): array {
                $s['integrations']['matomo']=\CajeerEngine\Integrations\MatomoConfig::fromPost([
                    'enabled'=>'1','mode'=>getenv('MATOMO_MODE'),
                    'server_url'=>getenv('MATOMO_URL'), 'site_id'=>getenv('MATOMO_SITE'),
                    'page_views'=>'1','contact_events'=>'1']);
                $s['settings']['email']='secret@example.org';
                $s['settings']['phone']='+12025550100';
                return $s;
            });
            $s=$app->load(); echo $app->render($s,$s['pages']['/']);'''
        raw = subprocess.check_output(['php','-r',program],cwd=root,text=True,
                                      env={**os.environ,'MATOMO_MODE':mode,'MATOMO_URL':url,'MATOMO_SITE':site})
        match = re.search(r'<script defer src="/assets/matomo.js\?v=108" ([^>]*)></script>',raw)
        assert match, 'Matomo missing from rendered PHP template'
        attrs = dict(re.findall(r'data-matomo-([a-z-]+)="([^"]*)"',match.group(1)))
        assert attrs == {'mode':mode,'url':url,'site':site,'pageviews':'1','contacts':'1'},attrs
        raw = re.sub(r'<script defer src="/assets/(?:consent|ga4|metrica|gtm|clarity|matomo).js\?v=\d+"[^>]*></script>', '',raw)
        return raw,attrs

    consent=(SOURCE/'public/assets/consent.js').read_text()
    matomo=(SOURCE/'public/assets/matomo.js').read_text()
    assert matomo.count('location.reload();')==1
    matomo=matomo.replace('location.reload();','window.__matomoReloadCalls=(window.__matomoReloadCalls||0)+1;')
    requests=[]
    errors=[]

    with sync_playwright() as p:
        executable = shutil.which('chromium') or p.chromium.executable_path
        browser = p.chromium.launch(executable_path=executable,headless=True,args=['--no-sandbox','--disable-gpu'])
        context = browser.new_context()
        def intercept(route):
            requests.append(route.request.url)
            route.fulfill(status=200,content_type='application/javascript',body='window.__matomoRemoteLoaded=true;')
        context.route('https://cdn.matomo.cloud/**',intercept)
        context.route('https://stats.example.org/**',intercept)

        def setup(page,mode,url,site='9',mask=None):
            html,attrs=html_for(mode,url,site)
            page.on('pageerror',lambda error:errors.append(str(error)))
            page.evaluate('''() => {
                const jar = new Map();
                Object.defineProperty(document,'cookie',{
                    configurable:true,
                    get() {return Array.from(jar,([k,v])=>`${k}=${v}`).join('; ');},
                    set(raw) {
                        const pair=raw.split(';')[0]; const index=pair.indexOf('=');
                        if(index<0) return;
                        const key=pair.slice(0,index), value=pair.slice(index+1);
                        if(/Max-Age=0/i.test(raw)) jar.delete(key);
                        else jar.set(key,value);
                    }
                });
            }''')
            if mask is not None:
                page.evaluate('''mask=>{
                    document.cookie=`cajeer_lite_consent=v1.1.${Math.floor(Date.now()/1000)}.${mask}; Path=/`;
                }''',mask)
            page.set_content(html)
            page.add_script_tag(content=consent)
            page.evaluate('''({source,attrs}) => {
                const s=document.createElement('script');
                s.dataset.matomoMode=attrs.mode;
                s.dataset.matomoUrl=attrs.url;
                s.dataset.matomoSite=attrs.site;
                s.dataset.matomoPageviews=attrs.pageviews;
                s.dataset.matomoContacts=attrs.contacts;
                s.textContent=source;
                document.body.appendChild(s);
            }''', {'source':matomo,'attrs':attrs})

        def queue(page):
            return page.evaluate('''() => Array.isArray(window._paq) ? window._paq : []''')

        page=context.new_page()
        setup(page,'cloud','https://site.matomo.cloud/')
        assert not requests and not queue(page), 'Matomo contacted before consent'
        assert page.evaluate('CajeerMatomo.status().initialized') is False
        assert page.evaluate('CajeerMatomo.event("contact","email")') is False
        page.locator('[data-consent-action="reject"]').first.click()
        assert not requests and not queue(page), 'Matomo contacted after denial'
        page.locator('[data-consent-open]').click()
        page.locator('[data-consent-category="marketing"]').check()
        page.locator('[data-consent-action="save"]').click()
        assert not requests, 'Marketing alone started Matomo'
        page.locator('[data-consent-open]').click()
        page.locator('[data-consent-category="analytics"]').check()
        page.locator('[data-consent-action="save"]').click()
        page.wait_for_function('window.__matomoRemoteLoaded === true')
        assert requests==['https://cdn.matomo.cloud/site.matomo.cloud/matomo.js'], requests
        q=queue(page)
        assert q[:6]==[['requireConsent'],['requireCookieConsent'],
                       ['setTrackerUrl','https://site.matomo.cloud/matomo.php'],
                       ['setSiteId','9'],['setConsentGiven'],['setCookieConsentGiven']],q
        assert q[6]==['trackPageView'] and sum(e[0]=='trackPageView' for e in q)==1,q
        assert page.evaluate('CajeerMatomo.event("signup","complete","footer")') is True
        assert page.evaluate('CajeerMatomo.goal(4,12.5)') is True
        assert page.evaluate('CajeerMatomo.goal("4")') is False
        assert page.evaluate('CajeerMatomo.event("email@example.org","click")') is False
        assert page.evaluate('CajeerMatomo.event("contact","phone1234567890")') is False
        page.evaluate('''() => {
            for(const href of ['mailto:secret@example.org','tel:+12025550100']) {
                const a=document.createElement('a'); a.href=href; document.body.appendChild(a);
                a.addEventListener('click',e=>e.preventDefault());
                a.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true}));
            }
        }''')
        q=queue(page)
        assert ['trackEvent','signup','complete','footer'] in q
        assert ['trackGoal',4,12.5] in q
        assert ['trackEvent','contact','email'] in q and ['trackEvent','contact','phone'] in q
        assert 'secret@example.org' not in str(q) and '12025550100' not in str(q)
        page.evaluate('''() => {
            document.cookie='_pk_id.9.123=secret; Path=/';
            document.cookie='_pk_ses.9.123=secret; Path=/';
            document.cookie='site_setting=keep; Path=/';
        }''')
        page.evaluate('CajeerConsent.revoke()')
        assert page.evaluate('window.__matomoReloadCalls') == 1
        assert page.evaluate('CajeerMatomo.status().reloading') is True
        assert queue(page)[-3:] == [['forgetConsentGiven'],['forgetCookieConsentGiven'],['deleteCookies']]
        assert page.evaluate('CajeerMatomo.event("signup","complete")') is False
        assert page.evaluate('CajeerMatomo.goal(4)') is False
        assert page.evaluate('document.cookie.includes("_pk_id") || document.cookie.includes("_pk_ses")') is False
        assert page.evaluate('document.cookie.includes("site_setting=keep")') is True
        assert len(requests)==1,'Matomo requested again on revocation'

        page2=context.new_page()
        setup(page2,'cloud','https://site.matomo.cloud/',mask=0)
        assert len(requests)==1, 'Stored refusal did not block Matomo'
        page3=context.new_page()
        setup(page3,'onpremise','https://stats.example.org/matomo/',site='11',mask=1)
        page3.wait_for_function('window.__matomoRemoteLoaded === true')
        assert requests[-1]=='https://stats.example.org/matomo/matomo.js',requests
        assert queue(page3)[2:4] == [['setTrackerUrl','https://stats.example.org/matomo/matomo.php'],['setSiteId','11']]
        assert sum(e[0]=='trackPageView' for e in queue(page3))==1
        assert not errors,errors
        browser.close()
print('Lite 1.0.8 Matomo Cloud/On-Premise browser consent/events/goals/revocation OK (provider/navigation emulated)')
