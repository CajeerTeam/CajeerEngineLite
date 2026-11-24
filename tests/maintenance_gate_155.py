#!/usr/bin/env python3
"""Real loopback Nginx test of the shipped maintenance gate, without FPM/systemd."""
from pathlib import Path
import http.client
import os
import shutil
import socket
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]

def main():
    nginx = shutil.which('nginx')
    if not nginx:
        raise SystemExit('Nginx required for HTTP gate integration test')
    lines = []
    for file in ('nginx.conf', 'nginx-reverse-proxy.conf'):
        rule = [s.strip() for s in (ROOT/'deploy'/file).read_text().splitlines()
                if 'if (-f /var/lib/cajeer-maintenance/active)' in s]
        assert len(rule) == 1
        lines.extend(rule)
    assert lines[0] == lines[1]
    with tempfile.TemporaryDirectory(prefix='maintenance155-') as tmp:
        work = Path(tmp); work.chmod(0o755)
        gate = work/'active'; public = work/'public'; public.mkdir(mode=0o755)
        (public/'asset.css').write_text('body {}\n')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        config = work/'nginx.conf'
        rule = lines[0].replace('/var/lib/cajeer-maintenance/active', str(gate))
        config.write_text(f'''pid {work}/nginx.pid;
error_log {work}/error.log;
events {{}}
http {{
    access_log off;
    client_body_temp_path {work}/client;
    proxy_temp_path {work}/proxy;
    fastcgi_temp_path {work}/fastcgi;
    uwsgi_temp_path {work}/uwsgi;
    scgi_temp_path {work}/scgi;
    server {{
        listen 127.0.0.1:{port};
        root {public};
        {rule}
        location = /asset.css {{ try_files $uri =404; }}
        location / {{ return 200 "application response"; }}
    }}
}}
''')
        process = subprocess.Popen([nginx, '-p', tmp, '-c', str(config), '-g', 'daemon off;'],
                                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        def request(path):
            conn = http.client.HTTPConnection('127.0.0.1', port, timeout=2)
            try:
                conn.request('GET', path)
                response = conn.getresponse(); response.read(); return response.status
            finally:
                conn.close()
        try:
            for _ in range(50):
                try:
                    if request('/') == 200: break
                except OSError: time.sleep(.1)
            else: raise RuntimeError('Nginx failed to start')
            assert request('/asset.css') == 200
            gate.write_text('test journal\n'); gate.chmod(0o644)
            for path in ('/', '/admin', '/health/ready', '/asset.css'):
                assert request(path) == 503, path
            gate.unlink()
            assert request('/') == request('/asset.css') == 200
        finally:
            process.terminate()
            try: process.wait(timeout=5)
            except subprocess.TimeoutExpired: process.kill(); process.wait()
    print('Maintenance Nginx gate: real HTTP 200 → 503 (including static asset) → 200 OK')

if __name__ == '__main__': main()
