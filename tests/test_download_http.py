"""Native-wrapped asynchronous download responses over localhost; no NAS/network download."""
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
import urllib.parse
import urllib.request


ROOT = Path(__file__).resolve().parents[1]
PREFIX = ROOT / "source/usr/local/emhttp/plugins/unraid-vgpu-manager"
FILENAME = "nvidia-595.58.03-6.18.58-Unraid-3.txz"


class DownloadHttpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        php = os.environ.get("PHP_BIN") or shutil.which("php") or str(ROOT / ".tools/php/root/usr/bin/php8.4")
        if not Path(php).is_file():
            raise unittest.SkipTest("PHP CLI unavailable")
        cls.temporary = tempfile.TemporaryDirectory(prefix="vgpu-download-http-")
        cls.directory = Path(cls.temporary.name)
        fixture = r'''<?php
require_once BOOTSTRAP;
require_once UI_TEMPLATE;
function uvm_cli(string $action, array $payload = []): array {
    file_put_contents(CALL_LOG, json_encode(['action'=>$action, 'payload'=>$payload]) . "\n", FILE_APPEND);
    if ($action === 'status') return ['settings'=>\Vgpu\Store::defaults(), 'devices'=>[], 'runtime'=>[]];
    if ($action === 'logs') return ['available'=>false, 'lines'=>[]];
    if ($action !== 'source-download') throw new RuntimeException('Unexpected fixture mutation');
    $case = $_GET['fixture_case'] ?? '';
    if ($case === 'failure') throw new RuntimeException('MD5 mismatch </script><script>unsafe()</script> glpat-FIXTUREONLY');
    if ($case === 'unknown') throw new UvmUnknownResultException('Completion is unknown');
    if ($case === 'missing-receipt') return ['ok'=>true];
    $identity = \Vgpu\PackageIdentity::parse($payload['filename'] ?? 'nvidia-595.58.03-6.18.58-Unraid-3.txz', '6.18.58-Unraid');
    return ['ok'=>true, 'package'=>$identity + ['md5'=>str_repeat('a',32), 'checksum_verified'=>true,
        'path'=>'/boot/config/plugins/unraid-vgpu-manager/packages/'.$identity['filename']]];
}
function uvm_profile_scan(): array { throw new RuntimeException('Async downloads must not scan a GPU'); }
function uvm_read(string $path, int $limit=65536): string { return ''; }
$var=['csrf_token'=>'http-download-fixture-csrf'];
echo '<!doctype html><main data-unraid-wrapper="fixture">';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($var['csrf_token'],$_POST['csrf_token'])) {
        echo '<script type="application/json" id="uvm-download-result">{"ok":false,"outcome":"failed","error":"Native gateway CSRF rejected"}</script></main>';
        exit;
    }
    unset($_POST['csrf_token']);
} else {
    echo '</main>'; return;
}
foreach (['driver','vgpu','status'] as $ui_tab) {
    $page=ui_template($ui_tab);
    foreach (['uvm_cli','uvm_profile_scan','uvm_read'] as $function) {
        $page=str_replace('function '.$function.'(', 'function uvm_original_'.substr($function,4).'(', $page);
    }
    eval('?>'.$page);
}
echo '</main>';
'''
        for key, value in {"BOOTSTRAP": PREFIX / "include/bootstrap.php", "UI_TEMPLATE": ROOT / "tests/ui_template.php",
                           "CALL_LOG": cls.directory / "calls.jsonl"}.items():
            fixture = fixture.replace(key, json.dumps(str(value)))
        (cls.directory / "fixture.php").write_text(fixture)
        with socket.socket() as listener:
            listener.bind(("127.0.0.1", 0))
            port = listener.getsockname()[1]
        cls.origin = f"http://127.0.0.1:{port}"
        cls.url = cls.origin + "/fixture.php"
        cls.process = subprocess.Popen([php, "-n", "-S", f"127.0.0.1:{port}", "-t", str(cls.directory)],
                                       stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(60):
            try:
                with urllib.request.urlopen(cls.url, timeout=1) as response:
                    if response.status == 200:
                        response.read()
                        return
            except OSError:
                time.sleep(0.05)
        cls.process.terminate()
        cls.process.wait(timeout=5)
        cls.temporary.cleanup()
        raise RuntimeError("Local download HTTP fixture did not start")

    @classmethod
    def tearDownClass(cls):
        cls.process.terminate()
        cls.process.wait(timeout=5)
        cls.temporary.cleanup()

    def calls(self):
        path = self.directory / "calls.jsonl"
        return [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []

    def post(self, changes=None, *, case="", origin=None, fetch_site="same-origin"):
        fields = {"uvm_action": "source-download", "uvm_download_async": "1", "uvm_tab": "driver",
                  "csrf_token": "http-download-fixture-csrf", "uvm_csrf_token": "http-download-fixture-csrf",
                  "download_package": FILENAME, **(changes or {})}
        request = urllib.request.Request(self.url + ("?fixture_case=" + case if case else ""),
                                        data=urllib.parse.urlencode(fields).encode(),
                                        headers={"Origin": origin or self.origin, "Sec-Fetch-Site": fetch_site})
        with urllib.request.urlopen(request, timeout=10) as response:
            text = response.read().decode()
        self.assertIn('data-unraid-wrapper="fixture"', text)
        markers = re.findall(r'<script type="application/json" id="uvm-download-result">(.*?)</script>', text, re.S)
        self.assertEqual(len(markers), 1, text[:500])
        self.assertNotIn('id="uvm-manager-', text)
        return json.loads(markers[0]), text

    def test_exact_package_download_dispatches_once_without_saving_unsaved_source(self):
        before = len(self.calls())
        result, text = self.post({"source_url": "https://unsaved.example.test/not-used", "credential_token": "unsaved-fixture-token"})
        self.assertEqual(self.calls()[before:], [{"action": "source-download", "payload": {"filename": FILENAME}}])
        self.assertTrue(result["ok"])
        self.assertEqual(result["outcome"], "cached")
        self.assertEqual(result["data"]["package"]["filename"], FILENAME)
        self.assertNotIn("unsaved-fixture-token", text)

    def test_gateway_plugin_csrf_origin_tab_and_async_flags_are_checked_before_dispatch(self):
        for changes, origin, site in [({"csrf_token": "bad"}, None, "same-origin"),
                                      ({"uvm_csrf_token": "bad"}, None, "same-origin"),
                                      ({"uvm_tab": "vgpu"}, None, "same-origin"),
                                      ({"uvm_download_async": "0"}, None, "same-origin"),
                                      ({"download_package": "", "download_package[]": "bad"}, None, "same-origin"),
                                      ({}, "https://external.example.test", "cross-site")]:
            with self.subTest(changes=changes, origin=origin):
                before = len(self.calls())
                result, _ = self.post(changes, origin=origin, fetch_site=site)
                self.assertFalse(result["ok"])
                self.assertEqual(result["outcome"], "failed")
                self.assertEqual(len(self.calls()), before)

    def test_confirmed_failure_and_unknown_completion_are_distinct_and_escaped(self):
        failed, text = self.post(case="failure")
        self.assertFalse(failed["ok"])
        self.assertEqual(failed["outcome"], "failed")
        self.assertNotIn("glpat-FIXTUREONLY", text)
        self.assertNotIn("</script><script>", text)
        for case in ("unknown", "missing-receipt"):
            result, _ = self.post(case=case)
            self.assertFalse(result["ok"])
            self.assertEqual(result["outcome"], "unknown")
            self.assertNotIn("data", result)


if __name__ == "__main__":
    unittest.main()
