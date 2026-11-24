#!/usr/bin/env python3
"""Real signed code install/rollback and TLS readiness; systemctl is simulated.
Uses a disposable PHP HTTP backend, not the host's production FPM or services.
"""
from pathlib import Path
import hashlib
import argparse
import base64
import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time

sys.dont_write_bytecode = True
from release_engineering_148 import inventory_for_test
from release_build_148 import clean_env
ROOT = Path(__file__).resolve().parents[1]


def port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


def main(queue=False, healthy=False):
    with tempfile.TemporaryDirectory(prefix='health156-') as folder:
        work = Path(folder); work.chmod(0o755)
        site, candidate = work/'site', work/'candidate'
        for tree in (site, candidate):
            for name in inventory_for_test():
                target = tree/name
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(ROOT/name, target)
        state, runtime = work/'state', work/'run'
        state.mkdir(); runtime.mkdir()
        base = (site/'VERSION').read_text().strip()
        major, minor, patch = map(int, base.split('.'))
        target = f'{major}.{minor}.{patch+1}'
        (candidate/'VERSION').write_text(target+'\n')
        lite = candidate/'core/Lite.php'
        lite.write_text(lite.read_text().replace(f"VERSION = '{base}'", f"VERSION = '{target}'"))
        index = candidate/'public/index.php'
        if not healthy: index.write_text(index.read_text().replace('declare(strict_types=1);', '''declare(strict_types=1);
// Deliberately unhealthy signed test candidate, never shipped as a release.
if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)==='/health/ready') {
    http_response_code(503); header('Content-Type: application/json'); echo '{"status":"unavailable"}'; exit;
}''',1))
        ports = set()
        while len(ports)<3: ports.add(port())
        backend, health, public = sorted(ports)
        tlskey, cert = work/'tls.key', work/'tls.crt'
        key, trust, package = work/'sign.key', work/'sign.pub', work/'package'
        def run(args, **kw):
            return subprocess.run(args, check=True, **kw)
        run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=localhost',
             '-addext','subjectAltName=DNS:localhost','-keyout',str(tlskey),'-out',str(cert)],capture_output=True)
        run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048','-out',str(key)],capture_output=True)
        key.chmod(0o600)
        run(['openssl','pkey','-in',str(key),'-pubout','-out',str(trust)],capture_output=True)
        env = {**clean_env(),'CAJEER_ADMIN_PASSWORD':'Health156-test-password!', 'CAJEER_UPDATE_SIGNING_KEY_FILE':str(key),
               'CAJEER_UPDATE_TRUSTED_KEY_FILE':str(trust),'CAJEER_UPDATE_CONTROL_WORKER':'1','CAJEER_TRUSTED_PROXY_IPS':'127.0.0.1'}
        run(['php','bin/cajeer','install','https://localhost','Health test'],cwd=site,env=env,capture_output=True)
        run(['php','bin/cajeer','update','package',str(candidate),str(package)],cwd=site,env=env,capture_output=True)
        if queue:
            # Only GitHub HTTP responses and OS lifecycle are simulated.
            # Actual streaming, signature checks, scheduler and installer run.
            if os.geteuid()!=0: raise RuntimeError('RC managed policy fixture requires an isolated root runner')
            policy=work/'trust.json'
            policy.write_text(json.dumps({'format':1,'minimum_target':target,'keys':[trust.read_text()],'revoked':[]})); policy.chmod(0o644)
            payload=package.read_bytes(); name=f'CajeerEngineLite-{target}.cjrupdate'
            entry={'from':base,'name':name,'bytes':len(payload),'sha256':hashlib.sha256(payload).hexdigest()}
            raw=json.dumps({'format':1,'product':'CajeerEngineLite','to':target,'packages':[entry]},separators=(',',':')).encode()
            signature=subprocess.check_output(['openssl','dgst','-sha256','-sign',str(key)],input=raw)
            idx=work/'update-index.json'; idx.write_text(json.dumps({'payload':base64.b64encode(raw).decode(),'signature':base64.b64encode(signature).decode()}))
            url=f'https://github.com/Test/Repo/releases/download/v{target}/'
            assets=[{'name':name,'size':entry['bytes'],'state':'uploaded','browser_download_url':url+name,'digest':'sha256:'+entry['sha256']},
                    {'name':'update-index.json','size':idx.stat().st_size,'state':'uploaded','browser_download_url':url+'update-index.json'}]
            (work/'releases.json').write_text(json.dumps([{'id':1,'draft':False,'prerelease':True,'tag_name':'v'+target,'assets':assets}]))
            window=time.strftime('%H:%M',time.gmtime(time.time()-3600))+'-'+time.strftime('%H:%M',time.gmtime(time.time()+3600))
            env.update(CAJEER_RC_TEST_FIXTURE='1',CAJEER_UPDATE_TRUST_POLICY_FILE=str(policy),CAJEER_AUTO_UPDATE_GITHUB_REPO='Test/Repo',CAJEER_AUTO_UPDATE_ENABLED='1',CAJEER_AUTO_UPDATE_INSTALL='1',CAJEER_AUTO_UPDATE_ALLOW_PREVIEW_INSTALL='1',CAJEER_AUTO_UPDATE_WINDOW_UTC=window,TEST_TARGET=target,TEST_WORK=str(work))
            shutil.copy2(ROOT/'tests/rc_transport_159.php',work/'transport.php')
            run(['php',str(work/'transport.php'),'stable'],cwd=site,env=env)
            run(['php',str(work/'transport.php'),'configure'],cwd=site,env=env)
        before = hashlib.sha256((site/'storage/site.json').read_bytes()).hexdigest()
        config = work/'nginx.conf'
        config.write_text(f'''pid {work}/nginx.pid;
error_log {work}/error.log;
events {{}}
http {{ access_log off;
client_body_temp_path {work}/body; proxy_temp_path {work}/proxy; fastcgi_temp_path {work}/fastcgi;
uwsgi_temp_path {work}/uwsgi; scgi_temp_path {work}/scgi;
server {{ listen 127.0.0.1:{health} ssl; server_name localhost;
ssl_certificate {cert}; ssl_certificate_key {tlskey};
location = /health/ready {{ proxy_pass http://127.0.0.1:{backend}; proxy_set_header Host localhost; proxy_set_header X-Forwarded-Host localhost; proxy_set_header X-Forwarded-Proto https; }}
location / {{ return 404; }} }}
server {{ listen 127.0.0.1:{public}; open_file_cache off; if (-f {state}/active) {{ return 503; }}
location / {{ proxy_pass http://127.0.0.1:{backend}; proxy_set_header Host localhost; }} }} }}
''')
        log = (work/'nginx.log').open('w')
        nginx = subprocess.Popen(['nginx','-p',str(work),'-c',str(config),'-g','daemon off;'],stdout=log,stderr=log)
        try:
            for _ in range(100):
                if nginx.poll() is not None: raise RuntimeError((work/'nginx.log').read_text())
                try:
                    with socket.create_connection(('127.0.0.1',health),timeout=.1): break
                except OSError: time.sleep(.05)
            else: raise RuntimeError('Nginx not ready')
            script = r'''
set -euo pipefail
source "$SUPERVISOR"
state_dir="$TEST_WORK/state"; runtime_dir="$TEST_WORK/run"
site_root="$TEST_SITE"; units=(php-test.service); fpm=php-test.service
health_host=localhost; health_port="$TEST_HEALTH"; health_ca="$TEST_CA"
pid=; running=inactive
stop_backend() { if [[ -n $pid ]]; then kill "$pid" 2>/dev/null || :; wait "$pid" 2>/dev/null || :; pid=; fi; running=inactive; }
trap stop_backend EXIT
start_backend() { (cd "$site_root" && exec php -S "127.0.0.1:$TEST_BACKEND" -t public router.php) >> "$TEST_WORK/php.log" 2>&1 & pid=$!; running=active; /bin/sleep .15; }
public_code() { /usr/bin/curl --disable --noproxy '*' -s --max-time 3 -o /dev/null -w '%{http_code}' "http://127.0.0.1:$TEST_PUBLIC/health/ready"; }
# Only the OS lifecycle is mocked. Start/stop the actual disposable PHP server.
# The systemd/root configuration boundary is simulated here.
validate_units() { :; }
if [[ $TEST_QUEUE == 0 ]]; then validate_trust() { :; }; fi
prop() { case "$2" in ActiveState) echo "$running";; MainPID|ControlPID) [[ $running == inactive ]] && echo 0 || echo "$pid";; *) return 1;; esac; }
system() {
    [[ -f "$state_dir/active" && $(public_code) == 503 ]] || return 1
    if [[ $1 == stop ]]; then stop_backend; else guard && start_backend; fi
}
worker() {
    local q=$1; shift
    case "$*" in
        'maintenance plan') if [[ $TEST_QUEUE == 1 ]]; then php "$site_root/bin/cajeer" update maintenance plan; else echo install; fi;;
        'scheduler tick')
            if [[ $TEST_QUEUE == 1 ]]; then
                if [[ $q == 1 ]]; then [[ $running == inactive && $(public_code) == 503 ]] || return 1; else [[ $running == active && $(public_code) == 200 ]] || return 1; fi
                (cd "$site_root" && CAJEER_AUTO_UPDATE_WORKERS_QUIESCED="$q" php "$TEST_WORK/transport.php" tick)
            else
                [[ $q == 1 && $running == inactive && $(public_code) == 503 ]] || return 1
                CAJEER_UPDATE_CONFIRM=INSTALL php "$site_root/bin/cajeer" update install "$TEST_PACKAGE"
            fi;;
        *) CAJEER_AUTO_UPDATE_WORKERS_QUIESCED="$q" CAJEER_UPDATE_CONFIRM="${rollback_confirmation:-}" php "$site_root/bin/cajeer" update "$@";;
    esac
}
# Accelerate only the retry delays; actual TLS/HTTP checks remain unchanged.
sleep() { /bin/sleep .05; }
start_backend
[[ $(public_code) == 200 ]]
health_probe "$TEST_BASE"
if health_probe 99.99.99; then exit 1; fi
saved_ca=$health_ca; health_ca=; if health_probe "$TEST_BASE"; then exit 1; fi; health_ca=$saved_ca
if [[ $TEST_QUEUE == 1 ]]; then
    touch "$TEST_WORK/outage"
    cycle > "$TEST_WORK/tick.json"; grep -q retry_scheduled "$TEST_WORK/tick.json"
    [[ $(public_code) == 200 && ! -e "$state_dir/active" ]]
    cycle > "$TEST_WORK/tick.json"; grep -q retry_wait "$TEST_WORK/tick.json"
    rm "$TEST_WORK/outage"
    (cd "$site_root" && php "$TEST_WORK/transport.php" retry-now)
    cycle > "$TEST_WORK/tick.json"; grep -q checked "$TEST_WORK/tick.json"
    [[ $(public_code) == 200 && $(cat "$site_root/VERSION") == "$TEST_BASE" ]]
    CAJEER_AUTO_UPDATE_ALLOW_PREVIEW_INSTALL=0 cycle > "$TEST_WORK/tick.json"; grep -q idle "$TEST_WORK/tick.json"
    [[ $(cat "$site_root/VERSION") == "$TEST_BASE" ]]
fi
rc=0; cycle || rc=$?
if [[ $TEST_HEALTHY == 1 ]]; then
    [[ $rc == 0 && ! -e "$state_dir/hold" && ! -e "$state_dir/active" && $(public_code) == 200 ]]
    [[ $(cat "$site_root/VERSION") == "$TEST_TARGET" ]]
    health_probe "$TEST_TARGET"
else
    [[ $rc == 3 && -f "$state_dir/hold" && ! -e "$state_dir/active" && $(public_code) == 200 ]]
    [[ $(cat "$site_root/VERSION") == "$TEST_BASE" ]]
    health_probe "$TEST_BASE"
    if cycle; then exit 1; fi
fi
'''
            run(['/bin/bash','-c',script],env={**env,'SUPERVISOR':str(ROOT/'deploy/cajeer-maintenance.sh'),
                 'TEST_WORK':str(work),'TEST_SITE':str(site),'TEST_CA':str(cert),'TEST_PACKAGE':str(package),
                 'TEST_BASE':base,'TEST_TARGET':target,'TEST_QUEUE':str(int(queue)),'TEST_HEALTHY':str(int(healthy)),'TEST_BACKEND':str(backend),'TEST_HEALTH':str(health),'TEST_PUBLIC':str(public)},timeout=120)
            assert hashlib.sha256((site/'storage/site.json').read_bytes()).hexdigest()==before
            assert (site/'public/index.php').read_bytes()==(ROOT/'public/index.php').read_bytes()
        finally:
            nginx.terminate()
            try: nginx.wait(timeout=5)
            except subprocess.TimeoutExpired: nginx.kill(); nginx.wait()
            log.close()
    print('Automatic update RC: '+('scheduler, retry, preview policy, signed index and package, ' if queue else '')+('successful HTTPS commit' if healthy else 'TLS, signed install, HTTP 503 gate, rollback, re-probe and durable hold')+' OK')


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__); parser.add_argument('--queue',action='store_true'); parser.add_argument('--healthy',action='store_true'); args=parser.parse_args(); main(args.queue,args.healthy)
