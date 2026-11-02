<?php
declare(strict_types=1);

namespace Vgpu;

/** Authenticated download only. This class never installs packages or touches a GPU. */
final class Download
{
    private Paths $paths;
    private $runner;
    private const MAX_PACKAGE = 2147483648;

    public function __construct(Paths $paths, ?callable $runner = null)
    {
        $this->paths = $paths;
        $this->runner = $runner ?? new Process();
    }

    public function test(array $source, array $credentials, string $kernel): array
    {
        $result = $this->versions($source, $credentials, $kernel);
        return ['ok' => true, 'message' => 'Source authentication and matching package metadata verified',
            'versions' => $result['versions']];
    }

    public function versions(array $source, array $credentials, string $kernel): array
    {
        $context = $this->context($source, $credentials, $kernel, false);
        $candidates = $context['mode'] === 'generic' ? $this->candidates($context) : $this->releaseList($context);
        return ['ok' => true, 'versions' => array_map(static function (array $item): array {
            return array_intersect_key($item, array_flip(['driver', 'kernel', 'tag', 'filename']));
        }, $candidates)];
    }

    public function fetch(array $source, array $credentials, string $kernel): array
    {
        $context = $this->context($source, $credentials, $kernel);
        $selected = null;
        foreach ($this->candidates($context) as $candidate) {
            if ($candidate['driver'] === $context['driver']) $selected = $candidate;
        }
        if ($selected === null) throw new \RuntimeException('The selected driver is unavailable; no fallback was attempted');
        $md5 = $this->checksum($context, $selected);
        $directory = $this->paths->cacheDir . '/' . $context['kernel'];
        $this->directory($directory);
        $destination = $directory . '/' . $selected['filename'];
        if (is_link($destination)) throw new \RuntimeException('Package cache target is a symbolic link');
        if (is_file($destination)) {
            if (hash_file('md5', $destination) !== $md5) throw new \RuntimeException('Existing cached package differs; it was preserved');
            $this->validateArchive($destination, $context);
        } else {
            $temporary = $this->request($selected['url'], $context, self::MAX_PACKAGE, $directory, $selected['accept']);
            try {
                if (hash_file('md5', $temporary) !== $md5) throw new \RuntimeException('Package MD5 mismatch; existing packages were preserved');
                $this->validateArchive($temporary, $context);
                // Callers hold Store::locked(). Same-directory rename also works
                // on Unraid's FAT flash drive, where hard links are unavailable.
                if (file_exists($destination) || is_link($destination) || !@rename($temporary, $destination)) {
                    throw new \RuntimeException('Cannot publish package cache without overwriting another download');
                }
                chmod($destination, 0600);
            } finally {
                @unlink($temporary);
            }
        }
        $record = $md5 . '  ' . $selected['filename'] . "\n";
        $checksumFile = $destination . '.md5';
        if (is_link($checksumFile)) throw new \RuntimeException('Checksum cache target is a symbolic link');
        if (is_file($checksumFile)) {
            if (file_get_contents($checksumFile) !== $record) throw new \RuntimeException('Existing checksum record differs; it was preserved');
        } else {
            $temporary = $this->temporary($directory);
            try {
                if (file_put_contents($temporary, $record) !== strlen($record) || file_exists($checksumFile) ||
                    is_link($checksumFile) || !@rename($temporary, $checksumFile)) {
                    throw new \RuntimeException('Cannot publish checksum record');
                }
            } finally { @unlink($temporary); }
        }
        return ['ok' => true, 'package' => ['path' => $destination, 'filename' => $selected['filename'],
            'kernel' => $context['kernel'] . '-Unraid', 'driver' => $selected['driver'], 'md5' => $md5]];
    }

    private function context(array $source, array $credentials, string $kernel, bool $requireDriver = true): array
    {
        $kernel = preg_replace('/-Unraid$/', '', $kernel);
        $driver = (string)($source['driver'] ?? '');
        $provider = $source['provider'] ?? '';
        $mode = $source['mode'] ?? 'releases';
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $kernel)) throw new \InvalidArgumentException('Explicit X.Y.Z kernel version is required');
        if ($mode === 'generic' && $driver === '') throw new \InvalidArgumentException('Generic requires an explicit driver version; automatic discovery is unavailable');
        if (($requireDriver || $driver !== '') && !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $driver)) {
            throw new \InvalidArgumentException('An explicit X.Y.Z driver version is required; latest is not selected automatically');
        }
        if (!in_array($provider, ['github', 'gitlab'], true) || !in_array($mode, ['releases', 'generic'], true) ||
            ($provider === 'github' && $mode !== 'releases')) throw new \InvalidArgumentException('Unsupported source provider/mode');
        $repository = $this->url((string)($source['repository'] ?? ''));
        $parts = parse_url($repository);
        if (isset($parts['query'])) throw new \InvalidArgumentException('Repository URL must not contain a query');
        $project = trim((string)($parts['path'] ?? ''), '/');
        $project = preg_replace('/\.git$/', '', $project);
        $segments = explode('/', $project);
        if (count($segments) < 2 || ($provider === 'github' && count($segments) !== 2)) throw new \InvalidArgumentException('Invalid repository path');
        foreach ($segments as $segment) {
            if (!preg_match('/^[A-Za-z0-9_.-]+$/D', $segment) || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Invalid repository path');
            }
        }
        $origin = $this->origin($repository);
        $authOrigin = $origin;
        if ($provider === 'github') {
            $api = (strtolower($parts['host']) === 'github.com' && !isset($parts['port']))
                ? 'https://api.github.com/repos/' . $project : $origin . '/api/v3/repos/' . $project;
            $authOrigin = $this->origin($api);
        } else {
            $id = (string)($source['project_id'] ?? '');
            if ($id !== '' && !preg_match('/^[1-9][0-9]*$/D', $id)) throw new \InvalidArgumentException('GitLab project_id must be numeric');
            $api = $origin . '/api/v4/projects/' . ($id !== '' ? $id : rawurlencode($project));
        }
        $type = (string)($credentials['type'] ?? 'none');
        if (!in_array($type, ['none', 'bearer', 'private-token', 'deploy-token'], true) ||
            ($provider === 'github' && !in_array($type, ['none', 'bearer'], true))) throw new \InvalidArgumentException('Unsupported authentication type');
        if ($type === 'deploy-token' && $mode !== 'generic') throw new \InvalidArgumentException('A deploy token cannot query the GitLab Releases API; select Generic with an explicit version');
        $token = $type === 'none' ? '' : (string)($credentials['token'] ?? '');
        if ($type !== 'none' && ($token === '' || strlen($token) > 4096 || preg_match('/[\x00-\x20\x7f]/', $token))) throw new \InvalidArgumentException('Missing or invalid authentication token');
        $tag = $this->template((string)($source['tag_template'] ?? 'v{kernel}-{driver}'), $kernel, $driver);
        return compact('source', 'provider', 'mode', 'kernel', 'driver', 'api', 'authOrigin', 'type', 'token', 'tag');
    }

    private function candidates(array $context): array
    {
        if ($context['mode'] === 'generic') {
            $name = (string)($context['source']['package_name'] ?? 'build');
            if (!preg_match('/^[A-Za-z0-9_.+-]+$/D', $name) || $name === '.' || $name === '..') throw new \InvalidArgumentException('Invalid Generic package name');
            $version = $this->template((string)($context['source']['package_version_template'] ?? 'v{kernel}-{driver}'), $context['kernel'], $context['driver']);
            $filename = 'nvidia-' . $context['driver'] . '-' . $context['kernel'] . '-Unraid-1.txz';
            $base = $context['api'] . '/packages/generic/' . rawurlencode($name) . '/' . rawurlencode($version) . '/';
            $candidate = ['driver' => $context['driver'], 'kernel' => $context['kernel'] . '-Unraid', 'tag' => $version,
                'filename' => $filename, 'url' => $base . rawurlencode($filename), 'md5_url' => $base . rawurlencode($filename . '.md5'), 'accept' => 'application/octet-stream'];
            // A deploy token may read an explicit file, not enumerate Releases.
            $candidate['md5'] = $this->checksum($context, $candidate);
            return [$candidate];
        }
        $endpoint = $context['provider'] === 'github' ? '/releases/tags/' : '/releases/';
        $data = $this->json($context['api'] . $endpoint . rawurlencode($context['tag']), $context);
        $result = $this->releaseAssets($data, $context, $context['tag']);
        if (!$result) throw new \RuntimeException('No canonical driver/MD5 pair matches the running kernel');
        return $result;
    }

    private function releaseList(array $context): array
    {
        $result = [];
        // Discovery is bounded; it never silently downloads the newest version.
        for ($page = 1; $page <= 3; ++$page) {
            $releases = $this->json($context['api'] . '/releases?per_page=100&page=' . $page, $context);
            if (!array_is_list($releases)) throw new \RuntimeException('Invalid Release list response');
            foreach ($releases as $release) {
                if (!is_array($release) || !is_string($release['tag_name'] ?? null) || !empty($release['draft'])) continue;
                foreach ($this->releaseAssets($release, $context, $release['tag_name']) as $candidate) {
                    $expectedTag = $this->template((string)($context['source']['tag_template'] ?? 'v{kernel}-{driver}'), $context['kernel'], $candidate['driver']);
                    if ($candidate['tag'] === $expectedTag) $result[$candidate['driver']] = $candidate;
                }
            }
            if (count($releases) < 100) break;
            if ($page === 3) throw new \RuntimeException('Release discovery exceeded 300 entries; narrow the source or use an explicit Generic version');
        }
        if (!$result) throw new \RuntimeException('No canonical driver/MD5 pair matches the running kernel');
        $result = array_values($result);
        usort($result, static fn(array $a, array $b): int => version_compare($a['driver'], $b['driver']));
        return $result;
    }

    private function releaseAssets(array $data, array $context, string $tag): array
    {
        $assets = $context['provider'] === 'github' ? ($data['assets'] ?? null) : ($data['assets']['links'] ?? null);
        if (!is_array($assets)) throw new \RuntimeException('Release response has no asset list');
        $byName = [];
        foreach ($assets as $asset) {
            if (!is_array($asset) || !isset($asset['name']) || !is_string($asset['name'])) continue;
            $name = $asset['name'];
            if (!preg_match('/^nvidia-[0-9]+\.[0-9]+\.[0-9]+-' . preg_quote($context['kernel'], '/') . '-Unraid-1\.txz(?:\.md5)?$/D', $name)) continue;
            if (isset($byName[$name])) throw new \RuntimeException('Release contains duplicate matching assets');
            if ($context['provider'] === 'github') {
                $id = $asset['id'] ?? null;
                if (!is_int($id) || $id < 1) throw new \RuntimeException('Invalid GitHub release asset ID');
                $url = $context['api'] . '/releases/assets/' . $id;
            } else {
                $url = $this->url((string)($asset['url'] ?? ''));
            }
            $byName[$name] = $url;
        }
        $result = [];
        foreach ($byName as $name => $url) {
            if (str_ends_with($name, '.md5')) continue;
            if (!isset($byName[$name . '.md5'])) throw new \RuntimeException('Matching driver asset has no MD5 attachment');
            preg_match('/^nvidia-([0-9]+\.[0-9]+\.[0-9]+)-/', $name, $match);
            $result[] = ['driver' => $match[1], 'kernel' => $context['kernel'] . '-Unraid', 'tag' => $tag,
                'filename' => $name, 'url' => $url, 'md5_url' => $byName[$name . '.md5'], 'accept' => 'application/octet-stream'];
        }
        usort($result, static fn(array $a, array $b): int => version_compare($a['driver'], $b['driver']));
        return $result;
    }

    private function checksum(array $context, array $candidate): string
    {
        if (isset($candidate['md5'])) return $candidate['md5'];
        $path = $this->request($candidate['md5_url'], $context, 4096, null, $candidate['accept']);
        try { $body = (string)file_get_contents($path); } finally { @unlink($path); }
        if (!preg_match('/^([a-fA-F0-9]{32})[ \t]+\*?' . preg_quote($candidate['filename'], '/') . '\r?\n?$/D', $body, $match)) {
            throw new \RuntimeException('Malformed MD5 attachment or unexpected filename');
        }
        return strtolower($match[1]);
    }

    private function json(string $url, array $context): array
    {
        $path = $this->request($url, $context, 8388608, null, 'application/json');
        try { $body = (string)file_get_contents($path); } finally { @unlink($path); }
        try { $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new \RuntimeException('Source did not return valid JSON metadata'); }
        if (!is_array($data)) throw new \RuntimeException('Invalid source metadata');
        return $data;
    }

    private function request(string $url, array $context, int $limit, ?string $directory = null, string $accept = 'application/octet-stream'): string
    {
        $temporary = $this->temporary($directory ?? $this->paths->runtimeDir);
        $authenticated = $this->origin($url) === $context['authOrigin'];
        try {
            for ($redirect = 0; $redirect <= 5; ++$redirect) {
                $url = $this->url($url);
                $config = 'url = ' . $this->quote($url) . "\n";
                $config .= 'header = ' . $this->quote('Accept: ' . $accept) . "\n";
                if ($authenticated && $context['type'] !== 'none') {
                    $header = match ($context['type']) {
                        'bearer' => 'Authorization: Bearer ', 'private-token' => 'PRIVATE-TOKEN: ', 'deploy-token' => 'DEPLOY-TOKEN: '
                    };
                    $config .= 'header = ' . $this->quote($header . $context['token']) . "\n";
                }
                $timeout = $limit > 8388608 ? 900 : 30;
                $result = ($this->runner)(['curl', '--disable', '--silent', '--show-error', '--proto', '=https', '--connect-timeout', '8',
                    '--max-time', (string)$timeout, '--max-filesize', (string)$limit, '--output', $temporary,
                    '--write-out', "%{http_code}\n%{content_type}\n%{redirect_url}\n", '--config', '-'], $timeout + 5, $config);
                if (($result['code'] ?? -1) !== 0) throw new \RuntimeException('Download transport failed (code ' . (int)($result['code'] ?? -1) . '); credentials and response details omitted');
                $fields = explode("\n", (string)($result['stdout'] ?? ''), 4);
                $status = (int)($fields[0] ?? 0);
                if (in_array($status, [301, 302, 303, 307, 308], true)) {
                    $next = $this->url((string)($fields[2] ?? ''));
                    // Once a request leaves the configured origin, never reattach its token.
                    $authenticated = $authenticated && $this->origin($next) === $this->origin($url);
                    $url = $next;
                    continue;
                }
                if ($status !== 200) throw new \RuntimeException('Download HTTP ' . $status . '; verify source permissions/version (no fallback)');
                if (stripos((string)($fields[1] ?? ''), 'text/html') !== false) throw new \RuntimeException('Source returned an HTML login/error page instead of an artifact');
                clearstatcache(true, $temporary);
                $size = filesize($temporary);
                if ($size === false || $size < 1 || $size > $limit) throw new \RuntimeException('Empty or oversized download');
                $handle = fopen($temporary, 'rb');
                $head = $handle ? fread($handle, 256) : false;
                if ($handle) fclose($handle);
                if ($head === false || preg_match('/^\s*(?:<!doctype\s+html|<html)/i', $head)) throw new \RuntimeException('Source returned HTML or unreadable content');
                return $temporary;
            }
            throw new \RuntimeException('Too many source redirects');
        } catch (\Throwable $error) { @unlink($temporary); throw $error; }
    }

    private function validateArchive(string $path, array $context): void
    {
        $handle = fopen($path, 'rb');
        $magic = $handle ? fread($handle, 6) : false;
        if ($handle) fclose($handle);
        if ($magic !== "\xfd7zXZ\x00") throw new \RuntimeException('Package is not an XZ archive');
        $this->command(['xz', '--test', '--', $path], 120);
        $listing = $this->command(['tar', '--list', '--verbose', '--numeric-owner', '--full-time', '--quoting-style=escape', '--xz', '--file', $path], 180);
        if (strlen($listing) >= 2097152) throw new \RuntimeException('Archive listing is oversized or truncated');
        $entries = []; $links = []; $rawNames = []; $sizes = [];
        foreach (explode("\n", trim($listing)) as $line) {
            if (!preg_match('/^([-dlh][rwxstST-]{9})\s+([0-9]+)\/([0-9]+)\s+([0-9]+)\s+[0-9-]+\s+[0-9:.]+\s+(.+)$/D', $line, $match)) {
                throw new \RuntimeException('Unsupported or unsafe TAR entry');
            }
            if ($match[2] !== '0' || $match[3] !== '0') throw new \RuntimeException('Driver archive must contain root-owned files');
            $name = $match[5]; $link = null;
            if ($match[1][0] === 'l') {
                $pair = explode(' -> ', $name, 2);
                if (count($pair) !== 2) throw new \RuntimeException('Malformed symbolic link');
                [$name, $link] = $pair;
            } elseif ($match[1][0] === 'h') {
                $pair = explode(' link to ', $name, 2);
                if (count($pair) !== 2) throw new \RuntimeException('Malformed hard link');
                [$name, $link] = $pair;
            }
            $rawName = $name;
            $name = $this->archivePath($name);
            if ($name === '') continue;
            if (isset($entries[$name])) throw new \RuntimeException('Duplicate archive target');
            if ($link !== null) {
                $links[$name] = $match[1][0] === 'h' ? $this->archivePath($link) : $this->symbolicTarget($name, $link);
            }
            if (preg_match('#^lib/modules/([^/]+)/#', $name, $module) && $module[1] !== $context['kernel'] . '-Unraid') {
                throw new \RuntimeException('Driver archive contains modules for another kernel');
            }
            $entries[$name] = $match[1][0];
            $rawNames[$name] = $rawName;
            $sizes[$name] = (int)$match[4];
        }
        foreach ($entries as $name => $type) {
            $parent = dirname($name);
            while ($parent !== '.') {
                if (isset($entries[$parent]) && $entries[$parent] !== 'd') throw new \RuntimeException('Archive member traverses a non-directory parent');
                $parent = dirname($parent);
            }
        }
        $prefix = 'lib/modules/' . $context['kernel'] . '-Unraid/kernel/drivers/video/';
        $modules = ['nvidia', 'nvidia-modeset', 'nvidia-drm', 'nvidia-uvm', 'nvidia-vgpu-vfio'];
        $members = [];
        foreach ($modules as $module) {
            $member = $prefix . $module . '.ko';
            if (($entries[$member] ?? '') !== '-' || ($sizes[$member] ?? 0) < 1) throw new \RuntimeException('Required regular vGPU module is missing or empty');
            $members[$member] = $rawNames[$member];
        }
        foreach (['usr/bin/nvidia-vgpud', 'usr/bin/nvidia-vgpu-mgr', 'usr/lib64/libvgpu_unlock_rs.so',
            'usr/share/nvidia/vgpu/vgpuConfig.xml', 'usr/lib64/libcuda.so.1', 'usr/lib64/libnvcuvid.so.1',
            'usr/lib64/libnvidia-encode.so.1', 'usr/lib64/libnvidia-ml.so.1'] as $required) {
            $resolved = $required; $visited = [];
            while (isset($links[$resolved])) {
                if (isset($visited[$resolved])) throw new \RuntimeException('Archive link cycle');
                $visited[$resolved] = true;
                $resolved = $links[$resolved];
            }
            if (($entries[$resolved] ?? '') !== '-' || ($sizes[$resolved] ?? 0) < 1) throw new \RuntimeException('Package is not a compatible merged vGPU/CUDA bundle');
        }
        $scratch = $this->temporary($this->paths->runtimeDir);
        unlink($scratch);
        if (!mkdir($scratch, 0700)) throw new \RuntimeException('Cannot create module verification directory');
        try {
            $this->command(array_merge(['tar', '--extract', '--xz', '--file', $path, '--directory', $scratch,
                '--no-same-owner', '--no-same-permissions', '--keep-old-files', '--'], array_values($members)), 180);
            foreach (array_keys($members) as $member) {
                $module = $scratch . '/' . $member;
                if (trim($this->command(['modinfo', '-F', 'version', $module])) !== $context['driver']) throw new \RuntimeException('Module driver version differs from selected artifact');
                $magic = trim($this->command(['modinfo', '-F', 'vermagic', $module]));
                if (!str_starts_with($magic, $context['kernel'] . '-Unraid ')) throw new \RuntimeException('Module vermagic differs from running kernel');
            }
        } finally { $this->removeTemporaryTree($scratch); }
    }

    private function command(array $argv, int $timeout = 30): string
    {
        $result = ($this->runner)($argv, $timeout, null);
        if (($result['code'] ?? -1) !== 0) throw new \RuntimeException('Passive package verification failed: ' . basename($argv[0]));
        return (string)($result['stdout'] ?? '');
    }

    private function archivePath(string $path): string
    {
        if (str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path) || str_starts_with($path, '/')) throw new \RuntimeException('Unsafe archive path');
        while (str_starts_with($path, './')) $path = substr($path, 2);
        $path = rtrim($path, '/');
        if ($path === '.') return '';
        if (in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true) || str_contains($path, '//')) throw new \RuntimeException('Unsafe archive path');
        return $path;
    }

    private function symbolicTarget(string $name, string $target): string
    {
        if ($target === '' || str_contains($target, '\\') || preg_match('/[\x00-\x1f\x7f]/', $target)) throw new \RuntimeException('Unsafe archive symlink');
        if (preg_match('#^/(?:tmp|home|work|vgpu-build|mnt)/#', $target)) throw new \RuntimeException('Build-staging symlink in package');
        $parts = str_starts_with($target, '/') || dirname($name) === '.' ? [] : explode('/', dirname($name));
        foreach (explode('/', $target) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                if (!$parts) throw new \RuntimeException('Archive symlink escapes package root');
                array_pop($parts);
            } else $parts[] = $part;
        }
        return implode('/', $parts);
    }

    private function template(string $value, string $kernel, string $driver): string
    {
        $value = str_replace(['{kernel}', '{driver}'], [$kernel, $driver], $value);
        if ($value === '' || strlen($value) > 200 || !preg_match('#^[A-Za-z0-9._+/-]+$#D', $value) || in_array('..', explode('/', $value), true)) throw new \InvalidArgumentException('Invalid source version/tag template');
        return $value;
    }

    private function url(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('Only credential-free HTTPS source URLs are allowed');
        }
        return $url;
    }

    private function origin(string $url): string
    {
        $parts = parse_url($this->url($url));
        return 'https://' . strtolower($parts['host']) . (isset($parts['port']) && $parts['port'] !== 443 ? ':' . $parts['port'] : '');
    }

    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function directory(string $path): void
    {
        if (is_link($path) || (!is_dir($path) && !mkdir($path, 0700, true))) throw new \RuntimeException('Cannot create private cache/runtime directory');
    }

    private function temporary(string $directory): string
    {
        $this->directory($directory);
        $path = tempnam($directory, '.download-');
        if ($path === false) throw new \RuntimeException('Cannot create temporary download');
        chmod($path, 0600);
        return $path;
    }

    private function removeTemporaryTree(string $path): void
    {
        // Only our freshly-created module-verification directory is passed here.
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname());
            else @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
