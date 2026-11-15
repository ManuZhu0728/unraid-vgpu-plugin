<?php
declare(strict_types=1);

namespace Vgpu;

/**
 * Shared with unraid-vgpu/scripts/package/PkgtoolsMigration.php.
 * Unraid kernel suffixes put the NVIDIA driver version in pkgtools' NAME,
 * so a driver-version change requires an explicit old-record%new-package.
 */
final class PkgtoolsMigration
{
    public static function select(string $root, string $package): array
    {
        $expected = basename($package, '.txz');
        if (!str_ends_with($package, '.txz') || !self::identity($expected) ||
            !str_starts_with($package, '/') || preg_match('/[%*?\[\]\\\\\s\x00-\x1f\x7f]/', $package)) {
            throw new \RuntimeException('Unsupported pkgtools package path');
        }
        $records = self::records($root);
        if (count($records) > 1) throw new \RuntimeException('Multiple installed NVIDIA driver packages require maintenance before migration');
        $previous = array_key_first($records);
        return ['previous' => $previous, 'expected' => $expected,
            'argument' => $previous === null ? $package : $previous . '%' . $package];
    }

    public static function verify(string $root, array $selection): void
    {
        $expected = $selection['expected'] ?? '';
        if (!is_string($expected) || !self::identity($expected) ||
            array_keys(self::records($root)) !== [$expected]) {
            throw new \RuntimeException('Installed NVIDIA pkgtools record does not match the selected package');
        }
    }

    private static function identity(string $name): ?array
    {
        if (!preg_match('/^nvidia(?:-[A-Za-z0-9._+-]+)?-([0-9]+\.[0-9]+\.[0-9]+)-([0-9]+\.[0-9]+\.[0-9]+-Unraid)-([1-9][0-9]*)$/D', $name, $match)) return null;
        return ['driver' => $match[1], 'kernel' => $match[2]];
    }

    private static function records(string $root): array
    {
        $resolvedRoot = realpath($root === '' ? '/' : $root);
        if ($resolvedRoot === false || !is_dir($resolvedRoot)) throw new \RuntimeException('Installation root is unavailable');
        $root = rtrim($resolvedRoot, '/');
        $records = []; $seenDirectories = [];
        foreach (['/var/lib/pkgtools/packages', '/var/log/packages'] as $relative) {
            $directory = $root . $relative;
            if (!file_exists($directory) && !is_link($directory)) continue;
            $canonical = realpath($directory);
            if ($canonical === false || !is_dir($canonical) || !is_readable($canonical) ||
                ($root !== '' && !str_starts_with($canonical, $root . '/'))) {
                throw new \RuntimeException('Installed package database is unavailable or outside the installation root');
            }
            if (isset($seenDirectories[$canonical])) continue;
            $seenDirectories[$canonical] = true;
            foreach (new \FilesystemIterator($canonical, \FilesystemIterator::SKIP_DOTS) as $entry) {
                $name = $entry->getFilename();
                $identity = self::identity($name);
                if ($identity === null) continue;
                // pkgtools 15 uses only /var/lib/pkgtools. A separate legacy
                // database is not an alias and old%new cannot migrate it.
                if ($relative === '/var/log/packages') {
                    throw new \RuntimeException('Separate legacy NVIDIA package records require database migration before driver installation');
                }
                if ($entry->isLink() || !$entry->isFile() || $entry->getSize() > 8388608) {
                    throw new \RuntimeException('Invalid installed NVIDIA package record');
                }
                $contents = file_get_contents($entry->getPathname());
                if ($contents === false || !str_contains($contents, "FILE LIST:\n")) throw new \RuntimeException('Cannot inspect installed NVIDIA package ownership');
                $listing = explode("FILE LIST:\n", $contents, 2)[1];
                $prefix = 'lib/modules/' . $identity['kernel'] . '/kernel/drivers/video/';
                foreach (['nvidia.ko', 'nvidia-vgpu-vfio.ko'] as $module) {
                    if (!preg_match('#^(?:\./)?' . preg_quote($prefix . $module, '#') . '\r?$#m', $listing)) {
                        throw new \RuntimeException('Installed NVIDIA package record lacks the expected vGPU modules');
                    }
                }
                if (isset($records[$name])) throw new \RuntimeException('Duplicate installed NVIDIA package records');
                $records[$name] = true;
            }
        }
        ksort($records);
        return $records;
    }
}
