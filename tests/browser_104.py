#!/usr/bin/env python3
"""Chromium integration of actual PHP render and shipped JS with an emulated cookie jar.

Local HTTP navigation is restricted in some test sandboxes. HTTP endpoints are
verified separately by tests/http.sh. The Google script response is mocked;
this does not verify data delivery to a live GA4 property.
"""
import os
import pathlib
import re
import shutil
import subprocess
import tempfile
from playwright.sync_api import sync_playwright

SOURCE = pathlib.Path(__file__).resolve().parent.parent
chrome = next((p for n in ('chromium', 'google-chrome', 'google-chrome-stable')
               if (p := shutil.which(n))), None)

with tempfile.TemporaryDirectory(prefix='lite-104-ui-') as folder:
    root = pathlib.Path(folder) / 'site'
    shutil.copytree(SOURCE, root)
    subprocess.run(['php', 'bin/cajeer', 'install', 'https://lite.example.org', 'GA4 Test'], cwd=root,
                   env={**os.environ, 'CAJEER_ADMIN_PASSWORD': 'TestPassword123'},
                   check=True, capture_output=True)
    configure = '''require "bootstrap.php";
        $app=new \\CajeerEngine\\Lite(getcwd());
        $app->change(static function(array $d): array {
            $d['integrations']['ga4'] = \\CajeerEngine\\Integrations\\GA4Config::fromPost([
                'enabled'=>'1','measurement_id'=>'G-1234567890',
                'page_views'=>'1','contact_clicks'=>'1','debug_mode'=>'1']);
            return $d;
        });
        $s=$app->load(); echo $app->render($s,$s['pages']['/']);'''
    html = subprocess.check_output(['php', '-r', configure], cwd=root, text=True)
    tag = re.search(r'<script defer src="/assets/ga4.js\?v=109" ([^>]*)></script>', html)
    assert tag, 'GA4 runtime missing from PHP rendered template'
    ga4attrs = dict(re.findall(r'data-ga4-([a-z]+)="([^"]*)"', tag.group(1)))
    assert ga4attrs['id'] == 'G-1234567890' and ga4attrs['pageviews'] == '1', ga4attrs
    html = re.sub(r'<script defer src="/assets/(?:consent|ga4).js\?v=\d+"></script>', '', html)
    html = re.sub(r'<script defer src="/assets/ga4.js\?v=109" [^>]*></script>', '', html)
    html = html.replace('<link rel="stylesheet" href="/assets/style.css">', '')
    consentjs = (root/'public/assets/consent.js').read_text()
    ga4js = (root/'public/assets/ga4.js').read_text()

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=chrome or playwright.chromium.executable_path, headless=True,
                     args=['--no-sandbox', '--disable-gpu'])
        page = browser.new_page()
        errors = []
        remote = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        def capture(route):
            remote.append(route.request.url)
            route.fulfill(status=200, content_type='application/javascript',
                          body='window.__mockGoogleTagLoaded = true;')
        page.route('https://www.googletagmanager.com/gtag/js?*', capture)
        page.goto('about:blank')
        page.evaluate('''() => {
          const jar = new Map();
          Object.defineProperty(document, 'cookie', {
            configurable: true,
            get() { return Array.from(jar, ([k,v])=>`${k}=${v}`).join('; '); },
            set(value) {
              const part = value.split(';')[0]; const idx = part.indexOf('=');
              if (idx < 0) return;
              const key = part.slice(0,idx), val=part.slice(idx+1);
              if (/Max-Age=0/i.test(value)) jar.delete(key);
              else jar.set(key,val);
            }
          });
        }''')
        page.set_content(html)
        page.add_script_tag(content=consentjs)
        # Appending an actual script node preserves document.currentScript.
        page.evaluate('''({source, attrs}) => {
            const script=document.createElement('script');
            script.dataset.ga4Id=attrs.id;
            script.dataset.ga4Pageviews=attrs.pageviews;
            script.dataset.ga4Contacts=attrs.contacts;
            script.dataset.ga4Debug=attrs.debug;
            script.textContent=source;
            document.body.appendChild(script);
        }''', {'source':ga4js,'attrs':ga4attrs})
        assert page.locator('#cookie-consent').is_visible(), 'banner missing'
        assert page.evaluate('CajeerGA4.status().consent') is False
        assert page.evaluate('CajeerGA4.track("custom_event")') is False
        assert not remote, 'Google tag requested without visitor consent'
        page.locator('[data-consent-action="reject"]').first.click()
        assert not remote, 'Google tag requested on rejection'
        page.locator('[data-consent-open]').click()
        page.locator('[data-consent-category="analytics"]').check()
        page.locator('[data-consent-action="save"]').click()
        page.wait_for_function('window.__mockGoogleTagLoaded === true')
        assert len(remote) == 1, f'Google tag requested {len(remote)} times'
        page.wait_for_function('CajeerGA4.status().tagLoaded')
        data = page.evaluate('() => window.dataLayer.map(x => Array.from(x))')
        assert data[0][:2] == ['consent','default'] and data[0][2]['analytics_storage'] == 'denied', 'default consent missing'
        assert data[1][:2] == ['consent','update'] and data[1][2]['analytics_storage'] == 'granted', 'consent update missing'
        assert data[1][2]['ad_storage'] == 'denied' and data[1][2]['ad_user_data'] == 'denied', 'marketing enabled without consent'
        assert sum(e[:2]==['event','page_view'] for e in data) == 1, 'not exactly one page_view'
        assert any(e[:2]==['config','G-1234567890'] and e[2]['send_page_view'] is False and e[2]['debug_mode'] for e in data), 'gtag config invalid'
        assert page.evaluate('CajeerGA4.track("feature_used", {kind:"test", amount:5})') is True
        assert page.evaluate('CajeerGA4.track("page_view")') is False
        assert page.evaluate('CajeerGA4.track("feature_used", {send_to:"different"})') is False
        assert page.evaluate('CajeerGA4.track("<script>")') is False
        page.evaluate('''() => {const a=document.createElement('a');a.href='mailto:secret@example.com';a.textContent='Mail';document.body.append(a);a.addEventListener('click',ev=>ev.preventDefault());a.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true}));}''')
        data = page.evaluate('() => window.dataLayer.map(x => Array.from(x))')
        assert any(e[:2]==['event','contact_click'] and e[2]['contact_type']=='email' and 'secret@example.com' not in str(e) for e in data), 'missing or unsafe contact event'
        page.evaluate('document.cookie = "_ga=GA1.1.testing;path=/"')
        page.locator('[data-consent-open]').click()
        page.locator('[data-consent-action="reject"]').last.click()
        assert page.evaluate('CajeerGA4.status().consent') is False
        assert page.evaluate('window["ga-disable-G-1234567890"]') is True
        assert page.evaluate('CajeerGA4.track("feature_used")') is False
        assert page.evaluate('document.cookie.includes("_ga=")') is False, 'GA cookie not cleared'
        assert page.evaluate('window.dataLayer.slice(-1)[0][0]') == 'consent', 'revocation not updated'
        page.locator('[data-consent-open]').click()
        page.locator('[data-consent-category="analytics"]').check()
        page.locator('[data-consent-category="marketing"]').check()
        page.locator('[data-consent-action="save"]').click()
        assert page.evaluate('CajeerGA4.status().consent') is True
        assert len(remote) == 1, 'Google tag downloaded twice on regrant'
        assert page.evaluate('window.dataLayer.slice(-1)[0][2].analytics_storage') == 'granted', 'regrant not updated'
        assert page.evaluate('window.dataLayer.slice(-1)[0][2].ad_storage') == 'granted', 'marketing consent not applied'
        assert sum(e[:2]==['event','page_view'] for e in page.evaluate('window.dataLayer.map(x=>Array.from(x))')) == 1, 'pageview repeated'
        assert not errors, f'Browser JavaScript errors: {errors}'
        browser.close()
print('GA4 Chromium runtime/consent integration OK (emulated cookies and Google response)')
