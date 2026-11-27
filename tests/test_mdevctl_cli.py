"""Real mdevctl argument/stop/undefine contract in a private, GPU-free namespace.

Only regular fixture sysfs files are writable. No host /sys, GPU devices,
network, service, or Unraid installation is exposed.
"""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


class RealMdevctlTests(unittest.TestCase):
    UUID = 'ae9572f6-1259-4136-9aa4-497dda98d2db'
    PCI = '0000:01:00.0'
    TYPE = 'nvidia-762'

    @classmethod
    def setUpClass(cls):
        cls.binary = os.environ.get('MDEVCTL_BIN')
        if not cls.binary:
            raise unittest.SkipTest('Set MDEVCTL_BIN to run real isolated mdevctl contracts')
        if not Path(cls.binary).is_file() or not shutil.which('bwrap'):
            raise RuntimeError('Configured real mdevctl/bubblewrap dependency is missing')

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='vgpu-real-mdevctl-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        for name in ['proc', 'dev', 'lib', 'lib64', 'usr/lib/x86_64-linux-gnu',
                     'usr/lib/mdevctl/scripts.d/callouts', 'usr/lib/mdevctl/scripts.d/notifiers',
                     'etc/mdevctl.d', 'sys/class/mdev_bus', 'sys/bus/mdev/devices', 'usr/bin']:
            (self.root/name).mkdir(parents=True, exist_ok=True)
        shutil.copy2(self.binary, self.root/'usr/bin/mdevctl')
        self.parent = self.root/'sys/devices/pci0000:00'/self.PCI
        self.device = self.parent/self.UUID
        self.device.mkdir(parents=True)
        self.type = self.parent/'mdev_supported_types'/self.TYPE
        self.type.mkdir(parents=True)
        (self.type/'name').write_text('NVIDIA RTXA5500-1Q\n')
        (self.type/'device_api').write_text('vfio-pci\n')
        (self.type/'available_instances').write_text('23\n')
        (self.device/'mdev_type').symlink_to('../mdev_supported_types/'+self.TYPE)
        (self.device/'remove').write_text('0')
        (self.root/'sys/bus/mdev/devices'/self.UUID).symlink_to('/sys/devices/pci0000:00/'+self.PCI+'/'+self.UUID)
        (self.root/'sys/class/mdev_bus'/self.PCI).symlink_to('/sys/devices/pci0000:00/'+self.PCI)
        self.definition = self.root/'etc/mdevctl.d'/self.PCI/self.UUID
        self.definition.parent.mkdir()
        self.definition.write_text(json.dumps({'mdev_type': self.TYPE, 'start': 'manual', 'attrs': []}))
        self.prefix = [
            'bwrap', '--unshare-all', '--die-with-parent', '--uid', '0', '--gid', '0',
            '--bind', str(self.root), '/', '--ro-bind', '/lib', '/lib', '--ro-bind', '/lib64', '/lib64',
            '--ro-bind', '/usr/lib/x86_64-linux-gnu', '/usr/lib/x86_64-linux-gnu',
            '--proc', '/proc', '--dev', '/dev', '/usr/bin/mdevctl']

    def run_cli(self, *args):
        return subprocess.run(self.prefix+list(args), capture_output=True, text=True, timeout=15)

    def test_real_help_stop_has_no_parent_but_undefine_does(self):
        stop = self.run_cli('stop', '--help')
        undefine = self.run_cli('undefine', '--help')
        self.assertEqual(stop.returncode, 0, stop.stderr)
        self.assertEqual(undefine.returncode, 0, undefine.stderr)
        self.assertIn('--uuid', stop.stdout)
        self.assertNotIn('--parent', stop.stdout)
        self.assertIn('--parent', undefine.stdout)

    def test_old_stop_parent_argv_is_a_real_parser_failure(self):
        result = self.run_cli('stop', '-u', self.UUID, '-p', self.PCI)
        self.assertEqual(result.returncode, 2, result.stdout+result.stderr)
        self.assertIn("unexpected argument '-p'", result.stderr)
        self.assertEqual((self.device/'remove').read_text(), '0')
        self.assertTrue(self.definition.exists())

    def test_uuid_only_stop_reaches_real_sysfs_write_without_undefining(self):
        result = self.run_cli('stop', '-u', self.UUID)
        self.assertEqual(result.returncode, 0, result.stdout+result.stderr)
        self.assertEqual((self.device/'remove').read_text(), '1')
        self.assertTrue(self.definition.exists())

    def test_undefine_retains_parent_scope_and_other_definition(self):
        other = self.root/'etc/mdevctl.d/0000:02:00.0'/self.UUID
        other.parent.mkdir()
        other.write_bytes(self.definition.read_bytes())
        stopped = self.run_cli('stop', '-u', self.UUID)
        self.assertEqual(stopped.returncode, 0, stopped.stderr)
        # Emulate only the kernel's disappearance of this private fixture mdev.
        (self.root/'sys/bus/mdev/devices'/self.UUID).unlink()
        result = self.run_cli('undefine', '-u', self.UUID, '-p', self.PCI)
        self.assertEqual(result.returncode, 0, result.stdout+result.stderr)
        self.assertFalse(self.definition.exists())
        self.assertTrue(other.exists())


if __name__ == '__main__':
    unittest.main()
