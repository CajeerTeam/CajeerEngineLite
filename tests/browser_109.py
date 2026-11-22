#!/usr/bin/env python3
"""1.0.9 combined consent/analytics integration using real PHP HTML and JS.

Remote provider script responses and reloads are mocked: this checks the local
security boundaries, event queues, and browser behavior, NOT real data delivery.
"""
import html as html_entities
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
from playwright.sync_api import sync_playwright

SOURCE = Path(__file__).resolve().parent.parent
COOKIE_JS = (SOURCE / 'public/assets/consent.js').read_text()
JS = {name: (SOURCE / f'public/assets/{name}.js').read_text()
      for name in ['ga4', 'metrica', 'gtm', 'clarity', 'matomo']}
for name in ['metrica', 'gtm', 'clarity', 'matomo']:
    assert JS[name].count('location.reload();') == 1
    JS[name] = JS[name].replace('location.reload();',
        f'window.__reload_{name}=(window.__reload_{name}||0)+1;')

with tempfile.TemporaryDirectory(prefix='lite-109-browser-') as directory:
    site = Path(directory) / 'app'
    shutil.copytree(SOURCE, site)
    subprocess.run(['php', 'bin/cajeer', 'install', 'https://lite.example.org', 'Integration test'],
                   cwd=site, env={**os.environ, 'CAJEER_ADMIN_PASSWORD': 'VeryStrongTestPassword123'},
                   check=True, capture_output=True)

    def render(gtm=True):
        program = '''require "bootstrap.php";
            $app=new \\CajeerEngine\\Lite(getcwd());
            $app->change(static function(array $s): array {
              $s['settings']['email']='sensitive@example.com';
              $s['settings']['phone']='+12025550123';
              $s['integrations']['ga4']=\\CajeerEngine\\Integrations\\GA4Config::fromPost([
                'enabled'=>'1','measurement_id'=>'G-1234567890','page_views'=>'1','contact_clicks'=>'1']);
              $s['integrations']['gtm']=\\CajeerEngine\\Integrations\\GTMConfig::fromPost([
                'container_id'=>'GTM-ABCDE123','contact_events'=>'1'] +
                (getenv('GTM_ACTIVE') === '1' ? ['enabled'=>'1'] : []));
              $s['integrations']['metrica']=\\CajeerEngine\\Integrations\\MetricaConfig::fromPost([
                'enabled'=>'1','counter_id'=>'12345678','page_views'=>'1','webvisor'=>'1',
                'clickmap'=>'1','contact_goals'=>'1',
                'email_goal'=>'contact_email','phone_goal'=>'contact_phone']);
              $s['integrations']['clarity']=\\CajeerEngine\\Integrations\\ClarityConfig::fromPost([
                'enabled'=>'1','project_id'=>'abc123','recordings'=>'1','contact_events'=>'1']);
              $s['integrations']['matomo']=\\CajeerEngine\\Integrations\\MatomoConfig::fromPost([
                'enabled'=>'1','mode'=>'cloud','server_url'=>'https://site.matomo.cloud/',
                'site_id'=>'9','page_views'=>'1','contact_events'=>'1']);
              return $s;
            });
            $state=$app->load(); echo $app->render($state,$state['pages']['/']);'''
        markup = subprocess.check_output(['php', '-r', program], cwd=site, text=True,
             env={**os.environ, 'GTM_ACTIVE': '1' if gtm else '0'})
        pattern = r'<script defer src="/assets/([a-z0-9]+)\.js\?v=\d+"([^>]*)></script>'
        scripts = []
        for match in re.finditer(pattern, markup):
            name = match.group(1)
            attrs = dict((k, html_entities.unescape(v)) for k, v in re.findall(
                r'(data-[a-z0-9-]+)="([^"]*)"', match.group(2)))
            scripts.append((name, attrs))
        markup = re.sub(pattern, '', markup)
        assert scripts[0][0] == 'consent', scripts
        assert not any(k == 'ga4' for k, _ in scripts) if gtm else any(k == 'ga4' for k, _ in scripts)
        return markup, scripts

    pages = {True: render(True), False: render(False)}
    requests, errors = [], []

    with sync_playwright() as p:
        executable = shutil.which('chromium') or p.chromium.executable_path
        browser = p.chromium.launch(executable_path=executable, headless=True,
                                    args=['--no-sandbox', '--disable-gpu'])
        context = browser.new_context()
        def intercept(route):
            requests.append(route.request.url)
            route.fulfill(status=200, content_type='text/javascript',
                          body='window.__mockProviderResponses=(window.__mockProviderResponses||0)+1;')
        context.route('https://**/*', intercept)

        def setup(gtm=True, mask=None):
            page = context.new_page()
            page.on('pageerror', lambda err: errors.append(str(err)))
            page.evaluate('''() => {
              const jar = new Map();
              Object.defineProperty(document, 'cookie', {
                configurable: true,
                get() { return Array.from(jar, ([k,v]) => `${k}=${v}`).join('; '); },
                set(raw) {
                  const pair=raw.split(';')[0], i=pair.indexOf('=');
                  if(i < 0) return;
                  const key=pair.slice(0,i), value=pair.slice(i+1);
                  if(/Max-Age=0/i.test(raw)) jar.delete(key);
                  else jar.set(key,value);
                }
              });
            }''')
            if mask is not None:
                page.evaluate('''mask => {
                  document.cookie=`cajeer_lite_consent=v1.1.${Math.floor(Date.now()/1000)}.${mask}; Path=/`;
                }''', mask)
            markup, scripts = pages[gtm]
            page.set_content(markup)
            for name, attrs in scripts:
                source = COOKIE_JS if name == 'consent' else JS[name]
                page.evaluate('''({attrs, source}) => {
                  const s=document.createElement('script');
                  for(const [key,value] of Object.entries(attrs)) s.setAttribute(key,value);
                  s.textContent=source; document.body.appendChild(s);
                }''', {'attrs':attrs, 'source':source})
            return page

        page = setup(gtm=True)
        assert requests == [], 'A provider made a request before consent: ' + str(requests)
        assert page.evaluate('CajeerGTM.track("cajeer_signup")') is False
        assert page.evaluate('CajeerMatomo.goal(5)') is False
        assert page.evaluate('CajeerMetrica.goal("signup")') is False
        assert page.evaluate('CajeerClarity.event("cajeer_test")') is False
        page.locator('[data-consent-action="reject"]').first.click()
        assert requests == [], 'An explicit rejection permitted analytics'
        # A marketing-only choice is enough to permit GTM (for marketing tags),
        # but no analytics or session recorder should begin.
        partial = setup(gtm=True, mask=2)
        partial.wait_for_function('CajeerGTM.status().remoteLoaded')
        assert len(requests) == 1 and 'gtm.js?id=GTM-ABCDE123' in requests[0], requests
        assert not partial.evaluate('CajeerClarity.status().initialized')
        assert not partial.evaluate('CajeerMetrica.status().initialized')
        assert not partial.evaluate('CajeerMatomo.status().initialized')
        assert partial.evaluate('CajeerGTM.track("cajeer_purchase", {}, "marketing")') is True
        assert partial.evaluate('CajeerGTM.track("cajeer_purchase", {}, "analytics")') is False
        partial.close()

        initial = len(requests)
        page.locator('[data-consent-open]').click()
        page.locator('[data-consent-category="analytics"]').check()
        page.locator('[data-consent-category="marketing"]').check()
        page.locator('[data-consent-category="recordings"]').check()
        page.locator('[data-consent-action="save"]').click()
        page.wait_for_function('window.__mockProviderResponses >= 4')
        urls = requests[initial:]
        assert len(urls) == 4, urls
        assert any('googletagmanager.com/gtm.js?id=' in u for u in urls)
        assert any('mc.yandex.ru/metrika/tag.js' in u for u in urls)
        assert any('clarity.ms/tag/abc123' in u for u in urls)
        assert any('cdn.matomo.cloud/site.matomo.cloud/matomo.js' in u for u in urls)
        assert not any('gtag/js' in u for u in urls), 'GA4 loaded twice with GTM'
        dl = page.evaluate('window.dataLayer.map(x => typeof x.length === "number" ? Array.from(x) : x)')
        assert dl[0][0:2] == ['consent', 'default']
        assert dl[0][2]['analytics_storage'] == 'denied'
        assert dl[1][0:2] == ['consent', 'update']
        assert dl[1][2]['analytics_storage'] == 'granted'
        assert dl[1][2]['ad_storage'] == 'granted'
        assert any(x.get('event') == 'gtm.js' for x in dl if isinstance(x, dict))
        assert page.evaluate('CajeerMetrica.goal("signup", {placement:"footer"})') is True
        assert page.evaluate('CajeerMetrica.goal("signup", {email:"secret@example.com"})') is False
        assert page.evaluate('CajeerMetrica.goal("signup", {value:"secret@example.com"})') is False
        assert page.evaluate('CajeerMetrica.goal("signup", {campaign:"1234567890123"})') is False
        assert page.evaluate('CajeerMetrica.hit("/account/123456789")') is False
        assert page.evaluate('CajeerMetrica.hit("/about")') is True
        assert page.evaluate('CajeerGTM.track("cajeer_signup", {placement:"footer"})') is True
        assert page.evaluate('CajeerGTM.track("cajeer_signup", {email:"secret@example.com"})') is False
        assert page.evaluate('CajeerMatomo.event("signup", "complete", "footer")') is True
        assert page.evaluate('CajeerClarity.event("cajeer_checkout")') is True
        assert page.evaluate('CajeerClarity.event("cajeer_1234567890123")') is False
        page.evaluate('''() => {
          for(const href of ['mailto:secret@example.com','tel:+12025550123']) {
            const a=document.createElement('a');a.href=href;document.body.appendChild(a);
            a.addEventListener('click', e => e.preventDefault());
            a.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true}));
          }
          document.cookie='_ga=private; Path=/';
          document.cookie='_ym_uid=private; Path=/';
          document.cookie='_pk_id.9.123=private; Path=/';
          document.cookie='_clck=private; Path=/';
          document.cookie='cajeer_site_preference=keep; Path=/';
        }''')
        assert page.evaluate('CajeerConsent.revoke()') is True
        for name in ['metrica', 'gtm', 'clarity', 'matomo']:
            assert page.evaluate(f'window.__reload_{name}') == 1, f'{name}: no revocation navigation'
        assert page.evaluate('CajeerMetrica.goal("signup")') is False
        assert page.evaluate('CajeerGTM.track("cajeer_signup")') is False
        assert page.evaluate('CajeerMatomo.goal(5)') is False
        assert page.evaluate('CajeerClarity.event("cajeer_checkout")') is False
        assert page.evaluate('document.cookie.includes("cajeer_site_preference=keep")')
        for name in ['_ga=', '_ym_uid=', '_pk_id.', '_clck=']:
            assert not page.evaluate('name => document.cookie.includes(name)', name), name
        assert len(requests) == initial + 4, 'Fresh provider requests after revocation'
        denied = setup(gtm=True, mask=0)
        assert len(requests) == initial + 4, 'Persistent refusal ignored'
        assert not denied.evaluate('CajeerMatomo.status().initialized')

        # Direct GA4 (without GTM) and an isolated PII-guard regression.
        google = setup(gtm=False, mask=1)
        google.wait_for_function('CajeerGA4.status().tagLoaded')
        assert any('googletagmanager.com/gtag/js?id=' in u for u in requests), requests
        assert google.evaluate('CajeerGA4.track("feature_used", {placement:"footer"})') is True
        for params in [
            '{email:"a@b.example"}', '{label:"a@b.example"}',
            '{phone:"+12025550123"}', '{url:"https://example.org"}',
            '{customer:"1234567890123"}', '{search_query:"private"}',
        ]:
            assert google.evaluate(f'CajeerGA4.track("feature_used", {params})') is False, params
        data = google.evaluate('window.dataLayer.map(x=>Array.from(x))')
        assert sum(e[:2] == ['event', 'page_view'] for e in data) == 1
        assert not errors, errors
        browser.close()
print('Lite 1.0.9 combined browser security/consent/provider runtime tests OK (provider responses/reloads emulated)')
