#!/usr/bin/env python3
"""Build and verify a signed exact-base distribution from separate clean source exports."""
import argparse
import base64
import json
from pathlib import Path
import shutil
import sys
import tempfile
sys.dont_write_bytecode = True
from release_build_148 import build, digest, version, run

ROOT = Path(__file__).resolve().parents[1]


def verify(dist, public, target):
    # Reuse the production decoder and package signature verifier, not a second trust policy.
    code = r'''require "bootstrap.php";
    $d=$argv[1];$key=$argv[2];$to=$argv[3];
    $index=\CajeerEngine\Update\DistributionIndex::verify(\CajeerEngine\Update\UpdatePackage::readRegular($d.'/update-index.json',65536),$to,$key);
    foreach($index as $p){
        $file=$d.'/'.$p['name'];$v=\CajeerEngine\Update\UpdatePackage::verify($file,$key);
        if($v['manifest']['from']!==$p['from']||$v['manifest']['to']!==$to||filesize($file)!==$p['bytes']||!hash_equals($v['package_sha256'],$p['sha256']))throw new RuntimeException('Distribution package mismatch');
    }echo json_encode($index,JSON_THROW_ON_ERROR);'''
    packages = json.loads(run(['php','-r',code,str(dist),str(public),target], ROOT))
    primary = f'CajeerEngineLite-{target}.cjrupdate'
    expected = {p['name'] for p in packages} | {f'CajeerEngineLite-{target}.zip','release-report.json','SHA256SUMS','SHA256SUMS.sig','update-index.json'}
    if primary not in expected or {p.name for p in dist.iterdir()} != expected or any(p.is_symlink() or not p.is_file() for p in dist.iterdir()):
        raise ValueError('Unexpected distribution inventory')
    run(['openssl','dgst','-sha256','-verify',str(public),'-signature','SHA256SUMS.sig','SHA256SUMS'],dist)
    sums = (dist/'SHA256SUMS').read_text().splitlines()
    expected_primary = {primary,f'CajeerEngineLite-{target}.zip','release-report.json'}
    if len(sums)!=3 or {line.split('  ')[1] for line in sums} != expected_primary:
        raise ValueError('Invalid primary checksum inventory')
    for line in sums:
        sha,name=line.split('  ')
        if digest(dist/name)!=sha: raise ValueError('Primary artifact mismatch')
    receipt=json.loads((dist/'release-report.json').read_text())
    primary_entry=next(p for p in packages if p['name']==primary)
    if receipt['to']!=target or receipt['from']!=primary_entry['from']:
        raise ValueError('Primary report mismatch')
    return packages


def distribute(base, extras, candidate, output, private, public, base_commit=None,target_commit=None):
    if output.exists() or output.is_symlink() or len(extras)>15: raise ValueError('Invalid distribution output or too many bases')
    target=version(candidate)
    roots=[base,*extras]; versions=[version(r) for r in roots]
    if len(set(versions))!=len(versions): raise ValueError('Duplicate base version')
    for path in (output, private, public):
        if any(path==r or r in path.parents for r in [*roots,candidate]): raise ValueError('Keys and output must be outside source trees')
    with tempfile.TemporaryDirectory(prefix='.distribution-',dir=output.parent) as tmp:
        work=Path(tmp); dist=work/'primary'
        build(base,candidate,dist,private,public,base_commit,target_commit)
        primary=dist/f'CajeerEngineLite-{target}.cjrupdate'
        entries=[{'from':versions[0],'name':primary.name,'bytes':primary.stat().st_size,'sha256':digest(primary)}]
        for root, source in zip(extras,versions[1:]):
            extra=work/('base-'+source)
            # Exact old updater performs its own upgrade and rollback rehearsal.
            build(root,candidate,extra,private,public,allow_nonadjacent=True)
            name=f'CajeerEngineLite-{source}-to-{target}.cjrupdate'
            shutil.copyfile(extra/primary.name,dist/name)
            file=dist/name
            entries.append({'from':source,'name':name,'bytes':file.stat().st_size,'sha256':digest(file)})
        payload=json.dumps({'format':1,'product':'CajeerEngineLite','to':target,'packages':sorted(entries,key=lambda p:p['from'])},sort_keys=True,separators=(',',':')).encode()
        (work/'payload').write_bytes(payload)
        run(['openssl','dgst','-sha256','-sign',str(private),'-out',str(work/'sig'),str(work/'payload')],work)
        (dist/'update-index.json').write_text(json.dumps({'payload':base64.b64encode(payload).decode(),'signature':base64.b64encode((work/'sig').read_bytes()).decode()},separators=(',',':'))+'\n')
        verify(dist,public,target)
        if output.exists() or output.is_symlink(): raise ValueError('Output appeared during build')
        dist.rename(output)


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--verify',type=Path);p.add_argument('--target');p.add_argument('--base',type=Path);p.add_argument('--extra-base',action='append',type=Path,default=[])
    for name in ('candidate','output','signing-key','trusted-key'):p.add_argument('--'+name,type=Path)
    p.add_argument('--base-commit');p.add_argument('--target-commit')
    a=p.parse_args()
    try:
        if a.verify:
            if not a.trusted_key or not a.target: raise ValueError('Verification requires key and target')
            verify(a.verify.resolve(),a.trusted_key.resolve(),a.target)
        else:
            args=[a.base,*a.extra_base,a.candidate,a.output,a.signing_key,a.trusted_key]
            if any(x is None or x.is_symlink() for x in args):raise ValueError('Missing or unsafe distribution argument')
            distribute(a.base.resolve(),[x.resolve() for x in a.extra_base],a.candidate.resolve(),a.output.resolve(),a.signing_key.resolve(),a.trusted_key.resolve(),a.base_commit,a.target_commit)
    except (ValueError,OSError,RuntimeError,KeyError) as e:p.exit(1,f'Distribution failed: {e}\n')

if __name__=='__main__':main()
