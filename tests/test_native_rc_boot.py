"""Native startup regression: pinned Unraid script + real pkgtools, no NAS/GPU."""
import hashlib
import importlib.util
import contextlib
import io
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PKGTOOLS = ROOT.parent / 'unraid-vgpu/inputs/packages/pkgtools-15.0-noarch-42.txz'


class NativeStartupContractTests(unittest.TestCase):
    def test_pinned_native_script_is_unmodified(self):
        data = (ROOT / 'tests/fixtures/unraid/rc.modules').read_bytes()
        self.assertEqual(hashlib.sha256(data).hexdigest(), '70e506c193d0a65227747ee0a0481ba97e777e19c3d00873c887e1f212b3546e')
        text = data.decode()
        self.assertIn('/boot/config/plugins/*/packages/${RELEASE%%-*}/', text)
        self.assertLess(text.index('/sbin/installpkg $PKG'), text.index('/sbin/depmod --all'))

    def test_release_payload_does_not_add_system_startup_hooks(self):
        specification = importlib.util.spec_from_file_location('native_contract_build', ROOT / 'scripts/build-plugin.py')
        builder = importlib.util.module_from_spec(specification)
        specification.loader.exec_module(builder)
        with tempfile.TemporaryDirectory(prefix='native-payload-contract-') as temporary, contextlib.redirect_stdout(io.StringIO()):
            package, _ = builder.build('2026.11.17.1', Path(temporary))
            with tarfile.open(package) as archive:
                self.assertTrue(all(p.name.startswith('usr/local/emhttp/plugins/unraid-vgpu-manager/') for p in archive))
        code = (ROOT / 'source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/Installer.php').read_text()
        self.assertNotIn("['upgradepkg'", code)
        self.assertNotIn("['installpkg'", code)
        self.assertNotIn('rc.modules-', code)


class RealNativeStartupTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.php = os.environ.get('PHP_BIN') or shutil.which('php') or str(ROOT / '.tools/php/root/usr/bin/php8.4')
        if not shutil.which('bwrap') or not PKGTOOLS.is_file() or not Path(cls.php).is_file():
            raise unittest.SkipTest('Real native startup needs bubblewrap, PHP and retained genuine pkgtools; no network downloads or NAS fallback')
        probe = subprocess.run(['bwrap', '--unshare-all', '--ro-bind', '/', '/', '/bin/true'], capture_output=True)
        if probe.returncode:
            raise unittest.SkipTest('Private user/mount namespaces unavailable; never run native startup on the host')

    def run_native(self, script):
        environment = dict(os.environ, NATIVE_RC_MODULES='1')
        result = subprocess.run([self.php, '-n', str(ROOT / 'tests' / script)], env=environment, capture_output=True, text=True, timeout=180)
        self.assertEqual(result.returncode, 0, result.stdout[-3000:] + result.stderr[-3000:])
        self.assertNotIn('Warning:', result.stdout)
        self.assertNotIn('Fatal error:', result.stdout)

    def test_multiple_builds_switching_native_records_and_migration(self):
        self.run_native('test_installer.php')

    def test_native_install_precedes_verification_and_initialization(self):
        self.run_native('test_lifecycle.php')
