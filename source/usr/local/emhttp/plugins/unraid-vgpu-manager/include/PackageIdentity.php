<?php
declare(strict_types=1);

namespace Vgpu;

/** Public driver package naming shared by every delivery path. */
final class PackageIdentity
{
    public static function build(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string)$value)) {
            throw new \InvalidArgumentException('Package build must be a positive integer');
        }
        $value = (string)$value;
        $limit = (string)PHP_INT_MAX;
        if (strlen($value) > strlen($limit) || (strlen($value) === strlen($limit) && strcmp($value, $limit) > 0)) {
            throw new \InvalidArgumentException('Package build is too large');
        }
        return (int)$value;
    }

    public static function parse(string $filename, ?string $kernel = null): array
    {
        if (!preg_match('/^nvidia-([0-9]+\.[0-9]+\.[0-9]+)-([0-9]+\.[0-9]+\.[0-9]+-Unraid)-([1-9][0-9]*)\.txz$/D', $filename, $match)) {
            throw new \InvalidArgumentException('Invalid NVIDIA driver package filename');
        }
        if ($kernel !== null && self::kernel($kernel) !== $match[2]) {
            throw new \InvalidArgumentException('Driver package belongs to another kernel');
        }
        return ['filename' => $filename, 'driver' => $match[1], 'kernel' => $match[2], 'build' => self::build($match[3])];
    }

    public static function filename(string $driver, string $kernel, int|string $build = 1): string
    {
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $driver)) {
            throw new \InvalidArgumentException('Driver version must be X.Y.Z');
        }
        return 'nvidia-' . $driver . '-' . self::kernel($kernel) . '-' . self::build($build) . '.txz';
    }

    public static function genericVersion(string $kernel, string $driver, int|string $build = 1): string
    {
        $identity = self::parse(self::filename($driver, $kernel, $build));
        return 'v' . substr($identity['kernel'], 0, -7) . '-' . $driver .
            ($identity['build'] === 1 ? '' : '-build' . $identity['build']);
    }

    /** Old receipts omitted build, but could only have selected a build-1 file. */
    public static function receipt(array $package): array
    {
        if (!is_string($package['filename'] ?? null)) throw new \InvalidArgumentException('Invalid package receipt filename');
        $identity = self::parse($package['filename']);
        if (($package['driver'] ?? null) !== $identity['driver'] || ($package['kernel'] ?? null) !== $identity['kernel']) {
            throw new \InvalidArgumentException('Package identity differs from its receipt');
        }
        if (!array_key_exists('build', $package)) {
            if ($identity['build'] !== 1) throw new \InvalidArgumentException('Package build is missing from its receipt');
        } elseif (self::build($package['build']) !== $identity['build']) {
            throw new \InvalidArgumentException('Package build differs from its receipt');
        }
        return array_replace($package, $identity);
    }

    private static function kernel(string $kernel): string
    {
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-Unraid)?$/D', $kernel)) {
            throw new \InvalidArgumentException('Kernel version must be X.Y.Z-Unraid');
        }
        return str_ends_with($kernel, '-Unraid') ? $kernel : $kernel . '-Unraid';
    }
}
