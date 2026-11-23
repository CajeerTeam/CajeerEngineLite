#!/usr/bin/env python3
"""Chromium layout acceptance using authenticated HTML fetched from a live PHP HTTP test server.

Some CI/sandbox browsers prohibit loopback navigation. Fetch pages via urllib and
render them with set_content; actual browser networking is a separate staging gate.
"""
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

root=Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='cms140-browser-') as tmp:
    project=Path(tmp)/'project'
    shutil.copytree(root,project,ignore=shutil.ignore_patterns('__pycache__'))
    for name in ('site.json','site.lock'):
        (project/'storage'/name).unlink(missing_ok=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    url=f'http://127.0.0.1:{port}'
    subprocess.run(['php','bin/cajeer','install',url,'Chromium 1.4.0'],cwd=project,
                   check=True,stdout=subprocess.DEVNULL,
                   env={**os.environ,'CAJEER_ADMIN_PASSWORD':'BrowserStable140Password!'})
    with open(Path(tmp)/'server.log','wb') as log:
        server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','router.php'],
                                cwd=project,stdout=log,stderr=subprocess.STDOUT)
        try:
            for _ in range(100):
                try:
                    with urllib.request.urlopen(url+'/health/ready',timeout=1) as response:
                        if response.status==200:break
                except OSError:time.sleep(.07)
            else:raise RuntimeError('PHP web server not ready')
            jar=http.cookiejar.CookieJar()
            opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
            def get(route):
                with opener.open(url+route,timeout=15) as response:
                    assert response.status==200,(route,response.status)
                    return response.read().decode('utf-8')
            def post(route,values):
                body=urllib.parse.urlencode(values).encode()
                request=urllib.request.Request(url+route,data=body,headers={'Origin':url})
                with opener.open(request,timeout=20) as response:
                    assert response.status==200,(route,response.status)
                    return response.read().decode('utf-8')
            assert get('/health/ready')=='{"status":"ready"}'
            login=get('/admin')
            csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',login).group(1)
            home=post('/admin',{'csrf':csrf,'action':'login','password':'BrowserStable140Password!'})
            assert 'admin-body' in home
            csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',home).group(1)
            css=(project/'public/assets/style.css').read_text()+'\n'+(project/'public/assets/admin.css').read_text()
            with sync_playwright() as playwright:
                browser=playwright.chromium.launch(executable_path=shutil.which('chromium') or playwright.chromium.executable_path,
                                                   headless=True,args=['--no-sandbox','--disable-gpu'])
                try:
                    page=browser.new_page(viewport={'width':1280,'height':900})
                    routes=(('/admin','.admin-body'),
                            ('/admin/media','input[name="media_file"]'),
                            ('/admin/seo','.seo-control'),
                            ('/admin/developer','.dev-tools'),
                            ('/admin/operations','.cms-operations'),
                            ('/admin/editor','#cms-block-editor'),
                            ('/admin/ai','body'))
                    for route,selector in routes:
                        html=get(route)
                        assert 'Administrator login required' not in html
                        page.set_content(html)
                        page.add_style_tag(content=css)
                        assert page.locator(selector).count()>0,(route,selector)
                        for width in (1280,768,390,320):
                            page.set_viewport_size({'width':width,'height':900})
                            assert page.locator('body').evaluate('el => el.scrollWidth <= innerWidth+2'),(route,width)
                    result=post('/admin/operations',{'csrf':csrf,'action':'create'})
                    assert 'Резервная копия создана:' in result
                    assert re.search(r'cms-\d{8}T\d{6}Z-[a-f0-9]{16}\.tar',result)
                    page.set_content(result)
                    page.add_style_tag(content=css)
                    assert page.locator('.cms-operations table tbody tr').count()>=1
                finally:browser.close()
            print('CMS Experience Stable 1.4.0 Chromium: 7 authenticated HTTP views, 4 widths and TAR backup UI OK (network rendered via set_content)')
        finally:
            server.terminate()
            try:server.wait(timeout=8)
            except subprocess.TimeoutExpired:server.kill();server.wait()
