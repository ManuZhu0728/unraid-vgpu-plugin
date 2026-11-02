<?php
declare(strict_types=1);

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/Application.php';

use Vgpu\Application;
use Vgpu\Download;
use Vgpu\Inventory;
use Vgpu\Paths;
use Vgpu\Runtime;
use Vgpu\Store;

$inventoryChecks = 0;
function inventory_check(bool $condition, string $message): void
{
    global $inventoryChecks;
    if (!$condition) throw new RuntimeException($message);
    ++$inventoryChecks;
}

final class InventoryFixture
{
    public const UUID = 'ae9572f6-1259-4136-9aa4-497dda98d2db';
    public const OTHER = '868bfe06-47c1-40a3-82df-ffd518be72d3';
    public const THIRD = 'f090c00f-338b-47b4-a419-700101cd70c5';
    public const PCI = '0000:01:00.0';
    public const OTHER_PCI = '0000:03:00.0';
    public string $root;
    public Paths $paths;
    public Inventory $inventory;
    public array $configuration = ['uuid' => self::UUID, 'pci' => self::PCI, 'type' => 'nvidia-762', 'profile' => 'NVIDIA RTXA5500-1Q'];

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/vgpu-inventory-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->paths = new Paths($this->root);
        $this->inventory = new Inventory($this->paths);
    }

    public function put(string $path, string $contents): string
    {
        $path = $this->paths->path($path);
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        file_put_contents($path, $contents);
        return $path;
    }

    public function definition(string $uuid = self::UUID, string $pci = self::PCI, string $type = 'nvidia-762', string $start = 'manual', array $extra = []): string
    {
        return $this->put('/etc/mdevctl.d/' . $pci . '/' . $uuid, json_encode(['mdev_type' => $type, 'start' => $start] + $extra, JSON_THROW_ON_ERROR));
    }

    public function snapshot(array $active = [], bool $known = true): array
    {
        return ['checks' => ['mdevs_known' => $known], 'active_mdevs' => $active];
    }

    public function read(array $configured = [], array $active = [], bool $known = true): array
    {
        return $this->inventory->read($configured, $this->snapshot($active, $known));
    }

    public function row(array $result, string $uuid = self::UUID, string $pci = self::PCI): array
    {
        $rows = array_values(array_filter($result['devices'], static fn($row) => $row['uuid'] === $uuid && $row['pci'] === $pci));
        inventory_check(count($rows) === 1, 'Expected one inventory row for ' . $pci . '/' . $uuid);
        return $rows[0];
    }

    public function fingerprint(): array
    {
        $result = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $relative = substr($file->getPathname(), strlen($this->root));
            $result[$relative] = $file->isLink() ? 'link:' . readlink($file->getPathname()) : ($file->isDir() ? 'directory' : hash_file('sha256', $file->getPathname()));
        }
        ksort($result);
        return $result;
    }

    public function close(): void
    {
        // Only the explicitly created test root is removed.
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir() && !$file->isLink()) { chmod($file->getPathname(), 0700); rmdir($file->getPathname()); }
            else unlink($file->getPathname());
        }
        rmdir($this->root);
    }
}

$f = new InventoryFixture();
try {
    $empty = $f->read();
    inventory_check($empty['devices'] === [] && $empty['checks'] === ['active_known' => true, 'defined_known' => true], 'A missing mdevctl directory must mean no system definitions');
    inventory_check($f->fingerprint() === [], 'An empty inventory must not create directories, files or locks');

    $saved = $f->row($f->read([$f->configuration]));
    inventory_check($saved['managed'] && !$saved['active'] && !$saved['defined'], 'Saved configuration without a system device must remain visible');
    inventory_check($saved['configuration'] === $f->configuration && $saved['profile'] === 'NVIDIA RTXA5500-1Q', 'Original plugin configuration must remain unchanged');
    $withoutType = $f->configuration;
    $withoutType['type'] = '';
    $row = $f->row($f->read([$withoutType]));
    inventory_check($row['type'] === '' && $row['managed'] && $row['profile'] === $withoutType['profile'], 'An empty saved type is allowed and must retain its profile name');

    $f->put('/sys/bus/pci/devices/' . $f::PCI . '/mdev_supported_types/nvidia-762/name', "NVIDIA RTXA5500-1Q\n");
    $f->put('/sys/bus/pci/devices/' . $f::PCI . '/mdev_supported_types/nvidia-762/description', "num_heads=4, framebuffer=1024M, max_resolution=5120x2880\n");
    $active = [$f::UUID => ['pci' => $f::PCI, 'type' => 'nvidia-762', 'group' => '27']];
    $row = $f->row($f->read([], $active));
    inventory_check($row['active'] && !$row['defined'] && !$row['managed'] && $row['configuration'] === null, 'An externally created active mdev must be visible without adopting it');
    inventory_check($row['type'] === 'nvidia-762' && $row['profile'] === 'NVIDIA RTXA5500-1Q' && $row['framebuffer'] === '1024M', 'Observed type name and framebuffer must come from current sysfs');
    inventory_check($row['iommu_group'] === '27', 'Active IOMMU group must be retained');

    $f->definition(start: 'auto', extra: ['attrs' => [['private' => 'do-not-expose-device-attributes']]]);
    $row = $f->row($f->read());
    inventory_check($row['defined'] && !$row['active'] && !$row['managed'] && $row['definition_start'] === 'auto', 'A defined but inactive device must be displayed separately from plugin configuration');
    inventory_check(!str_contains(json_encode($row), 'do-not-expose-device-attributes'), 'Unused definition attributes must not be returned to the browser');
    $before = $f->fingerprint();
    $result = $f->read([$f->configuration], $active);
    $row = $f->row($result);
    inventory_check(count($result['devices']) === 1 && $row['managed'] && $row['active'] && $row['defined'], 'Same PCI and UUID must merge all three sources into one row');
    inventory_check($row['active_type'] === 'nvidia-762' && $row['defined_type'] === 'nvidia-762' && $row['configured_type'] === 'nvidia-762', 'Each source type must remain independently inspectable');
    inventory_check($before === $f->fingerprint(), 'Inspecting definitions and active identities must not write or import anything');

    $f->put('/sys/bus/pci/devices/' . $f::PCI . '/mdev_supported_types/nvidia-999/name', "NVIDIA RTXA5500-1Q\n");
    $renumbered = [$f::UUID => ['pci' => $f::PCI, 'type' => 'nvidia-999', 'group' => 'not-a-group']];
    $row = $f->row($f->read([$f->configuration], $renumbered));
    inventory_check($row['type'] === 'nvidia-999' && $row['defined_type'] === 'nvidia-762' && $row['configured_type'] === 'nvidia-762', 'Active type wins display priority without rewriting the definition or saved type');
    inventory_check($row['profile'] === 'NVIDIA RTXA5500-1Q' && $row['configuration'] === $f->configuration, 'A profile whose ID changed must resolve by its current sysfs name');
    inventory_check($row['iommu_group'] === null, 'Invalid IOMMU group values must not become filesystem identities');
    unlink($f->paths->path('/sys/bus/pci/devices/' . $f::PCI . '/mdev_supported_types/nvidia-999/name'));
    $row = $f->row($f->read([$f->configuration], $renumbered));
    inventory_check($row['profile'] === '', 'A stale saved profile must not be presented as the name of a different observed type');

    $f->definition(type: 'nvidia-998');
    $row = $f->row($f->read([$f->configuration]));
    inventory_check($row['type'] === 'nvidia-998' && $row['profile'] === '' && $row['configuration'] === $f->configuration, 'A different inactive system definition must not inherit the saved profile name');
    $f->definition();

    $f->definition($f::UUID, $f::OTHER_PCI, 'nvidia-763');
    $result = $f->read([$f->configuration], [$f::UUID => ['pci' => $f::OTHER_PCI, 'type' => 'nvidia-763', 'group' => '28']]);
    inventory_check(count($result['devices']) === 2, 'Duplicate UUID definitions under different parents must not collapse');
    $first = $f->row($result);
    $second = $f->row($result, $f::UUID, $f::OTHER_PCI);
    inventory_check($first['managed'] && !$first['active'] && !$second['managed'] && $second['active'], 'A device on another PCI parent must not inherit plugin management');
    inventory_check($result['devices'][0]['pci'] === $f::PCI, 'Inventory ordering must be stable by PCI and UUID');

    $unknown = $f->row($f->read([$f->configuration], [], false));
    inventory_check($unknown['active'] === null, 'Unknown active state must not become stopped');
    inventory_check($f->read([], [], false)['messages'] !== [], 'Unknown active state must have a concise inspection message');
    $badSnapshot = $f->inventory->read([$f->configuration], ['checks' => ['mdevs_known' => true], 'active_mdevs' => 'invalid-snapshot']);
    inventory_check(!$badSnapshot['checks']['active_known'] && $f->row($badSnapshot)['active'] === null, 'A non-array active snapshot must not emit warnings or claim an empty device set');
    $partialActive = $active + ['not-a-uuid' => ['pci' => $f::PCI, 'type' => 'nvidia-762']];
    $partialSnapshot = $f->read([array_replace($f->configuration, ['uuid' => $f::THIRD])], $partialActive);
    inventory_check(!$partialSnapshot['checks']['active_known'] && $f->row($partialSnapshot)['active'] === true && $f->row($partialSnapshot, $f::THIRD)['active'] === null, 'Partial active metadata must retain positive entries and mark unseen identities unknown');
    $unknownType = [$f::UUID => ['pci' => $f::PCI, 'type' => '', 'group' => null]];
    $row = $f->row($f->read([$f->configuration], $unknownType));
    inventory_check($row['active'] && $row['type'] === '' && $row['profile'] === '', 'A missing observed type must not be replaced with a stale saved identity');

    $badPath = $f->put('/etc/mdevctl.d/' . $f::PCI . '/' . $f::OTHER, '{bad json');
    $configOnly = array_replace($f->configuration, ['uuid' => $f::THIRD]);
    $result = $f->read([$configOnly], $active);
    inventory_check(!$result['checks']['defined_known'] && $result['messages'] !== [], 'Malformed definitions must mark a partial inventory rather than empty success');
    inventory_check($f->row($result)['defined'] === true && $f->row($result, $f::THIRD)['defined'] === null, 'Positively read definitions stay true; unseen rows become unknown after a partial read');
    inventory_check($f->row($result)['active'] === true, 'Definition failures must not discard a known active device');
    unlink($badPath);

    $badPath = $f->definition($f::OTHER);
    chmod($badPath, 0000);
    inventory_check(!$f->read()['checks']['defined_known'], 'Unreadable definition permissions must be reported even when tests run as root');
    chmod($badPath, 0600);
    unlink($badPath);
    $outside = $f->put('/outside-definition.json', '{"mdev_type":"nvidia-799","start":"auto"}');
    symlink($outside, $badPath);
    $result = $f->read();
    inventory_check(!$result['checks']['defined_known'] && !in_array($f::OTHER, array_column($result['devices'], 'uuid'), true), 'Definition file symlinks must not be followed');
    unlink($badPath);
    $f->put('/etc/mdevctl.d/' . $f::PCI . '/' . $f::OTHER, str_repeat('x', 70000));
    inventory_check(!$f->read()['checks']['defined_known'], 'Oversized definition JSON must not be read without a bound');
    unlink($badPath);
    foreach ([['mdev_type' => '../escape', 'start' => 'manual'], ['mdev_type' => ['nvidia-762'], 'start' => 'manual'], ['mdev_type' => 'nvidia-762', 'start' => 'invalid']] as $bad) {
        $f->put('/etc/mdevctl.d/' . $f::PCI . '/' . $f::OTHER, json_encode($bad));
        inventory_check(!$f->read()['checks']['defined_known'], 'Invalid type paths or start policies must make definition inspection incomplete');
    }
    unlink($badPath);

    $intelPci = '0000:00:02.0';
    $f->put('/sys/bus/pci/devices/' . $intelPci . '/vendor', "0x8086\n");
    $f->definition($f::OTHER, $intelPci, 'i915-GVTg_V4_4');
    $result = $f->read([], [$f::OTHER => ['pci' => $intelPci, 'type' => 'i915-GVTg_V4_4', 'group' => '29']]);
    inventory_check(!in_array($f::OTHER, array_column($result['devices'], 'uuid'), true), 'Other vendors mediated devices must not appear in the NVIDIA inventory');
    inventory_check($result['checks']['defined_known'], 'Valid unrelated definitions must not create false inspection failures');
    $f->put('/etc/mdevctl.d/not-a-pci/' . $f::OTHER, '{"mdev_type":"nvidia-762","start":"manual"}');
    $f->put('/etc/mdevctl.d/' . $f::PCI . '/not-a-uuid', '{"mdev_type":"nvidia-762","start":"manual"}');
    inventory_check($f->read()['checks']['defined_known'], 'Non-device files and directories must be ignored');

    $f->put('/sys/bus/pci/devices/' . $intelPci . '/vendor', "0x10de\n");
    $f->definition($f::THIRD, $intelPci, 'vendor-custom');
    $row = $f->row($f->read(), $f::THIRD, $intelPci);
    inventory_check($row['type'] === 'vendor-custom', 'An NVIDIA PCI vendor must allow a nonstandard observed type to be inspected');

    $commands = [];
    $runner = function (array $argv) use (&$commands): array { $commands[] = $argv; throw new RuntimeException('Inventory must not invoke commands'); };
    $store = new Store($f->paths);
    $app = new Application($store, new Runtime($f->paths, $runner), new Download($f->paths, $runner), $runner);
    $before = $f->fingerprint();
    $status = $app->dispatch('status');
    inventory_check(isset($status['device_inventory']['devices']) && $status['device_inventory']['devices'] !== [], 'Application status must expose the merged system inventory');
    inventory_check($commands === [], 'Status must not invoke mdevctl, drivers, daemons or network');
    inventory_check($before === $f->fingerprint() && !is_file($f->paths->lockFile) && !is_dir($f->paths->configDir), 'Application inventory must not write configuration or take a mutation lock');
} finally { $f->close(); }

$f = new InventoryFixture();
try {
    $f->put('/outside/' . $f::UUID, '{"mdev_type":"nvidia-762","start":"manual"}');
    mkdir($f->paths->path('/etc/mdevctl.d'), 0700, true);
    symlink($f->paths->path('/outside'), $f->paths->path('/etc/mdevctl.d/' . $f::PCI));
    inventory_check(!$f->read()['checks']['defined_known'] && $f->read()['devices'] === [], 'Symlinked parent directories must not escape the definitions root');
    unlink($f->paths->path('/etc/mdevctl.d/' . $f::PCI));
    chmod($f->paths->path('/etc/mdevctl.d'), 0000);
    inventory_check(!$f->read()['checks']['defined_known'], 'An unreadable definitions directory must report unknown state');
    chmod($f->paths->path('/etc/mdevctl.d'), 0700);
    rmdir($f->paths->path('/etc/mdevctl.d'));
    symlink($f->paths->path('/outside'), $f->paths->path('/etc/mdevctl.d'));
    inventory_check(!$f->read()['checks']['defined_known'], 'The definitions root itself must not be a symlink');
} finally { $f->close(); }

echo "Device inventory: $inventoryChecks checks passed (read-only fake filesystem; no commands or GPU)\n";
