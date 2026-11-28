"""Optional real nginx auth_request/PHP-FPM regression; localhost fixtures only.

Set NGINX_BIN and PHP_FPM_BIN to unprivileged test binaries. This deliberately
keeps the native authentication subrequest (unlike PHP -S and mocked fetches).
"""
import os
import shutil
import subprocess
import time
import unittest
import urllib.request

import test_cache_delete_http as cache_http


class WebGuiGatewayTests(cache_http.CacheDeleteHttpTests):
    @classmethod
    def setUpClass(cls):
        cls.nginx = os.environ.get('NGINX_BIN') or shutil.which('nginx')
        cls.fpm_binary = os.environ.get('PHP_FPM_BIN') or shutil.which('php-fpm') or shutil.which('php-fpm8.4')
        if not cls.nginx or not cls.fpm_binary:
            raise unittest.SkipTest('Real gateway regression needs NGINX_BIN and PHP_FPM_BIN (no NAS access)')
        cls.fpm = None
        cls.addClassCleanup(cls.stop_fpm)
        super().setUpClass()

    @classmethod
    def start_server(cls, port):
        directory = str(cls.directory)
        # Extend only the fixture's read boundary for an actual browser GET.
        # POSTs still run production UI state, Application and PackageCache.
        script = cls.fixture.read_text()
        script = script.replace("    if ($action!=='cache-delete')", r'''    if ($action==='status' && ($_SERVER['REQUEST_METHOD']??'')==='GET') {
        global $store;
        return ['settings'=>$store->settings(),'devices'=>[],'credential_configured'=>false,
            'override_content'=>'','pending_restart'=>false,
            'package_cache'=>(new \Vgpu\PackageCache($store))->listing(),
            'runtime'=>['ok'=>true,'runtime'=>['kernel'=>'6.18.58-Unraid','loaded_driver'=>'595.58.03']]];
    }
    if ($action!=='cache-delete')''')
        script = script.replace("function uvm_profile_scan(): array { throw", "function uvm_profile_scan(): array { if (($_SERVER['REQUEST_METHOD']??'')==='GET') return []; throw")
        get_branch = "if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { echo '</main>'; return; }"
        assert script.count(get_branch) == 1
        script = script.replace(get_branch, r'''if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
    if (is_file(__DIR__.'/browser-enabled')) {
        $page=ui_template('driver');
        foreach (['uvm_cli','uvm_profile_scan','uvm_read'] as $function) $page=str_replace('function '.$function.'(','function uvm_original_'.substr($function,4).'(',$page);
        eval('?>'.$page);
        // Native WebGUI also appends a second copy of the same CSRF field.
        echo '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("form").forEach(function(f){const i=document.createElement("input");i.type="hidden";i.name="csrf_token";i.value="fixture-delete-csrf";f.append(i);});});</script>';
    }
    echo '</main>'; return;
}''')
        cls.fixture.write_text(script)
        cls.start_gateway(port)

    @classmethod
    def start_gateway(cls, port):
        directory = str(cls.directory)
        (cls.directory / 'auth.php').write_text(getattr(cls, 'auth_script', '<?php http_response_code(204);'))
        fpm_conf = cls.directory / 'fpm.conf'
        fpm_conf.write_text(f'''[global]
daemonize = no
error_log = {directory}/fpm.log
[fixture]
listen = {directory}/php.sock
pm = ondemand
pm.max_children = 6
pm.process_idle_timeout = 5s
catch_workers_output = yes
php_admin_value[post_max_size] = {getattr(cls, 'fpm_post_limit', '2M')}
''' + ('user = root\ngroup = root\n' if os.geteuid() == 0 else ''))
        cls.fpm = subprocess.Popen([cls.fpm_binary, '-n', '-F', '-y', str(fpm_conf)] + (['-R'] if os.geteuid() == 0 else []),
                                   stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(60):
            if (cls.directory / 'php.sock').exists():
                break
            if cls.fpm.poll() is not None:
                raise RuntimeError('Fixture PHP-FPM did not start')
            time.sleep(.05)
        config = cls.directory / 'nginx.conf'
        paths = '\n'.join(f'{kind}_temp_path "{directory}/{kind}";' for kind in ['client_body', 'fastcgi', 'proxy', 'scgi', 'uwsgi'])
        config.write_text(f'''daemon off;
pid "{directory}/nginx.pid";
error_log "{directory}/nginx.log" info;
events {{ worker_connections 64; }}
http {{
  access_log off;
  {paths}
  fastcgi_keep_conn on;
  fastcgi_read_timeout 5s;
  client_max_body_size 3m;
  auth_request /auth.php;
  server {{
    listen 127.0.0.1:{port};
    root "{directory}";
    location / {{ try_files $uri /fixture.php$is_args$args; }}
    location ~ \\.php$ {{
      fastcgi_pass unix:{directory}/php.sock;
      fastcgi_param SCRIPT_FILENAME $request_filename;
      fastcgi_param SCRIPT_NAME $fastcgi_script_name;
      fastcgi_param REQUEST_METHOD $request_method;
      fastcgi_param CONTENT_TYPE $content_type;
      fastcgi_param CONTENT_LENGTH $content_length;
      fastcgi_param QUERY_STRING $query_string;
      fastcgi_param REQUEST_URI $request_uri;
      fastcgi_param DOCUMENT_ROOT $document_root;
      fastcgi_param SERVER_PROTOCOL $server_protocol;
      fastcgi_param HTTP_HOST $http_host;
      fastcgi_param SERVER_PORT $server_port;
      fastcgi_param REDIRECT_STATUS 200;
    }}
  }}
}}''')
        cls.process = subprocess.Popen([cls.nginx, '-p', directory, '-c', str(config)], stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        cls.url = cls.origin + '/Settings/cache-fixture'

    @classmethod
    def stop_fpm(cls):
        if cls.fpm is not None and cls.fpm.poll() is None:
            cls.fpm.terminate()
            try:
                cls.fpm.wait(timeout=5)
            except subprocess.TimeoutExpired:
                cls.fpm.kill()
                cls.fpm.wait(timeout=5)

    def test_multipart_negative_control_stalls_before_application(self):
        boundary = '----------------gateway-fixture'
        fields = {'uvm_action': 'cache-delete', 'uvm_tab': 'driver', 'uvm_cache_delete_async': '1',
                  'csrf_token': 'fixture-delete-csrf', 'uvm_csrf_token': 'fixture-delete-csrf', 'cache_filename': cache_http.FREE}
        body = ''.join(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n' for k, v in fields.items()) + f'--{boundary}--\r\n'
        request = urllib.request.Request(self.url, data=body.encode(), headers={'Content-Type': 'multipart/form-data; boundary='+boundary, 'Origin': self.origin})
        started = time.monotonic()
        with self.assertRaises(TimeoutError):
            urllib.request.urlopen(request, timeout=1)
        self.assertGreaterEqual(time.monotonic() - started, .8)
        self.assertEqual((self.directory / 'calls.jsonl').read_text(), '', 'Old multipart never reaches Application')
        self.assertTrue((self.cache / cache_http.FREE).is_file())
        # A second request with ordinary form encoding succeeds on the SAME gateway.
        result = self.post()
        self.assertTrue(result['ok'])
        self.assertFalse((self.cache / cache_http.FREE).exists())

    def test_actual_browser_deletes_and_refresh_keeps_file_absent(self):
        if not os.environ.get('PLAYWRIGHT_MODULE'):
            self.skipTest('Set PLAYWRIGHT_MODULE for the real browser -> nginx -> PHP -> temporary-file check')
        settings = self.cache.parent.parent / 'settings.json'
        original_settings = settings.read_bytes()
        (self.directory / 'browser-enabled').touch()
        try:
            result = subprocess.run(['node', str(cache_http.ROOT / 'tests/test_ui_gateway.cjs'), self.url],
                                    capture_output=True, text=True, timeout=45)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertIn('real gateway browser passed', result.stdout)
            self.assertFalse((self.cache / cache_http.FREE).exists())
            self.assertFalse((self.cache / (cache_http.FREE + '.md5')).exists())
            self.assertTrue((self.cache / cache_http.SELECTED).is_file())
            self.assertFalse((self.cache.parent / '6.18.57' / cache_http.HISTORY).exists())
            self.assertFalse((self.cache.parent.parent / 'package-history/6.18.57-Unraid.json').exists())
            self.assertEqual(settings.read_bytes(), original_settings)
        finally:
            (self.directory / 'browser-enabled').unlink()


if __name__ == '__main__':
    unittest.main()
