<?php
declare(strict_types=1);

namespace Vgpu;

/** Explicit per-kernel authorization receipts; never discover packages by scanning. */
final class PackageHistory
{
    private const MAX_FILE = 32768;

    public function __construct(private Store $store) {}

    public static function fromPackage(array $package): array
    {
        return ['package' => $package, 'saved_boot_id' => $package['saved_boot_id'] ?? '',
            'source_id' => $package['source_id'] ?? '', 'source_fingerprint' => $package['source_fingerprint'] ?? '',
            'local' => ($package['source'] ?? '') === 'local'];
    }

    public static function authorization(array $candidate): array
    {
        return self::normalize($candidate);
    }

    /** Shared policy for selected, prepared, installed and historical candidates. */
    public static function assertPolicy(array $candidate, array $settings, string $kernel): void
    {
        $package = PackageIdentity::receipt($candidate['package'] ?? []);
        $driver = $package['driver'];
        if ($package['kernel'] !== $kernel || !preg_match('/^[a-f0-9]{32}$/D', (string)($package['md5'] ?? ''))) {
            throw new \RuntimeException('Prepared driver metadata is invalid');
        }
        $branch = (string)($settings['upgrade']['driver_branch'] ?? '');
        if ($branch !== '' && (!preg_match('/^[0-9]+$/D', $branch) || explode('.', $driver)[0] !== $branch)) throw new \RuntimeException('Prepared driver belongs to another NVIDIA branch');
        if (($settings['source']['driver'] ?? '') !== '' && $driver !== $settings['source']['driver']) throw new \RuntimeException('Prepared driver differs from the selected version');
        if (($settings['source']['build'] ?? '') !== '' && $package['build'] !== PackageIdentity::build($settings['source']['build'])) throw new \RuntimeException('Prepared driver differs from the selected package build');
        if (!($candidate['local'] ?? false)) {
            if (($candidate['source_id'] ?? '') !== Store::sourceIdentity($settings['source']) ||
                ($candidate['source_fingerprint'] ?? '') !== Upgrade::sourceFingerprint($settings['source'])) {
                throw new \RuntimeException('Driver source changed; prepare the package again');
            }
        }
    }

    /** Cheap receipt/cache binding; installation still performs inspectCached(). */
    public static function cached(Paths $paths, array $package, string $kernel): bool
    {
        try { $identity = PackageIdentity::receipt($package); }
        catch (\InvalidArgumentException) { return false; }
        $expected = $paths->cacheDir . '/' . preg_replace('/-Unraid$/', '', $kernel) . '/' . $identity['filename'];
        clearstatcache(true, $expected);
        $md5 = $package['md5'] ?? '';
        return $identity['kernel'] === $kernel && ($package['path'] ?? '') === $expected &&
            !is_link($expected) && is_file($expected) && realpath($expected) === $expected &&
            is_string($md5) && preg_match('/^[a-f0-9]{32}$/D', $md5) && hash_file('md5', $expected) === $md5;
    }

    public function read(string $kernel): ?array
    {
        $path = $this->file($kernel);
        $this->safeParents(dirname($path));
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) return null;
        if (is_link($path) || !is_file($path) || filesize($path) > self::MAX_FILE || (lstat($path)['nlink'] ?? 0) !== 1) {
            throw new \RuntimeException('Driver package history is invalid');
        }
        $data = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema_version'] ?? null) !== 1) throw new \RuntimeException('Driver package history is invalid');
        $candidate = self::normalize($data);
        if ($candidate['package']['kernel'] !== $kernel) throw new \RuntimeException('Driver package history belongs to another kernel');
        return $candidate;
    }

    /** Call only for a verified prior selection or a proven successful install. */
    public function remember(array $candidate): void
    {
        $candidate = self::normalize($candidate);
        $data = ['schema_version' => 1] + $candidate;
        if (strlen(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) + 1 > self::MAX_FILE) {
            throw new \RuntimeException('Driver package history is too large');
        }
        $path = $this->file($candidate['package']['kernel']);
        $directory = dirname($path);
        $this->safeParents($directory);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Cannot create driver package history');
        $this->safeParents($directory);
        if (!chmod($directory, 0700)) throw new \RuntimeException('Cannot protect driver package history');
        if (is_link($path) || (file_exists($path) && (!is_file($path) || (lstat($path)['nlink'] ?? 0) !== 1))) {
            throw new \RuntimeException('Driver package history target is invalid');
        }
        $this->store->write($path, $data);
    }

    private static function normalize(array $candidate): array
    {
        $package = PackageIdentity::receipt($candidate['package'] ?? []);
        if (!is_string($package['path'] ?? null) || !str_starts_with($package['path'], '/') ||
            !is_string($package['source'] ?? null) || $package['source'] === '' ||
            !is_string($package['md5'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $package['md5'])) {
            throw new \RuntimeException('Driver package history metadata is invalid');
        }
        $boot = $candidate['saved_boot_id'] ?? '';
        if (!self::bootId($boot) || !is_bool($candidate['local'] ?? null)) throw new \RuntimeException('Driver package history authorization is invalid');
        $local = $candidate['local'];
        if ($local !== (($package['source'] ?? '') === 'local')) throw new \RuntimeException('Driver package history source is invalid');
        $sourceId = $candidate['source_id'] ?? '';
        $fingerprint = $candidate['source_fingerprint'] ?? '';
        if (!is_string($sourceId) || !is_string($fingerprint) || (!$local &&
            (!preg_match('/^[a-f0-9]{64}$/D', $sourceId) || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint)))) {
            throw new \RuntimeException('Driver package history provenance is invalid');
        }
        // Plans and arbitrary package metadata must not bloat a durable receipt.
        $package = array_intersect_key($package, array_flip(['filename', 'driver', 'kernel', 'build', 'path', 'md5', 'source', 'provider', 'checksum_verified']));
        $result = ['package' => $package, 'saved_boot_id' => $boot, 'source_id' => $sourceId,
            'source_fingerprint' => $fingerprint, 'local' => $local];
        if (array_key_exists('installed_boot_id', $candidate)) {
            if (!self::bootId($candidate['installed_boot_id'])) throw new \RuntimeException('Driver package history installation boot is invalid');
            $result['installed_boot_id'] = $candidate['installed_boot_id'];
        }
        return $result;
    }

    private function file(string $kernel): string
    {
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+-Unraid$/D', $kernel)) throw new \RuntimeException('Driver package history kernel is invalid');
        return $this->store->paths->configDir . '/package-history/' . $kernel . '.json';
    }

    private function safeParents(string $directory): void
    {
        for ($parent = $directory; $parent !== '/' && $parent !== $this->store->paths->root; $parent = dirname($parent)) {
            if (is_link($parent) || (file_exists($parent) && !is_dir($parent))) throw new \RuntimeException('Driver package history directory is invalid');
        }
    }

    private static function bootId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $value) === 1;
    }
}
