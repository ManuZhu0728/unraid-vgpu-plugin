"""Cooperative handover from a legacy polling process in a private local root."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]
PREFIX = ROOT/'source/usr/local/emhttp/plugins/unraid-vgpu-manager/include'


class WatchReloadTests(unittest.TestCase):
    def setUp(self):
        self.php = os.environ.get('PHP_BIN') or shutil.which('php')
        if not self.php:
            self.skipTest('PHP CLI needed for local watcher handover')
        self.temporary = tempfile.TemporaryDirectory(prefix='vgpu-watch-reload-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        bootstrap = str(PREFIX/'bootstrap.php')
        init = 'require $argv[1]; $s=new Vgpu\\Store(new Vgpu\\Paths($argv[2])); $v=Vgpu\\Store::defaults(); $v["upgrade"]["enabled"]=true; $s->saveSettings($v);'
        subprocess.run([self.php,'-n','-r',init,bootstrap,str(self.root)],check=True,capture_output=True)
        self.backend = self.root/'fixture-cli.php'
        self.backend.write_text('<?php echo \'{"ok":true,"data":{"status":"current","message":"local fixture ready"}}\';')
        script = (PREFIX/'upgrade-watch.php').read_text()
        substitutions = {
            "require_once __DIR__ . '/bootstrap.php';": 'require_once '+json.dumps(bootstrap)+';',
            "if (PHP_SAPI !== 'cli' || !is_file('/etc/unraid-version')) exit(1);": "if (PHP_SAPI !== 'cli') exit(1);",
            'if ($uid !== 0) exit(1);': '// Private fixture runs under the developer account.',
            '$paths = new Paths();': '$paths = new Paths($argv[3]);',
            "['/usr/bin/php', __DIR__ . '/cli.php', 'upgrade-check']": '[PHP_BINARY, '+json.dumps(str(self.backend))+", 'upgrade-check']",
        }
        for old,new in substitutions.items():
            self.assertEqual(script.count(old),1,old)
            script=script.replace(old,new)
        self.script=self.root/'watch.php'; self.script.write_text(script)
        self.log=self.root/'watch.log'
        self.processes=[]
        self.addCleanup(self.stop_children)

    def stop_children(self):
        for process in self.processes:
            if process.poll() is None:
                process.terminate(); process.wait(timeout=5)

    def request(self):
        code='require $argv[1]; echo Vgpu\\UpgradeWatch::requestStart(new Vgpu\\Store(new Vgpu\\Paths($argv[2])));'
        return subprocess.check_output([self.php,'-n','-r',code,str(PREFIX/'bootstrap.php'),str(self.root)],text=True).strip()

    def launch(self,generation):
        with self.log.open('ab') as log:
            process=subprocess.Popen([self.php,'-n',str(self.script),'--generation',generation,str(self.root)],stdin=subprocess.DEVNULL,stdout=log,stderr=log)
        self.processes.append(process)
        return process

    def wait(self,predicate,seconds=5):
        deadline=time.monotonic()+seconds
        while time.monotonic()<deadline:
            if predicate(): return
            time.sleep(.03)
        self.fail(self.log.read_text() if self.log.exists() else 'Local watcher did not reach its expected state')

    def test_update_waits_for_legacy_owner_and_starts_new_code_without_signals(self):
        runtime=self.root/'var/run/unraid-vgpu-manager'; runtime.mkdir(parents=True)
        legacy=r'''$dir=$argv[1]; $h=fopen($dir.'/upgrade-watch.lock','ce'); flock($h,LOCK_EX); file_put_contents($dir.'/legacy-ready','yes');
while(!is_file($dir.'/upgrade-watch.stop')){clearstatcache();usleep(20000);} file_put_contents($dir.'/legacy-stopped','yes'); usleep(200000); flock($h,LOCK_UN); fclose($h);'''
        old=subprocess.Popen([self.php,'-n','-r',legacy,str(runtime)])
        self.processes.append(old)
        self.wait(lambda:(runtime/'legacy-ready').exists())
        new=self.launch(self.request())
        self.wait(lambda:self.log.exists() and 'update-check current' in self.log.read_text())
        self.assertTrue((runtime/'legacy-stopped').exists())
        self.assertEqual(old.wait(timeout=2),0)
        self.assertIsNone(new.poll())
        self.assertFalse((runtime/'upgrade-watch.stop').exists())
        self.assertEqual(json.loads((runtime/'upgrade-watch.json').read_text())['pid'],new.pid)
        subprocess.run([self.php,'-n',str(self.script),'--stop','unused',str(self.root)],check=True,capture_output=True)
        self.assertEqual(new.wait(timeout=7),0)

    def test_obsolete_pending_starts_exit_instead_of_accumulating_waiters(self):
        obsolete=self.request(); current=self.request()
        old=self.launch(obsolete)
        self.assertEqual(old.wait(timeout=2),0)
        new=self.launch(current)
        self.wait(lambda:self.log.exists() and 'update-check current' in self.log.read_text())
        self.assertIsNone(new.poll())


if __name__=='__main__':
    unittest.main()
