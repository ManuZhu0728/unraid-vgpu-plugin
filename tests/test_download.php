<?php
declare(strict_types=1);

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';

use Vgpu\Download;
use Vgpu\Paths;

function download_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function download_fails(callable $operation, string $contains): void
{
    try { $operation(); }
    catch (Throwable $error) {
        download_check(str_contains($error->getMessage(), $contains), 'Unexpected failure: ' . $error->getMessage());
        download_check(!str_contains($error->getMessage(), 'fixture-secret'), 'Credential leaked in exception');
        return;
    }
    throw new RuntimeException('Expected failure: ' . $contains);
}

final class DownloadFixture
{
    public string $root;
    public Paths $paths;
    public Download $download;
    public array $source;
    public array $credentials;
    public array $routes = [];
    public array $calls = [];
    public string $package = "\xfd7zXZ\x00fixture-package";
    public string $filename = 'nvidia-595.58.03-6.18.58-Unraid-1.txz';
    public string $listing;
    public string $moduleVersion = '595.58.03';
    public string $vermagic = '6.18.58-Unraid SMP preempt mod_unload';
    public string $failureCommand = '';
    public string $releaseUrl;
    public string $listUrl;
    public string $packageUrl;
    public string $md5Url;

    public function __construct(string $provider = 'gitlab', string $mode = 'releases')
    {
        $this->root = sys_get_temp_dir() . '/vgpu-download-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->paths = new Paths($this->root);
        $host = $provider === 'github' ? 'github.com' : 'git.example.test';
        $this->source = ['provider' => $provider, 'mode' => $mode, 'repository' => 'https://' . $host . '/owner/repo',
            'driver' => '595.58.03', 'tag_template' => 'v{kernel}-{driver}', 'project_id' => '42',
            'package_name' => 'build', 'package_version_template' => 'v{kernel}-{driver}'];
        $this->credentials = ['type' => $provider === 'github' ? 'bearer' : ($mode === 'generic' ? 'deploy-token' : 'private-token'),
            'token' => 'fixture-secret', 'username' => 'fixture-user'];
        $this->releaseUrl = $provider === 'github'
            ? 'https://api.github.com/repos/owner/repo/releases/tags/v6.18.58-595.58.03'
            : 'https://git.example.test/api/v4/projects/42/releases/v6.18.58-595.58.03';
        $this->listUrl = $provider === 'github' ? 'https://api.github.com/repos/owner/repo/releases?per_page=100&page=1'
            : 'https://git.example.test/api/v4/projects/42/releases?per_page=100&page=1';
        $this->packageUrl = $provider === 'github'
            ? 'https://api.github.com/repos/owner/repo/releases/assets/100'
            : 'https://git.example.test/api/v4/projects/42/packages/generic/build/v6.18.58-595.58.03/' . $this->filename;
        $this->md5Url = $provider === 'github'
            ? 'https://api.github.com/repos/owner/repo/releases/assets/101'
            : $this->packageUrl . '.md5';
        $this->refreshRoutes($provider);
        $this->listing = $this->normalListing();
        $this->download = new Download($this->paths, $this->run(...));
    }

    public function refreshRoutes(string $provider = 'gitlab'): void
    {
        $assets = $provider === 'github'
            ? [['name' => $this->filename, 'id' => 100], ['name' => $this->filename . '.md5', 'id' => 101]]
            : [['name' => $this->filename, 'url' => $this->packageUrl], ['name' => $this->filename . '.md5', 'url' => $this->md5Url]];
        $release = $provider === 'github' ? ['assets' => $assets] : ['assets' => ['links' => $assets]];
        $release['tag_name'] = 'v6.18.58-595.58.03';
        $this->routes[$this->releaseUrl] = ['body' => json_encode($release), 'type' => 'application/json'];
        $this->routes[$this->packageUrl] = ['body' => $this->package];
        $this->routes[$this->md5Url] = ['body' => md5($this->package) . '  ' . $this->filename . "\n"];
    }

    public function normalListing(): string
    {
        $files = [];
        foreach (['nvidia', 'nvidia-modeset', 'nvidia-drm', 'nvidia-uvm', 'nvidia-vgpu-vfio'] as $module) {
            $files[] = 'lib/modules/6.18.58-Unraid/kernel/drivers/video/' . $module . '.ko';
        }
        foreach (['usr/bin/nvidia-vgpud', 'usr/bin/nvidia-vgpu-mgr', 'usr/lib64/libvgpu_unlock_rs.so',
            'usr/share/nvidia/vgpu/vgpuConfig.xml', 'usr/lib64/libcuda.so.1', 'usr/lib64/libnvcuvid.so.1',
            'usr/lib64/libnvidia-encode.so.1', 'usr/lib64/libnvidia-ml.so.1'] as $name) $files[] = $name;
        return implode("\n", array_map(static fn(string $name): string => '-rw-r--r-- 0/0 1 2026-11-02 10:00:00 ' . $name, $files)) . "\n";
    }

    public function run(array $argv, int $timeout = 15, ?string $stdin = null): array
    {
        $this->calls[] = ['argv' => $argv, 'stdin' => $stdin, 'timeout' => $timeout];
        download_check(!str_contains(implode(' ', $argv), 'fixture-secret'), 'Token in process argv');
        if ($argv[0] === $this->failureCommand) return ['code' => 7, 'stdout' => '', 'stderr' => 'fixture-secret must not leak'];
        if ($argv[0] === 'curl') {
            download_check($argv[1] === '--disable', 'User curlrc must not affect authentication');
            download_check(!in_array('--location', $argv, true), 'Uncontrolled authenticated redirects');
            download_check(preg_match('/^url = "(.*)"$/m', (string)$stdin, $match) === 1, 'URL missing from curl stdin config');
            $url = stripcslashes($match[1]);
            download_check(!in_array($url, $argv, true), 'URL unexpectedly appears in argv');
            if ($url === $this->listUrl && !isset($this->routes[$url])) {
                $route = $this->routes[$this->releaseUrl];
                $release = json_decode($route['body'] ?? '', true);
                if (is_array($release)) $route['body'] = json_encode([$release]);
            } else {
                download_check(isset($this->routes[$url]), 'Unexpected HTTP request');
                $route = $this->routes[$url];
            }
            $target = $argv[array_search('--output', $argv, true) + 1];
            file_put_contents($target, $route['body'] ?? 'redirect');
            return ['code' => $route['code'] ?? 0, 'stdout' => ($route['status'] ?? 200) . "\n" .
                ($route['type'] ?? 'application/octet-stream') . "\n" . ($route['redirect'] ?? '') . "\n", 'stderr' => ''];
        }
        if ($argv[0] === 'xz') return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        if ($argv[0] === 'tar' && in_array('--list', $argv, true)) return ['code' => 0, 'stdout' => $this->listing, 'stderr' => ''];
        if ($argv[0] === 'tar' && in_array('--extract', $argv, true)) {
            $directory = $argv[array_search('--directory', $argv, true) + 1];
            foreach (array_slice($argv, array_search('--', $argv, true) + 1) as $member) {
                $file = $directory . '/' . $member;
                if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
                file_put_contents($file, 'fake module');
            }
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        }
        if ($argv[0] === 'modinfo') return ['code' => 0, 'stdout' => $argv[2] === 'version' ? $this->moduleVersion : $this->vermagic, 'stderr' => ''];
        throw new RuntimeException('Unexpected command');
    }

    public function cleanup(): void
    {
        if (!is_dir($this->root)) return;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname());
            else unlink($item->getPathname());
        }
        rmdir($this->root);
    }
}

$fixtures = [];
$fixture = static function (string $provider = 'gitlab', string $mode = 'releases') use (&$fixtures): DownloadFixture {
    $item = new DownloadFixture($provider, $mode); $fixtures[] = $item; return $item;
};
$passed = 0;
$case = static function (string $name, callable $test) use (&$passed): void { $test(); ++$passed; echo 'PASS download: ' . $name . "\n"; };

try {
    $case('GitLab release links and complete atomic cache', function () use ($fixture): void {
        $f = $fixture();
        $versions = $f->download->versions($f->source, $f->credentials, '6.18.58-Unraid');
        download_check(count($versions['versions']) === 1, 'Missing selected version');
        download_check(!str_contains(json_encode($versions), 'fixture-secret'), 'Metadata token leak');
        $package = $f->download->fetch($f->source, $f->credentials, '6.18.58')['package'];
        download_check(file_get_contents($package['path']) === $f->package, 'Wrong cached bytes');
        download_check(file_get_contents($package['path'] . '.md5') === md5($f->package) . '  ' . $f->filename . "\n", 'Wrong MD5 record');
        download_check($package['kernel'] === '6.18.58-Unraid' && $package['driver'] === '595.58.03', 'Wrong compatibility metadata');
        $before = filemtime($package['path']);
        $f->download->fetch($f->source, $f->credentials, '6.18.58');
        download_check(filemtime($package['path']) === $before, 'Valid cached package was replaced');
        download_check(glob($f->paths->runtimeDir . '/.download-*') === [], 'Temporary verification files leaked');
    });
    $case('GitHub uses asset API with octet-stream authentication', function () use ($fixture): void {
        $f = $fixture('github');
        $f->download->fetch($f->source, $f->credentials, '6.18.58');
        foreach ($f->calls as $call) if ($call['argv'][0] === 'curl') {
            download_check(str_contains($call['stdin'], 'Authorization: Bearer fixture-secret'), 'Missing GitHub authentication');
            if (str_contains($call['stdin'], '/releases/assets/')) download_check(str_contains($call['stdin'], 'Accept: application/octet-stream'), 'Wrong asset Accept header');
        }
    });
    $case('Generic deploy token needs no Release/project enumeration', function () use ($fixture): void {
        $f = $fixture('gitlab', 'generic');
        $f->download->test($f->source, $f->credentials, '6.18.58');
        $f->download->fetch($f->source, $f->credentials, '6.18.58');
        foreach ($f->calls as $call) if ($call['argv'][0] === 'curl') {
            download_check(str_contains($call['stdin'], '/packages/generic/'), 'Deploy token used with ordinary API');
            download_check(str_contains($call['stdin'], 'DEPLOY-TOKEN: fixture-secret'), 'Missing deploy token');
        }
    });
    $case('Deploy token Releases misuse rejected before network', function () use ($fixture): void {
        $f = $fixture(); $f->credentials['type'] = 'deploy-token';
        download_fails(fn() => $f->download->test($f->source, $f->credentials, '6.18.58'), 'deploy token');
        download_check($f->calls === [], 'Rejected configuration still connected');
    });
    $case('Anonymous public source works', function () use ($fixture): void {
        $f = $fixture(); $f->credentials = ['type' => 'none'];
        $f->download->test($f->source, $f->credentials, '6.18.58');
        download_check(!str_contains($f->calls[0]['stdin'], 'TOKEN:') && !str_contains($f->calls[0]['stdin'], 'Authorization:'), 'Unexpected anonymous auth');
    });
    $case('External asset and redirect do not receive credentials', function () use ($fixture): void {
        $f = $fixture();
        $external = 'https://cdn.example.test/file.txz?signature=fixture-secret';
        $f->routes[$f->packageUrl] = ['status' => 302, 'redirect' => $external];
        $f->routes[$external] = ['body' => $f->package];
        $f->download->fetch($f->source, $f->credentials, '6.18.58');
        $found = false;
        foreach ($f->calls as $call) if ($call['argv'][0] === 'curl' && str_contains($call['stdin'], 'cdn.example.test')) {
            $found = true; download_check(!str_contains($call['stdin'], 'PRIVATE-TOKEN:'), 'Token forwarded to CDN');
        }
        download_check($found, 'Redirect was not followed');
    });
    $case('External GitLab asset links never inherit authentication', function () use ($fixture): void {
        $f = $fixture(); $f->packageUrl = 'https://cdn.example.test/package'; $f->md5Url = 'https://cdn.example.test/checksum';
        $f->refreshRoutes(); $f->download->fetch($f->source, $f->credentials, '6.18.58');
        foreach ($f->calls as $call) if ($call['argv'][0] === 'curl' && str_contains($call['stdin'], 'cdn.example.test')) {
            download_check(!str_contains($call['stdin'], 'fixture-secret'), 'External release asset received token');
        }
    });
    $case('HTTP, embedded credentials and token control characters are rejected', function () use ($fixture): void {
        foreach (['http://git.example.test/owner/repo', 'https://user:pass@git.example.test/owner/repo', 'https://git.example.test/owner/repo?token=bad'] as $repository) {
            $f = $fixture(); $f->source['repository'] = $repository;
            download_fails(fn() => $f->download->test($f->source, $f->credentials, '6.18.58'), $repository[4] === ':' ? 'HTTPS' : ($repository === 'https://user:pass@git.example.test/owner/repo' ? 'HTTPS' : 'query'));
        }
        $f = $fixture(); $f->credentials['token'] = "fixture-secret\nheader=evil";
        download_fails(fn() => $f->download->test($f->source, $f->credentials, '6.18.58'), 'token');
    });
    $case('401/403/404 and HTML never become a cached package', function () use ($fixture): void {
        foreach ([401, 403, 404] as $status) {
            $f = $fixture(); $f->routes[$f->releaseUrl] = ['status' => $status, 'body' => 'fixture-secret'];
            download_fails(fn() => $f->download->fetch($f->source, $f->credentials, '6.18.58'), 'HTTP ' . $status);
            download_check(!is_dir($f->paths->cacheDir), 'Failed lookup wrote a cache package');
        }
        $f = $fixture(); $f->routes[$f->releaseUrl] = ['body' => '<html>sign in</html>', 'type' => 'application/json'];
        download_fails(fn() => $f->download->test($f->source, $f->credentials, '6.18.58'), 'HTML');
    });
    $case('Wrong kernel, missing checksum and duplicate assets fail', function () use ($fixture): void {
        $f = $fixture(); $f->routes[$f->releaseUrl]['body'] = str_replace('6.18.58', '6.18.54', $f->routes[$f->releaseUrl]['body']);
        download_fails(fn() => $f->download->test($f->source, $f->credentials, '6.18.58'), 'No canonical');
        $f = $fixture(); $data = json_decode($f->routes[$f->releaseUrl]['body'], true); array_pop($data['assets']['links']);
        $f->routes[$f->releaseUrl]['body'] = json_encode($data);
        download_fails(fn() => $f->download->test($f->source, $f->credentials, '6.18.58'), 'MD5 attachment');
        $f = $fixture(); $data = json_decode($f->routes[$f->releaseUrl]['body'], true); $data['assets']['links'][] = $data['assets']['links'][0];
        $f->routes[$f->releaseUrl]['body'] = json_encode($data);
        download_fails(fn() => $f->download->test($f->source, $f->credentials, '6.18.58'), 'duplicate');
    });
    $case('MD5 errors preserve an existing different package', function () use ($fixture): void {
        $f = $fixture(); $f->routes[$f->md5Url]['body'] = str_repeat('0', 32) . '  ' . $f->filename . "\n";
        download_fails(fn() => $f->download->fetch($f->source, $f->credentials, '6.18.58'), 'MD5 mismatch');
        $f = $fixture(); mkdir($f->paths->cacheDir . '/6.18.58', 0700, true);
        $path = $f->paths->cacheDir . '/6.18.58/' . $f->filename; file_put_contents($path, 'old package');
        download_fails(fn() => $f->download->fetch($f->source, $f->credentials, '6.18.58'), 'preserved');
        download_check(file_get_contents($path) === 'old package', 'Old cache was overwritten');
    });
    $case('Malformed checksum filename and broken XZ are rejected', function () use ($fixture): void {
        $f = $fixture(); $f->routes[$f->md5Url]['body'] = md5($f->package) . "  other.txz\n";
        download_fails(fn() => $f->download->fetch($f->source, $f->credentials, '6.18.58'), 'Malformed MD5');
        $f = $fixture(); $f->failureCommand = 'xz';
        download_fails(fn() => $f->download->fetch($f->source, $f->credentials, '6.18.58'), 'verification failed: xz');
        $f = $fixture(); $f->package = 'not an archive'; $f->refreshRoutes();
        download_fails(fn() => $f->download->fetch($f->source, $f->credentials, '6.18.58'), 'not an XZ');
    });
    $case('Archive traversal, specials, duplicates, symlink parents and bad ownership fail', function () use ($fixture): void {
        $bad = [
            '-rw-r--r-- 0/0 1 2026-11-02 10:00:00 ../etc/passwd',
            'crw-r--r-- 0/0 1 2026-11-02 10:00:00 dev/evil',
            '-rw-r--r-- 0/0 1 2026-11-02 10:00:00 usr/bin/nvidia-vgpud',
            'lrwxrwxrwx 0/0 0 2026-11-02 10:00:00 usr -> ../../etc',
            'lrwxrwxrwx 0/0 0 2026-11-02 10:00:00 lib -> /usr/lib',
            '-rw-r--r-- 1000/1000 1 2026-11-02 10:00:00 stray',
        ];
        foreach ($bad as $entry) {
            $f = $fixture(); $f->listing .= $entry . "\n";
            download_fails(fn() => $f->download->fetch($f->source, $f->credentials, '6.18.58'), $entry[0] === 'c' ? 'unsafe TAR' : ($entry[0] === 'l' ? ($entry === $bad[4] ? 'non-directory' : 'escapes') : ($entry === $bad[0] ? 'Unsafe archive' : ($entry === $bad[5] ? 'root-owned' : 'Duplicate'))));
        }
    });
    $case('Missing vGPU module and module version/vermagic mismatches fail', function () use ($fixture): void {
        $f = $fixture(); $f->listing = preg_replace('/^.*nvidia-vgpu-vfio\.ko\n/m', '', $f->listing);
        download_fails(fn() => $f->download->fetch($f->source, $f->credentials, '6.18.58'), 'vGPU module');
        $f = $fixture(); $f->moduleVersion = '580.159.03';
        download_fails(fn() => $f->download->fetch($f->source, $f->credentials, '6.18.58'), 'driver version');
        $f = $fixture(); $f->vermagic = '6.18.54-Unraid SMP';
        download_fails(fn() => $f->download->fetch($f->source, $f->credentials, '6.18.58'), 'vermagic');
        $f = $fixture(); $f->listing = str_replace('-rw-r--r-- 0/0 1 2026-11-02 10:00:00 usr/lib64/libcuda.so.1',
            '-rw-r--r-- 0/0 0 2026-11-02 10:00:00 usr/lib64/libcuda.so.1', $f->listing);
        download_fails(fn() => $f->download->fetch($f->source, $f->credentials, '6.18.58'), 'compatible merged');
    });
    $case('TAR dot prefixes and relative library links are supported', function () use ($fixture): void {
        $f = $fixture(); $f->listing = preg_replace('/ 10:00:00 /', ' 10:00:00 ./', $f->listing);
        $f->listing = str_replace('-rw-r--r-- 0/0 1 2026-11-02 10:00:00 ./usr/lib64/libcuda.so.1',
            'lrwxrwxrwx 0/0 0 2026-11-02 10:00:00 ./usr/lib64/libcuda.so.1 -> libcuda.so.595.58.02', $f->listing);
        $f->listing .= '-rw-r--r-- 0/0 1 2026-11-02 10:00:00 ./usr/lib64/libcuda.so.595.58.02' . "\n";
        $f->download->fetch($f->source, $f->credentials, '6.18.58');
    });
    $case('Transport errors are redacted and HTTPS downgrade refused', function () use ($fixture): void {
        $f = $fixture(); $f->failureCommand = 'curl';
        download_fails(fn() => $f->download->test($f->source, $f->credentials, '6.18.58'), 'transport failed');
        $f = $fixture(); $f->routes[$f->releaseUrl] = ['status' => 302, 'redirect' => 'http://cdn.example.test/login'];
        download_fails(fn() => $f->download->test($f->source, $f->credentials, '6.18.58'), 'HTTPS');
    });
    $case('GitHub and GitLab discover multiple driver tags with no selected driver', function () use ($fixture): void {
        foreach (['github', 'gitlab'] as $provider) {
            $f = $fixture($provider); $f->source['driver'] = '';
            $release = json_decode($f->routes[$f->releaseUrl]['body'], true);
            $older = json_decode(str_replace('595.58.03', '580.159.03', json_encode($release)), true);
            $wrongKernel = json_decode(str_replace('6.18.58', '6.18.54', json_encode($release)), true);
            $wrongTag = $release; $wrongTag['tag_name'] = 'unrelated-tag';
            $f->routes[$f->listUrl] = ['body' => json_encode([$release, $older, $wrongKernel, $wrongTag]), 'type' => 'application/json'];
            $versions = $f->download->versions($f->source, $f->credentials, '6.18.58')['versions'];
            download_check(array_column($versions, 'driver') === ['580.159.03', '595.58.03'], 'Discovery did not filter/sort driver versions');
            download_fails(fn() => $f->download->fetch($f->source, $f->credentials, '6.18.58'), 'explicit X.Y.Z driver');
            $f->source['driver'] = 'latest';
            download_fails(fn() => $f->download->fetch($f->source, $f->credentials, '6.18.58'), 'explicit X.Y.Z driver');
        }
        $f = $fixture('gitlab', 'generic'); $f->source['driver'] = '';
        download_fails(fn() => $f->download->versions($f->source, $f->credentials, '6.18.58'), 'automatic discovery is unavailable');
    });
    $case('Discovery follows bounded pagination rather than choosing latest', function () use ($fixture): void {
        $f = $fixture();
        $empty = ['tag_name' => 'unrelated', 'assets' => ['links' => []]];
        $f->routes[$f->listUrl] = ['body' => json_encode(array_fill(0, 100, $empty)), 'type' => 'application/json'];
        $page2 = str_replace('&page=1', '&page=2', $f->listUrl);
        $f->routes[$page2] = ['body' => '[' . $f->routes[$f->releaseUrl]['body'] . ']', 'type' => 'application/json'];
        download_check(count($f->download->versions($f->source, $f->credentials, '6.18.58')['versions']) === 1, 'Page two not discovered');
    });
    $case('Real passive XZ/TAR parsing accepts GNU dot prefixes and hard links', function () use ($fixture): void {
        $f = $fixture(); $staging = $f->root . '/fixture-content'; mkdir($staging, 0700);
        foreach (explode("\n", trim($f->normalListing())) as $line) {
            $name = substr($line, strpos($line, '10:00:00 ') + 9);
            $path = $staging . '/' . $name;
            if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
            file_put_contents($path, 'fake passive module/library');
        }
        link($staging . '/usr/lib64/libcuda.so.1', $staging . '/usr/lib64/libcuda.so.595.58.02');
        $archive = $f->root . '/fixture.txz'; $real = new Vgpu\Process();
        $built = $real(['tar', '--create', '--xz', '--owner=0', '--group=0', '--file', $archive, '--directory', $staging, '.'], 30);
        download_check($built['code'] === 0, 'Cannot create passive TAR fixture');
        $f->package = file_get_contents($archive); $f->refreshRoutes();
        $download = new Download($f->paths, static function (array $argv, int $timeout = 15, ?string $stdin = null) use ($f, $real): array {
            return in_array($argv[0], ['curl', 'modinfo'], true) ? $f->run($argv, $timeout, $stdin) : $real($argv, $timeout, $stdin);
        });
        $download->fetch($f->source, $f->credentials, '6.18.58');
    });
    echo 'Download: ' . $passed . " cases passed; no network, installer, service, or GPU command executed.\n";
} finally {
    foreach ($fixtures as $item) $item->cleanup();
}
