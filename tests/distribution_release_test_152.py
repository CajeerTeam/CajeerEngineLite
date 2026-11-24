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
from release_publication_158 import base_tag, verify_download

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
        # A missing intermediate stable release must not block primary distribution.
        distribute(roots[1],roots[:1],candidate,out,private,public)
        packages=verify(out,public,target)
        assert {p['from'] for p in packages}=={prior,older}
        def rejects():
            try:verify(out,public,target)
            except (ValueError,RuntimeError):return
            raise AssertionError('Invalid distribution accepted')
        index=out/'update-index.json';data=index.read_bytes();index.write_bytes(data.replace(b'"payload":"',b'"payload":"A',1));rejects();index.write_bytes(data)
        assert next(p for p in packages if p['name']==f'CajeerEngineLite-{target}.cjrupdate')['from']==older
        alt=out/f'CajeerEngineLite-{prior}-to-{target}.cjrupdate';data=alt.read_bytes();alt.write_bytes(data[:-1]+bytes([data[-1]^1]));rejects();alt.write_bytes(data)
        alt.unlink();rejects();alt.write_bytes(data)
        verify(out,public,target)
        def release(tag, draft=False, preview=False):
            return {'tag_name':tag,'draft':draft,'prerelease':preview}
        assert base_tag([[release('v1.5.6'),release('v1.5.9',preview=True)],[release('v1.5.7'),release('v1.6.0',draft=True)]],'v1.5.8')=='v1.5.7'
        for pages in ([[release('v1.5.8')]], [[release('v1.5.7'),release('1.5.7')]], [[]]):
            try: base_tag(pages,'v1.5.8')
            except ValueError: pass
            else: raise AssertionError('Invalid base selection accepted')
        remote=work/'downloaded';shutil.copytree(out,remote);verify_download(out,remote,public,target)
        (remote/'unexpected').write_bytes(b'bad')
        try:verify_download(out,remote,public,target)
        except (RuntimeError,ValueError):pass
        else:raise AssertionError('Extra downloaded asset accepted')
        (remote/'unexpected').unlink()
        remote_alt=remote/alt.name;data=remote_alt.read_bytes();remote_alt.write_bytes(data[:-1]+bytes([data[-1]^1]))
        try:verify_download(out,remote,public,target)
        except (RuntimeError,ValueError):pass
        else:raise AssertionError('Modified downloaded asset accepted')
        remote_alt.write_bytes(data);verify_download(out,remote,public,target)
        print('Publication: stable base selection, exact signed draft inventory and tamper rejection OK')
        print('Distribution release: two actual package rehearsals, index/asset tampering and missing asset rejection OK')

if __name__=='__main__':main()
