"""Real localhost auth_request/PHP-FPM + Upload/Application/cache verification.

tar/xz are real passive tools. Only modinfo output is a fixture: no NVIDIA code,
installer, GPU tool, existing browser, NAS, or external network is used.
"""
import base64
import hashlib
import io
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tarfile
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

import test_webgui_gateway as gateway

ROOT = Path(__file__).resolve().parents[1]
PREFIX = ROOT / 'source/usr/local/emhttp/plugins/unraid-vgpu-manager'
NAME = 'nvidia-595.58.03-6.18.58-Unraid-4.txz'
OLD = 'nvidia-595.58.03-6.18.58-Unraid-3.txz'
TOKEN = 'fixture-upload-csrf'


class UploadGatewayTests(unittest.TestCase):
    fpm_post_limit = '256K'
    auth_script = "<?php http_response_code(is_file(__DIR__.'/reject-auth') ? 401 : 204);"
    start_gateway = classmethod(gateway.WebGuiGatewayTests.start_gateway.__func__)
    stop_fpm = classmethod(gateway.WebGuiGatewayTests.stop_fpm.__func__)

    @classmethod
    def setUpClass(cls):
        cls.nginx = os.environ.get('NGINX_BIN') or shutil.which('nginx')
        cls.fpm_binary = os.environ.get('PHP_FPM_BIN') or shutil.which('php-fpm') or shutil.which('php-fpm8.4')
        if not cls.nginx or not cls.fpm_binary:
            raise unittest.SkipTest('Upload gateway regression needs NGINX_BIN and PHP_FPM_BIN; no NAS access')
        cls.php = os.environ.get('PHP_BIN') or shutil.which('php') or str(ROOT / '.tools/php/root/usr/bin/php8.4')
        cls.temporary = tempfile.TemporaryDirectory(prefix='vgpu-upload-gateway-')
        cls.directory = Path(cls.temporary.name)
        cls.addClassCleanup(cls.temporary.cleanup)
        cls.fpm = None
        cls.addClassCleanup(cls.stop_fpm)
        cls.fixture = cls.directory / 'fixture.php'
        cls.fixture.write_text((ROOT / 'tests/upload_gateway_fixture.php').read_text()
                               .replace('__PLUGIN_PREFIX__', str(PREFIX)).replace('__TEST_ROOT__', str(ROOT)))
        cls.package_path = cls.directory / NAME
        with tarfile.open(cls.package_path, 'w:xz') as archive:
            members = ['lib/modules/6.18.58-Unraid/kernel/drivers/video/' + m + '.ko'
                       for m in ['nvidia', 'nvidia-modeset', 'nvidia-drm', 'nvidia-uvm', 'nvidia-vgpu-vfio']]
            members += ['usr/bin/nvidia-vgpud', 'usr/bin/nvidia-vgpu-mgr', 'usr/lib64/libvgpu_unlock_rs.so',
                        'usr/share/nvidia/vgpu/vgpuConfig.xml', 'usr/lib64/libcuda.so.1', 'usr/lib64/libnvcuvid.so.1',
                        'usr/lib64/libnvidia-encode.so.1', 'usr/lib64/libnvidia-ml.so.1', 'usr/share/fixture-binary.dat']
            for member in members:
                data = os.urandom(400000) if member.endswith('.dat') else b'passive fixture, never executable\x00\xff'
                info = tarfile.TarInfo(member); info.size = len(data); info.uid = info.gid = info.mtime = 0; info.mode = 0o644
                archive.addfile(info, io.BytesIO(data))
        cls.package = cls.package_path.read_bytes()
        cls.md5 = hashlib.md5(cls.package).hexdigest()
        cls.md5_path = cls.directory / (NAME + '.md5')
        cls.md5_path.write_text(cls.md5 + '  ' + NAME + '\n')
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0)); port = listener.getsockname()[1]
        cls.origin = f'http://127.0.0.1:{port}'
        cls.start_gateway(port)
        cls.addClassCleanup(cls.stop_nginx)
        cls.url = cls.origin + '/Settings/upload-fixture'
        for _ in range(60):
            try:
                with urllib.request.urlopen(cls.url, timeout=1) as response:
                    response.read(); break
            except OSError:
                time.sleep(.05)
        else:
            raise RuntimeError('Local upload gateway did not start')

    @classmethod
    def stop_nginx(cls):
        if cls.process.poll() is None:
            cls.process.terminate(); cls.process.wait(timeout=5)

    def setUp(self):
        subprocess.run([self.php, '-n', str(self.fixture), 'reset'], check=True, capture_output=True)
        self.cache = self.directory / 'root/boot/config/plugins/unraid-vgpu-manager/packages/6.18.58'
        self.config = self.cache.parent.parent
        self.before = (self.config / 'settings.json').read_bytes()

    def post(self, action, *, origin=None, **fields):
        fields = {'uvm_action': action, 'uvm_tab': 'driver', 'csrf_token': TOKEN, 'uvm_csrf_token': TOKEN, **fields}
        request = urllib.request.Request(self.url, data=urllib.parse.urlencode(fields).encode(),
                                         headers={'Origin': origin or self.origin, 'Sec-Fetch-Site': 'same-origin'})
        with urllib.request.urlopen(request, timeout=8) as response:
            body = response.read().decode()
        markers = re.findall(r'<script type="application/json" id="uvm-upload-result">(.*?)</script>', body, re.S)
        self.assertEqual(len(markers), 1, body[:2000])
        return json.loads(markers[0])

    def begin(self, **overrides):
        return self.post('source-upload-begin', **{'filename': NAME, 'total': len(self.package), 'checksum': self.md5, **overrides})

    def chunk(self, identifier, offset, data):
        return self.post('source-upload-chunk', upload_id=identifier, offset=offset,
                         chunk_data=base64.urlsafe_b64encode(data).rstrip(b'=').decode())

    def stage(self, **overrides):
        begun = self.begin(**overrides); self.assertTrue(begun['ok'], begun)
        identifier = begun['data']['upload_id']
        for offset in range(0, len(self.package), 60000):
            result = self.chunk(identifier, offset, self.package[offset:offset + 60000])
            self.assertTrue(result['ok'], result)
        return identifier

    def unchanged(self):
        self.assertEqual((self.config / 'settings.json').read_bytes(), self.before)
        self.assertEqual((self.cache / OLD).read_bytes(), b'original selected cache')
        self.assertFalse((self.cache / NAME).exists())

    def test_real_binary_upload_md5_and_atomic_selection(self):
        identifier = self.stage()
        staged = self.directory / ('root/var/run/unraid-vgpu-manager/upload-' + identifier + '/data.tmp')
        self.assertEqual(staged.read_bytes(), self.package)
        self.unchanged()
        result = self.post('source-upload-finish', upload_id=identifier)
        self.assertTrue(result['ok'], result)
        receipt = result['data']['package']
        self.assertEqual(receipt['md5'], self.md5); self.assertTrue(receipt['checksum_verified'])
        self.assertEqual((self.cache / NAME).read_bytes(), self.package)
        self.assertEqual((self.cache / (NAME + '.md5')).read_text(), self.md5 + '  ' + NAME + '\n')
        self.assertFalse(staged.parent.exists())
        settings = json.loads((self.config / 'settings.json').read_text())
        self.assertEqual(settings['selected_package']['filename'], NAME)
        for key in ['runtime', 'driver', 'source', 'upgrade']:
            self.assertEqual(settings[key], json.loads(self.before)[key])
        self.assertEqual((self.cache / OLD).read_bytes(), b'original selected cache')

    def test_md5_is_required_before_staging(self):
        for checksum in ['', ' ', 'bad', '0' * 32 + '  other.txz', 'x' * 4097]:
            with self.subTest(checksum=checksum[:40]):
                result = self.begin(checksum=checksum)
                self.assertFalse(result['ok']); self.assertEqual(result['outcome'], 'failed')
                self.unchanged()
        runtime = self.directory / 'root/var/run/unraid-vgpu-manager'
        self.assertFalse(list(runtime.glob('upload-*')))

    def test_cancel_is_exact_idempotent_and_preserves_other_session(self):
        first = self.begin()['data']['upload_id']; second = self.begin()['data']['upload_id']
        self.assertTrue(self.chunk(first, 0, bytes(range(256)) * 100)['ok'])
        for _ in range(2):
            result = self.post('source-upload-cancel', upload_id=first)
            self.assertTrue(result['ok']); self.assertEqual(result['data']['upload_id'], first)
        runtime = self.directory / 'root/var/run/unraid-vgpu-manager'
        self.assertFalse((runtime / ('upload-' + first)).exists())
        self.assertTrue((runtime / ('upload-' + second)).exists()); self.unchanged()

    def test_bad_checksum_archive_and_collision_preserve_old_cache(self):
        identifier = self.stage(checksum='0' * 32)
        result = self.post('source-upload-finish', upload_id=identifier)
        self.assertFalse(result['ok']); self.assertIn('MD5', result['error']); self.unchanged()
        begun = self.begin(total=4, checksum=hashlib.md5(b'bad!').hexdigest())
        identifier = begun['data']['upload_id']; self.assertTrue(self.chunk(identifier, 0, b'bad!')['ok'])
        self.assertFalse(self.post('source-upload-finish', upload_id=identifier)['ok']); self.unchanged()
        identifier = self.stage()
        (self.cache / NAME).write_bytes(b'existing conflicting cache')
        result = self.post('source-upload-finish', upload_id=identifier)
        self.assertFalse(result['ok']); self.assertEqual((self.cache / NAME).read_bytes(), b'existing conflicting cache')
        self.assertEqual((self.config / 'settings.json').read_bytes(), self.before)

    def test_csrf_origin_offset_encoding_and_oversize_fail_without_append(self):
        for changes in [{'csrf_token': 'bad'}, {'uvm_csrf_token': 'bad'}, {'uvm_tab': 'vgpu'}, {'origin': 'https://attacker.example.test'}]:
            result = self.begin(**changes); self.assertFalse(result['ok']); self.unchanged()
        identifier = self.begin()['data']['upload_id']
        for data in ['A', 'YR', 'AA==', 'a+b/', 'x' * 1398105]:
            result = self.post('source-upload-chunk', upload_id=identifier, offset=0, chunk_data=data, path='/must-not-read')
            self.assertFalse(result['ok']); self.unchanged()
        self.assertTrue(self.chunk(identifier, 0, b'\x00\xff\n\r')['ok'])
        self.assertFalse(self.chunk(identifier, 0, b'repeated')['ok'])
        self.assertFalse(self.post('source-upload-finish', upload_id=identifier)['ok'])
        staged = self.directory / ('root/var/run/unraid-vgpu-manager/upload-' + identifier + '/data.tmp')
        self.assertEqual(staged.read_bytes(), b'\x00\xff\n\r')

    def test_unknown_finish_can_have_committed_but_never_reports_failure(self):
        identifier = self.stage(); (self.directory / 'lose-finish').touch()
        result = self.post('source-upload-finish', upload_id=identifier)
        self.assertFalse(result['ok']); self.assertEqual(result['outcome'], 'unknown')
        self.assertEqual((self.cache / NAME).read_bytes(), self.package)

    def test_original_multipart_binary_stalls_but_text_encoding_works(self):
        identifier = self.begin()['data']['upload_id']
        boundary = '----------------binary-fixture'
        fields = {'uvm_action': 'source-upload-chunk', 'uvm_tab': 'driver', 'csrf_token': TOKEN,
                  'uvm_csrf_token': TOKEN, 'upload_id': identifier, 'offset': '0'}
        body = ''.join(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n' for k, v in fields.items()).encode()
        body += f'--{boundary}\r\nContent-Disposition: form-data; name="chunk"; filename="chunk.bin"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()
        body += self.package[:1000] + f'\r\n--{boundary}--\r\n'.encode()
        request = urllib.request.Request(self.url, data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + boundary, 'Origin': self.origin})
        with self.assertRaises(TimeoutError):
            urllib.request.urlopen(request, timeout=1)
        calls = [json.loads(line) for line in (self.directory / 'calls.jsonl').read_text().splitlines()]
        self.assertEqual([c['action'] for c in calls], ['source-upload-begin'], 'Original binary upload never reaches Application')
        self.assertTrue(self.chunk(identifier, 0, self.package[:1000])['ok'], 'URL encoding succeeds on the same native auth gateway')
        self.unchanged()

    def test_browser_upload_cancel_beacon_and_failures(self):
        if not os.environ.get('PLAYWRIGHT_MODULE'):
            self.skipTest('Real browser upload tests need PLAYWRIGHT_MODULE')
        for mode in ['success', 'cancel', 'beacon', 'missing-md5', 'empty-md5', 'oversized-md5', 'invalid-md5', 'wrong-name-md5',
                     'bad-md5', 'cancel-failure', 'cancel-unknown', 'finish-unknown', 'auth-expired']:
            with self.subTest(mode=mode):
                self.setUp()
                (self.directory / 'browser-mode').write_text(mode)
                result = subprocess.run(['node', str(ROOT / 'tests/test_ui_upload_gateway.cjs'), self.url, str(self.package_path), str(self.md5_path), mode],
                                        capture_output=True, text=True, timeout=45)
                calls = [json.loads(line) for line in (self.directory / 'calls.jsonl').read_text().splitlines()] if (self.directory / 'calls.jsonl').exists() else []
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr + '\nApplication calls: ' + str(calls))
                for call in calls:
                    self.assertTrue(call['content_type'].startswith('application/x-www-form-urlencoded'))
                    self.assertTrue(call['native_consumed'] and call['mirror_valid'])
                if mode == 'beacon':
                    cancelled = [call for call in calls if call['action'] == 'source-upload-cancel']
                    self.assertEqual(len(cancelled), 1, 'Browser pagehide must really send one URL-encoded cancellation beacon')
                    self.assertEqual(cancelled[0]['upload_id'], next(c['upload_id'] for c in calls if c['action'] == 'source-upload-chunk'))
                if mode == 'auth-expired':
                    self.assertEqual([c['action'] for c in calls], ['source-upload-begin'], 'Expired native auth must reject before chunk/cancel reaches the application')
                if mode in ['success', 'finish-unknown']:
                    self.assertEqual((self.cache / NAME).read_bytes(), self.package)
                else:
                    self.unchanged()
                sessions = list((self.directory / 'root/var/run/unraid-vgpu-manager').glob('upload-*'))
                if mode not in ['cancel-failure', 'auth-expired']:
                    for _ in range(30):
                        if not sessions: break
                        time.sleep(.05)
                        sessions = list((self.directory / 'root/var/run/unraid-vgpu-manager').glob('upload-*'))
                    self.assertEqual(sessions, [], 'Confirmed completion/cancellation must clear staging')
                else:
                    self.assertEqual(len(sessions), 1, 'Refused cancellation must not pretend to have cleared staging')


if __name__ == '__main__':
    unittest.main()
