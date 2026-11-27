<?php
declare(strict_types=1);

namespace Vgpu;

/** Read-only ownership check for ONE live mdev, not its physical GPU. */
final class TargetUsage
{
    public function __construct(private Paths $paths) {}

    public function inspect(string $uuid, string $group): array
    {
        $entry = $this->paths->path('/sys/bus/mdev/devices/' . $uuid);
        clearstatcache(true);
        $target = realpath($entry);
        if ($target === false || !str_starts_with($target, $this->paths->path('/sys/devices/'))) {
            return ['known' => false, 'holders' => [], 'unresolved' => []];
        }
        $known = is_readable($this->paths->path('/proc'));
        $holders = $unresolved = [];
        $groupNode = @stat($this->paths->path('/dev/vfio/' . $group));
        $groupDevice = $this->characterDevice($groupNode);
        $processes = glob($this->paths->path('/proc/[0-9]*'), GLOB_ONLYDIR);
        if ($processes === false) $known = false;
        foreach ($processes ?: [] as $directory) {
            if (!is_readable($directory . '/fd')) {
                if (is_dir($directory)) $known = false;
                continue;
            }
            $busy = $unknown = [];
            $descriptors = glob($directory . '/fd/*');
            if ($descriptors === false) $known = false;
            foreach ($descriptors ?: [] as $fd) {
                $path = @readlink($fd);
                if ($path === false) {
                    if (file_exists($fd) || is_link($fd)) $known = false;
                    continue;
                }
                // Bind mounts may give a group node a different pathname.
                // Compare actual character-device identity, not that alias.
                if ($groupDevice !== null && $this->characterDevice(@stat($fd)) === $groupDevice) {
                    $busy[] = $path;
                    continue;
                }
                $node = preg_replace('/ \(deleted\)$/D', '', $path);
                if (preg_match('#^/dev/vfio/(?:noiommu-)?([0-9]+)$#D', $node, $match)) {
                    if ($match[1] === $group) $busy[] = $path;
                    continue;
                }
                $cdev = preg_match('#^/dev/vfio/devices/(?:noiommu-)?(vfio[0-9]+)$#D', $node, $match) ? $match[1] : null;
                $anonymous = $node === 'anon_inode:[vfio-device]';
                // Linux exposes vfio-device-syspath in fdinfo for both group-
                // acquired anonymous device FDs and cdevs, including aliases.
                $info = ($anonymous || str_starts_with($node, '/dev/'))
                    ? @file_get_contents($directory . '/fdinfo/' . basename($fd)) : false;
                $hasIdentity = is_string($info) && preg_match('/^vfio-device-syspath:/m', $info);
                if ($cdev === null && !$anonymous && !$hasIdentity) continue;
                $owner = $this->devicePath($info, $cdev);
                if ($owner === null) $unknown[] = $path;
                elseif ($owner === $target) $busy[] = $path;
            }
            if ($busy) $holders[] = $this->holder($directory, $busy);
            if ($unknown) { $known = false; $unresolved[] = $this->holder($directory, $unknown); }
        }
        clearstatcache(true);
        if (realpath($entry) !== $target) $known = false;
        return ['known' => $known, 'holders' => $holders, 'unresolved' => $unresolved];
    }

    private function characterDevice(array|false $stat): ?int
    {
        return $stat !== false && ($stat['mode'] & 0170000) === 0020000 ? $stat['rdev'] : null;
    }

    private function devicePath(string|false $info, ?string $cdev): ?string
    {
        if (is_string($info) && preg_match('/^vfio-device-syspath:/m', $info)) {
            if (preg_match_all('/^vfio-device-syspath:[ \t]*(\/sys\/devices\/[^\r\n]+)\r?$/m', $info, $matches) !== 1 ||
                preg_match('#/(?:\.|\.\.)(?:/|$)#', $matches[1][0])) return null;
            $path = realpath($this->paths->path($matches[1][0]));
        } elseif ($cdev !== null) {
            // cdev numbers are NOT IOMMU group numbers. Older kernels may
            // omit fdinfo identity; use the cdev's actual sysfs parent instead.
            $path = realpath($this->paths->path('/sys/class/vfio-dev/' . $cdev . '/device'));
        } else {
            return null;
        }
        return $path !== false && str_starts_with($path, $this->paths->path('/sys/devices/')) ? $path : null;
    }

    private function holder(string $directory, array $handles): array
    {
        $cgroup = (string)@file_get_contents($directory . '/cgroup');
        preg_match('/(?:docker[\/-]|docker-)([a-f0-9]{12,64})/', $cgroup, $container);
        return ['pid' => (int)basename($directory), 'name' => trim((string)@file_get_contents($directory . '/comm')),
            'container' => isset($container[1]) ? substr($container[1], 0, 12) : null,
            'handles' => array_values(array_unique($handles))];
    }
}
