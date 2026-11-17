"""Passive payload checks; do not invoke an Unraid package installer."""
import base64
import hashlib
import importlib.util
from pathlib import Path
import re
import shutil
import tarfile
import tempfile
import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('build_plugin', ROOT / 'scripts/build-plugin.py')
BUILD = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILD)


class PackagingTests(unittest.TestCase):
    def assert_list_metadata(self, xml):
        self.assertEqual(xml.attrib['author'], 'lbcz')
        self.assertEqual(xml.attrib['icon'], 'nvidia-driver.png')
        self.assertEqual(xml.attrib['launch'], 'Settings/unraid-vgpu-manager')

    def test_offline_payload_is_only_new_plugin_and_is_self_contained(self):
        with tempfile.TemporaryDirectory(prefix='vgpu-package-test-') as temporary:
            package, installer = BUILD.build('2026.11.02.1', Path(temporary))
            self.assertEqual(installer.name, 'unraid-vgpu-manager.plg')
            with tarfile.open(package, 'r:xz') as archive:
                names = archive.getnames()
                self.assertTrue(names)
                hook = 'usr/local/emhttp/plugins/dynamix.plugin.manager/post-hooks/unraid-vgpu-manager'
                self.assertTrue(all(name.startswith('usr/local/emhttp/plugins/unraid-vgpu-manager/') or name == hook for name in names))
                self.assertIn(hook, names)
                self.assertEqual(archive.getmember(hook).mode, 0o755)
                self.assertEqual(archive.extractfile(hook).read(), (ROOT / 'source' / hook).read_bytes())
                self.assertFalse(any('novidio-vgpu-driver/' in name or 'credentials.json' in name for name in names))
                self.assertTrue(all(member.uid == 0 and member.gid == 0 for member in archive.getmembers()))
                for component in ['README.md', 'images/nvidia-driver.png', 'include/Upgrade.php', 'include/Installer.php', 'include/upgrade-watch.php', 'include/upgrade-check.php', 'scripts/upgrade-helper', 'include/Logs.php', 'include/Inventory.php', 'include/ui-page.php', 'include/ui/common.php', 'include/ui/state.php', 'include/ui/driver.php', 'include/ui/vgpu.php', 'include/ui/status.php']:
                    self.assertIn('usr/local/emhttp/plugins/unraid-vgpu-manager/' + component, names)
                prefix = 'usr/local/emhttp/plugins/unraid-vgpu-manager/'
                self.assertIn(prefix + 'include/PackageIdentity.php', names)
                self.assertIn(prefix + 'include/InstallConfiguration.php', names)
                for component in ['StartupInventory.php', 'PackageHistory.php', 'PackageCache.php', 'PkgtoolsMigration.php', 'InstallContract.php', 'InstallTimeout.php']:
                    self.assertIn(prefix + 'include/' + component, names)
                parent = archive.extractfile(prefix + 'unraid-vgpu-manager.page').read().decode()
                self.assertIn('Type="xmenu"', parent)
                self.assertIn('Tabs="true"', parent)
                # The native list reads README.md from this exact directory and
                # resolves the PLG icon under images/. Reuse the Settings asset.
                icon = re.search(r'^Icon="([^"]+)"$', parent, re.MULTILINE).group(1)
                self.assertEqual(icon, BUILD.ICON)
                self.assertEqual(archive.extractfile(prefix + 'images/' + icon).read(),
                    (ROOT / 'source' / BUILD.PREFIX / 'images' / icon).read_bytes())
                readme = archive.extractfile(prefix + 'README.md').read().decode('utf-8')
                self.assertEqual(readme, (ROOT / 'source' / BUILD.PREFIX / 'README.md').read_text())
                self.assertTrue(readme.startswith('**NVIDIA vGPU Manager**\n'))
                description = readme.split('\n\n', 1)[1].strip()
                self.assertNotIn('\n', description)
                self.assertEqual(description.count('。'), 1)
                for feature in ['Unraid', 'NVIDIA vGPU', '驱动下载', '开机恢复', '系统升级准备']:
                    self.assertIn(feature, readme)
                self.assertEqual(archive.getmember(prefix + 'README.md').mode, 0o644)
                self.assertEqual(archive.getmember(prefix + 'images/' + icon).mode, 0o644)
                for index, tab in enumerate(['driver', 'vgpu', 'status'], 1):
                    child = archive.extractfile(prefix + 'unraid-vgpu-manager-' + tab + '.page').read().decode()
                    self.assertIn(f'Menu="unraid-vgpu-manager:{index}"', child)
                    self.assertIn("include/ui-page.php", child)
                    self.assertIn('Markdown="false"', child)
                    body = child.split('\n---\n', 1)[1].strip()
                    self.assertTrue(body.startswith('<?php'))
                    self.assertTrue(body.endswith('?>'))
            xml = ET.parse(installer).getroot()
            self.assert_list_metadata(xml)
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
            self.assertIn('cli.php config-save', script)
            self.assertIn('"enabled":false', script)
            self.assertLess(script.index('cli.php config-save'), script.index('removepkg unraid-vgpu-manager'))

    def test_public_installer_requires_explicit_credential_free_public_urls(self):
        with tempfile.TemporaryDirectory(prefix='vgpu-package-test-') as temporary:
            BUILD.build('2026.11.02.1', Path(temporary), 'https://github.com/example/plugin/releases/download/v1', 'https://github.com/example/plugin/releases/latest/download/unraid-vgpu-manager.plg')
            xml = ET.parse(Path(temporary) / 'unraid-vgpu-manager.plg').getroot()
            self.assert_list_metadata(xml)
            self.assertTrue(xml.attrib['pluginURL'].startswith('https://github.com/example/'))
            self.assertIsNotNone(xml.find('FILE/URL'))
        for url in ['https://secret@example.com/a', 'http://example.com/a', 'https://example.com/a?token=secret']:
            with self.assertRaises(ValueError):
                # argparse.ArgumentTypeError derives from Exception, not ValueError.
                try:
                    BUILD.https_url(url)
                except Exception as error:
                    raise ValueError(str(error))

    def test_online_definition_has_the_same_native_list_metadata(self):
        self.assert_list_metadata(ET.parse(ROOT / 'unraid-vgpu-manager.plg').getroot())

    def test_description_and_settings_icon_are_required_payload_files(self):
        for missing in ['README.md', 'images/nvidia-driver.png']:
            with self.subTest(missing=missing), tempfile.TemporaryDirectory(prefix='vgpu-metadata-test-') as temporary:
                fixture = Path(temporary)
                shutil.copytree(ROOT / 'source', fixture / 'source')
                (fixture / 'source' / BUILD.PREFIX / missing).unlink()
                with patch.object(BUILD, 'ROOT', fixture), self.assertRaisesRegex(ValueError, 'Missing component: ' + re.escape(missing)):
                    BUILD.build('2026.11.02.1', fixture / 'output')
                self.assertFalse((fixture / 'output').exists())


if __name__ == '__main__':
    unittest.main()
