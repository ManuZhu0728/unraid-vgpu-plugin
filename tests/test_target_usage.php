<?php
declare(strict_types=1);
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';

function target_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class TargetUsageFixture
{
    public string $root;
    public Vgpu\Paths $paths;
    public string $uuid = 'ae9572f6-1259-4136-9aa4-497dda98d2db';
    public string $target = '/sys/devices/pci0000:00/0000:01:00.0/ae9572f6-1259-4136-9aa4-497dda98d2db';
    public string $other = '/sys/devices/pci0000:00/0000:01:00.0/868bfe06-47c1-40a3-82df-ffd518be72d3';

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/vgpu-target-usage-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->paths = new Vgpu\Paths($this->root);
        foreach ([$this->target, $this->other, '/sys/bus/mdev/devices', '/proc/700/fd', '/proc/700/fdinfo'] as $directory) mkdir($this->paths->path($directory), 0700, true);
        symlink($this->paths->path($this->target), $this->paths->path('/sys/bus/mdev/devices/' . $this->uuid));
        $this->put('/proc/700/comm', 'qemu');
        $this->put('/proc/700/cgroup', '0::/docker/0123456789abcdef0123456789abcdef');
    }

    public function put(string $path, string $contents): void
    {
        $file = $this->paths->path($path);
        if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
        file_put_contents($file, $contents);
    }

    public function fd(string $path, ?string $info = null): void
    {
        symlink($path, $this->paths->path('/proc/700/fd/4'));
        if ($info !== null) $this->put('/proc/700/fdinfo/4', $info);
    }

    public function inspect(): array { return (new Vgpu\TargetUsage($this->paths))->inspect($this->uuid, '27'); }

    public function close(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->root);
    }
}

$cases = [];
foreach (['/dev/vfio/27', '/dev/vfio/27 (deleted)', '/dev/vfio/noiommu-27'] as $path) {
    $cases['target group ' . $path] = function (TargetUsageFixture $f) use ($path): void {
        $f->fd($path);
        $r = $f->inspect();
        target_check($r['known'] && $r['holders'][0]['pid'] === 700 && $r['holders'][0]['container'] === '0123456789ab', 'Target group must report its precise holder');
    };
}
foreach (['/dev/vfio/devices/vfio42', '/dev/vfio/devices/noiommu-vfio42', 'anon_inode:[vfio-device]', '/dev/container-vfio-alias'] as $path) {
    foreach ([true, false] as $target) {
        $cases[($target ? 'target' : 'other') . ' fdinfo ' . $path] = function (TargetUsageFixture $f) use ($path, $target): void {
            $f->fd($path, "pos:\t0\nflags:\t02000002\nvfio-device-syspath: " . ($target ? $f->target : $f->other) . "\n");
            $r = $f->inspect();
            target_check($r['known'] && (bool)$r['holders'] === $target, 'Kernel fdinfo identity must distinguish target from another device, even if the group FD is closed');
        };
    }
}
foreach ([true, false] as $target) {
    $cases[($target ? 'target' : 'other') . ' sysfs cdev parent'] = function (TargetUsageFixture $f) use ($target): void {
        $f->fd('/dev/vfio/devices/vfio42');
        mkdir($f->paths->path('/sys/class/vfio-dev/vfio42'), 0700, true);
        symlink($f->paths->path($target ? $f->target : $f->other), $f->paths->path('/sys/class/vfio-dev/vfio42/device'));
        $r = $f->inspect();
        target_check($r['known'] && (bool)$r['holders'] === $target, 'Missing fdinfo may fall back to the actual sysfs cdev parent, never numeric group guessing');
    };
}
foreach (['/dev/nvidia0', '/dev/nvidiactl', '/dev/nvidia-uvm', '/dev/nvidia-modeset', '/dev/nvidia-caps/nvidia-cap1', '/dev/dri/renderD128', '/dev/vfio/42', '/dev/vfio/vfio', '/dev/iommu'] as $path) {
    $cases['not target ' . $path] = function (TargetUsageFixture $f) use ($path): void {
        $f->fd($path);
        $r = $f->inspect();
        target_check($r['known'] && !$r['holders'] && !$r['unresolved'], 'A host GPU/global container/unrelated group FD is not evidence of target mdev ownership');
    };
}
foreach ([null, '', 'vfio-device-syspath: /tmp/not-a-device', 'vfio-device-syspath: /sys/devices/missing',
    "vfio-device-syspath: /sys/devices/../devices/missing\n"] as $info) {
    $cases['unresolved fdinfo ' . var_export($info, true)] = function (TargetUsageFixture $f) use ($info): void {
        $f->fd('anon_inode:[vfio-device]', $info);
        $r = $f->inspect();
        target_check(!$r['known'] && !$r['holders'] && $r['unresolved'][0]['pid'] === 700, 'Missing/invalid VFIO ownership must fail closed without inventing a holder of the target');
    };
}
$cases['conflicting fdinfo cannot authorize removal'] = function (TargetUsageFixture $f): void {
    $f->fd('/dev/vfio/devices/vfio42', 'vfio-device-syspath: ' . $f->target . "\nvfio-device-syspath: " . $f->other . "\n");
    target_check(!$f->inspect()['known'], 'Duplicate kernel identity fields must not select whichever is convenient');
};
$cases['cdev number equal to target group is not proof of ownership'] = function (TargetUsageFixture $f): void {
    $f->fd('/dev/vfio/devices/vfio27');
    target_check(!$f->inspect()['known'], 'Cdev 27 must not be equated with group 27 without a mapping');
};
foreach ([true, false] as $target) {
    $cases[($target ? 'target' : 'other') . ' group character-device alias'] = function (TargetUsageFixture $f) use ($target): void {
        // Real harmless character-device metadata, no mknod/GPU access needed.
        mkdir($f->paths->path('/dev/vfio'), 0700, true);
        symlink('/dev/null', $f->paths->path('/dev/vfio/27'));
        $f->fd($target ? '/dev/null' : '/dev/zero');
        $r = $f->inspect();
        target_check($r['known'] && (bool)$r['holders'] === $target, 'A bind-mounted target group alias must be detected by rdev, without treating every character device as the target');
    };
}
$cases['missing target or process visibility cannot authorize mutation'] = function (TargetUsageFixture $f): void {
    unlink($f->paths->path('/sys/bus/mdev/devices/' . $f->uuid));
    target_check(!$f->inspect()['known'], 'Missing target must fail closed');
};

foreach ($cases as $name => $test) {
    $f = new TargetUsageFixture();
    try { $test($f); echo 'PASS target usage: ' . $name . "\n"; }
    finally { $f->close(); }
}
echo 'Target usage: ' . count($cases) . " hardware-free scenarios passed\n";
