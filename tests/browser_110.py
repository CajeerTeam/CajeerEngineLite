#!/usr/bin/env python3
"""Browser acceptance for 1.1.0 consent cross-tab sync and GA4 hard withdrawal.
Provider responses and navigation are simulated; live account delivery is NOT tested.
"""
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='lite-110-browser-') as work:
    site = Path(work) / 'site'
    shutil.copytree(root, site)
    subprocess.run(['php', 'bin/cajeer', 'install', 'https://lite.example.org', 'Acceptance'],
        cwd=site, env={**os.environ, 'CAJEER_ADMIN_PASSWORD': 'VeryStrongPassword123'},
        check=True, capture_output=True)
    source = '''require "bootstrap.php";
        $app=new \\CajeerEngine\\Lite(getcwd());
        $app->change(static function(array $s): array {
          $s['integrations']['ga4']=\\CajeerEngine\\Integrations\\GA4Config::fromPost([
            'enabled'=>'1','measurement_id'=>'G-1234567890','page_views'=>'1','contact_clicks'=>'1']);
          return $s;
        });
        $state=$app->load(); echo $app->render($state,$state['pages']['/']);'''
    markup = subprocess.check_output(['php', '-r', source], cwd=site, text=True)
    assert 'src="/assets/ga4.js?v=110"' in markup
    markup = re.sub(r'<script defer src="/assets/[^>]+></script>', '', markup)
    markup = markup.replace('<link rel="stylesheet" href="/assets/style.css">','')
    consent = (site/'public/assets/consent.js').read_text()
    ga4 = (site/'public/assets/ga4.js').read_text()
    assert ga4.count('location.reload();') == 1
    ga4 = ga4.replace('location.reload();',
        'window.__ga4Reload=(window.__ga4Reload||0)+1;')
    requests, errors = [], []
    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=shutil.which('chromium') or pw.chromium.executable_path,
            headless=True, args=['--no-sandbox','--disable-gpu'])
        page = browser.new_page()
        page.on('pageerror', lambda e: errors.append(str(e)))
        page.route('https://www.googletagmanager.com/**', lambda route:
            (requests.append(route.request.url), route.fulfill(status=200,
              content_type='text/javascript', body='window.__googleMock=true')))
        page.evaluate('''() => {
          const cookies=new Map();
          Object.defineProperty(document,'cookie',{
            configurable:true,
            get(){return [...cookies].map(([k,v])=>k+'='+v).join('; ');},
            set(raw){const part=raw.split(';')[0];const i=part.indexOf('=');
              if(i<0)return;const k=part.slice(0,i),v=part.slice(i+1);
              if(/Max-Age=0/i.test(raw))cookies.delete(k);else cookies.set(k,v);
            }
          });
        }''')
        page.set_content(markup)
        page.evaluate('''src => { const s=document.createElement('script');
          s.textContent=src;document.body.append(s); }''', consent)
        assert page.locator('#cookie-consent').is_visible()
        assert requests == [], 'request occurred before consent'
        # Simulate a different tab updating the single shared consent cookie.
        page.evaluate('''() => {
          document.cookie=`cajeer_lite_consent=v1.1.${Math.floor(Date.now()/1000)}.7`;
          window.dispatchEvent(new Event('focus'));
        }''')
        assert page.locator('#cookie-consent').is_hidden(), 'cross-tab grant left stale banner'
        page.locator('[data-consent-open]').click()
        assert page.locator('[data-consent-category="analytics"]').is_checked()
        page.evaluate('''() => {
          document.cookie=`cajeer_lite_consent=v1.1.${Math.floor(Date.now()/1000)}.0`;
          window.dispatchEvent(new Event('focus'));
        }''')
        assert not page.locator('[data-consent-category="analytics"]').is_checked(), 'stale editor'
        page.evaluate('''() => {
          document.cookie=`cajeer_lite_consent=v1.1.${Math.floor(Date.now()/1000)}.1`;
          window.dispatchEvent(new Event('focus'));
        }''')
        assert page.locator('[data-consent-category="analytics"]').is_checked()
        page.locator('[data-consent-action="cancel"]').click()
        assert page.locator('#cookie-consent').is_hidden()
        page.evaluate('''src => { const s=document.createElement('script');
          s.dataset.ga4Id='G-1234567890';s.dataset.ga4Pageviews='1';
          s.dataset.ga4Contacts='1';s.textContent=src;document.body.append(s); }''',ga4)
        page.wait_for_function('CajeerGA4.status().tagLoaded')
        assert len(requests)==1 and 'gtag/js?id=' in requests[0]
        assert page.evaluate('CajeerGA4.track("signup", {source:"footer"})')
        page.evaluate('''() => {document.cookie='_ga=abc';document.cookie='site_pref=keep';}''')
        assert page.evaluate('CajeerConsent.revoke()') is True
        assert page.evaluate('window.__ga4Reload') == 1, 'GA4 did not enforce hard withdrawal'
        assert page.evaluate('CajeerGA4.status().reloading') is True
        assert page.evaluate('CajeerGA4.track("signup")') is False
        assert not page.evaluate('document.cookie.includes("_ga=")')
        assert page.evaluate('document.cookie.includes("site_pref=keep")')
        assert len(requests)==1, 'new Google network request after withdrawal'
        assert errors == [], errors
        browser.close()
print('Lite 1.1.0 Chromium cross-tab consent and GA4 withdrawal acceptance OK (provider mocked)')
