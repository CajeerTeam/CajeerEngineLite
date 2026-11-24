#!/usr/bin/env python3
"""Run multi-base release rehearsals and negative verification with disposable keys."""
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path
sys.dont_write_bytecode=True
from release_build_148 import version
from release_engineering_148 import inventory_for_test
from release_distribution_152 import distribute, verify

ROOT=Path(__file__).resolve().parents[1]

def main():
    with tempfile.TemporaryDirectory(prefix='distribution-test-152-') as tmp:
        work=Path(tmp);candidate=work/'target'
        for name in inventory_for_test():
            dest=candidate/name;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(ROOT/name,dest)
        target=version(candidate)
        # Fixtures with different managed source bytes, using the actual updater. Official workflow uses exact Git tags instead.
        major,minor,patch=map(int,target.split('.'))
        prior=f'{major}.{minor}.{patch-1}' if patch else f'{major}.{minor-1}.9'
        older=f'{major}.{minor}.{patch-2}' if patch>=2 else f'{major}.{minor-1}.{8 if patch==0 else 9}'
        roots=[]
        for source in (prior,older):
            dest=work/source;shutil.copytree(candidate,dest);(dest/'VERSION').write_text(source+'\n')
            lite=dest/'core/Lite.php';lite.write_text(lite.read_text().replace(f"VERSION = '{target}'",f"VERSION = '{source}'"))
            (dest/'tests/old_fixture_152.json').write_text('{"base":"'+source+'"}\n');roots.append(dest)
        private=work/'private.pem';public=work/'public.pem';out=work/'dist'
        subprocess.run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048','-out',str(private)],check=True,stderr=subprocess.DEVNULL)
        private.chmod(0o600);subprocess.run(['openssl','pkey','-in',str(private),'-pubout','-out',str(public)],check=True)
        distribute(roots[0],roots[1:],candidate,out,private,public)
        packages=verify(out,public,target)
        assert {p['from'] for p in packages}=={prior,older}
        def rejects():
            try:verify(out,public,target)
            except (ValueError,RuntimeError):return
            raise AssertionError('Invalid distribution accepted')
        index=out/'update-index.json';data=index.read_bytes();index.write_bytes(data.replace(b'"payload":"',b'"payload":"A',1));rejects();index.write_bytes(data)
        alt=out/f'CajeerEngineLite-{older}-to-{target}.cjrupdate';data=alt.read_bytes();alt.write_bytes(data[:-1]+bytes([data[-1]^1]));rejects();alt.write_bytes(data)
        alt.unlink();rejects();alt.write_bytes(data)
        verify(out,public,target)
        print('Distribution release: two actual package rehearsals, index/asset tampering and missing asset rejection OK')

if __name__=='__main__':main()
