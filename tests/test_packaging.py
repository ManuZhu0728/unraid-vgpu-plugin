"""Passive payload checks; do not invoke an Unraid package installer."""
import base64
import hashlib
import importlib.util
from pathlib import Path
import tarfile
import tempfile
import unittest
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('build_plugin', ROOT / 'scripts/build-plugin.py')
BUILD = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILD)


class PackagingTests(unittest.TestCase):
    def test_offline_payload_is_only_new_plugin_and_is_self_contained(self):
        with tempfile.TemporaryDirectory(prefix='vgpu-package-test-') as temporary:
            package, installer = BUILD.build('2026.11.02.1', Path(temporary))
            self.assertEqual(installer.name, 'unraid-vgpu-manager.plg')
            with tarfile.open(package, 'r:xz') as archive:
                names = archive.getnames()
                self.assertTrue(names)
                self.assertTrue(all(name.startswith('usr/local/emhttp/plugins/unraid-vgpu-manager/') for name in names))
                self.assertFalse(any('novidio-vgpu-driver/' in name or 'credentials.json' in name for name in names))
                self.assertTrue(all(member.uid == 0 and member.gid == 0 for member in archive.getmembers()))
                for component in ['include/Upgrade.php', 'include/Installer.php', 'include/upgrade-watch.php', 'scripts/upgrade-helper', 'include/Logs.php', 'include/Inventory.php', 'include/ui-page.php', 'include/ui/common.php', 'include/ui/state.php', 'include/ui/driver.php', 'include/ui/vgpu.php', 'include/ui/status.php']:
                    self.assertIn('usr/local/emhttp/plugins/unraid-vgpu-manager/' + component, names)
                prefix = 'usr/local/emhttp/plugins/unraid-vgpu-manager/'
                parent = archive.extractfile(prefix + 'unraid-vgpu-manager.page').read().decode()
                self.assertIn('Type="xmenu"', parent)
                self.assertIn('Tabs="true"', parent)
                for index, tab in enumerate(['driver', 'vgpu', 'status'], 1):
                    child = archive.extractfile(prefix + 'unraid-vgpu-manager-' + tab + '.page').read().decode()
                    self.assertIn(f'Menu="unraid-vgpu-manager:{index}"', child)
                    self.assertIn("include/ui-page.php", child)
                    self.assertIn('Markdown="false"', child)
                    body = child.split('\n---\n', 1)[1].strip()
                    self.assertTrue(body.startswith('<?php'))
                    self.assertTrue(body.endswith('?>'))
            xml = ET.parse(installer).getroot()
            entry = xml.find('FILE')
            self.assertEqual(entry.attrib['Type'], 'base64')
            self.assertIsNone(entry.find('URL'))
            self.assertEqual(base64.b64decode(entry.findtext('INLINE')), package.read_bytes())
            self.assertEqual(entry.findtext('MD5'), hashlib.md5(package.read_bytes()).hexdigest())
            script = ''.join(item.findtext('INLINE', '') for item in xml.findall('FILE'))
            self.assertNotIn('download.sh', script)
            self.assertNotIn('killall', script)
            self.assertNotIn('modprobe -r', script)
            self.assertIn('scripts/upgrade-helper start', script)
            self.assertIn('scripts/upgrade-helper stop', script)
            self.assertLess(script.index('scripts/rc.vgpu boot'), script.index('scripts/upgrade-helper start'))
            self.assertNotIn('/usr/bin/plugin_update_helper', script)

    def test_public_installer_requires_explicit_credential_free_public_urls(self):
        with tempfile.TemporaryDirectory(prefix='vgpu-package-test-') as temporary:
            BUILD.build('2026.11.02.1', Path(temporary), 'https://github.com/example/plugin/releases/download/v1', 'https://github.com/example/plugin/releases/latest/download/unraid-vgpu-manager.plg')
            xml = ET.parse(Path(temporary) / 'unraid-vgpu-manager.plg').getroot()
            self.assertTrue(xml.attrib['pluginURL'].startswith('https://github.com/example/'))
            self.assertIsNotNone(xml.find('FILE/URL'))
        for url in ['https://secret@example.com/a', 'http://example.com/a', 'https://example.com/a?token=secret']:
            with self.assertRaises(ValueError):
                # argparse.ArgumentTypeError derives from Exception, not ValueError.
                try:
                    BUILD.https_url(url)
                except Exception as error:
                    raise ValueError(str(error))


if __name__ == '__main__':
    unittest.main()
