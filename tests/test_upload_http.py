"""Exercise the real PHP upload boundary over localhost, with a fake backend."""

import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request


ROOT = Path(__file__).resolve().parents[1]
PREFIX = ROOT / "source/usr/local/emhttp/plugins/unraid-vgpu-manager"


class UploadHttpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        php = os.environ.get("PHP_BIN") or shutil.which("php")
        if not php:
            php = str(ROOT / ".tools/php/root/usr/bin/php8.4")
        if not Path(php).is_file():
            raise unittest.SkipTest("PHP CLI unavailable")
        cls.temporary = tempfile.TemporaryDirectory(prefix="vgpu-upload-http-")
        directory = Path(cls.temporary.name)
        fixture = r'''<?php
require_once BOOTSTRAP;
function uvm_cli(string $action, array $payload = []): array {
    if ($action === 'status') return ['settings' => \Vgpu\Store::defaults(), 'devices' => [], 'runtime' => []];
    if ($action === 'source-upload-chunk') {
        return ['action' => $action, 'offset' => $payload['offset'] + filesize($payload['path']),
            'http_uploaded' => is_uploaded_file($payload['path']), 'native_csrf_present' => array_key_exists('csrf_token', $_POST)];
    }
    return ['action' => $action, 'payload' => $payload, 'upload_id' => str_repeat('a', 32), 'offset' => 0, 'max_chunk_size' => 1048576,
        'native_csrf_present' => array_key_exists('csrf_token', $_POST)];
}
function uvm_profile_scan(): array { return []; }
function uvm_read(string $path, int $limit = 65536): string { return ''; }
$page = file_get_contents(PAGE);
foreach (['uvm_cli', 'uvm_profile_scan', 'uvm_read'] as $function) {
    $page = str_replace('function ' . $function . '(', 'function uvm_original_' . substr($function, 4) . '(', $page);
}
$var = ['csrf_token' => 'http-upload-fixture-csrf'];
echo '<!doctype html><main data-unraid-wrapper="fixture">';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Model Unraid's native gateway: validate its field, then consume it before PHP.
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($var['csrf_token'], $_POST['csrf_token'])) {
        echo '<script type="application/json" id="uvm-upload-result">{"ok":false,"error":"Native gateway CSRF rejected"}</script></main>';
        exit;
    }
    unset($_POST['csrf_token']);
}
eval('?>' . $page);
echo '</main>';
'''
        fixture = fixture.replace("BOOTSTRAP", json.dumps(str(PREFIX / "include/bootstrap.php")))
        fixture = fixture.replace("PAGE", json.dumps(str(PREFIX / "unraid-vgpu-manager.page")))
        (directory / "fixture.php").write_text(fixture)
        with socket.socket() as listener:
            listener.bind(("127.0.0.1", 0))
            port = listener.getsockname()[1]
        cls.origin = f"http://127.0.0.1:{port}"
        cls.url = cls.origin + "/fixture.php"
        cls.process = subprocess.Popen([php, "-n", "-d", "upload_max_filesize=512K", "-d", "post_max_size=1M", "-S", f"127.0.0.1:{port}", "-t", str(directory)],
            stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(60):
            try:
                with urllib.request.urlopen(cls.url, timeout=1) as response:
                    if response.status == 200:
                        return
            except (OSError, urllib.error.URLError):
                time.sleep(0.05)
        cls.process.terminate()
        cls.process.wait(timeout=5)
        cls.temporary.cleanup()
        raise RuntimeError("Local PHP fixture did not start")

    @classmethod
    def tearDownClass(cls):
        cls.process.terminate()
        cls.process.wait(timeout=5)
        cls.temporary.cleanup()

    def post(self, fields, chunk=None, origin=None):
        boundary = "upload-fixture-multipart-boundary"
        body = bytearray()
        for key, value in fields.items():
            body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
        if chunk is not None:
            body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="chunk"; filename="chunk.bin"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode())
            body.extend(chunk)
            body.extend(b"\r\n")
        body.extend(f"--{boundary}--\r\n".encode())
        request = urllib.request.Request(self.url, data=bytes(body), headers={
            "Content-Type": f"multipart/form-data; boundary={boundary}",
            "Origin": self.origin if origin is None else origin,
            "Sec-Fetch-Site": "same-origin",
        })
        with urllib.request.urlopen(request, timeout=10) as response:
            html = response.read().decode()
        self.assertIn('data-unraid-wrapper="fixture"', html)
        match = re.search(r'<script type="application/json" id="uvm-upload-result">(.*?)</script>', html, re.S)
        self.assertIsNotNone(match, html[:500])
        return json.loads(match.group(1))

    def test_begin_finish_and_cancel_survive_an_html_page_wrapper(self):
        begun = self.post({"uvm_action": "source-upload-begin", "csrf_token": "http-upload-fixture-csrf", "uvm_csrf_token": "http-upload-fixture-csrf",
            "filename": "nvidia-595.58.03-6.18.58-Unraid-1.txz", "total": "123"})
        self.assertTrue(begun["ok"])
        self.assertEqual(begun["data"]["payload"]["total"], 123)
        self.assertFalse(begun["data"]["native_csrf_present"])
        for action in ("source-upload-finish", "source-upload-cancel"):
            result = self.post({"uvm_action": action, "csrf_token": "http-upload-fixture-csrf", "uvm_csrf_token": "http-upload-fixture-csrf", "upload_id": "a" * 32})
            self.assertTrue(result["ok"])
            self.assertEqual(result["data"]["action"], action)
            self.assertFalse(result["data"]["native_csrf_present"])

    def test_browser_chunk_size_respects_php_upload_limits(self):
        with urllib.request.urlopen(self.url, timeout=10) as response:
            html = response.read().decode()
        self.assertIn("const browserChunkSize = 458752;", html)

    def test_real_multipart_file_passes_php_uploaded_file_boundary(self):
        chunk = b"real multipart fixture\x00" * 16384
        result = self.post({"uvm_action": "source-upload-chunk", "csrf_token": "http-upload-fixture-csrf", "uvm_csrf_token": "http-upload-fixture-csrf",
            "upload_id": "a" * 32, "offset": "7"}, chunk)
        self.assertTrue(result["ok"])
        self.assertTrue(result["data"]["http_uploaded"])
        self.assertFalse(result["data"]["native_csrf_present"])
        self.assertEqual(result["data"]["offset"], 7 + len(chunk))

    def test_forged_path_and_bad_csrf_are_rejected_before_backend(self):
        for change in ({"path": "/tmp/phpForged"}, {"csrf_token": "wrong"}):
            result = self.post({"uvm_action": "source-upload-chunk", "csrf_token": "http-upload-fixture-csrf", "uvm_csrf_token": "http-upload-fixture-csrf",
                "upload_id": "a" * 32, "offset": "0", **change})
            self.assertFalse(result["ok"])
            self.assertNotIn("data", result)

    def test_plugin_mirror_is_required_after_native_gateway_validation(self):
        native = {"uvm_action": "source-upload-begin", "csrf_token": "http-upload-fixture-csrf",
            "filename": "nvidia-595.58.03-6.18.58-Unraid-1.txz", "total": "123"}
        for extra in ({}, {"uvm_csrf_token": "wrong"}, {"uvm_csrf_token[]": "bad"}):
            with self.subTest(extra=extra):
                result = self.post({**native, **extra})
                self.assertFalse(result["ok"])
                self.assertNotIn("data", result)

    def test_cross_origin_still_fails_after_gateway_consumes_native_token(self):
        result = self.post({"uvm_action": "source-upload-begin", "csrf_token": "http-upload-fixture-csrf",
            "uvm_csrf_token": "http-upload-fixture-csrf", "filename": "nvidia-595.58.03-6.18.58-Unraid-1.txz", "total": "123"},
            origin="https://attacker.example.test")
        self.assertFalse(result["ok"])
        self.assertNotIn("data", result)


if __name__ == "__main__":
    unittest.main()
