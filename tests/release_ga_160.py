#!/usr/bin/env python3
"""GA distribution rehearsal; no network, no official private keys."""
import argparse
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
sys.dont_write_bytecode=True
from release_engineering_148 import inventory_for_test
from release_distribution_152 import distribute, verify
from release_build_148 import version
ROOT=Path(__file__).resolve().parents[1]

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--base',type=Path,help='Exact prior Stable source export')
    p.add_argument('--rc',type=Path,help='Exact prior RC source export')
    p.add_argument('--staging',action='store_true',help='Also run disposable managed-trust HTTPS sign-off fixture (root runner)')
    a=p.parse_args()
    with tempfile.TemporaryDirectory(prefix='ga160-') as tmp:
        work=Path(tmp);target=work/'target'
        for name in inventory_for_test():
            dest=target/name;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(ROOT/name,dest)
        v=version(target)
        def fixture(name, ver):
            dest=work/name;shutil.copytree(target,dest)
            (dest/'VERSION').write_text(ver+'\n');lite=dest/'core/Lite.php';lite.write_text(lite.read_text().replace(f"VERSION = '{v}'",f"VERSION = '{ver}'"));return dest
        base=a.base.resolve() if a.base else fixture('base','1.5.8')
        rc=a.rc.resolve() if a.rc else fixture('rc','1.5.9')
        key=work/'private.pem';public=work/'public.pem';dist=work/'dist'
        subprocess.run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048','-out',str(key)],check=True,stderr=subprocess.DEVNULL)
        key.chmod(0o600);subprocess.run(['openssl','pkey','-in',str(key),'-pubout','-out',str(public)],check=True)
        distribute(base,[rc],target,dist,key,public)
        assert {entry['from'] for entry in verify(dist,public,v)}=={version(base),version(rc)}
        subprocess.run(['php',str(ROOT/'tests/ga_transport_160.php'),str(target),str(dist),str(public)],check=True)
        if a.staging:
            from rc_staging_149 import main as staging
            staging(dist,public,ga=True)
        print('GA signed Stable/RC upgrade, rollback and publication verification OK')

if __name__=='__main__':main()
