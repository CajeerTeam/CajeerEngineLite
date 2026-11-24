#!/usr/bin/env python3
"""Isolated real HTTP load/soak against disposable local CMS, never a remote host."""
import argparse
import concurrent.futures
import json
import os
import shutil
import signal
import socket
import subprocess
import tempfile
import time
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--seconds', type=int, default=15)
    parser.add_argument('--workers', type=int, default=4)
    parser.add_argument('--p95-ms', type=float, default=1000)
    args = parser.parse_args()
    if not 1 <= args.seconds <= 3600 or not 1 <= args.workers <= 32 or not 0 < args.p95_ms <= 600000:
        parser.error('invalid bounds')
    with tempfile.TemporaryDirectory(prefix='cajeer-load-147-') as tmp:
        site = Path(tmp) / 'site'
        site.mkdir()
        for name in ('core', 'public', 'templates', 'bin'):
            shutil.copytree(ROOT / name, site / name)
        for name in ('bootstrap.php', 'router.php', 'VERSION'):
            shutil.copy2(ROOT / name, site / name)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}'
        env = dict(os.environ, CAJEER_ADMIN_PASSWORD='LoadFixture147Password!', CAJEER_MONITORING='1', PHP_CLI_SERVER_WORKERS='4')
        subprocess.run(['php', 'bin/cajeer', 'install', base, 'Operational load test'], cwd=site, env=env, check=True, stdout=subprocess.DEVNULL)
        subprocess.run(['php', 'bin/cajeer', 'operations', 'backup', 'create'], cwd=site, env=env, check=True, stdout=subprocess.DEVNULL)
        with open(os.devnull, 'wb') as log:
            server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', 'public', 'router.php'], cwd=site, env=env, stdout=log, stderr=log, start_new_session=True)
            try:
                for _ in range(100):
                    try:
                        with urllib.request.urlopen(base+'/health/ready', timeout=1) as r:
                            if json.load(r)['status']=='ready': break
                    except (OSError, ValueError): time.sleep(.1)
                else: raise RuntimeError('server not ready')
                start = time.monotonic(); deadline = start + args.seconds
                def worker(index):
                    paths = ('/', '/health/ready', '/robots.txt', '/sitemap.xml')
                    # Fixed-size histogram in milliseconds; bounded even in hour-long runs.
                    hist = [0] * 10001; count = errors = 0
                    while time.monotonic() < deadline:
                        t = time.monotonic(); path = paths[(count+index)%len(paths)]
                        try:
                            with urllib.request.urlopen(base+path, timeout=5) as response:
                                body = response.read()
                                if response.status != 200 or not body: errors += 1
                        except OSError: errors += 1
                        hist[min(10000, int((time.monotonic()-t)*1000+.999))] += 1
                        count += 1
                    return count, errors, hist
                with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as pool:
                    rows = list(pool.map(worker, range(args.workers)))
                count = sum(r[0] for r in rows); errors = sum(r[1] for r in rows)
                hist = [sum(r[2][i] for r in rows) for i in range(10001)]
                acc = 0; p95 = None
                for i, n in enumerate(hist):
                    acc += n
                    if count and acc >= count*.95: p95=i; break
                elapsed = time.monotonic()-start
                metrics = json.loads(subprocess.check_output(['php','bin/cajeer','monitor','status'], cwd=site, env=env))
                result = {'requests':count,'errors':errors,'seconds':round(elapsed,2),'requests_per_second':round(count/elapsed,2),'p95_ms_rounded_up':p95,'retained_telemetry_requests':metrics['all']['requests'],'invalid_records':metrics['invalid_records']}
                print(json.dumps(result, indent=2))
                if count<20 or errors or p95 is None or p95>args.p95_ms or metrics['all']['requests']==0 or metrics['invalid_records']:
                    raise SystemExit(1)
                subprocess.run(['php','bin/cajeer','monitor','check','--verify-backup'],cwd=site,env=env,check=True,stdout=subprocess.DEVNULL)
            finally:
                os.killpg(server.pid, signal.SIGTERM)
                server.wait(timeout=10)

if __name__ == '__main__': main()
