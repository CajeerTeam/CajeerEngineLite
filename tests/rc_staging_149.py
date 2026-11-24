#!/usr/bin/env python3
"""Real disposable HTTPS staging via Nginx TLS termination and PHP HTTP backend.
No external services, no production secrets, no insecure TLS bypass.
"""
import json
import base64
import secrets
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import ssl
import urllib.request
import urllib.parse
import urllib.error
import http.cookiejar
import re
import sys

sys.dont_write_bytecode = True
from release_engineering_148 import inventory_for_test

SOURCE = Path(__file__).resolve().parents[1]
PASSWORD = 'RCStaging149Password!'


def port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        return s.getsockname()[1]


def main(artifacts=None, trust=None):
    if not shutil.which('nginx'):
        raise RuntimeError('Nginx is required for this staging test')
    with tempfile.TemporaryDirectory(prefix='cajeer-rc149-') as folder:
        work = Path(folder)
        root = work/'site'
        root.mkdir()
        for name in inventory_for_test():
            if name.startswith('storage/'): continue
            dest=root/name
            dest.parent.mkdir(parents=True,exist_ok=True)
            shutil.copy2(SOURCE/name,dest)
        env = {k: os.environ[k] for k in ('PATH','HOME','LANG','LC_ALL','TMPDIR') if k in os.environ}
        env.update(CAJEER_TRUSTED_PROXY_IPS='127.0.0.1',CAJEER_COMPAT_DOCUMENT_ROOT=str(root/'public'),CAJEER_ADMIN_PASSWORD=PASSWORD)
        backend, front = port(), port()
        while front == backend:
            front = port()
        origin = f'https://127.0.0.1:{front}'
        key, cert = work/'key.pem',work/'cert.pem'
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=127.0.0.1',
                        '-addext','subjectAltName=IP:127.0.0.1','-keyout',str(key),'-out',str(cert)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        def cli(*args, expected=0, extra=None):
            p=subprocess.run(['php','-d',f'openssl.cafile={cert}','bin/cajeer',*args],cwd=root,env={**env,**(extra or {})},capture_output=True,text=True,timeout=90)
            if p.returncode != expected:
                raise AssertionError(f'{args}: expected {expected}, got {p.returncode}: {p.stdout[-2500:]} {p.stderr[-1000:]}')
            return p.stdout
        cli('install',origin,'RC staging')
        cfg=work/'nginx.conf'
        cfg.write_text(f'''pid {work}/nginx.pid;
error_log {work}/nginx-error.log;
worker_processes 1;
events {{ worker_connections 128; }}
http {{ access_log off; client_body_temp_path {work}/body; proxy_temp_path {work}/proxy;
fastcgi_temp_path {work}/fastcgi; uwsgi_temp_path {work}/uwsgi; scgi_temp_path {work}/scgi;
server {{ listen 127.0.0.1:{front} ssl; server_name 127.0.0.1;
ssl_certificate {cert}; ssl_certificate_key {key}; client_max_body_size 10m;
location / {{ proxy_pass http://127.0.0.1:{backend}; proxy_set_header Host $http_host;
proxy_set_header X-Forwarded-Host $http_host; proxy_set_header X-Forwarded-Proto https; }} }} }}
''')
        with (work/'php.log').open('w') as log:
            processes=[]
            try:
                processes.append(subprocess.Popen(['php','-S',f'127.0.0.1:{backend}','-t','public','router.php'],cwd=root,env=env,stdout=log,stderr=log))
                processes.append(subprocess.Popen(['nginx','-p',str(work),'-c',str(cfg),'-g','daemon off;'],stdout=log,stderr=log))
                class NoRedirect(urllib.request.HTTPRedirectHandler):
                    def redirect_request(self,*args,**kwargs): return None
                opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPSHandler(context=ssl.create_default_context(cafile=str(cert))),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect())
                def request(path, data=None, headers=None):
                    body=data if isinstance(data,bytes) else (None if data is None else urllib.parse.urlencode(data).encode())
                    req=urllib.request.Request(origin+path,data=body,headers=headers or {})
                    try: response=opener.open(req,timeout=10)
                    except urllib.error.HTTPError as e: response=e
                    with response: return response.status,response.read(),response.headers
                for _ in range(100):
                    if any(p.poll() is not None for p in processes): raise RuntimeError('Staging process exited')
                    try:
                        if request('/health/ready')[0]==200: break
                    except OSError: time.sleep(.05)
                else: raise RuntimeError('Staging not ready')
                status,body,headers=request('/admin')
                assert status==200 and 'secure' in headers.get('Set-Cookie','').lower() and 'httponly' in headers.get('Set-Cookie','').lower(), (status, headers.get_all('Set-Cookie'), body[:200])
                assert request('/admin/media')[0]==403
                assert request('/storage/site.json')[0]==404
                csrf=re.search(rb'name="csrf" value="([a-f0-9]{64})"',body).group(1).decode()
                assert request('/admin',{'csrf':csrf,'action':'login','password':PASSWORD},{'Origin':'https://invalid.example'})[0]==403
                assert request('/admin',{'csrf':csrf,'action':'login','password':PASSWORD},{'Origin':origin})[0]==303
                status,body,_=request('/admin'); assert status==200
                csrf=re.search(rb'name="csrf" value="([a-f0-9]{64})"',body).group(1).decode()
                assert request('/admin',{'csrf':csrf,'action':'save','old':'','path':'/rc-staging','title':'RC staging article','description':'RC description','body':'RC staging persisted','published':'1','menu':'1'},{'Origin':origin})[0]==303
                assert b'RC staging persisted' in request('/rc-staging')[1]
                assert b'/rc-staging' in request('/sitemap.xml')[1]
                for route in ('/admin/media','/admin/seo','/admin/developer','/admin/operations','/admin/editor'):
                    assert request(route)[0]==200,route
                # Gate must refuse missing backup and unapproved network access.
                missing=json.loads(cli('release','rc-check',expected=1))
                assert not missing['automated_ok']
                cli('release','rc-check','--live',expected=1)
                cli('release','rc-check','--provider=oidc',expected=1)
                # Upload through actual HTTPS multipart route; no direct filesystem seeding.
                png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGOUT9rBwMDAxMDAwMDAAAAOPQE9xgjZwQAAAABJRU5ErkJggg==')
                boundary='rc-'+secrets.token_hex(16)
                fields=b''.join(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k,v in {'csrf':csrf,'action':'upload'}.items())
                multipart=fields+f'--{boundary}\r\nContent-Disposition: form-data; name="media_file"; filename="rc.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+png+f'\r\n--{boundary}--\r\n'.encode()
                assert request('/admin/media',multipart,{'Origin':origin,'Content-Type':'multipart/form-data; boundary='+boundary})[0]==303
                media=next(iter(json.loads((root/'storage/media/index.json').read_text())['files']))
                assert request('/media/'+media)[:2]==(200,png)
                status,body,_=request('/admin/operations',{'csrf':csrf,'action':'create'},{'Origin':origin})
                assert status==200
                backup=re.search(rb'cms-[0-9A-Z]*-[a-f0-9]*\.tar',body).group().decode()
                assert request('/admin/operations',{'csrf':csrf,'action':'download','name':backup},{'Origin':origin})[1][257:262]==b'ustar'
                assert request('/admin',{'csrf':csrf,'action':'save','old':'','path':'/rc-later','title':'Later','body':'Later page','published':'1'},{'Origin':origin})[0]==303
                assert request('/rc-later')[0]==200
                assert request('/admin/operations',{'csrf':csrf,'action':'restore','name':backup,'confirm':'RESTORE'},{'Origin':origin})[0]==303
                assert request('/rc-later')[0]==404
                assert request('/admin/media')[0]==403
                assert b'RC staging persisted' in request('/rc-staging')[1]
                assert request('/media/'+media)[:2]==(200,png)
                local=json.loads(cli('release','rc-check',expected=2))
                assert local['automated_ok'] and not local['production_accepted'] and local['status']=='pending_live',local
                live=json.loads(cli('release','rc-check','--live',expected=2,extra={'CAJEER_RC_STAGING_CONFIRM':'STAGING'}))
                assert live['automated_ok'] and live['status']=='pending_manual' and not live['production_accepted'],live
                assert all(c['status']=='not_run' for c in live['checks'] if c['id'].startswith('provider.'))
                unconfigured=json.loads(cli('release','rc-check','--live','--provider=ga4',expected=1,extra={'CAJEER_RC_STAGING_CONFIRM':'STAGING'}))
                assert {'id':'provider.ga4','status':'fail'} in unconfigured['checks']
                if artifacts is not None:
                    # Exercise the final gate over actual HTTPS, using independently signed disposable artifacts.
                    env['CAJEER_UPDATE_TRUSTED_KEY_FILE']=str(trust)
                    for _ in range(30): assert request('/')[0]==200
                    assert request('/health/ready')[2].get('X-Cajeer-Release')==(root/'VERSION').read_text().strip()
                    flag='--artifacts='+str(artifacts)
                    local=json.loads(cli('release','production-check',flag,expected=2))
                    assert local['status']=='pending_live' and not local['production_accepted']
                    cli('release','production-check',flag,'--live',expected=1)
                    consent={'CAJEER_PRODUCTION_LIVE_CONFIRM':'LIVE'}
                    report=json.loads(cli('release','production-check',flag,'--live',expected=2,extra=consent))
                    assert report['status']=='pending_signoff' and report['automated_ok'],report
                    evidence=work/'evidence';evidence.mkdir()
                    hashes={}
                    import hashlib
                    for name in ('ci','browser_e2e','staging_fpm_tls','providers_and_consent','offhost_restore','upgrade_rollback','load_soak','production_observation'):
                        content=('SYNTHETIC TEST ONLY - '+name+'\n').encode()
                        (evidence/(name+'.txt')).write_bytes(content);hashes[name]=hashlib.sha256(content).hexdigest()
                    approval={'reviewer':'Test reviewer','decision':'approve','version':(root/'VERSION').read_text().strip(),'receipt_sha256':report['artifact']['receipt_sha256'],'origin_sha256':report['origin_sha256'],'site_sha256':report['site_sha256'],'evidence':hashes}
                    (evidence/'approval.json').write_text(json.dumps(approval))
                    signflag='--evidence='+str(evidence)
                    cli('release','sign-off',flag,'--live',signflag,expected=1,extra=consent)
                    consent['CAJEER_PRODUCTION_SIGNOFF_CONFIRM']='I_REVIEWED_PRODUCTION_EVIDENCE'
                    (evidence/'ci.txt').write_text('tampered')
                    cli('release','sign-off',flag,'--live',signflag,expected=1,extra=consent)
                    (evidence/'ci.txt').write_text('SYNTHETIC TEST ONLY - ci\n')
                    record=json.loads(cli('release','sign-off',flag,'--live',signflag,extra=consent))
                    assert record['status']=='operator_attested' and record['production_accepted'] and record['evidence_sha256']==hashes
                    saved=root/'storage/app/release-signoff'/(record['id']+'.json')
                    assert json.loads(saved.read_text())==record and saved.stat().st_mode&0o077==0
                    asset=root/'public/assets/consent.js';original=asset.read_bytes();asset.write_bytes(original+b'\n')
                    cli('release','production-check',flag,expected=1);asset.write_bytes(original)
                    extra=root/'public/extra.txt';extra.write_text('unexpected');cli('release','production-check',flag,expected=1);extra.unlink()
                    result=json.loads(cli('release','production-check',flag,'--live',expected=1,extra={**consent,'CAJEER_MONITORING':'0'}))
                    assert {'id':'monitor.recent_samples','status':'fail'} in result['checks']
                    print('1.5.0 production gate: exact signed code, real HTTPS, live version, monitoring, explicit test sign-off and tamper rejection OK')
                print('RC 1.4.9: verified TLS, Nginx, HTTP login/page/media/backup/restore, session revocation, live gate and unconfigured provider refusal OK; no real provider credentials')
            finally:
                for p in reversed(processes):
                    p.terminate()
                    try: p.wait(timeout=5)
                    except subprocess.TimeoutExpired: p.kill();p.wait()

if __name__=='__main__':
    if len(sys.argv)==1: main()
    elif len(sys.argv)==3: main(Path(sys.argv[1]).resolve(),Path(sys.argv[2]).resolve())
    else: raise SystemExit('Usage: rc_staging_149.py [artifacts trust-key]')
