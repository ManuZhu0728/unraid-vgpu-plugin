"""Pinned native post-hook dispatch + real Bash; disposable paths, no NAS/GPU."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
HOOK = ROOT / 'source/usr/local/emhttp/plugins/dynamix.plugin.manager/post-hooks/unraid-vgpu-manager'
NATIVE_DIRECTORY = '/usr/local/emhttp/plugins/dynamix.plugin.manager/post-hooks'
HELPER = '/usr/local/emhttp/plugins/unraid-vgpu-manager/scripts/upgrade-helper'


class NativeUpgradeHookTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='vgpu-native-post-hook-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.php = os.environ.get('PHP_BIN') or shutil.which('php') or str(ROOT / '.tools/php/root/usr/bin/php8.4')
        self.hooks = self.root / 'hooks'
        self.hooks.mkdir()
        self.helper = self.root / 'helper'
        self.helper.write_text('''#!/bin/bash
printf '%s\\n' "$*" >> "$CASE_ROOT/calls"
printf 'driver-start\\n' >> "$CASE_ROOT/order"
sleep 0.03
printf 'driver-finished\\n' >> "$CASE_ROOT/order"
echo 'fixture driver result'
exit "${CASE_FAILURE:-0}"
''')
        self.helper.chmod(0o755)
        # Map just the absolute command path, retaining the production hook body.
        hook = self.hooks / 'unraid-vgpu-manager'
        hook.write_text(HOOK.read_text().replace(HELPER, str(self.helper)))
        hook.chmod(0o755)

    def dispatch(self, version='7.3.4', method='update', plugin='unRAIDServer.plg', error='', failure=0, download=False):
        dispatcher = (ROOT / 'tests/fixtures/unraid' / f'post-hooks-{version}.php').read_text()
        # Exact native function, with only its fixture hook directory substituted.
        dispatcher = dispatcher.replace(NATIVE_DIRECTORY, str(self.hooks))
        dispatcher += '''
function write($text) { echo $text; }
function run($command) { passthru($command, $code); return $code; }
$method=$argv[1]; $plugin=$argv[2]; $download_only=$argv[4]==='1';
post_hooks($argv[3]);
file_put_contents(getenv('CASE_ROOT').'/order', "native-command-complete\\n", FILE_APPEND);
'''
        harness = self.root / 'native-dispatch.php'
        harness.write_text(dispatcher)
        return subprocess.run([self.php, '-n', str(harness), method, plugin, error, '1' if download else '0'],
            env=dict(os.environ, CASE_ROOT=str(self.root), CASE_FAILURE=str(failure)),
            capture_output=True, text=True, timeout=10)

    def test_native_versions_wait_for_our_driver_result(self):
        for version in ['7.3.4', '7.4.0']:
            with self.subTest(version=version):
                result = self.dispatch(version)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn('fixture driver result', result.stdout)
                self.assertEqual((self.root / 'calls').read_text().splitlines(), ['check'])
                self.assertEqual((self.root / 'order').read_text().splitlines(),
                    ['driver-start', 'driver-finished', 'native-command-complete'])
                (self.root / 'calls').unlink()
                (self.root / 'order').unlink()

    def test_only_successful_os_install_or_update_triggers(self):
        for version in ['7.3.4', '7.4.0']:
            for method, plugin, error, download in [
                ('check', 'unRAIDServer.plg', '', False), ('remove', 'unRAIDServer.plg', '', False),
                ('update', 'another-plugin.plg', '', False), ('update', 'unRAIDServer.plg.evil', '', False),
                ('update', 'unRAIDServer.plg', 'failed', False), ('update', 'unRAIDServer.plg', '', True),
            ]:
                with self.subTest(version=version, method=method, plugin=plugin, error=error, download=download):
                    result = self.dispatch(version, method, plugin, error, download=download)
                    self.assertEqual(result.returncode, 0, result.stderr)
                    self.assertFalse((self.root / 'calls').exists())

    def test_install_and_versioned_os_definition_are_supported(self):
        result = self.dispatch(method='install', plugin='/tmp/plugins/unRAIDServer-7.4.0.plg')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((self.root / 'calls').read_text(), 'check\n')

    def test_driver_failure_does_not_fail_the_successful_os_update(self):
        result = self.dispatch(failure=1)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('not confirmed', result.stderr)
        self.assertTrue((self.root / 'order').read_text().endswith('native-command-complete\n'))

    def test_missing_backend_reports_a_warning_without_os_failure(self):
        self.helper.unlink()
        result = self.dispatch()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('entry is missing', result.stderr)
        self.assertFalse((self.root / 'calls').exists())

    def test_hook_and_helper_do_not_replace_shared_helper_or_touch_runtime(self):
        text = HOOK.read_text()
        self.assertNotIn('/usr/bin/plugin_update_helper', text)
        for command in ['modprobe', 'installpkg', 'upgradepkg', 'killall', 'reboot', 'inotifywait']:
            self.assertNotRegex(text, r'\b' + command + r'\b')
        helper = (ROOT / 'source/usr/local/emhttp/plugins/unraid-vgpu-manager/scripts/upgrade-helper').read_text()
        self.assertIn('exec "$PHP" "$BASE/include/upgrade-check.php"', helper)
        self.assertIn('nohup "$PHP" "$WATCH"', helper)


if __name__ == '__main__':
    unittest.main()
