#!/usr/bin/env python3
"""Build, verify and rehearse an official release from two clean source exports.
No network calls; never run against a live installation. Python standard library only.
"""
import argparse
import base64
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import stat
import subprocess
import tempfile
import time
import zipfile

VERSION = re.compile(r'(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\Z')
# Old updater treats these as repository metadata, not deployment changes.
SOURCE_ONLY = {'.github/CODEOWNERS', '.github/FUNDING.yml', '.github/workflows/release.yml'}
FIXED = {'VERSION', 'LICENSE', 'NOTICE', '.gitignore', 'bootstrap.php', 'router.php',
         'bin/cajeer', 'public/index.php', 'public/.htaccess', 'storage/.gitkeep',
         '.github/workflows/ci.yml'} | SOURCE_ONLY
PATTERN = re.compile(r'(?:[A-Z][A-Z0-9_]*\.md|core/[A-Za-z0-9_/-]+\.php|'
                     r'templates/[A-Za-z0-9_/-]+\.cjr|tests/[A-Za-z0-9_/-]+\.(?:php|sh|py|json)|'
                     r'docs/[A-Z][A-Z0-9_]*\.md|deploy/[A-Za-z0-9_.-]+\.(?:conf|md|service|sh)|'
                     r'public/assets/[A-Za-z0-9_/-]+\.(?:css|js|svg|png|ico|woff2))\Z')


def digest(path):
    with path.open('rb') as f:
        return hashlib.file_digest(f, 'sha256').hexdigest()


def inventory(root, installed=False):
    result = {}
    for folder, dirs, files in os.walk(root, followlinks=False):
        for name in dirs + files:
            p = Path(folder) / name
            if p.is_symlink():
                raise ValueError(f'Symlink in release tree: {p.relative_to(root)}')
        if installed and Path(folder) == root:
            dirs[:] = [d for d in dirs if d != 'storage']
        for name in files:
            p = Path(folder) / name
            rel = p.relative_to(root).as_posix()
            st = p.stat()
            if not stat.S_ISREG(st.st_mode) or st.st_nlink != 1:
                raise ValueError(f'Unsafe release file: {rel}')
            if rel not in FIXED and not PATTERN.fullmatch(rel):
                raise ValueError(f'Unexpected release file: {rel}')
            if st.st_size > 12_000_000:
                raise ValueError(f'Release file too large: {rel}')
            result[rel] = digest(p)
    required = {'VERSION', 'bootstrap.php', 'bin/cajeer', 'core/Lite.php', 'public/index.php',
                'router.php', 'public/.htaccess', 'LICENSE', 'NOTICE'}
    if not required <= result.keys():
        raise ValueError('Incomplete source export')
    if not installed and ('storage/.gitkeep' not in result or (root/'storage/.gitkeep').stat().st_size):
        raise ValueError('Clean source export must contain only empty storage/.gitkeep')
    return result


def version(root):
    v = (root/'VERSION').read_text().strip()
    if not VERSION.fullmatch(v):
        raise ValueError('Invalid VERSION')
    if not re.search(r"public const VERSION\s*=\s*'" + re.escape(v) + r"'\s*;", (root/'core/Lite.php').read_text()):
        raise ValueError('VERSION and Lite::VERSION disagree')
    return v


def clean_env(extra=None):
    # Do not forward any live CMS/provider credentials or release signing secrets.
    env = {k: os.environ[k] for k in ('PATH', 'HOME', 'LANG', 'LC_ALL', 'TMPDIR', 'SYSTEMROOT') if k in os.environ}
    env.update(extra or {})
    return env


def run(args, root, extra=None):
    env = clean_env(extra)
    p = subprocess.run([str(a) for a in args], cwd=root, env=env, text=True,
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=180)
    if p.returncode != 0:
        raise RuntimeError(f'{args[0]} {args[1]} failed (exit {p.returncode}): {p.stderr[-2000:]}')
    return p.stdout


def cli(root, *args, env=None):
    return run(['php', 'bin/cajeer', *args], root, env)


def source_only(path):
    return path in SOURCE_ONLY or bool(re.fullmatch(r"docs/[A-Z][A-Z0-9_]*\.md", path))


def normalized(files):
    return {k: v for k, v in files.items() if not source_only(k) and k != 'storage/.gitkeep'}


def assert_code(root, expected):
    if normalized(inventory(root, installed=True)) != normalized(expected):
        raise ValueError('Installed source does not match expected release bytes')


def zip_source(root, files, output):
    # Sorted entries, normalized modes and fixed ZIP timestamp; no host paths or runtime state.
    with zipfile.ZipFile(output, 'x', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for name in sorted(files):
            raw = (root/name).read_bytes()
            if hashlib.sha256(raw).hexdigest() != files[name]:
                raise ValueError(f'Source changed during build: {name}')
            entry = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = (stat.S_IFREG | (0o755 if name == 'bin/cajeer' else 0o644)) << 16
            z.writestr(entry, raw, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)


def extract_verified(archive, files, root):
    root.mkdir()
    with zipfile.ZipFile(archive) as z:
        if z.namelist() != sorted(files) or z.testzip() is not None:
            raise ValueError('ZIP inventory or CRC mismatch')
        for name in sorted(files):
            raw = z.read(name)
            if hashlib.sha256(raw).hexdigest() != files[name]:
                raise ValueError('ZIP payload SHA-256 mismatch')
            dest = root/name
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(raw)
            dest.chmod(0o755 if name == 'bin/cajeer' else 0o644)


def fresh_install(root, expected_version):
    cli(root, 'install', 'https://release.invalid', 'Release rehearsal',
        env={'CAJEER_ADMIN_PASSWORD': secrets.token_urlsafe(32) + 'aA1!'})
    health(root, expected_version)
    return digest(root/'storage/site.json')


def health(root, expected_version, fixture=None):
    if version(root) != expected_version:
        raise ValueError('Wrong installed version')
    cli(root, 'doctor')
    cli(root, 'security', 'audit', '--json')
    # Real loopback HTTP, not a synthetic invocation of the front controller.
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    with tempfile.TemporaryFile() as log:
        server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', 'public', 'router.php'],
                                  cwd=root, env=clean_env(), stdout=log, stderr=log)
        def get(path):
            conn = http.client.HTTPConnection('127.0.0.1', port, timeout=3)
            try:
                conn.request('GET', path, headers={'Host': 'release.invalid'})
                r = conn.getresponse()
                return r.status, r.read(2_000_000)
            finally:
                conn.close()
        try:
            for _ in range(100):
                if server.poll() is not None:
                    raise ValueError('Rehearsal HTTP server exited')
                try:
                    status, raw = get('/health/ready')
                    break
                except OSError:
                    time.sleep(.05)
            else:
                raise ValueError('Rehearsal HTTP server unavailable')
            if status != 200 or json.loads(raw) != {'status': 'ready'}:
                raise ValueError('HTTP readiness failed')
            for path in ('/', '/robots.txt', '/sitemap.xml'):
                status, body = get(path)
                if status != 200 or not body:
                    raise ValueError('HTTP smoke failed: '+path)
            if fixture:
                status, body = get('/release-rehearsal')
                if status != 200 or b'RC rehearsal content' not in body:
                    raise ValueError('Published content lost during release')
                status, body = get('/media/'+fixture['id'])
                if status != 200 or hashlib.sha256(body).hexdigest() != fixture['sha256']:
                    raise ValueError('Media lost during release')
                status, body = get('/sitemap.xml')
                if b'/release-rehearsal' not in body:
                    raise ValueError('Sitemap lost published content')
                if get('/storage/site.json')[0] != 404 or get('/admin/media')[0] != 403:
                    raise ValueError('Private resources exposed during release')
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill(); server.wait()


def seed_rehearsal(root, work):
    # A real nonempty site exercising the production repository and media service.
    png = work/'rehearsal.png'
    png.write_bytes(base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGOUT9rBwMDAxMDAwMDAAAAOPQE9xgjZwQAAAABJRU5ErkJggg=='))
    fixture = json.loads(run(['php', '-r', r'''require "bootstrap.php";
        $a=new \CajeerEngine\Lite(getcwd());
        $a->change(static function(array $s):array {
            $s["pages"]["/release-rehearsal"]=\CajeerEngine\Lite::page([
                "path"=>"/release-rehearsal","title"=>"Release rehearsal","body"=>"RC rehearsal content",
                "published"=>"1","menu"=>"1"]);return $s;});
        echo json_encode((new \CajeerEngine\Media\MediaLibrary(getcwd()))->ingest($argv[1],"rehearsal.png"),JSON_THROW_ON_ERROR);''', str(png)], root))
    backup = cli(root, 'operations','backup','create').strip()
    cli(root, 'operations','backup','verify',backup)
    return fixture, backup


def persistent_hashes(root):
    paths = [root/'storage/site.json', root/'storage/media/index.json']
    paths += sorted((root/'storage/media/files').iterdir())
    return {p.relative_to(root).as_posix(): digest(p) for p in paths if p.is_file()}


def build(base, candidate, output, private, public, base_commit=None, target_commit=None, allow_nonadjacent=False):
    for commit in (base_commit, target_commit):
        if commit is not None and not re.fullmatch(r"(?:[0-9a-f]{40}|[0-9a-f]{64})", commit):
            raise ValueError("Invalid source commit ID")
    if (base_commit is None) != (target_commit is None):
        raise ValueError("Provide both source commit IDs or neither")
    for root in (base, candidate):
        if not root.is_dir() or root.is_symlink():
            raise ValueError('Source must be a directory')
    if base == candidate or base in candidate.parents or candidate in base.parents:
        raise ValueError('Source roots must be separate')
    for path in (output, private, public):
        if any(path == r or r in path.parents for r in (base, candidate)):
            raise ValueError('Output and keys must be outside source trees')
    if output.exists() or output.is_symlink():
        raise ValueError('Output directory must not exist')
    if not output.parent.is_dir():
        raise ValueError('Output parent must exist')
    if private == public or private.is_symlink() or public.is_symlink():
        raise ValueError('Separate regular key files required')
    if private.stat().st_mode & 0o077:
        raise ValueError('Signing key permissions must be 0600 or stricter')
    old, new = inventory(base), inventory(candidate)
    before, after = version(base), version(candidate)
    a, b, c = map(int, before.split('.')); x, y, z = map(int, after.split('.'))
    if (x,y,z) <= (a,b,c):
        raise ValueError('Release must advance version')
    if not allow_nonadjacent and (a, b, c+1) != (x,y,z) and (a,b+1,0) != (x,y,z) and (a+1,0,0) != (x,y,z):
        raise ValueError('Release must follow an adjacent version')
    changed = sorted(k for k in old.keys() | new.keys() if old.get(k) != new.get(k) and not source_only(k))
    # Build using the BASE updater, not the new one. Independently compare all changed paths.
    with tempfile.TemporaryDirectory(prefix='.cajeer-release-', dir=output.parent) as work:
        work = Path(work)
        dist = work/'dist'; dist.mkdir()
        package = dist/f'CajeerEngineLite-{after}.cjrupdate'
        full = dist/f'CajeerEngineLite-{after}.zip'
        live = work/'live'; shutil.copytree(base, live)
        result = json.loads(cli(live, 'update', 'package', str(candidate), str(package),
                                env={'CAJEER_UPDATE_SIGNING_KEY_FILE': str(private)}))
        trust = {'CAJEER_UPDATE_TRUSTED_KEY_FILE': str(public)}
        report = json.loads(run(['php', '-r', 'require "bootstrap.php";echo json_encode(\\CajeerEngine\\Update\\UpdatePackage::verify($argv[1],$argv[2]),JSON_THROW_ON_ERROR);', str(package), str(public)], live))
        manifest = report['manifest']
        if manifest['from'] != before or manifest['to'] != after or result['package_sha256'] != digest(package):
            raise ValueError('Package version or digest mismatch')
        actual = {m['path']: (m['before'], m['after']) for m in manifest['files']}
        if actual != {k: (old.get(k), new.get(k)) for k in changed}:
            raise ValueError('Base updater omitted or altered changed paths; manual transition required')
        zip_source(candidate, new, full)
        clean = work/'clean'; extract_verified(full, new, clean)
        fresh_install(clean, after)
        fresh_install(live, before)
        fixture, backup = seed_rehearsal(live, work)
        health(live, before, fixture)
        original = persistent_hashes(live)
        cli(live, 'update', 'verify', str(package), env=trust)
        installed = json.loads(cli(live, 'update', 'install', str(package), env=dict(trust, CAJEER_UPDATE_CONFIRM='INSTALL')))
        assert_code(live, new); health(live, after, fixture)
        cli(live, 'operations', 'backup', 'verify', backup)
        if persistent_hashes(live) != original:
            raise ValueError('Code update changed site data')
        cli(live, 'update', 'rollback', installed['id'], env=dict(trust, CAJEER_UPDATE_CONFIRM='ROLLBACK'))
        assert_code(live, old); health(live, before, fixture)
        cli(live, 'operations', 'backup', 'verify', backup)
        if persistent_hashes(live) != original:
            raise ValueError('Rollback changed site data')
        # Independently check that a modified package is rejected by the actual installed verifier.
        damaged = work/'damaged.cjrupdate'; shutil.copyfile(package, damaged)
        with damaged.open('r+b') as f:
            f.seek(-1, 2); byte = f.read(1); f.seek(-1, 2); f.write(bytes([byte[0] ^ 1]))
        try:
            cli(live, 'update', 'verify', str(damaged), env=trust)
        except RuntimeError:
            pass
        else:
            raise ValueError('Modified release was accepted')
        receipt = {'from': before, 'to': after, 'php': run(['php','-r','echo PHP_VERSION;'], live),
                   'schema': 14, 'key_sha256': report['key_sha256'], 'changed_files': changed,
                   'base_commit': base_commit, 'target_commit': target_commit,
                   'base_tree_sha256': hashlib.sha256(json.dumps(old, sort_keys=True, separators=(',', ':')).encode()).hexdigest(),
                   'target_tree_sha256': hashlib.sha256(json.dumps(new, sort_keys=True, separators=(',', ':')).encode()).hexdigest(),
                   'runtime_files': {k: v for k, v in new.items() if k in {'VERSION','bootstrap.php','router.php','bin/cajeer'} or k.startswith(('core/','public/','templates/'))},
                   'source_only_changes': sorted(k for k in old.keys() | new.keys() if source_only(k) and old.get(k) != new.get(k)),
                   'checks': ['zip_inventory', 'fresh_install', 'loopback_http', 'signature', 'exact_upgrade', 'site_preserved', 'populated_site_media_preserved', 'backup_verified_before_after_rollback', 'rollback', 'tamper_rejected'],
                   'production_accepted': False}
        (dist/'release-report.json').write_text(json.dumps(receipt, indent=2, sort_keys=True)+'\n')
        sums = dist/'SHA256SUMS'
        sums.write_text(''.join(f'{digest(p)}  {p.name}\n' for p in sorted(dist.iterdir())))
        sig = dist/'SHA256SUMS.sig'
        run(['openssl','dgst','-sha256','-sign',str(private),'-out',str(sig),str(sums)], work)
        run(['openssl','dgst','-sha256','-verify',str(public),'-signature',str(sig),str(sums)], work)
        # Publication is an atomic same-filesystem rename of a complete verified directory.
        if output.exists() or output.is_symlink():
            raise ValueError('Output appeared during build')
        dist.rename(output)
    print(json.dumps(receipt, indent=2))


def main():
    p = argparse.ArgumentParser(description=__doc__)
    for option in ('base','candidate','output','signing-key','trusted-key'):
        p.add_argument('--'+option, required=True, type=Path)
    p.add_argument("--base-commit")
    p.add_argument("--target-commit")
    a = p.parse_args()
    try:
        paths = [a.base, a.candidate, a.output, a.signing_key, a.trusted_key]
        if any(q.is_symlink() for q in paths):
            raise ValueError('Symlink argument is not allowed')
        build(*(q.resolve() for q in paths), base_commit=a.base_commit, target_commit=a.target_commit)
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError) as e:
        p.exit(1, f'Release failed: {e}\n')


if __name__ == '__main__':
    main()
