#!/usr/bin/env python3
"""1.3.9 integration: real PHP HTTP/session + browser rendering (sandbox-safe transport)."""
import http.cookiejar
import os
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.parse
import urllib.request
from pathlib import Path

from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='cms139-browser-') as temporary:
    project = Path(temporary) / 'project'
    shutil.copytree(root, project, ignore=shutil.ignore_patterns('__pycache__'))
    for filename in ('site.json','site.lock'):
        (project / 'storage' / filename).unlink(missing_ok=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0))
        port=sock.getsockname()[1]
    url=f'http://127.0.0.1:{port}'
    subprocess.run(['php','bin/cajeer','install',url,'Browser 1.3.9'], cwd=project,
                   env={**os.environ,'CAJEER_ADMIN_PASSWORD':'BrowserProduction139!'},
                   check=True,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
    with open(Path(temporary)/'server.log','wb') as log:
        server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','router.php'],
                                cwd=project,stdout=log,stderr=subprocess.STDOUT)
        try:
            for _ in range(100):
                try:
                    urllib.request.urlopen(url+'/health/live',timeout=1).close()
                    break
                except OSError:
                    time.sleep(.08)
            else: raise RuntimeError('PHP HTTP server did not start')
            jar=http.cookiejar.CookieJar()
            opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
            def get(path):
                with opener.open(url+path,timeout=15) as response:
                    assert response.status==200, (path,response.status)
                    return response.read().decode('utf-8')
            def post(path,values):
                data=urllib.parse.urlencode(values).encode()
                request=urllib.request.Request(url+path,data=data,headers={'Origin':url})
                with opener.open(request,timeout=20) as response:
                    return response.read().decode('utf-8')
            login=get('/admin')
            assert 'Вход в админ-панель' in login
            csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',login).group(1)
            admin=post('/admin',{'csrf':csrf,'action':'login','password':'BrowserProduction139!'})
            assert 'admin-body' in admin and 'Вход в админ-панель' not in admin
            csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',admin).group(1)
            css=(project/'public/assets/style.css').read_text()+'\n'+(project/'public/assets/admin.css').read_text()
            with sync_playwright() as playwright:
                browser=playwright.chromium.launch(executable_path=shutil.which('chromium') or playwright.chromium.executable_path,
                                                   headless=True,args=['--no-sandbox','--disable-gpu'])
                page=browser.new_page(viewport={'width':1280,'height':900})
                for route,selector in (
                    ('/admin/media','input[type="file"][name="media_file"]'),
                    ('/admin/seo','.seo-control'),
                    ('/admin/developer','.dev-tools'),
                    ('/admin/operations','.cms-operations'),
                    ('/admin/editor','#cms-block-editor'),
                    ('/admin/ai','body'),
                ):
                    markup=get(route)
                    page.set_content(markup)
                    page.add_style_tag(content=css)
                    assert page.locator(selector).count()>0,route
                    assert 'Administrator login required' not in page.locator('body').inner_text(),route
                    for width in (1280,768,390,320):
                        page.set_viewport_size({'width':width,'height':900})
                        assert page.locator('body').evaluate('node => node.scrollWidth <= innerWidth+2'),(route,width)
                backup_html=post('/admin/operations',{'csrf':csrf,'action':'create'})
                page.set_content(backup_html)
                page.add_style_tag(content=css)
                assert 'Резервная копия создана' in page.locator('body').inner_text()
                assert page.locator('button',has_text='Проверить').count()>=1
                assert page.locator('button',has_text='Скачать').count()>=1
                page.set_content(get('/'))
                assert page.get_by_role('heading').count()>0
                browser.close()
        finally:
            server.terminate()
            try: server.wait(timeout=5)
            except subprocess.TimeoutExpired: server.kill();server.wait(timeout=5)
print('CMS Experience Hardening 1.3.9 Chromium: actual PHP responses, authenticated session, 6 CMS screens, responsive 1280/768/390/320, backup OK')
