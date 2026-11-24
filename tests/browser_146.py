#!/usr/bin/env python3
"""Actual network navigation in Chromium, Firefox and WebKit (no set_content)."""
import argparse
import base64
import json
import os
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.request
from pathlib import Path
from playwright.sync_api import sync_playwright

parser=argparse.ArgumentParser()
parser.add_argument('--browser',choices=['chromium','firefox','webkit'],default='chromium')
args=parser.parse_args()
source=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='cajeer-browser-146-') as folder:
    root=Path(folder)/'site'
    root.mkdir()
    for name in ('core','public','templates'):
        shutil.copytree(source/name,root/name)
    for name in ('bootstrap.php','router.php','VERSION'):
        shutil.copy(source/name,root/name)
    (root/'bin').mkdir()
    shutil.copy(source/'bin/cajeer',root/'bin/cajeer')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0))
        port=sock.getsockname()[1]
    url=f'http://127.0.0.1:{port}'
    environment=os.environ.copy()
    environment['CAJEER_ADMIN_PASSWORD']='CompatibilityBrowser146Password!'
    subprocess.run(['php','bin/cajeer','install',url,'Compatibility browser'],cwd=root,env=environment,check=True,capture_output=True)
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','router.php'],cwd=root,
                            stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,env=environment)
    try:
        for i in range(100):
            try:
                with urllib.request.urlopen(url+'/health/ready',timeout=1) as response:
                    if response.status==200:break
            except OSError:
                time.sleep(.06)
        else:raise AssertionError('PHP server not ready')
        with sync_playwright() as playwright:
            launcher=getattr(playwright,args.browser)
            launch={'headless':True}
            if args.browser=='chromium':
                if shutil.which('chromium'):
                    launch['executable_path']=shutil.which('chromium')
                launch['args']=['--no-sandbox']
            browser=launcher.launch(**launch)
            try:
                context=browser.new_context(viewport={'width':1280,'height':900})
                page=context.new_page()
                response=page.goto(url+'/health/ready',wait_until='load')
                assert response.status==200
                assert page.locator('body').inner_text().strip()=='{"status":"ready"}'
                response=page.goto(url+'/admin',wait_until='load')
                assert response.status==200
                assert page.locator('input[name="csrf"]').count()>0
                page.locator('input[name="password"]').fill('CompatibilityBrowser146Password!')
                page.locator('button').filter(has_text='Войти по паролю').click()
                page.wait_for_url('**/admin')
                assert page.locator('.admin-body').count()==1,'real authenticated browser navigation'
                for route,selector in [('/admin','.admin-body'),('/admin/media','input[name="media_file"]'),
                    ('/admin/seo','.seo-control'),('/admin/developer','.dev-tools'),
                    ('/admin/operations','.cms-operations'),('/admin/editor','#cms-block-editor')]:
                    response=page.goto(url+route,wait_until='load')
                    assert response.status==200,(route,response.status)
                    assert page.locator(selector).count()>0,(route,selector)
                    assert 'Administrator login required' not in page.locator('body').inner_text()
                    for width in (1280,768,390):
                        page.set_viewport_size({'width':width,'height':900})
                        assert page.locator('body').evaluate('el=>el.scrollWidth <= innerWidth + 4'),(args.browser,route,width)
                # Actual browser form submissions across CMS, not HTML-only fixtures.
                page.set_viewport_size({'width':1280,'height':900})
                def form(action):
                    return page.locator('form').filter(has=page.locator(f'input[name="action"][value="{action}"]')).first
                def create(path,title):
                    assert page.goto(url+'/admin?view=pages&new=1').status==200
                    editor=form('save')
                    editor.locator('input[name="path"]').fill(path)
                    editor.locator('input[name="title"]').fill(title)
                    editor.locator('textarea[name="body"]').fill('Browser RC persisted content')
                    editor.locator('input[name="published"]').check()
                    editor.locator('button').click()
                    assert page.goto(url+path).status==200
                    assert 'Browser RC persisted content' in page.locator('body').inner_text()
                create('/browser-rc','Browser RC')
                assert page.goto(url+'/admin/media').status==200
                png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGOUT9rBwMDAxMDAwMDAAAAOPQE9xgjZwQAAAABJRU5ErkJggg==')
                page.locator('input[name="media_file"]').set_input_files({'name':'rc.png','mimeType':'image/png','buffer':png})
                form('upload').locator('button').click()
                index=json.loads((root/'storage/media/index.json').read_text())
                media_id=next(iter(index['files']))
                assert context.request.get(url+'/media/'+media_id).body()==png
                assert page.goto(url+'/admin/operations').status==200
                form('create').locator('button').click()
                assert form('restore').count()==1
                create('/browser-rc-later','Browser RC later')
                assert page.goto(url+'/admin/operations').status==200
                restore=form('restore')
                restore.locator('input[name="confirm"]').fill('RESTORE')
                restore.locator('button').click()
                assert page.goto(url+'/browser-rc').status==200
                assert page.goto(url+'/browser-rc-later').status==404
                assert context.request.get(url+'/media/'+media_id).body()==png
                assert page.goto(url+'/admin/media').status==403,'Restore must revoke old session'
                # Live public route is accessible to an anonymous browser context.
                public=context.browser.new_context()
                anonymous=public.new_page()
                assert anonymous.goto(url+'/',wait_until='load').status==200
                assert anonymous.goto(url+'/admin/media',wait_until='load').status==403
                public.close()
                print(f'Compatibility Browser 1.4.6: {args.browser}, real login + HTTP navigation, 6 routes, 3 widths, page/media/backup/restore, unauthenticated restrictions OK')
            finally:browser.close()
    finally:
        server.terminate()
        try:server.wait(timeout=5)
        except subprocess.TimeoutExpired:server.kill();server.wait()
