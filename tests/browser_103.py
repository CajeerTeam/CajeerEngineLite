#!/usr/bin/env python3
"""Optional headless Chromium interaction test (pip install playwright; system Chromium).

The environment can block local HTTP navigation; this uses real rendered PHP markup
and a browser-controlled cookie jar, not a mocked DOM or a network E2E claim.
"""
import os
import pathlib
import re
import shutil
import subprocess
import tempfile
from playwright.sync_api import sync_playwright

SOURCE = pathlib.Path(__file__).resolve().parent.parent
chrome = next((p for n in ('chromium', 'google-chrome', 'google-chrome-stable') if (p := shutil.which(n))), None)
if not chrome:
    raise SystemExit('A system Chrome/Chromium executable is required')

with tempfile.TemporaryDirectory(prefix='lite-103-ui-') as folder:
    root = pathlib.Path(folder) / 'site'
    shutil.copytree(SOURCE, root)
    subprocess.run(['php', 'bin/cajeer', 'install', 'http://127.0.0.1:8080', 'Test'], cwd=root,
                   env={**os.environ, 'CAJEER_ADMIN_PASSWORD': 'TestPassword123'},
                   check=True, capture_output=True)
    php = ('require "bootstrap.php"; $app=new \\CajeerEngine\\Lite(getcwd()); '
           '$s=$app->load(); echo $app->render($s,$s["pages"]["/"]);')
    html = subprocess.check_output(['php', '-r', php], cwd=root, text=True)
    html = re.sub(r'<script defer src="/assets/consent.js\?v=110"></script>', '', html)
    html = re.sub(r'<link rel="stylesheet" href="/assets/style.css">', '', html)
    js = (root / 'public/assets/consent.js').read_text()
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=chrome, headless=True,
                                              args=['--no-sandbox', '--disable-gpu'])
        page = browser.new_page()
        errors = []
        page.on('pageerror', lambda err: errors.append(str(err)))
        # about:blank cannot set real cookies; emulate the cookie property only.
        page.evaluate('''() => {
          const jar = {};
          Object.defineProperty(document, 'cookie', {
            get() { return Object.entries(jar).map(([k,v]) => `${k}=${v}`).join('; '); },
            set(value) {
              const part = value.split(';')[0]; const idx = part.indexOf('=');
              if (idx !== -1) jar[part.slice(0,idx)] = part.slice(idx+1);
            }
          });
        }''')
        page.set_content(html)
        page.add_script_tag(content=js)
        assert page.locator('#cookie-consent').is_visible(), 'initial banner missing'
        assert page.evaluate('CajeerConsent.get().saved') is False, 'no consent should be saved'
        assert not page.evaluate('CajeerConsent.has("analytics")'), 'analytics enabled by default'
        page.locator('[data-consent-action="accept"]').click()
        assert page.evaluate('CajeerConsent.has("analytics") && CajeerConsent.has("marketing") && CajeerConsent.has("recordings")'), 'accept all not applied'
        assert not page.locator('#cookie-consent').is_visible(), 'banner did not close'
        page.locator('[data-consent-open]').click()
        assert page.locator('[data-consent-editor]').is_visible(), 'preferences did not open'
        assert page.locator('[data-consent-category="analytics"]').is_checked(), 'previous choice not restored'
        page.locator('[data-consent-action="reject"]').last.click()
        assert page.evaluate('CajeerConsent.get().saved'), 'rejection must persist'
        assert not page.evaluate('CajeerConsent.has("analytics")'), 'revoke did not deny analytics'
        page.locator('[data-consent-open]').click()
        page.locator('[data-consent-category="analytics"]').check()
        page.locator('[data-consent-category="recordings"]').check()
        page.locator('[data-consent-action="save"]').click()
        assert page.evaluate('CajeerConsent.get().analytics && CajeerConsent.get().recordings && !CajeerConsent.get().marketing'), 'partial consent failed'
        page.locator('[data-consent-open]').click()
        page.locator('[data-consent-editor]').press('Escape')
        assert not page.locator('#cookie-consent').is_visible(), 'Escape did not dismiss editor'
        assert not errors, f'Browser JavaScript errors: {errors}'
        browser.close()
print('Lite 1.0.3 browser interactions OK (real DOM, simulated cookie storage)')
