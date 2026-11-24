#!/usr/bin/env python3
"""Disposable signed build + real HTTPS sign-off workflow; never production evidence."""
import argparse
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
sys.dont_write_bytecode=True
from release_engineering_148 import inventory_for_test
from rc_staging_149 import main as staging

ROOT=Path(__file__).resolve().parents[1]

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base',type=Path,help='Exact clean previous source export; otherwise use synthetic previous version')
    args=parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='stable150-') as tmp:
        work=Path(tmp);target=work/'next';target.mkdir()
        for name in inventory_for_test():
            out=target/name;out.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(ROOT/name,out)
        base=args.base.resolve() if args.base else work/'base'
        if not args.base:
            shutil.copytree(target,base);(base/'VERSION').write_text('1.4.9\n')
            lite=base/'core/Lite.php';lite.write_text(lite.read_text().replace("VERSION = '1.5.0'","VERSION = '1.4.9'"))
        private=work/'private.pem';public=work/'public.pem';dist=work/'dist'
        subprocess.run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048','-out',str(private)],check=True,stderr=subprocess.DEVNULL)
        private.chmod(0o600)
        subprocess.run(['openssl','pkey','-in',str(private),'-pubout','-out',str(public)],check=True)
        subprocess.run([sys.executable,str(ROOT/'tests/release_build_148.py'),'--base',str(base),'--candidate',str(target),'--output',str(dist),'--signing-key',str(private),'--trusted-key',str(public)],check=True)
        staging(dist,public)
        report=dist/'release-report.json';report.write_bytes(report.read_bytes()+b' ')
        try:
            subprocess.run(['php','-r',r'require "bootstrap.php"; \CajeerEngine\Release\ProductionStable::artifacts(getcwd(),$argv[1],$argv[2]);',str(dist),str(public)],cwd=ROOT,check=True,capture_output=True)
        except subprocess.CalledProcessError: pass
        else: raise AssertionError('Modified signed report accepted')
        print('Production Stable 1.5.0: signed artifact and sign-off integration passed (disposable keys/evidence)')

if __name__=='__main__': main()
