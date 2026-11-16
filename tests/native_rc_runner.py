#!/usr/bin/env python3
"""Execute UNMODIFIED upstream rc.modules + genuine pkgtools inside a private namespace.

Only passive fixture TXZ files are allowed. No host GPU/sysfs, network, system
configuration, hooks, or real Unraid are writable/accessible through this test.
"""
import argparse
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_PKGTOOLS = ROOT.parent / 'unraid-vgpu/inputs/packages/pkgtools-15.0-noarch-42.txz'


def native_install(root, kernel, pkgtools=DEFAULT_PKGTOOLS):
    root = Path(root).resolve()
    if root.parent != Path(tempfile.gettempdir()) or not root.name.startswith('vgpu-native-boot-'):
        raise ValueError('Only a NativeBootFixture root is allowed')
    if not (root / 'proc/sys/kernel/osrelease').is_file():
        raise ValueError('Missing passive fixture marker')
    import re
    if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+-Unraid', kernel):
        raise ValueError('Invalid fixture kernel')
    # Archives cannot carry doinst scripts, real ELF executables or path escapes.
    staged = root / 'boot/config/plugins/unraid-vgpu-manager/packages' / kernel.removesuffix('-Unraid')
    for package in staged.glob('*.txz'):
        with tarfile.open(package) as archive:
            for member in archive:
                name = member.name.removeprefix('./')
                if member.issym() or member.islnk() or name.startswith('/') or '..' in Path(name).parts or name.startswith('install/'):
                    raise ValueError('Not a passive fixture archive')
                if member.isfile() and archive.extractfile(member).read(4) == b'\x7fELF':
                    raise ValueError('Real binaries are forbidden in native fixture packages')
    with tempfile.TemporaryDirectory(prefix='vgpu-native-tools-') as temporary:
        tools = Path(temporary)
        with tarfile.open(pkgtools) as archive:
            script = archive.extractfile('sbin/installpkg').read()
        (tools / 'installpkg-real').write_bytes(script)
        (tools / 'uname').write_text('#!/bin/bash\nprintf "%s\\n" ' + kernel + '\n')
        (tools / 'installpkg').write_text('#!/bin/bash\nprintf "install %s\\n" "$1" >> /fixture/native-order.log\nexec /bin/bash /fixture-tools/installpkg-real --root /fixture "$@"\n')
        (tools / 'depmod').write_text('#!/bin/bash\nprintf "depmod\\n" >> /fixture/native-order.log\n')
        (tools / 'rc.runlog').write_text('#!/bin/bash\nlog(){ printf "%s\\n" "$*"; }\n')
        for name in ['uname', 'installpkg', 'depmod']:
            (tools / name).chmod(0o755)
        args = ['bwrap', '--unshare-all', '--die-with-parent', '--uid', '0', '--gid', '0',
                '--ro-bind', '/usr', '/usr', '--symlink', 'usr/bin', '/bin', '--symlink', 'usr/sbin', '/sbin',
                '--symlink', 'usr/lib', '/lib', '--symlink', 'usr/lib64', '/lib64',
                '--tmpfs', '/usr/sbin', '--dir', '/etc', '--dir', '/etc/rc.d', '--dir', '/var',
                '--tmpfs', '/tmp', '--proc', '/proc', '--dev', '/dev',
                '--bind', str(root), '/fixture', '--bind', str(root / 'boot'), '/boot',
                '--ro-bind', str(tools), '/fixture-tools',
                '--ro-bind', str(tools / 'uname'), '/usr/bin/uname',
                '--ro-bind', str(tools / 'installpkg'), '/usr/sbin/installpkg',
                '--ro-bind', str(tools / 'depmod'), '/usr/sbin/depmod',
                '--ro-bind', str(tools / 'rc.runlog'), '/etc/rc.d/rc.runlog',
                '--ro-bind', str(ROOT / 'tests/fixtures/unraid/rc.modules'), '/etc/rc.d/rc.modules',
                '--setenv', 'PATH', '/usr/bin:/usr/sbin', '--chdir', '/fixture',
                '/bin/bash', '/etc/rc.d/rc.modules']
        result = subprocess.run(args, capture_output=True, text=True, timeout=60)
        order = (root / 'native-order.log').read_text().splitlines() if (root / 'native-order.log').exists() else []
        if result.returncode or not order or order[-1] != 'depmod':
            raise RuntimeError('Native isolated boot failed: ' + result.stdout[-1500:] + result.stderr[-1500:])
        return {'scope': 'unmodified rc.modules + genuine installpkg --root, passive fixture modules', 'order': order}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', required=True, type=Path)
    parser.add_argument('--kernel', required=True)
    args = parser.parse_args()
    print(json.dumps(native_install(args.root, args.kernel)))
