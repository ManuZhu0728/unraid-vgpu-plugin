<?php
declare(strict_types=1);

namespace Vgpu;

/** File inventory/cleanup only. Never chooses a boot package or runs GPU tools. */
final class PackageCache
{
    private $remove;

    public function __construct(private Store $store, ?callable $remove = null)
    {
        $this->remove = $remove ?? static fn(string $file): bool => @unlink($file);
    }

    public function listing(): array
    {
        try {
            $references = $this->references();
            $packages = [];
            foreach ($this->entries($this->store->paths->cacheDir) as $kernel) {
                if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $kernel)) continue;
                $directory = $this->store->paths->cacheDir . '/' . $kernel;
                foreach ($this->entries($directory) as $name) {
                    try { $identity = PackageIdentity::parse($name, $kernel); }
                    catch (\InvalidArgumentException) { continue; }
                    $reasons = array_values(array_unique($references[$name] ?? []));
                    $size = 0;
                    try {
                        $stat = $this->regular($directory . '/' . $name);
                        $size = $stat['size'];
                        if ($this->exists($directory . '/' . $name . '.md5')) $this->regular($directory . '/' . $name . '.md5');
                    } catch (\Throwable) { $reasons[] = '文件类型或路径异常'; }
                    $packages[] = $identity + ['size' => $size, 'references' => $reasons, 'deletable' => !$reasons];
                    if (count($packages) > 512) throw new \RuntimeException('缓存包数量过多，请先人工核对。');
                }
            }
            usort($packages, static fn(array $a, array $b): int => version_compare($b['kernel'], $a['kernel']) ?: version_compare($b['driver'], $a['driver']) ?: $b['build'] <=> $a['build']);
            return ['available' => true, 'packages' => $packages, 'message' => ''];
        } catch (\Throwable) {
            // A broken reference must never make an in-use package deletable.
            return ['available' => false, 'packages' => [], 'message' => '无法安全读取缓存或引用记录，已禁用删除。请核对配置、升级、安装和回退记录。'];
        }
    }

    /** Caller must hold Store::locked(), shared with download, upgrade and boot. */
    public function delete(string $filename): array
    {
        $identity = PackageIdentity::parse($filename);
        $inventory = $this->listing();
        if (!$inventory['available']) throw new \RuntimeException($inventory['message']);
        $matches = array_values(array_filter($inventory['packages'], static fn(array $row): bool => $row['filename'] === $filename));
        if (count($matches) !== 1) throw new \RuntimeException('未找到指定缓存包，请刷新列表核对。');
        if (!$matches[0]['deletable']) throw new \RuntimeException('不能删除此缓存包：' . implode('、', $matches[0]['references']) . '。');
        // The request supplies a canonical filename, never a filesystem path.
        $directory = $this->store->paths->cacheDir . '/' . substr($identity['kernel'], 0, -7);
        $this->safeParents($directory);
        $path = $directory . '/' . $filename;
        $checksum = $path . '.md5';
        $this->regular($path);
        if ($this->exists($checksum)) $this->regular($checksum);
        if (!(($this->remove)($path))) throw new \RuntimeException('驱动包删除失败，文件未移除。');
        $removed = [$filename];
        try {
            if ($this->exists($checksum)) {
                $this->regular($checksum);
                if (!(($this->remove)($checksum))) throw new \RuntimeException('MD5 cleanup failed');
                $removed[] = $filename . '.md5';
            }
        } catch (\Throwable) {
            return ['ok' => false, 'changed' => true, 'filename' => $filename, 'removed' => $removed,
                'message' => 'TXZ 已删除，但同名 MD5 清理失败。请核对缓存；需要恢复时请重新下载或上传驱动包。'];
        }
        return ['ok' => true, 'changed' => true, 'filename' => $filename, 'removed' => $removed,
            'message' => '已删除 ' . $filename . ' 及其同名 MD5（如存在）。此操作不可本地撤销，需要时请重新下载或上传。'];
    }

    private function references(): array
    {
        $result = [];
        $add = static function (mixed $package, string $label) use (&$result): void {
            if ($package === null) return;
            if (!is_array($package)) throw new \RuntimeException('Invalid package reference');
            $identity = PackageIdentity::receipt($package);
            $result[$identity['filename']][] = $label;
        };
        $config = $this->store->paths->configDir;
        $settings = $this->record($this->store->paths->settingsFile, 131072);
        $add($settings['selected_package'] ?? null, '当前选中');
        $upgrade = $this->record($config . '/upgrade-state.json', 131072);
        $add($upgrade['package'] ?? null, '升级预下载');
        $add($upgrade['baseline_package'] ?? null, '升级基线/回退');
        $installed = $this->record($config . '/driver-state.json', 65536);
        if (isset($installed['last_success'])) {
            if (!is_array($installed['last_success']) || !isset($installed['last_success']['package'])) throw new \RuntimeException('Invalid installed reference');
            $add($installed['last_success']['package'], '上次成功安装');
        }
        if (($installed['status'] ?? '') === 'installing') {
            if (!isset($installed['attempted_package'])) throw new \RuntimeException('Invalid installation attempt');
            $add($installed['attempted_package'], '安装任务引用');
        }
        foreach ($this->entries($config . '/package-history') as $name) {
            if (!preg_match('/^([0-9]+\.[0-9]+\.[0-9]+-Unraid)\.json$/D', $name, $match)) continue;
            $candidate = (new PackageHistory($this->store))->read($match[1]);
            if ($candidate === null) throw new \RuntimeException('History changed while reading');
            $add($candidate['package'], '内核回退记录');
        }
        return $result;
    }

    private function record(string $path, int $limit): array
    {
        $this->safeParents(dirname($path));
        if (!$this->exists($path)) return [];
        $stat = $this->regular($path);
        if ($stat['size'] > $limit) throw new \RuntimeException('Reference file is too large');
        $body = @file_get_contents($path, false, null, 0, $limit + 1);
        if ($body === false || strlen($body) > $limit) throw new \RuntimeException('Cannot read package reference');
        $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Invalid package reference file');
        return $data;
    }

    private function entries(string $directory): array
    {
        $this->safeParents($directory);
        if (!$this->exists($directory)) return [];
        $entries = @scandir($directory);
        if ($entries === false || count($entries) > 1024) throw new \RuntimeException('Cannot enumerate bounded cache directory');
        return array_values(array_filter($entries, static fn(string $name): bool => !str_starts_with($name, '.')));
    }

    private function regular(string $file): array
    {
        $this->safeParents(dirname($file));
        clearstatcache(true, $file);
        $stat = @lstat($file);
        if (!$stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 || realpath($file) !== $file) {
            throw new \RuntimeException('Unsafe cache/reference file');
        }
        return $stat;
    }

    private function safeParents(string $directory): void
    {
        for ($parent = $directory; $parent !== '/' && $parent !== $this->store->paths->root; $parent = dirname($parent)) {
            clearstatcache(true, $parent);
            if (is_link($parent) || (file_exists($parent) && !is_dir($parent))) throw new \RuntimeException('Unsafe cache/reference directory');
        }
    }

    private function exists(string $path): bool
    {
        clearstatcache(true, $path);
        return file_exists($path) || is_link($path);
    }
}
