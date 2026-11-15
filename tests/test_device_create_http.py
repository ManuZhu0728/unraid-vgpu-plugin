"""Exercise native-wrapped create POSTs with real Application/Store in a private root."""

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
import uuid


ROOT = Path(__file__).resolve().parents[1]
PREFIX = ROOT / "source/usr/local/emhttp/plugins/unraid-vgpu-manager"


class DeviceCreateHttpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        php = os.environ.get("PHP_BIN") or shutil.which("php") or str(ROOT / ".tools/php/root/usr/bin/php8.4")
        if not Path(php).is_file():
            raise unittest.SkipTest("PHP CLI unavailable")
        cls.temporary = tempfile.TemporaryDirectory(prefix="vgpu-create-http-")
        cls.directory = Path(cls.temporary.name)
        cls.state_root = cls.directory / "private-root"
        fixture = r'''<?php
require_once BOOTSTRAP;
require_once APPLICATION;
require_once UI_TEMPLATE;
$privateRoot = PRIVATE_ROOT;
$paths = new \Vgpu\Paths($privateRoot);
$store = new \Vgpu\Store($paths);
$runner = static function(array $argv): array { throw new RuntimeException('Unexpected runtime command'); };
$application = new \Vgpu\Application($store, new \Vgpu\Runtime($paths, $runner), new \Vgpu\Download($paths, $runner), $runner);
function uvm_cli(string $action, array $payload = []): array {
    global $application, $paths, $store;
    file_put_contents(CALL_LOG, json_encode(['action' => $action, 'payload' => $payload]) . "\n", FILE_APPEND);
    if ($action === 'status') return ['settings' => \Vgpu\Store::defaults(), 'devices' => $store->devices(), 'runtime' => []];
    if ($action === 'logs') return ['available' => false, 'lines' => []];
    if (($_GET['fixture_case'] ?? '') === 'write-fail') {
        // A directory at the target filename defeats rename even when tests run as root.
        $failurePaths = new \Vgpu\Paths(PRIVATE_ROOT . '-write-fail');
        if (!is_dir($failurePaths->devicesFile)) mkdir($failurePaths->devicesFile, 0700, true);
        $failureStore = new \Vgpu\Store($failurePaths);
        $runner = static function(array $argv): array { throw new RuntimeException('Unexpected runtime command'); };
        $failing = new \Vgpu\Application($failureStore, new \Vgpu\Runtime($failurePaths, $runner), new \Vgpu\Download($failurePaths, $runner), $runner);
        return $failing->dispatch($action, $payload);
    }
    $result = $application->dispatch($action, $payload);
    if (($result['ok'] ?? true) !== true) throw new RuntimeException((string)($result['message'] ?? 'Operation failed'));
    return $result;
}
function uvm_profile_scan(): array { return []; }
function uvm_read(string $path, int $limit = 65536): string { return ''; }
$var = ['csrf_token' => 'http-create-fixture-csrf'];
echo '<!doctype html><main data-unraid-wrapper="fixture">';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($var['csrf_token'], $_POST['csrf_token'])) {
        echo '<script type="application/json" id="uvm-device-create-result">{"ok":false,"error":"Native gateway CSRF rejected"}</script></main>';
        exit;
    }
    unset($_POST['csrf_token']);
}
foreach (['driver', 'vgpu', 'status'] as $ui_tab) {
    $page = ui_template($ui_tab);
    foreach (['uvm_cli', 'uvm_profile_scan', 'uvm_read'] as $function) {
        $page = str_replace('function ' . $function . '(', 'function uvm_original_' . substr($function, 4) . '(', $page);
    }
    eval('?>' . $page);
}
echo '</main>';
'''
        replacements = {
            "BOOTSTRAP": PREFIX / "include/bootstrap.php",
            "APPLICATION": PREFIX / "include/Application.php",
            "UI_TEMPLATE": ROOT / "tests/ui_template.php",
            "PRIVATE_ROOT": cls.state_root,
            "CALL_LOG": cls.directory / "calls.jsonl",
        }
        for key, value in replacements.items():
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
                        response.read()  # Finish the readiness GET before taking call snapshots.
                        return
            except OSError:
                time.sleep(0.05)
        cls.process.terminate()
        cls.process.wait(timeout=5)
        cls.temporary.cleanup()
        raise RuntimeError("Private PHP fixture did not start")

    @classmethod
    def tearDownClass(cls):
        cls.process.terminate()
        cls.process.wait(timeout=5)
        cls.temporary.cleanup()

    def device(self):
        return {"uuid": str(uuid.uuid4()), "pci": "0000:01:00.0", "profile": "NVIDIA RTXA5500-1Q", "type": "nvidia-762"}

    def saved(self):
        file = self.state_root / "boot/config/plugins/unraid-vgpu-manager/devices.json"
        return json.loads(file.read_text()) if file.is_file() else []

    def calls(self):
        file = self.directory / "calls.jsonl"
        return [json.loads(line) for line in file.read_text().splitlines()] if file.exists() else []

    def post(self, device, *, change=None, origin=None, query=""):
        fields = {"uvm_tab": "vgpu", "uvm_action": "device-create", "csrf_token": "http-create-fixture-csrf",
                  "uvm_csrf_token": "http-create-fixture-csrf", **device, **(change or {})}
        request = urllib.request.Request(self.url + query, data=urllib.parse.urlencode(fields).encode(),
                                        headers={"Origin": self.origin if origin is None else origin, "Sec-Fetch-Site": "same-origin"})
        with urllib.request.urlopen(request, timeout=10) as response:
            html = response.read().decode()
        self.assertIn('data-unraid-wrapper="fixture"', html)
        matches = re.findall(r'<script type="application/json" id="uvm-device-create-result">(.*?)</script>', html, re.S)
        self.assertEqual(len(matches), 1, "All native tabs must emit exactly one response/dispatch")
        return json.loads(matches[0])

    def test_create_saves_once_and_get_refresh_is_read_only(self):
        device = self.device()
        before = len(self.calls())
        result = self.post(device)
        self.assertTrue(result["ok"])
        self.assertEqual(result["data"], {"uuid": device["uuid"], "changed": True})
        self.assertIn("未启动", result["message"])
        self.assertIn(device, self.saved())
        self.assertEqual(self.calls()[before:], [{"action": "device-create", "payload": device}])
        saved = self.saved()
        with urllib.request.urlopen(self.url + "?uvm_view=vgpu", timeout=10) as response:
            self.assertIn(device["uuid"], response.read().decode())
        self.assertEqual(self.saved(), saved)
        self.assertTrue(all(call["action"] in ("status", "logs") for call in self.calls()[before + 1:]))

    def test_duplicate_and_conflicting_identity_never_overwrite(self):
        device = self.device()
        self.assertTrue(self.post(device)["ok"])
        saved = self.saved()
        for candidate in (device, {**device, "profile": "NVIDIA RTXA5500-2Q", "type": "nvidia-763"}):
            result = self.post(candidate)
            self.assertFalse(result["ok"])
            self.assertIn("UUID", result["error"])
            self.assertEqual(self.saved(), saved)
        self.assertEqual(sum(row["uuid"] == device["uuid"] for row in self.saved()), 1)

    def test_invalid_origin_tab_fields_and_csrf_do_not_reach_backend(self):
        for change, origin in [({"csrf_token": "bad"}, None), ({"uvm_csrf_token": "bad"}, None),
                               ({"uvm_csrf_token[]": "bad", "uvm_csrf_token": "bad"}, None),
                               ({"uvm_tab": "driver"}, None), ({"uuid[]": "bad"}, None),
                               ({}, "https://external.example.test")]:
            with self.subTest(change=change, origin=origin):
                before = len(self.calls())
                saved = self.saved()
                result = self.post(self.device(), change=change, origin=origin)
                self.assertFalse(result["ok"])
                self.assertEqual(len(self.calls()), before)
                self.assertEqual(self.saved(), saved)

    def test_real_write_failure_is_not_success_and_does_not_corrupt_other_devices(self):
        device = self.device()
        saved = self.saved()
        result = self.post(device, query="?fixture_case=write-fail")
        self.assertFalse(result["ok"])
        self.assertIn("error", result)
        self.assertNotIn("data", result)
        self.assertEqual(self.saved(), saved)
        self.assertTrue(self.post(device)["ok"])

    def test_invalid_device_and_hostile_text_are_not_reported_as_saved(self):
        for changes in ({"uuid": "invalid"}, {"pci": "../../etc"}, {"profile": ""}, {"type": "nvidia-1;reboot"}):
            saved = self.saved()
            result = self.post({**self.device(), **changes})
            self.assertFalse(result["ok"])
            self.assertEqual(self.saved(), saved)


if __name__ == "__main__":
    unittest.main()
