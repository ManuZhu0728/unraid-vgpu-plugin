"""Native-wrapped cache deletion through real Application and temporary files only."""
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
PREFIX = ROOT / 'source/usr/local/emhttp/plugins/unraid-vgpu-manager'
FREE = 'nvidia-595.58.03-6.18.58-Unraid-2.txz'
SELECTED = 'nvidia-595.58.03-6.18.58-Unraid-3.txz'
HISTORY = 'nvidia-595.58.03-6.18.57-Unraid-3.txz'


class CacheDeleteHttpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.php = os.environ.get('PHP_BIN') or shutil.which('php') or str(ROOT / '.tools/php/root/usr/bin/php8.4')
        cls.temporary = tempfile.TemporaryDirectory(prefix='vgpu-cache-delete-http-')
        cls.directory = Path(cls.temporary.name)
        cls.cache = cls.directory / 'root/boot/config/plugins/unraid-vgpu-manager/driver-cache/6.18.58'
        script = r'''<?php
require_once BOOTSTRAP;
require_once APPLICATION;
require_once UI_TEMPLATE;
$store = new \Vgpu\Store(new \Vgpu\Paths(FIXTURE_ROOT));
$runner = static function (): never { throw new RuntimeException('Fixture forbids every GPU/network/process command'); };
$app = new \Vgpu\Application($store, new \Vgpu\Runtime($store->paths, $runner), new \Vgpu\Download($store->paths, $runner), $runner);
if (PHP_SAPI === 'cli') {
    $packages=[];
    foreach ([2,3] as $build) {
        $name='nvidia-595.58.03-6.18.58-Unraid-'.$build.'.txz';
        $path=$store->paths->cacheDir.'/6.18.58/'.$name;
        if (!is_dir(dirname($path))) mkdir(dirname($path),0700,true);
        file_put_contents($path,'HTTP fixture '.$name); file_put_contents($path.'.md5',md5_file($path).'  '.$name."\n");
        $packages[$build]=['filename'=>$name,'driver'=>'595.58.03','kernel'=>'6.18.58-Unraid','build'=>$build,'path'=>$path];
    }
    $name='nvidia-595.58.03-6.18.57-Unraid-3.txz';
    $path=$store->paths->cacheDir.'/6.18.57/'.$name;
    if (!is_dir(dirname($path))) mkdir(dirname($path),0700,true);
    file_put_contents($path,'HTTP history fixture '.$name);file_put_contents($path.'.md5',md5_file($path).'  '.$name."\n");
    $history=['filename'=>$name,'path'=>$path,'driver'=>'595.58.03','kernel'=>'6.18.57-Unraid','build'=>3,
        'md5'=>md5_file($path),'source'=>'local','saved_boot_id'=>'11111111-1111-4111-8111-111111111111'];
    (new \Vgpu\PackageHistory($store))->remember(\Vgpu\PackageHistory::fromPackage($history));
    $settings=\Vgpu\Store::defaults(); $settings['selected_package']=($argv[1]??'')==='select-history'?$history:$packages[($argv[1]??'')==='select-free'?2:3];
    $store->saveSettings($settings); return;
}
function uvm_cli(string $action, array $payload=[]): array {
    global $app;
    file_put_contents(CALL_LOG,json_encode(['action'=>$action,'payload'=>$payload])."\n",FILE_APPEND);
    if ($action!=='cache-delete') throw new RuntimeException('Async deletion must not request status/logs');
    return $app->dispatch($action,$payload);
}
function uvm_profile_scan(): array { throw new RuntimeException('No GPU scan'); }
function uvm_read(string $path,int $limit=65536): string { return ''; }
$var=['csrf_token'=>'fixture-delete-csrf'];
echo '<!doctype html><main data-unraid-wrapper="fixture">';
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { echo '</main>'; return; }
if (!is_string($_POST['csrf_token']??null)||!hash_equals($var['csrf_token'],$_POST['csrf_token'])) {
    echo '<script type="application/json" id="uvm-cache-delete-result">{"ok":false,"outcome":"failed","error":"Gateway CSRF rejected"}</script></main>'; return;
}
unset($_POST['csrf_token']);
foreach (['driver','vgpu','status'] as $ui_tab) {
    $page=ui_template($ui_tab);
    foreach (['uvm_cli','uvm_profile_scan','uvm_read'] as $function) $page=str_replace('function '.$function.'(','function uvm_original_'.substr($function,4).'(',$page);
    eval('?>'.$page);
}
echo '</main>';
'''
        for key, value in {'BOOTSTRAP': PREFIX / 'include/bootstrap.php', 'APPLICATION': PREFIX / 'include/Application.php',
                           'UI_TEMPLATE': ROOT / 'tests/ui_template.php', 'FIXTURE_ROOT': cls.directory / 'root', 'CALL_LOG': cls.directory / 'calls.jsonl'}.items():
            script = script.replace(key, json.dumps(str(value)))
        cls.fixture = cls.directory / 'fixture.php'
        cls.fixture.write_text(script)
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        cls.origin = f'http://127.0.0.1:{port}'
        cls.url = cls.origin + '/fixture.php'
        cls.start_server(port)
        for _ in range(60):
            try:
                with urllib.request.urlopen(cls.url, timeout=1) as response:
                    response.read()
                    return
            except OSError:
                time.sleep(0.05)
        cls.process.terminate(); cls.process.wait(timeout=5); cls.temporary.cleanup()
        raise RuntimeError('HTTP fixture did not start')

    @classmethod
    def start_server(cls, port):
        cls.process = subprocess.Popen([cls.php, '-n', '-S', f'127.0.0.1:{port}', '-t', str(cls.directory)], stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    @classmethod
    def tearDownClass(cls):
        cls.process.terminate(); cls.process.wait(timeout=5); cls.temporary.cleanup()

    def setUp(self):
        subprocess.run([self.php, '-n', str(self.fixture)], check=True, capture_output=True)
        (self.directory / 'calls.jsonl').write_text('')

    def post(self, changes=None, *, origin=None):
        fields = {'uvm_action': 'cache-delete', 'uvm_tab': 'driver', 'uvm_cache_delete_async': '1', 'csrf_token': 'fixture-delete-csrf',
                  'uvm_csrf_token': 'fixture-delete-csrf', 'cache_filename': FREE, **(changes or {})}
        request = urllib.request.Request(self.url, data=urllib.parse.urlencode(fields).encode(), headers={'Origin': origin or self.origin, 'Sec-Fetch-Site': 'same-origin'})
        with urllib.request.urlopen(request, timeout=10) as response:
            text = response.read().decode()
        self.assertIn('data-unraid-wrapper="fixture"', text)
        markers = re.findall(r'<script type="application/json" id="uvm-cache-delete-result">(.*?)</script>', text, re.S)
        self.assertEqual(len(markers), 1, text[:500])
        self.assertNotIn('id="uvm-manager-', text)
        return json.loads(markers[0])

    def test_real_delete_is_exact_and_preserves_selection_and_configuration(self):
        settings = self.cache.parent.parent / 'settings.json'
        original = settings.read_bytes()
        result = self.post({'source_url': 'https://unsaved.example.test', 'credential_token': 'not-sent', 'path': '/must-not-use'})
        self.assertTrue(result['ok'])
        self.assertEqual(result['data']['removed'], [FREE, FREE + '.md5'])
        self.assertFalse((self.cache / FREE).exists())
        self.assertFalse((self.cache / (FREE + '.md5')).exists())
        self.assertTrue((self.cache / SELECTED).is_file())
        self.assertEqual(settings.read_bytes(), original)
        calls = [json.loads(line) for line in (self.directory / 'calls.jsonl').read_text().splitlines()]
        self.assertEqual(calls, [{'action': 'cache-delete', 'payload': {'filename': FREE}}])

    def test_csrf_tab_origin_and_async_input_fail_before_dispatch(self):
        for change, origin in [({'csrf_token': 'bad'}, None), ({'uvm_csrf_token': 'bad'}, None), ({'uvm_tab': 'vgpu'}, None),
                               ({'uvm_cache_delete_async': '0'}, None), ({'cache_filename': '', 'cache_filename[]': 'bad'}, None), ({}, 'https://external.example.test')]:
            with self.subTest(change=change, origin=origin):
                result = self.post(change, origin=origin)
                self.assertFalse(result['ok'])
                self.assertEqual(result['outcome'], 'failed')
                self.assertEqual((self.directory / 'calls.jsonl').read_text(), '')
                self.assertTrue((self.cache / FREE).exists())

    def test_protected_stale_and_path_requests_never_delete_files(self):
        for filename in (SELECTED, '../' + FREE, str(self.cache / FREE), FREE + '.md5'):
            with self.subTest(filename=filename):
                self.assertFalse(self.post({'cache_filename': filename})['ok'])
                self.assertTrue((self.cache / FREE).is_file())
                self.assertTrue((self.cache / SELECTED).is_file())
        subprocess.run([self.php, '-n', str(self.fixture), 'select-free'], check=True, capture_output=True)
        self.assertFalse(self.post()['ok'])
        self.assertTrue((self.cache / FREE).is_file())

    def test_history_cleanup_requires_explicit_confirmation_and_removes_all_three(self):
        package = self.cache.parent / '6.18.57' / HISTORY
        receipt = self.cache.parent.parent / 'package-history/6.18.57-Unraid.json'
        original_receipt = receipt.read_bytes()
        self.assertFalse(self.post({'cache_filename': HISTORY})['ok'])
        self.assertTrue(package.is_file())
        self.assertEqual(receipt.read_bytes(), original_receipt)
        result = self.post({'cache_filename': HISTORY, 'cache_forget_history': '1'})
        self.assertTrue(result['ok'])
        self.assertEqual(result['data']['removed'], [HISTORY, HISTORY + '.md5', 'package-history/6.18.57-Unraid.json'])
        self.assertFalse(package.exists())
        self.assertFalse(package.with_name(HISTORY + '.md5').exists())
        self.assertFalse(receipt.exists())
        self.assertTrue((self.cache / SELECTED).is_file())
        calls = [json.loads(line) for line in (self.directory / 'calls.jsonl').read_text().splitlines()]
        self.assertEqual(calls[-1], {'action': 'cache-delete', 'payload': {'filename': HISTORY, 'forget_history': True}})

    def test_history_confirmation_cannot_bypass_new_selection_or_invalid_form(self):
        for value in ('0', 'true', ''):
            self.assertFalse(self.post({'cache_filename': HISTORY, 'cache_forget_history': value})['ok'])
        self.assertEqual((self.directory / 'calls.jsonl').read_text(), '')
        subprocess.run([self.php, '-n', str(self.fixture), 'select-history'], check=True, capture_output=True)
        self.assertFalse(self.post({'cache_filename': HISTORY, 'cache_forget_history': '1'})['ok'])
        self.assertTrue((self.cache.parent / '6.18.57' / HISTORY).is_file())
        self.assertTrue((self.cache.parent.parent / 'package-history/6.18.57-Unraid.json').is_file())


if __name__ == '__main__':
    unittest.main()
