#!/usr/bin/env python3
"""Exercise the release builder with isolated source trees and disposable RSA keys."""
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
# Import without creating __pycache__ in the source export.
sys.dont_write_bytecode = True


def main():
    with tempfile.TemporaryDirectory(prefix='release-148-test-') as tmp:
        work = Path(tmp)
        base, candidate = work/'base', work/'next'
        for dest in (base, candidate):
            dest.mkdir()
            # Copy only shipped source, never .git, local runtime or pycache.
            for name in inventory_for_test():
                target = dest/name
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(ROOT/name, target)
        v = (candidate/'VERSION').read_text().strip()
        major, minor, patch = map(int, v.split('.'))
        prior = f'{major}.{minor}.{patch-1}' if patch else f'{major}.{minor-1}.9'
        (base/'VERSION').write_text(prior+'\n')
        lite = base/'core/Lite.php'
        lite.write_text(lite.read_text().replace(f"VERSION = '{v}'", f"VERSION = '{prior}'"))
        # Actual file removal and addition, not just a version increment.
        (base/'tests/obsolete_release_148.json').write_text('{}\n')
        (candidate/'tests/added_release_148.json').write_text('{"new":true}\n')
        private, public = work/'private.pem', work/'public.pem'
        subprocess.run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048','-out',str(private)], check=True, stderr=subprocess.DEVNULL)
        private.chmod(0o600)
        subprocess.run(['openssl','pkey','-in',str(private),'-pubout','-out',str(public)], check=True)
        def build(name, ok=True):
            out = work/name
            p = subprocess.run([sys.executable, str(ROOT/'tests/release_build_148.py'), '--base',str(base), '--candidate',str(candidate), '--output',str(out), '--signing-key',str(private), '--trusted-key',str(public)], capture_output=True, text=True)
            if (p.returncode == 0) != ok:
                raise AssertionError(p.stdout+p.stderr)
            if not ok:
                assert not out.exists(), 'Failed release was published'
            return out
        out = build('release')
        receipt = json.loads((out/'release-report.json').read_text())
        assert receipt['from'] == prior and receipt['to'] == v and not receipt['production_accepted']
        assert {'tests/obsolete_release_148.json','tests/added_release_148.json'} <= set(receipt['changed_files'])
        subprocess.run(['openssl','dgst','-sha256','-verify',str(public),'-signature',str(out/'SHA256SUMS.sig'),str(out/'SHA256SUMS')], check=True)
        for line in (out/'SHA256SUMS').read_text().splitlines():
            sha, name = line.split('  ')
            assert hashlib.sha256((out/name).read_bytes()).hexdigest() == sha
        second = build('repeat')
        for suffix in ('zip','cjrupdate'):
            assert (out/f'CajeerEngineLite-{v}.{suffix}').read_bytes() == (second/f'CajeerEngineLite-{v}.{suffix}').read_bytes()
        (candidate/'storage/site.json').write_text('{"secret":"do not ship"}')
        build('dirty', False)
        (candidate/'storage/site.json').unlink()
        (candidate/'tests/linked_release_148.json').symlink_to(private)
        build('link', False)
        (candidate/'tests/linked_release_148.json').unlink()
        router = candidate/'router.php'; old = router.read_bytes(); router.write_bytes(old+b'\n')
        build('omitted', False); router.write_bytes(old)
        # Wrong public key must fail the signing trust check.
        other = work/'other.pem'
        subprocess.run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048','-out',str(other)],check=True,stderr=subprocess.DEVNULL)
        subprocess.run(['openssl','pkey','-in',str(other),'-pubout','-out',str(public)],check=True)
        build('wrong-key', False)
        print('Release Engineering: signed reproducible ZIP/package, actual upgrade/removal/rollback, dirty tree, symlink, omitted runtime path and wrong key rejection OK')


def inventory_for_test():
    # Tests also run in GitHub worktrees; export the current tracked-shaped tree.
    from release_build_148 import FIXED, PATTERN
    for p in ROOT.rglob('*'):
        if p.is_file():
            name = p.relative_to(ROOT).as_posix()
            if name in FIXED or PATTERN.fullmatch(name):
                yield name


if __name__ == '__main__':
    main()
