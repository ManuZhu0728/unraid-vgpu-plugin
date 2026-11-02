<?php
declare(strict_types=1);

namespace Vgpu;

/** Read existing mdev identities without importing them or running mdevctl. */
final class Inventory
{
    private const MAX_DEFINITIONS = 4096;
    private const MAX_JSON_BYTES = 65536;

    public function __construct(private Paths $paths) {}

    public function read(array $configured, array $snapshot): array
    {
        $rows = [];
        foreach ($configured as $configuration) {
            if (!is_array($configuration)) continue;
            $identity = $this->identity($configuration['pci'] ?? null, $configuration['uuid'] ?? null);
            if ($identity === null) continue;
            [$pci, $uuid] = $identity;
            $key = $pci . '/' . $uuid;
            $rows[$key] = $this->row($pci, $uuid);
            $rows[$key]['managed'] = true;
            $rows[$key]['configuration'] = $configuration;
            $rows[$key]['configured_type'] = $this->type($configuration['type'] ?? '') ?? '';
        }

        $activeKnown = ($snapshot['checks']['mdevs_known'] ?? false) === true;
        $activeDevices = $snapshot['active_mdevs'] ?? [];
        if (!is_array($activeDevices)) { $activeDevices = []; $activeKnown = false; }
        foreach ($activeDevices as $uuid => $active) {
            if (!is_array($active)) { $activeKnown = false; continue; }
            $identity = $this->identity($active['pci'] ?? null, $uuid);
            if ($identity === null) { $activeKnown = false; continue; }
            [$pci, $uuid] = $identity;
            $type = $this->type($active['type'] ?? '') ?? '';
            $key = $pci . '/' . $uuid;
            if (!$this->isNvidia($pci, $type) && !isset($rows[$key])) continue;
            $rows[$key] ??= $this->row($pci, $uuid);
            $rows[$key]['active'] = true;
            $rows[$key]['active_type'] = $type;
            $group = $active['group'] ?? null;
            $rows[$key]['iommu_group'] = is_string($group) && preg_match('/^[0-9]+$/D', $group) ? $group : null;
        }

        [$definitions, $definedKnown] = $this->definitions();
        foreach ($definitions as $key => $definition) {
            $rows[$key] ??= $this->row($definition['pci'], $definition['uuid']);
            $rows[$key]['defined'] = true;
            $rows[$key]['defined_type'] = $definition['type'];
            $rows[$key]['definition_start'] = $definition['start'];
        }

        foreach ($rows as &$row) {
            $row['active'] ??= $activeKnown ? false : null;
            $row['defined'] ??= $definedKnown ? false : null;
            $row['type'] = $row['active'] === true ? $row['active_type'] : ($row['defined'] === true ? $row['defined_type'] : $row['configured_type']);
            // A missing active type must not be replaced by a different saved
            // type. Likewise, never label a stale saved profile as observed.
            $row['profile'] = $row['type'] !== '' ? $this->sysfs($row['pci'], $row['type'], 'name') : '';
            if ($row['profile'] === '' && $row['configuration'] !== null &&
                (($row['active'] !== true && $row['defined'] !== true) || $row['type'] === $row['configured_type'])) {
                $profile = $row['configuration']['profile'] ?? '';
                $row['profile'] = is_string($profile) ? $profile : '';
            }
            $description = $row['type'] !== '' ? $this->sysfs($row['pci'], $row['type'], 'description') : '';
            if (preg_match('/(?:^|,)\s*framebuffer=([^,]+)/', $description, $match)) $row['framebuffer'] = trim($match[1]);
        }
        unset($row);
        ksort($rows, SORT_STRING);
        $messages = [];
        if (!$activeKnown) $messages[] = '当前设备状态读取不完整。';
        if (!$definedKnown) $messages[] = '部分系统设备定义读取失败，列表可能不完整。';
        return ['devices' => array_values($rows), 'checks' => ['active_known' => $activeKnown, 'defined_known' => $definedKnown], 'messages' => $messages];
    }

    private function row(string $pci, string $uuid): array
    {
        return [
            'uuid' => $uuid, 'pci' => $pci, 'type' => '', 'profile' => '', 'framebuffer' => '',
            'active' => null, 'defined' => null, 'managed' => false, 'configuration' => null,
            'active_type' => '', 'defined_type' => '', 'configured_type' => '',
            'definition_start' => '', 'iommu_group' => null,
        ];
    }

    private function identity($pci, $uuid): ?array
    {
        if (!is_string($pci) || !is_string($uuid) ||
            !preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/Di', $pci) ||
            !preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/Di', $uuid)) return null;
        return [strtolower($pci), strtolower($uuid)];
    }

    private function type($type): ?string
    {
        if (!is_string($type) || str_contains($type, '..')) return null;
        return $type === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $type) ? $type : null;
    }

    private function isNvidia(string $pci, string $type): bool
    {
        return preg_match('/^nvidia-[0-9]+$/D', $type) === 1 ||
            $this->smallRead($this->paths->path('/sys/bus/pci/devices/' . $pci . '/vendor'), 32) === '0x10de';
    }

    private function sysfs(string $pci, string $type, string $field): string
    {
        // PCI devices and mdev types are normal symlinks inside sysfs. Only
        // validated identities and fixed attribute names form this path.
        return $this->smallRead($this->paths->path('/sys/bus/pci/devices/' . $pci . '/mdev_supported_types/' . $type . '/' . $field), 4096);
    }

    private function smallRead(string $path, int $limit): string
    {
        if (!is_readable($path) || !is_file($path)) return '';
        $value = @file_get_contents($path, false, null, 0, $limit);
        return $value === false ? '' : trim($value);
    }

    private function directory(string $path): bool
    {
        $stat = @lstat($path);
        return $stat && ($stat['mode'] & 0170000) === 0040000 && ($stat['mode'] & 0444) !== 0 && is_readable($path);
    }

    private function definitions(): array
    {
        $root = $this->paths->path('/etc/mdevctl.d');
        if (@lstat($root) === false) return [[], true];
        if (!$this->directory($root)) return [[], false];
        $known = true;
        $result = [];
        $count = 0;
        $parents = glob($root . '/*');
        if ($parents === false) return [[], false];
        foreach ($parents as $parent) {
            $pci = basename($parent);
            if (!preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/Di', $pci)) continue;
            if (!$this->directory($parent)) { $known = false; continue; }
            $files = glob($parent . '/*');
            if ($files === false) { $known = false; continue; }
            foreach ($files as $path) {
                $identity = $this->identity($pci, basename($path));
                if ($identity === null) continue;
                if (++$count > self::MAX_DEFINITIONS) { $known = false; break 2; }
                [$pciName, $uuid] = $identity;
                $definition = $this->definition($path);
                if ($definition === null) { $known = false; continue; }
                if (!$this->isNvidia($pciName, $definition['type'])) continue;
                $key = $pciName . '/' . $uuid;
                $result[$key] = ['pci' => $pciName, 'uuid' => $uuid] + $definition;
            }
        }
        return [$result, $known];
    }

    private function definition(string $path): ?array
    {
        $before = @lstat($path);
        if (!$before || ($before['mode'] & 0170000) !== 0100000 || ($before['mode'] & 0444) === 0 ||
            $before['size'] > self::MAX_JSON_BYTES || !is_readable($path)) return null;
        $handle = @fopen($path, 'rb');
        if ($handle === false) return null;
        try {
            $opened = fstat($handle);
            if (!$opened || ($opened['mode'] & 0170000) !== 0100000 || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']) return null;
            $json = stream_get_contents($handle, self::MAX_JSON_BYTES + 1);
        } finally { fclose($handle); }
        if ($json === false || strlen($json) > self::MAX_JSON_BYTES) return null;
        $value = json_decode($json, true);
        if (!is_array($value)) return null;
        $type = $this->type($value['mdev_type'] ?? null);
        if ($type === null || $type === '' || !in_array($value['start'] ?? null, ['auto', 'manual'], true)) return null;
        return ['type' => $type, 'start' => $value['start']];
    }
}
