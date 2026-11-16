<?php
declare(strict_types=1);

namespace Vgpu;

/** Prepare only our packages for Unraid's existing rc.modules scan. No system hooks. */
final class NativeBoot
{
    public function __construct(private Store $store, private Download $download) {}

    /** Caller holds Store::locked(). Only our persistent plugin directory is changed. */
    public function migrate(): void
    {
        $paths = $this->store->paths;
        $marker = $paths->configDir . '/cache-layout.json';
        if (is_link($marker)) throw new \RuntimeException('Unsafe cache migration marker');
        $complete = is_file($marker);
        $moved = false;
        foreach ($this->entries($paths->bootPackagesDir) as $kernel) {
            if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $kernel)) continue;
            $directory = $paths->bootPackagesDir . '/' . $kernel;
            // An interrupted migration can resume; already published selections stay intact.
            if (is_file($directory . '/selection.json')) continue;
            foreach ($this->entries($directory) as $name) {
                if (!file_exists($directory . '/' . $name) && !is_link($directory . '/' . $name)) continue;
                // Recover a move interrupted between the TXZ and its MD5.
                if (str_ends_with($name, '.txz.md5') && !file_exists($directory . '/' . substr($name, 0, -4))) {
                    $filename = substr($name, 0, -4);
                    PackageIdentity::parse($filename, $kernel);
                    $target = $paths->cacheDir . '/' . $kernel . '/' . $filename;
                    $this->regular($target); $this->regular($directory . '/' . $name);
                    if ($this->checksum($directory . '/' . $name) !== hash_file('md5', $target)) throw new \RuntimeException('Interrupted cache checksum differs; files were preserved');
                    if (file_exists($target . '.md5') || is_link($target . '.md5')) {
                        if ($this->checksum($target . '.md5') !== $this->checksum($directory . '/' . $name) || !unlink($directory . '/' . $name)) throw new \RuntimeException('Cannot finish cache checksum migration');
                    } elseif (!rename($directory . '/' . $name, $target . '.md5')) throw new \RuntimeException('Cannot resume cache checksum migration');
                    $moved = true;
                    continue;
                }
                try { PackageIdentity::parse($name, $kernel); }
                catch (\InvalidArgumentException) { continue; }
                $old = $directory . '/' . $name;
                $moved = true;
                $this->regular($old);
                $this->regular($old . '.md5');
                $digest = hash_file('md5', $old);
                if ($this->checksum($old . '.md5') !== $digest) {
                    throw new \RuntimeException('Legacy plugin cache MD5 differs; no package was discarded');
                }
                $target = $paths->cacheDir . '/' . $kernel . '/' . $name;
                $this->directory(dirname($target));
                foreach ([$old => $target, $old . '.md5' => $target . '.md5'] as $source => $destination) {
                    if (file_exists($destination) || is_link($destination)) {
                        $this->regular($destination);
                        if (str_ends_with($source, '.md5') ? $this->checksum($source) !== $this->checksum($destination) : hash_file('sha256', $source) !== hash_file('sha256', $destination)) throw new \RuntimeException('Legacy and private caches differ; both were preserved');
                        if (!unlink($source)) throw new \RuntimeException('Cannot finish legacy cache migration');
                    } elseif (!rename($source, $destination)) throw new \RuntimeException('Cannot move legacy cache into the private cache');
                }
            }
        }
        if ($complete && !$moved) return;
        // Rewrite durable references after moves. Retrying after interruption is idempotent.
        $files = [$paths->settingsFile, $paths->configDir . '/upgrade-state.json', $paths->configDir . '/driver-state.json'];
        foreach ($this->entries($paths->configDir . '/package-history') as $name) {
            if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+-Unraid\.json$/D', $name)) $files[] = $paths->configDir . '/package-history/' . $name;
        }
        foreach ($files as $file) {
            if (!file_exists($file) && !is_link($file)) continue;
            $data = $this->json($file);
            $rewritten = $this->rewrite($data);
            if ($data !== $rewritten) $this->store->write($file, $rewritten);
        }
        $this->store->write($marker, ['schema_version' => 1, 'cache' => 'driver-cache', 'installer' => 'Unraid rc.modules']);
    }

    public function remember(array $package): void
    {
        $package = PackageIdentity::receipt($package);
        if (!PackageHistory::cached($this->store->paths, $package, $package['kernel'])) throw new \RuntimeException('Cannot remember an unverified cache receipt');
        $this->directory($this->store->paths->configDir . '/cache-receipts');
        $this->store->write($this->store->paths->configDir . '/cache-receipts/' . $package['filename'] . '.json', $package);
    }

    public function cachedReceipt(string $filename): array
    {
        $identity = PackageIdentity::parse($filename);
        $record = $this->store->paths->configDir . '/cache-receipts/' . $filename . '.json';
        if (file_exists($record) || is_link($record)) return PackageIdentity::receipt($this->json($record));
        // Legacy unselected cache has no source receipt. Explicit selection treats it
        // as a local package, with its existing external MD5 checked by inspectCached.
        $path = $this->store->paths->cacheDir . '/' . substr($identity['kernel'], 0, -7) . '/' . $filename;
        $this->regular($path . '.md5');
        return $identity + ['path' => $path, 'md5' => $this->checksum($path . '.md5'), 'source' => 'local', 'provider' => 'local', 'checksum_verified' => true];
    }

    /** Persist one immutable, verified package per kernel for the native installer. */
    public function sync(array $settings, array $prepared = []): void
    {
        $wanted = [];
        if (($settings['driver']['enabled'] ?? false) === true) {
            foreach (is_array($settings['selected_package'] ?? null) ? $this->selections() : [] as $kernel => $record) {
                try {
                    PackageHistory::assertPolicy($record['authorization'], $settings, $kernel);
                    $wanted[$kernel] = $record['authorization'];
                } catch (\Throwable) { /* Obsolete policy is removed from the install set. */ }
            }
            if (($prepared['status'] ?? '') === 'ready' && is_array($prepared['package'] ?? null)) {
                $candidate = ['package' => $prepared['package'], 'saved_boot_id' => $prepared['prepared_boot_id'] ?? '',
                    'source_id' => $prepared['source_id'] ?? '', 'source_fingerprint' => $prepared['source_fingerprint'] ?? '', 'local' => false];
                try {
                    PackageHistory::assertPolicy($candidate, $settings, $prepared['package']['kernel']);
                    $wanted[$prepared['package']['kernel']] = $candidate;
                } catch (\Throwable) { /* Changed policy withdraws obsolete preparations. */ }
            }
            if (is_array($settings['selected_package'] ?? null)) {
                $candidate = PackageHistory::fromPackage($settings['selected_package']);
                try {
                    PackageHistory::assertPolicy($candidate, $settings, $candidate['package']['kernel']);
                    $wanted[$candidate['package']['kernel']] = $candidate;
                } catch (\Throwable) { /* Invalid selections must not keep an old boot package active. */ }
            }
        }
        foreach ($this->selections() as $kernel => $record) {
            if (!isset($wanted[$kernel])) $this->retire(substr($kernel, 0, -7));
        }
        foreach ($wanted as $kernel => $candidate) {
            try { $this->publish($candidate); }
            catch (\Throwable $error) {
                $this->retire(substr($kernel, 0, -7));
                throw $error;
            }
        }
    }

    public function selections(): array
    {
        $result = [];
        foreach ($this->entries($this->store->paths->bootPackagesDir) as $kernel) {
            if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $kernel)) continue;
            $file = $this->store->paths->bootPackagesDir . '/' . $kernel . '/selection.json';
            if (file_exists($file) || is_link($file)) {
                $record = $this->json($file);
                $authorization = PackageHistory::authorization($record['authorization'] ?? []);
                if (($record['schema_version'] ?? null) !== 1 || $authorization['package']['kernel'] !== $kernel . '-Unraid') throw new \RuntimeException('Native boot selection metadata differs from its kernel');
                $result[$kernel . '-Unraid'] = $record;
            }
        }
        return $result;
    }

    /** A record made BEFORE this boot is the native installation intent, not proof. */
    public function selection(string $kernel): ?array
    {
        return $this->selections()[$kernel] ?? null;
    }

    public function verifyPublished(array $record): array
    {
        $package = PackageIdentity::receipt($record['authorization']['package'] ?? []);
        $kernel = substr($package['kernel'], 0, -7);
        $directory = $this->store->paths->bootPackagesDir . '/' . $kernel;
        $names = $this->entries($directory);
        sort($names);
        $expected = [$package['filename'], $package['filename'] . '.md5', 'selection.json'];
        sort($expected);
        if ($names !== $expected) throw new \RuntimeException('Native install directory must contain exactly the selected driver and MD5');
        $path = $directory . '/' . $package['filename'];
        $this->regular($path); $this->regular($path . '.md5');
        if (hash_file('md5', $path) !== $package['md5'] || $this->checksum($path . '.md5') !== $package['md5'] ||
            trim((string)file_get_contents($path . '.md5')) !== $package['md5'] . '  ' . $package['filename']) {
            throw new \RuntimeException('Native install package or MD5 differs from the selection');
        }
        return $package + ['boot_path' => $path];
    }

    private function publish(array $candidate): void
    {
        $candidate = PackageHistory::authorization($candidate);
        $package = $candidate['package'];
        $kernel = substr($package['kernel'], 0, -7);
        $old = $this->selection($package['kernel']);
        if ($old !== null && ($old['authorization'] ?? null) === $candidate) {
            $this->verifyPublished($old);
            return;
        }
        $this->download->verifyCached($package, $package['kernel']);
        $this->regular($package['path']);
        $boot = trim((string)@file_get_contents($this->store->paths->path('/proc/sys/kernel/random/boot_id')));
        if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $boot)) throw new \RuntimeException('Cannot identify this boot before preparing a native install');
        $this->directory($this->store->paths->bootPackagesDir);
        $temporary = $this->store->paths->configDir . '/.boot-stage-' . bin2hex(random_bytes(8));
        $this->directory($temporary);
        try {
            if (!copy($package['path'], $temporary . '/' . $package['filename']) || hash_file('md5', $temporary . '/' . $package['filename']) !== $package['md5']) throw new \RuntimeException('Cannot stage the selected native package');
            chmod($temporary . '/' . $package['filename'], 0600);
            if (file_put_contents($temporary . '/' . $package['filename'] . '.md5', $package['md5'] . '  ' . $package['filename'] . "\n") === false) throw new \RuntimeException('Cannot stage native checksum');
            chmod($temporary . '/' . $package['filename'] . '.md5', 0600);
            $this->store->write($temporary . '/selection.json', ['schema_version' => 1, 'staged_boot_id' => $boot, 'authorization' => $candidate]);
            // A crash in the replacement gap exposes ZERO packages, never both builds.
            // Old boot copies are first checked against the retained private cache.
            $this->retire($kernel);
            if (!rename($temporary, $this->store->paths->bootPackagesDir . '/' . $kernel)) throw new \RuntimeException('Cannot publish native driver selection');
        } finally {
            if (is_dir($temporary)) {
                foreach (scandir($temporary) ?: [] as $name) if ($name !== '.' && $name !== '..') @unlink($temporary . '/' . $name);
                @rmdir($temporary);
            }
        }
    }

    private function retire(string $kernel): void
    {
        $directory = $this->store->paths->bootPackagesDir . '/' . $kernel;
        if (!file_exists($directory) && !is_link($directory)) return;
        $this->parents($directory);
        $names = $this->entries($directory);
        foreach ($names as $name) {
            if ($name === 'selection.json') { $this->regular($directory . '/' . $name); continue; }
            $filename = str_ends_with($name, '.md5') ? substr($name, 0, -4) : $name;
            PackageIdentity::parse($filename, $kernel);
            $this->regular($directory . '/' . $name);
            $cached = $this->store->paths->cacheDir . '/' . $kernel . '/' . $name;
            $this->regular($cached);
            if (str_ends_with($name, '.md5') ? $this->checksum($cached) !== $this->checksum($directory . '/' . $name) : hash_file('sha256', $cached) !== hash_file('sha256', $directory . '/' . $name)) throw new \RuntimeException('Boot copy differs from retained cache; files were preserved');
        }
        $backup = $this->store->paths->configDir . '/.boot-retired-' . bin2hex(random_bytes(8));
        if (!rename($directory, $backup)) throw new \RuntimeException('Cannot withdraw the previous native selection');
        // Only validated boot copies, never the cache or an arbitrary recursive tree.
        foreach ($names as $name) if (!unlink($backup . '/' . $name)) throw new \RuntimeException('Native selection withdrawn; private boot-copy cleanup needs attention');
        if (!rmdir($backup)) throw new \RuntimeException('Cannot finish boot-copy cleanup');
    }

    private function rewrite(array $data): array
    {
        foreach ($data as $key => $value) if (is_array($value)) $data[$key] = $this->rewrite($value);
        $old = $this->store->paths->bootPackagesDir . '/';
        if (is_string($data['path'] ?? null) && str_starts_with($data['path'], $old)) {
            $identity = PackageIdentity::parse($data['filename'] ?? '');
            $expected = $old . substr($identity['kernel'], 0, -7) . '/' . $identity['filename'];
            if ($data['path'] !== $expected) throw new \RuntimeException('Legacy cache reference is outside the plugin cache');
            $data['path'] = $this->store->paths->cacheDir . substr($expected, strlen($this->store->paths->bootPackagesDir));
        }
        return $data;
    }

    private function json(string $file): array
    {
        $this->regular($file);
        if (filesize($file) > 131072) throw new \RuntimeException('Native boot metadata is oversized');
        $data = json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Native boot metadata is invalid');
        return $data;
    }

    private function checksum(string $file): string
    {
        $this->regular($file);
        if (filesize($file) > 4096 || !preg_match('/^([a-fA-F0-9]{32})(?:\s|$)/D', trim((string)file_get_contents($file)), $match)) throw new \RuntimeException('Cached MD5 is invalid');
        return strtolower($match[1]);
    }

    private function entries(string $directory): array
    {
        $this->parents($directory);
        if (!file_exists($directory)) return [];
        $entries = scandir($directory);
        if ($entries === false || count($entries) > 1024) throw new \RuntimeException('Cannot enumerate bounded plugin cache');
        return array_values(array_diff($entries, ['.', '..']));
    }

    private function directory(string $directory): void
    {
        $this->parents($directory);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Cannot create plugin boot/cache directory');
    }

    private function parents(string $file): void
    {
        for ($parent = $file; $parent !== '/' && $parent !== $this->store->paths->root; $parent = dirname($parent)) {
            if (is_link($parent) || (file_exists($parent) && !is_dir($parent))) throw new \RuntimeException('Unsafe plugin boot/cache directory');
        }
    }

    private function regular(string $file): void
    {
        $this->parents(dirname($file));
        clearstatcache(true, $file);
        $stat = @lstat($file);
        if (!$stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 || realpath($file) !== $file) throw new \RuntimeException('Unsafe plugin boot/cache file');
    }
}
