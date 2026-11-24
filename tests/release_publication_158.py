#!/usr/bin/env python3
"""Select a published stable base and verify the exact downloaded draft inventory."""
import argparse
import json
from pathlib import Path
import re
import sys
sys.dont_write_bytecode = True
from release_distribution_152 import verify
from release_build_148 import digest


def numeric(tag):
    if not isinstance(tag, str) or not re.fullmatch(r'v?(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)', tag):
        raise ValueError('Invalid version tag')
    return tuple(map(int, tag.removeprefix('v').split('.')))


def base_tag(pages, target):
    wanted = numeric(target)
    candidates = {}
    if not isinstance(pages, list) or not pages or any(not isinstance(p, list) for p in pages):
        raise ValueError('Invalid paginated releases response')
    for page in pages:
        for r in page:
            if not isinstance(r, dict) or type(r.get('draft')) is not bool or type(r.get('prerelease')) is not bool:
                raise ValueError('Invalid release metadata')
            if r['draft'] or r['prerelease']:
                continue
            try:
                tag = r['tag_name']; v = numeric(tag)
            except (KeyError, ValueError):
                continue  # Ignore non-version releases.
            if v >= wanted:
                raise ValueError('A stable release at or above the target already exists')
            if v in candidates:
                raise ValueError('Ambiguous stable version tags')
            candidates[v] = tag
    if not candidates:
        raise ValueError('No published stable base; dispatch explicitly with a reviewed base')
    return candidates[max(candidates)]


def verify_download(local, remote, key, target):
    verify(local, key, target)
    verify(remote, key, target)
    if {p.name: digest(p) for p in local.iterdir()} != {p.name: digest(p) for p in remote.iterdir()}:
        raise ValueError('Downloaded draft differs from the approved signed distribution')


def main():
    p = argparse.ArgumentParser(description=__doc__)
    sub = p.add_subparsers(dest='command', required=True)
    b = sub.add_parser('base'); b.add_argument('--target', required=True)
    v = sub.add_parser('verify-draft'); v.add_argument('--local', type=Path, required=True); v.add_argument('--remote', type=Path, required=True); v.add_argument('--trusted-key', type=Path, required=True); v.add_argument('--target', required=True)
    a = p.parse_args()
    try:
        if a.command == 'base':
            print(base_tag(json.load(sys.stdin), a.target))
        else:
            verify_download(a.local.resolve(), a.remote.resolve(), a.trusted_key.resolve(), a.target)
            print('Downloaded draft matches the verified signed distribution')
    except (ValueError, OSError, RuntimeError, KeyError) as e:
        p.exit(1, f'Release publication rejected: {e}\n')

if __name__ == '__main__':
    main()
