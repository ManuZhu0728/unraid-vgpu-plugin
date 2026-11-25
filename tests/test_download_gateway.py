"""Concurrent real nginx auth_request/FPM, curl progress and cooperative cancel.

All source responses use loopback; archives are inert fixtures, modinfo is
simulated, tar/xz/curl and Application are real. No Unraid or GPU is contacted.
"""
from concurrent.futures import ThreadPoolExecutor
import hashlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
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
import threading
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

import test_webgui_gateway as gateway

ROOT = Path(__file__).resolve().parents[1]
PREFIX = ROOT / 'source/usr/local/emhttp/plugins/unraid-vgpu-manager'
TOKEN = 'fixture-download-control-csrf'
NAME = 'nvidia-595.58.03-6.18.58-Unraid-2.txz'


class DownloadGatewayTests(unittest.TestCase):
    auth_script = "<?php http_response_code(is_file(__DIR__.'/reject-auth') ? 401 : 204);"
    start_gateway = classmethod(gateway.WebGuiGatewayTests.start_gateway.__func__)
    stop_fpm = classmethod(gateway.WebGuiGatewayTests.stop_fpm.__func__)

    @classmethod
    def setUpClass(cls):
        cls.nginx = os.environ.get('NGINX_BIN') or shutil.which('nginx')
        cls.fpm_binary = os.environ.get('PHP_FPM_BIN') or shutil.which('php-fpm') or shutil.which('php-fpm8.4')
        if not cls.nginx or not cls.fpm_binary:
            raise unittest.SkipTest('Set NGINX_BIN/PHP_FPM_BIN for local real download control gateway tests')
        cls.php = os.environ.get('PHP_BIN') or shutil.which('php')
        cls.temporary = tempfile.TemporaryDirectory(prefix='vgpu-download-gateway-')
        cls.directory = Path(cls.temporary.name)
        cls.addClassCleanup(cls.temporary.cleanup)
        cls.packages = {}
        for kernel in ['6.18.58', '6.18.64']:
            stream = io.BytesIO()
            with tarfile.open(fileobj=stream, mode='w:xz') as archive:
                members = [f'lib/modules/{kernel}-Unraid/kernel/drivers/video/{m}.ko' for m in
                           ['nvidia', 'nvidia-modeset', 'nvidia-drm', 'nvidia-uvm', 'nvidia-vgpu-vfio']]
                members += ['usr/bin/nvidia-vgpud', 'usr/bin/nvidia-vgpu-mgr', 'usr/lib64/libvgpu_unlock_rs.so',
                            'usr/share/nvidia/vgpu/vgpuConfig.xml', 'usr/lib64/libcuda.so.1', 'usr/lib64/libnvcuvid.so.1',
                            'usr/lib64/libnvidia-encode.so.1', 'usr/lib64/libnvidia-ml.so.1', 'usr/share/inert.dat']
                for name in members:
                    body = os.urandom(900000) if name.endswith('.dat') else b'inert fixture, never executable'
                    info = tarfile.TarInfo(name); info.size = len(body); info.mode = 0o644
                    archive.addfile(info, io.BytesIO(body))
            cls.packages[kernel] = stream.getvalue()
            (cls.directory / f'package-{kernel}.txz').write_bytes(stream.getvalue())

        class Source(BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass

            def do_GET(self):
                cls.source_calls.append(self.path)
                kernel = '6.18.64' if '6.18.64' in self.path else '6.18.58'
                filename = f'nvidia-595.58.03-{kernel}-Unraid-2.txz'
                url = 'https://driver.example.test/files/' + filename
                data = cls.packages[kernel]
                if '/releases/' in self.path:
                    data = json.dumps({'assets': {'links': [{'name': filename, 'url': url}, {'name': filename+'.md5', 'url': url+'.md5'}]}}).encode()
                elif self.path.endswith('.md5'):
                    md5 = '0'*32 if (cls.directory/'bad-md5').exists() else hashlib.md5(data).hexdigest()
                    data = (md5+'  '+filename+'\n').encode()
                elif (cls.directory/'source-failure').exists():
                    self.send_error(503); return
                self.send_response(200)
                self.send_header('Content-Type', 'application/json' if '/releases/' in self.path else 'application/octet-stream')
                self.send_header('Content-Length', str(len(data)))
                self.end_headers()
                try:
                    for offset in range(0, len(data), 32768):
                        self.wfile.write(data[offset:offset+32768]); self.wfile.flush()
                        if self.path.endswith('.txz'):
                            time.sleep(.08)
                except (BrokenPipeError, ConnectionResetError):
                    pass

        cls.source_calls = []
        cls.source = ThreadingHTTPServer(('127.0.0.1', 0), Source)
        cls.addClassCleanup(cls.source.server_close)
        cls.addClassCleanup(cls.source.shutdown)
        threading.Thread(target=cls.source.serve_forever, daemon=True).start()
        source_origin = f'http://127.0.0.1:{cls.source.server_port}'
        fixture = (ROOT/'tests/download_gateway_fixture.php').read_text().replace('__PLUGIN_PREFIX__', str(PREFIX)).replace('__TEST_ROOT__', str(ROOT)).replace('__PAYLOAD_ORIGIN__', source_origin)
        cls.fixture = cls.directory/'fixture.php'; cls.fixture.write_text(fixture)
        cls.fpm = None; cls.addClassCleanup(cls.stop_fpm)
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0)); port = listener.getsockname()[1]
        cls.origin = f'http://127.0.0.1:{port}'
        cls.start_gateway(port)
        cls.addClassCleanup(cls.stop_nginx)
        cls.url = cls.origin+'/Settings/download-fixture'
        for _ in range(60):
            try:
                with urllib.request.urlopen(cls.url, timeout=1):
                    break
            except OSError:
                time.sleep(.05)

    @classmethod
    def stop_nginx(cls):
        if cls.process.poll() is None:
            cls.process.terminate(); cls.process.wait(timeout=5)

    def setUp(self):
        # Only this test's private fixture tree is reset after every worker exits.
        private = self.directory/'root'
        if private.exists():
            shutil.rmtree(private)
        for name in ['reject-auth', 'bad-md5', 'source-failure', 'calls.jsonl']:
            (self.directory/name).unlink(missing_ok=True)
        subprocess.run([self.php, '-n', str(self.fixture), 'reset'], check=True, capture_output=True)
        self.cache = self.directory/'root/boot/config/plugins/unraid-vgpu-manager/driver-cache/6.18.58'
        self.config = self.cache.parent.parent
        self.before = (self.config/'settings.json').read_bytes()

    def post(self, action, **changes):
        fields = {'uvm_action': action, 'uvm_tab': 'driver', 'csrf_token': TOKEN, 'uvm_csrf_token': TOKEN, **changes}
        request = urllib.request.Request(self.url, data=urllib.parse.urlencode(fields).encode(), headers={'Origin': self.origin, 'Sec-Fetch-Site': 'same-origin'})
        with urllib.request.urlopen(request, timeout=12) as response:
            text = response.read().decode()
        marker = 'uvm-download-control-result' if action in ['download-status', 'download-cancel'] else ('uvm-upgrade-result' if action == 'upgrade-check' else 'uvm-download-result')
        matches = re.findall(r'<script type="application/json" id="'+marker+r'">(.*?)</script>', text, re.S)
        self.assertEqual(len(matches), 1, text[:1000])
        self.assertNotIn('LOCAL-FAKE-DOWNLOAD-TOKEN', text)
        self.assertNotIn('id="uvm-manager-', text)
        return json.loads(matches[0])

    def running(self, scope='manual'):
        deadline = time.monotonic()+4
        while time.monotonic() < deadline:
            state = self.post('download-status')['data']['task']
            if state.get('status') == 'active' and state.get('scope') == scope and state.get('downloaded_bytes', 0) > 0:
                return state
            time.sleep(.08)
        self.fail('No live byte progress while the configuration lock was held')

    def unchanged(self):
        self.assertEqual((self.config/'settings.json').read_bytes(), self.before)
        self.assertFalse((self.cache/NAME).exists())
        self.assertEqual(list(self.cache.glob('.download-*')), [])
        self.assertEqual(list((self.directory/'root/var/run/unraid-vgpu-manager').glob('.download-*')), [])

    def test_success_reports_real_bytes_through_native_gateway(self):
        with ThreadPoolExecutor() as pool:
            result = pool.submit(self.post, 'source-download', uvm_download_async='1', download_package=NAME, download_job_id='a'*32)
            task = self.running()
            self.assertEqual(task['total_bytes'], len(self.packages['6.18.58']))
            self.assertLess(task['downloaded_bytes'], task['total_bytes'])
            response = result.result(timeout=8)
        self.assertTrue(response['ok'], response)
        self.assertEqual(response['outcome'], 'cached')
        self.assertEqual((self.cache/NAME).read_bytes(), self.packages['6.18.58'])
        self.assertEqual((self.config/'settings.json').read_bytes(), self.before)
        self.assertEqual(self.post('download-status')['data']['task']['status'], 'completed')

    def test_cancel_reaches_worker_during_global_lock_and_cleans_partial_only(self):
        with ThreadPoolExecutor() as pool:
            result = pool.submit(self.post, 'source-download', uvm_download_async='1', download_package=NAME, download_job_id='b'*32)
            task = self.running()
            wrong = self.post('download-cancel', job_id='c'*32)
            self.assertFalse(wrong['ok'])
            reply = self.post('download-cancel', job_id=task['id'])
            self.assertTrue(reply['data']['accepted'])
            self.assertEqual(reply['data']['task']['status'], 'active')
            self.assertEqual(result.result(timeout=8)['outcome'], 'cancelled')
        self.assertEqual(self.post('download-status')['data']['task']['status'], 'cancelled')
        self.unchanged()

    def test_native_auth_failure_does_not_fake_cancellation(self):
        with ThreadPoolExecutor() as pool:
            result = pool.submit(self.post, 'source-download', uvm_download_async='1', download_package=NAME, download_job_id='d'*32)
            task = self.running()
            (self.directory/'reject-auth').touch()
            with self.assertRaises(urllib.error.HTTPError) as error:
                self.post('download-cancel', job_id=task['id'])
            self.assertEqual(error.exception.code, 401)
            (self.directory/'reject-auth').unlink()
            self.assertFalse(self.post('download-status')['data']['task']['cancel_requested'])
            self.post('download-cancel', job_id=task['id'])
            self.assertEqual(result.result(timeout=8)['outcome'], 'cancelled')
        self.unchanged()

    def test_source_http_and_checksum_failure_leave_no_partial_package(self):
        for flag in ['source-failure', 'bad-md5']:
            with self.subTest(flag=flag):
                (self.directory/flag).touch()
                reply = self.post('source-download', uvm_download_async='1', download_package=NAME)
                self.assertFalse(reply['ok'])
                self.assertEqual(reply['outcome'], 'failed')
                self.assertEqual(self.post('download-status')['data']['task']['status'], 'failed')
                self.unchanged()
                (self.directory/flag).unlink()

    def test_cancelled_upgrade_is_paused_until_explicit_resume(self):
        with ThreadPoolExecutor() as pool:
            result = pool.submit(self.post, 'upgrade-check', uvm_upgrade_async='1')
            task = self.running('target')
            self.assertEqual(task['kernel'], '6.18.64-Unraid')
            self.post('download-cancel', job_id=task['id'])
            result.result(timeout=8)
        self.assertEqual(self.post('download-status')['data']['upgrade']['status'], 'cancelled')
        automatic = subprocess.run([self.php, '-n', '-r', 'require $argv[1]; $s=new Vgpu\\Store(new Vgpu\\Paths($argv[2])); $u=new Vgpu\\Upgrade($s,new Vgpu\\Download($s->paths)); echo json_encode($s->locked(fn()=>$u->check($s->settings(),$s->credentials())));', str(PREFIX/'include/bootstrap.php'), str(self.directory/'root')], capture_output=True, text=True, check=True)
        self.assertEqual(json.loads(automatic.stdout)['status'], 'cancelled')
        reply = self.post('upgrade-check', uvm_upgrade_async='1')
        self.assertTrue(reply['ok'], reply)
        self.assertEqual(reply['data']['status'], 'ready')
        self.assertFalse(reply['data']['boot_ready'])

    def test_control_csrf_and_tab_fail_before_dispatch(self):
        for fields in [{'uvm_csrf_token': 'wrong'}, {'uvm_tab': 'vgpu'}]:
            with self.subTest(fields=fields):
                before = (self.directory/'calls.jsonl').read_bytes() if (self.directory/'calls.jsonl').exists() else b''
                reply = self.post('download-status', **fields)
                self.assertFalse(reply['ok'])
                self.assertEqual((self.directory/'calls.jsonl').read_bytes() if (self.directory/'calls.jsonl').exists() else b'', before)
        with self.assertRaises(urllib.error.HTTPError) as error:
            self.post('download-status', csrf_token='wrong')
        self.assertEqual(error.exception.code, 403)

    def test_actual_browser_observes_and_cancels_both_download_paths(self):
        if not os.environ.get('PLAYWRIGHT_MODULE'):
            self.skipTest('Set PLAYWRIGHT_MODULE for browser -> native gateway -> actual curl cancellation')
        result = subprocess.run(['node', str(ROOT/'tests/test_ui_download_gateway.cjs'), self.url], capture_output=True, text=True, timeout=45)
        self.assertEqual(result.returncode, 0, result.stdout+result.stderr)
        self.assertIn('real download gateway browser passed', result.stdout)
        self.assertEqual(self.post('download-status')['data']['task']['status'], 'cancelled')
        self.assertFalse((self.cache/NAME).exists())
        self.assertEqual(list(self.cache.glob('.download-*')), [])


if __name__ == '__main__':
    unittest.main()
