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

    /** Return deliberately unsorted builds under one release. */
    public function builds(array $builds = [10, 1, 2], string $provider = 'gitlab'): void
    {
        $assets = [];
        foreach ($builds as $index => $build) {
            $filename = 'nvidia-595.58.03-6.18.58-Unraid-' . $build . '.txz';
            $version = 'v6.18.58-595.58.03' . ($build > 1 ? '-build' . $build : '');
            $id = 200 + 2 * $index;
            $packageUrl = $provider === 'github'
                ? 'https://api.github.com/repos/owner/repo/releases/assets/' . $id
                : 'https://git.example.test/api/v4/projects/42/packages/generic/build/' . $version . '/' . $filename;
            $md5Url = $provider === 'github'
                ? 'https://api.github.com/repos/owner/repo/releases/assets/' . ($id + 1)
                : $packageUrl . '.md5';
            $assets[] = $provider === 'github' ? ['name' => $filename, 'id' => $id] : ['name' => $filename, 'url' => $packageUrl];
            $assets[] = $provider === 'github' ? ['name' => $filename . '.md5', 'id' => $id + 1] : ['name' => $filename . '.md5', 'url' => $md5Url];
            $this->routes[$packageUrl] = ['body' => $this->package];
            $this->routes[$md5Url] = ['body' => md5($this->package) . '  ' . $filename . "\n"];
        }
        $release = $provider === 'github' ? ['assets' => $assets] : ['assets' => ['links' => $assets]];
        $release['tag_name'] = 'v6.18.58-595.58.03';
        $this->routes[$this->releaseUrl] = ['body' => json_encode($release), 'type' => 'application/json'];
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

    public function automatic(bool $kernelRelease = true): string
    {
        $kernelUrl = str_replace('v6.18.58-595.58.03', '6.18.58-Unraid', $this->releaseUrl);
        $release = json_decode($this->routes[$this->releaseUrl]['body'], true);
        $release['tag_name'] = '6.18.58-Unraid';
        $this->routes[$kernelUrl] = $kernelRelease
            ? ['body' => json_encode($release), 'type' => 'application/json']
            : ['status' => 404];
        $this->source['url'] = $this->source['repository'];
        $this->source['provider'] = 'auto';
        $this->source['mode'] = 'auto';
        $this->source['driver'] = '';
        $this->credentials['type'] = 'auto';
        return $kernelUrl;
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
            if (is_callable($route)) $route = $route((string)$stdin);
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
    $case('Repository links automatically use kernel releases on both platforms', function () use ($fixture): void {
        foreach (['github', 'gitlab'] as $provider) {
            $f = $fixture($provider); $kernelUrl = $f->automatic();
            $versions = $f->download->versions($f->source, $f->credentials, '6.18.58')['versions'];
            download_check(count($versions) === 1 && $versions[0]['tag'] === '6.18.58-Unraid', 'Kernel release was not used');
            download_check(str_contains($f->calls[0]['stdin'], $kernelUrl), 'Automatic lookup should target the running kernel directly');
            $f->download->fetch($f->source, $f->credentials, '6.18.58');
            foreach ($f->calls as $call) if ($call['argv'][0] === 'curl') download_check(
                str_contains($call['stdin'], $provider === 'github' ? 'Authorization: Bearer fixture-secret' : 'PRIVATE-TOKEN: fixture-secret'), 'Automatic token header mismatch');
        }
    });
    $case('Missing kernel tag discovers the old versioned release layout', function () use ($fixture): void {
        foreach (['github', 'gitlab'] as $provider) {
            $f = $fixture($provider); $f->automatic(false);
            $result = $f->download->fetch($f->source, $f->credentials, '6.18.58');
            download_check($result['package']['driver'] === '595.58.03', 'Legacy published package was not resolved');
        }
    });
    $case('Automatic lookup does not hide authentication errors with a legacy fallback', function () use ($fixture): void {
        foreach ([401, 403] as $status) {
            $f = $fixture(); $kernelUrl = $f->automatic(); $f->routes[$kernelUrl] = ['status' => $status];
            download_fails(fn() => $f->download->versions($f->source, $f->credentials, '6.18.58'), 'HTTP ' . $status);
            download_check(count($f->calls) === 1, 'Authentication failure triggered another source lookup');
        }
    });
    $case('Repository plus deploy token resolves only the installed driver through Generic', function () use ($fixture): void {
        $f = $fixture(); $kernelUrl = $f->automatic();
        $f->routes[$kernelUrl] = ['status' => 401];
        mkdir($f->root . '/sys/module/nvidia', 0700, true);
        file_put_contents($f->root . '/sys/module/nvidia/version', '595.58.03');
        $route = $f->routes[$f->md5Url];
        $f->routes[$f->md5Url] = static fn(string $stdin): array => str_contains($stdin, 'DEPLOY-TOKEN:') ? $route : ['status' => 403];
        $route = $f->routes[$f->packageUrl];
        $f->routes[$f->packageUrl] = static fn(string $stdin): array => str_contains($stdin, 'DEPLOY-TOKEN:') ? $route : ['status' => 401];
        $versions = $f->download->versions($f->source, $f->credentials, '6.18.58')['versions'];
        download_check(count($versions) === 1 && $versions[0]['driver'] === '595.58.03' && $versions[0]['discovery'] === 'current-driver', 'Known installed driver must not be advertised as latest discovery');
        $result = $f->download->fetch($f->source, $f->credentials, '6.18.58');
        download_check($result['package']['discovery'] === 'current-driver', 'Download lost its limited discovery provenance');
        foreach ($f->calls as $call) if ($call['argv'][0] === 'curl') download_check(!str_contains($call['stdin'], 'per_page=') && !str_contains($call['stdin'], '580.'), 'Deploy lookup enumerated versions or guessed another driver');
    });
    $case('Known Generic fallback retains a missing-package error and never guesses versions', function () use ($fixture): void {
        $f = $fixture(); $kernelUrl = $f->automatic(); $f->source['driver'] = '595.58.03';
        $f->routes[$kernelUrl] = ['status' => 403]; $f->routes[$f->md5Url] = ['status' => 404];
        download_fails(fn() => $f->download->versions($f->source, $f->credentials, '6.18.58'), 'HTTP 404');
        download_check(count($f->calls) === 2, 'A missing known Generic package caused another guess');
    });
    $case('Explicit deploy token avoids Releases API and requires a known driver or link', function () use ($fixture): void {
        $f = $fixture(); $f->automatic(); $f->credentials['type'] = 'deploy-token';
        download_fails(fn() => $f->download->versions($f->source, $f->credentials, '6.18.58'), 'known driver');
        download_check($f->calls === [], 'Unknown driver caused unauthorized metadata probing');
        $f->source['driver'] = '595.58.03';
        $versions = $f->download->versions($f->source, $f->credentials, '6.18.58')['versions'];
        download_check($versions[0]['discovery'] === 'current-driver', 'Explicit deploy Generic result lacks provenance');
        foreach ($f->calls as $call) download_check(str_contains($call['stdin'], '/packages/generic/') && str_contains($call['stdin'], 'DEPLOY-TOKEN:'), 'Explicit deploy token was sent to ordinary Releases API');
    });
    $case('Automatic selection prefers the installed driver and otherwise newest matching version', function () use ($fixture): void {
        foreach ([false, true] as $installed) {
            $f = $fixture(); $kernelUrl = $f->automatic();
            $data = json_decode($f->routes[$kernelUrl]['body'], true);
            $older = json_decode(str_replace('595.58.03', '580.159.03', json_encode($data['assets']['links'])), true);
            $data['assets']['links'] = array_merge($data['assets']['links'], $older);
            $f->routes[$kernelUrl]['body'] = json_encode($data);
            $oldName = str_replace('595.58.03', '580.159.03', $f->filename);
            $oldUrl = str_replace('595.58.03', '580.159.03', $f->packageUrl);
            $f->routes[$oldUrl] = ['body' => $f->package];
            $f->routes[$oldUrl . '.md5'] = ['body' => md5($f->package) . '  ' . $oldName . "\n"];
            if ($installed) {
                mkdir($f->root . '/sys/module/nvidia', 0700, true);
                file_put_contents($f->root . '/sys/module/nvidia/version', '580.159.03');
                $f->moduleVersion = '580.159.03';
            }
            $result = $f->download->fetch($f->source, $f->credentials, '6.18.58');
            download_check($result['package']['driver'] === ($installed ? '580.159.03' : '595.58.03'), 'Wrong automatic driver preference');
        }
    });
    $case('Pasted release and GitHub download links resolve their exact assets', function () use ($fixture): void {
        foreach (['github', 'gitlab'] as $provider) {
            $f = $fixture($provider); $f->source['mode'] = 'auto'; $f->source['driver'] = '';
            $f->source['url'] = $provider === 'github'
                ? 'https://github.com/owner/repo/releases/tag/v6.18.58-595.58.03'
                : 'https://git.example.test/owner/repo/-/releases/v6.18.58-595.58.03';
            $f->download->fetch($f->source, $f->credentials, '6.18.58');
            download_check(str_contains($f->calls[0]['stdin'], $f->releaseUrl), 'Pasted release should not enumerate or use a different tag');
        }
        $f = $fixture('github'); $f->source['mode'] = 'auto'; $f->source['driver'] = '';
        $f->source['url'] = 'https://github.com/owner/repo/releases/download/v6.18.58-595.58.03/' . $f->filename;
        $f->download->fetch($f->source, $f->credentials, '6.18.58');
        foreach ($f->calls as $call) if ($call['argv'][0] === 'curl') download_check(!str_contains($call['stdin'], 'url = "https://github.com/'), 'Private GitHub assets must use the API, not a webpage redirect');
    });
    $case('Generic TXZ link accepts a deploy token with automatic header selection', function () use ($fixture): void {
        $f = $fixture('gitlab', 'generic');
        $f->source['url'] = $f->packageUrl; $f->source['mode'] = 'auto'; $f->source['provider'] = 'auto'; $f->source['driver'] = '';
        $f->credentials['type'] = 'auto';
        $route = $f->routes[$f->md5Url];
        $f->routes[$f->md5Url] = static fn(string $stdin): array => str_contains($stdin, 'DEPLOY-TOKEN:') ? $route : ['status' => 401];
        $route = $f->routes[$f->packageUrl];
        $f->routes[$f->packageUrl] = static fn(string $stdin): array => str_contains($stdin, 'DEPLOY-TOKEN:') ? $route : ['status' => 403];
        $f->download->fetch($f->source, $f->credentials, '6.18.58');
        foreach ($f->calls as $call) if ($call['argv'][0] === 'curl') download_check(str_contains($call['stdin'], '/packages/generic/'), 'Direct link unexpectedly enumerated a repository');
        download_check(count(array_filter($f->calls, static fn(array $call): bool => $call['argv'][0] === 'curl')) === 4, 'Automatic authentication did not retry the two explicit package reads once');
    });
    $case('Direct link auto-auth never retries 404 or forwards credentials across origins', function () use ($fixture): void {
        $f = $fixture('gitlab', 'generic'); $f->source['url'] = $f->packageUrl; $f->source['mode'] = 'auto'; $f->credentials['type'] = 'auto';
        $f->routes[$f->md5Url] = ['status' => 404];
        download_fails(fn() => $f->download->fetch($f->source, $f->credentials, '6.18.58'), 'HTTP 404');
        download_check(count($f->calls) === 1, '404 caused an authentication retry');
        $f = $fixture('gitlab', 'generic'); $f->source['url'] = $f->packageUrl; $f->source['mode'] = 'auto'; $f->credentials['type'] = 'auto';
        $f->routes[$f->md5Url] = ['status' => 302, 'redirect' => 'https://cdn.example.test/md5'];
        $f->routes['https://cdn.example.test/md5'] = ['status' => 401];
        download_fails(fn() => $f->download->fetch($f->source, $f->credentials, '6.18.58'), 'HTTP 401');
        $call = $f->calls[array_key_last($f->calls)];
        download_check(!str_contains($call['stdin'], 'fixture-secret'), 'Redirect authentication boundary changed');
    });
    $case('Bare MD5 attachments are accepted without weakening filename checks', function () use ($fixture): void {
        $f = $fixture(); $f->routes[$f->md5Url]['body'] = md5($f->package) . "\n";
        $result = $f->download->fetch($f->source, $f->credentials, '6.18.58');
        download_check($result['package']['checksum_verified'] === true, 'Remote checksum was not marked verified');
    });
    $case('Local import computes MD5 and checks the archive without network or installation', function () use ($fixture): void {
        $f = $fixture(); $path = $f->root . '/uploaded.txz'; file_put_contents($path, $f->package);
        $result = $f->download->importLocal($path, $f->filename, null, '6.18.58-Unraid');
        download_check($result['package']['source'] === 'local' && $result['package']['checksum_verified'] === false, 'Local calculated digest was incorrectly treated as external verification');
        download_check(file_get_contents($result['package']['path']) === $f->package && is_file($path), 'Local import must copy the file, not consume the upload');
        foreach ($f->calls as $call) download_check(in_array($call['argv'][0], ['tar', 'xz', 'modinfo'], true), 'Import executed a network or driver command');
    });
    $case('Local import accepts optional MD5 and rejects wrong kernel, checksum or archive', function () use ($fixture): void {
        $f = $fixture(); $path = $f->root . '/uploaded.txz'; $md5 = $f->root . '/uploaded.md5'; file_put_contents($path, $f->package);
        file_put_contents($md5, md5($f->package) . "\n");
        download_check($f->download->importLocal($path, $f->filename, $md5, '6.18.58')['package']['checksum_verified'] === true, 'Optional MD5 not verified');
        download_fails(fn() => $f->download->importLocal($path, $f->filename, null, '6.18.54'), 'another kernel');
        file_put_contents($md5, str_repeat('0', 32));
        download_fails(fn() => $f->download->importLocal($path, $f->filename, $md5, '6.18.58'), 'MD5 mismatch');
        file_put_contents($path, 'broken XZ');
        download_fails(fn() => $f->download->importLocal($path, $f->filename, null, '6.18.58'), 'preserved');
        download_check(file_get_contents($f->paths->cacheDir . '/6.18.58/' . $f->filename) === $f->package, 'Existing local cache was overwritten');
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
    $case('Release discovery preserves numeric build identities on both platforms', function () use ($fixture): void {
        foreach (['github', 'gitlab'] as $provider) {
            foreach ([false, true] as $automatic) {
                $f = $fixture($provider); $f->builds(provider: $provider);
                if ($automatic) $f->automatic();
                $versions = $f->download->versions($f->source, $f->credentials, '6.18.58')['versions'];
                download_check(array_column($versions, 'build') === [1, 2, 10], 'Builds were collapsed or sorted lexically');
                download_check(count(array_unique(array_column($versions, 'filename'))) === 3, 'Package identities are not distinct');
                $package = $f->download->fetch($f->source, $f->credentials, '6.18.58')['package'];
                download_check($package['build'] === 10 && str_ends_with($package['filename'], '-10.txz'), 'Highest same-driver build was not selected');
            }
        }
    });
    $case('Explicit build rollback preserves other cached builds and unavailable pin never falls back', function () use ($fixture): void {
        $f = $fixture(); $f->builds();
        $latest = $f->download->fetch($f->source, $f->credentials, '6.18.58')['package'];
        foreach (['2', '1'] as $build) {
            $f->source['build'] = $build;
            $package = $f->download->fetch($f->source, $f->credentials, '6.18.58')['package'];
            download_check($package['build'] === (int)$build, 'Explicit build pin was ignored');
            download_check(is_file($latest['path']) && file_get_contents($latest['path']) === $f->package, 'Latest cached build was replaced during rollback selection');
        }
        $f->source['build'] = '3';
        download_fails(fn() => $f->download->fetch($f->source, $f->credentials, '6.18.58'), 'no fallback');
        $f->source['driver'] = ''; $f->source['mode'] = 'auto'; $f->calls = [];
        download_fails(fn() => $f->download->versions($f->source, $f->credentials, '6.18.58'), 'explicit driver');
        download_check($f->calls === [], 'Build-only pin caused a network lookup');
    });
    $case('Installed driver preference keeps its branch when another driver has newer builds', function () use ($fixture): void {
        $f = $fixture(); $f->builds(); $kernelUrl = $f->automatic();
        $data = json_decode($f->routes[$kernelUrl]['body'], true);
        $old = json_decode(str_replace('595.58.03', '580.159.03', json_encode($data['assets']['links'])), true);
        $data['assets']['links'] = array_merge($data['assets']['links'], $old);
        $f->routes[$kernelUrl]['body'] = json_encode($data);
        foreach ($f->routes as $url => $route) {
            if (str_contains($url, '/packages/generic/')) $f->routes[str_replace('595.58.03', '580.159.03', $url)] =
                ['body' => str_replace('595.58.03', '580.159.03', $route['body'])];
        }
        mkdir($f->root . '/sys/module/nvidia', 0700, true);
        file_put_contents($f->root . '/sys/module/nvidia/version', '580.159.03');
        $f->moduleVersion = '580.159.03';
        $package = $f->download->fetch($f->source, $f->credentials, '6.18.58')['package'];
        download_check($package['driver'] === '580.159.03' && $package['build'] === 10, 'Automatic build selection jumped driver branches');
    });
    $case('New Generic builds use immutable buildN versions and legacy build1 remains compatible', function () use ($fixture): void {
        foreach (['', '1', '2', '10'] as $build) {
            $f = $fixture('gitlab', 'generic'); $f->builds(); $f->source['build'] = $build;
            $package = $f->download->fetch($f->source, $f->credentials, '6.18.58')['package'];
            $expected = $build === '' ? 1 : (int)$build;
            download_check($package['build'] === $expected, 'Generic build was not retained');
            foreach ($f->calls as $call) if ($call['argv'][0] === 'curl') {
                download_check(str_contains($call['stdin'], '/v6.18.58-595.58.03' . ($expected > 1 ? '-build' . $expected : '') . '/'), 'Generic package version mismatch');
                download_check(str_contains($call['stdin'], 'DEPLOY-TOKEN: fixture-secret'), 'Generic build request lost token');
            }
        }
        $f = $fixture('gitlab', 'generic'); $f->builds(); $f->source['build'] = '2';
        $f->source['package_version_template'] = 'v{kernel}-{driver}-build{build}';
        download_check($f->download->fetch($f->source, $f->credentials, '6.18.58')['package']['build'] === 2, 'Explicit build template failed');
    });
    $case('Known Generic fallback requests only the explicitly selected revision', function () use ($fixture): void {
        $f = $fixture(); $f->builds(); $kernelUrl = $f->automatic();
        $f->source['driver'] = '595.58.03'; $f->source['build'] = '2';
        $f->routes[$kernelUrl] = ['status' => 401];
        $package = $f->download->fetch($f->source, $f->credentials, '6.18.58')['package'];
        download_check($package['build'] === 2 && $package['discovery'] === 'current-driver', 'Generic fallback changed the revision');
        foreach ($f->calls as $call) if ($call['argv'][0] === 'curl' && str_contains($call['stdin'], '/packages/generic/')) {
            download_check(str_contains($call['stdin'], '/v6.18.58-595.58.03-build2/'), 'Fallback guessed another build');
        }
    });
    $case('Direct private build2 links preserve the build and reject conflicting pins before network', function () use ($fixture): void {
        foreach (['github', 'gitlab'] as $provider) {
            $f = $fixture($provider); $f->builds(provider: $provider); $f->source['mode'] = 'auto'; $f->source['driver'] = '';
            $name = 'nvidia-595.58.03-6.18.58-Unraid-2.txz';
            $f->source['url'] = $provider === 'github'
                ? 'https://github.com/owner/repo/releases/download/v6.18.58-595.58.03/' . $name
                : 'https://git.example.test/api/v4/projects/42/packages/generic/build/v6.18.58-595.58.03-build2/' . $name;
            $package = $f->download->fetch($f->source, $f->credentials, '6.18.58')['package'];
            download_check($package['build'] === 2 && $package['filename'] === $name, 'Direct link selected another build');
            $f->source['driver'] = '595.58.03'; $f->source['build'] = '1'; $f->calls = [];
            download_fails(fn() => $f->download->fetch($f->source, $f->credentials, '6.18.58'), 'different package build');
            download_check($f->calls === [], 'Conflicting direct build pin used the network');
        }
    });
    $case('Incomplete new build is not silently replaced with an old complete build', function () use ($fixture): void {
        $f = $fixture(); $f->builds([1, 2]);
        $data = json_decode($f->routes[$f->releaseUrl]['body'], true); array_pop($data['assets']['links']);
        $f->routes[$f->releaseUrl]['body'] = json_encode($data);
        download_fails(fn() => $f->download->fetch($f->source, $f->credentials, '6.18.58'), 'MD5 attachment');
        download_check(!is_dir($f->paths->cacheDir), 'Incomplete release unexpectedly populated cache');
    });
    $case('Local revision receipts are verified and legacy receipt compatibility is build1-only', function () use ($fixture): void {
        foreach ([1, 2, 10] as $build) {
            $f = $fixture(); $path = $f->root . '/uploaded.txz'; file_put_contents($path, $f->package);
            $name = 'nvidia-595.58.03-6.18.58-Unraid-' . $build . '.txz';
            $package = $f->download->importLocal($path, $name, null, '6.18.58')['package'];
            download_check($package['build'] === $build && $package['source'] === 'local', 'Local build identity lost');
            download_check($f->download->verifyCached($package, '6.18.58')['build'] === $build, 'Valid cache receipt rejected');
            $tampered = $package; $tampered['build'] = $build + 1;
            download_fails(fn() => $f->download->verifyCached($tampered, '6.18.58'), 'build differs');
            $legacy = $package; unset($legacy['build']);
            if ($build === 1) download_check($f->download->verifyCached($legacy, '6.18.58')['build'] === 1, 'Legacy build1 receipt was not normalized');
            else download_fails(fn() => $f->download->verifyCached($legacy, '6.18.58'), 'build is missing');
            foreach ($f->calls as $call) download_check(in_array($call['argv'][0], ['tar', 'xz', 'modinfo'], true), 'Local revision validation performed external work');
        }
    });
    echo 'Download: ' . $passed . " cases passed; no network, installer, service, or GPU command executed.\n";
} finally {
    foreach ($fixtures as $item) $item->cleanup();
}
