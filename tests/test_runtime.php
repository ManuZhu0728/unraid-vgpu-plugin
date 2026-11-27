<?php
declare(strict_types=1);
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/StartupInventory.php';

use Vgpu\Paths;
use Vgpu\Runtime;
use Vgpu\Store;

function runtime_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** A private fake /proc, /sys and runner: no real GPU command is ever invoked. */
final class RuntimeFixture
{
    public string $directory;
    public Paths $paths;
    public Runtime $runtime;
    public array $commands = [];
    public array $definitions = [];
    public array $failures = [];
    public array $domains = [];
    public bool $stopLeavesActive = false;
    public $afterStop = null;
    public array $settings;
    public array $device = ['uuid' => 'ae9572f6-1259-4136-9aa4-497dda98d2db', 'pci' => '0000:01:00.0', 'profile' => 'NVIDIA RTXA5500-1Q', 'type' => 'nvidia-762'];

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/vgpu-runtime-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->paths = new Paths($this->directory);
        $this->write('/proc/sys/kernel/osrelease', "6.18.58-Unraid\n");
        $this->write('/usr/lib64/libvgpu_unlock_rs.so', 'test-only paired UnlockRS');
        $this->write('/usr/share/nvidia/vgpu/vgpuConfig.xml', '<test-only-xml/>');
        $this->write('/sys/bus/pci/devices/0000:01:00.0/mdev_supported_types/nvidia-762/name', "NVIDIA RTXA5500-1Q\n");
        $this->write('/sys/bus/pci/devices/0000:01:00.0/mdev_supported_types/nvidia-762/available_instances', "23\n");
        mkdir($this->paths->path('/sys/bus/mdev/devices'), 0700, true);
        mkdir($this->paths->path('/sys/bus/pci/drivers/nvidia'), 0700, true);
        symlink($this->paths->path('/sys/bus/pci/devices/0000:01:00.0'), $this->paths->path('/sys/bus/pci/drivers/nvidia/0000:01:00.0'));
        $this->settings = Store::defaults();
        $this->settings['runtime']['enabled'] = true;
        $this->runtime = new Runtime($this->paths, $this->run(...));
    }

    public function write(string $path, string $contents): void
    {
        $file = $this->paths->path($path);
        if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
        file_put_contents($file, $contents);
    }

    public function process(int $pid, string $comm, int $parent = 1, string $maps = ''): void
    {
        $this->write('/proc/' . $pid . '/comm', $comm . "\n");
        $this->write('/proc/' . $pid . '/status', "PPid:\t$parent\n");
        $fields = array_fill(0, 20, '0');
        $fields[0] = 'S'; $fields[1] = (string)$parent; $fields[19] = '12345';
        $this->write('/proc/' . $pid . '/stat', $pid . ' (' . $comm . ') ' . implode(' ', $fields));
        $this->write('/proc/' . $pid . '/maps', $maps);
        mkdir($this->paths->path('/proc/' . $pid . '/fd'), 0700, true);
    }

    public function active(array $device): void
    {
        $actual = '/sys/devices/pci0000:00/' . $device['pci'] . '/' . $device['uuid'];
        if (!is_dir($this->paths->path($actual))) mkdir($this->paths->path($actual), 0700, true);
        symlink($this->paths->path('/sys/bus/pci/devices/' . $device['pci'] . '/mdev_supported_types/' . $device['type']), $this->paths->path($actual . '/mdev_type'));
        symlink('/sys/kernel/iommu_groups/27', $this->paths->path($actual . '/iommu_group'));
        symlink($this->paths->path($actual), $this->paths->path('/sys/bus/mdev/devices/' . $device['uuid']));
    }

    public function hasCommand(string $name): bool
    {
        return (bool)array_filter($this->commands, fn($argv) => $argv[0] === $name);
    }

    public function run(array $argv, int $timeout = 15, ?string $stdin = null): array
    {
        $this->commands[] = $argv;
        $key = implode(' ', array_slice($argv, 0, 2));
        if (isset($this->failures[$key])) return ['code' => $this->failures[$key], 'stdout' => '', 'stderr' => 'fake failure'];
        $output = '';
        if ($argv[0] === 'modinfo') $output = $argv[2] === 'version' ? "595.58.03\n" : "6.18.58-Unraid SMP preempt mod_unload\n";
        elseif ($argv[0] === 'nvidia-modprobe') $this->write('/sys/module/nvidia/version', "595.58.03\n");
        elseif ($argv[0] === 'modprobe') { /* success in fake filesystem */ }
        elseif ($argv[0] === 'env') {
            if ($argv[2] === 'nvidia-vgpu-mgr') {
                $library = $this->paths->path('/usr/lib64/libvgpu_unlock_rs.so');
                $this->process(600, 'nvidia-vgpu-mgr', 1, '1000-2000 r-xp 0 00:00 1 ' . $library . "\n");
            }
        } elseif ($argv[0] === 'virsh') {
            if ($argv[3] === 'list') $output = implode("\n", array_keys($this->domains));
            elseif ($argv[3] === 'dumpxml') $output = $this->domains[$argv[4]] ?? '';
            else throw new RuntimeException('Unexpected fake virsh command');
        } elseif ($argv[0] === 'mdevctl') {
            if ($argv[1] === 'stop') runtime_check(count($argv) === 4 && $argv[2] === '-u' && preg_match('/^[a-f0-9-]{36}$/D', $argv[3]) === 1, 'Real mdevctl stop accepts UUID only, never a parent argument');
            $uuid = $argv[array_search('-u', $argv, true) + 1];
            if ($argv[1] === 'list') {
                if (isset($this->definitions[$uuid])) {
                    $d = $this->definitions[$uuid];
                    $output = "$uuid {$d['pci']} {$d['type']} manual\n";
                }
            } elseif ($argv[1] === 'define') {
                $this->definitions[$uuid] = ['uuid' => $uuid, 'pci' => $argv[array_search('-p', $argv, true) + 1], 'type' => $argv[array_search('--type', $argv, true) + 1]];
            } elseif ($argv[1] === 'start') $this->active($this->definitions[$uuid]);
            elseif ($argv[1] === 'stop') {
                if (!$this->stopLeavesActive) {
                    $entry = $this->paths->path('/sys/bus/mdev/devices/' . $uuid);
                    unlink($entry . '/mdev_type');
                    unlink($entry . '/iommu_group');
                    unlink($entry);
                }
                if ($this->afterStop !== null) ($this->afterStop)($this);
            }
            elseif ($argv[1] === 'undefine') unset($this->definitions[$uuid]);
            else throw new RuntimeException('Unexpected fake mdevctl command');
        } else throw new RuntimeException('Real or unexpected command refused: ' . $argv[0]);
        return ['code' => 0, 'stdout' => $output, 'stderr' => ''];
    }

    public function close(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->directory);
    }
}

$tests = [];
$tests['disabled/status are read-only'] = function (RuntimeFixture $f): void {
    $settings = Store::defaults();
    runtime_check($f->runtime->ensure($settings, [])['ok'], 'Disabled runtime should keep UI usable');
    runtime_check($f->runtime->status($settings, [])['ok'], 'Status works without installed driver');
    runtime_check(!$f->commands && !is_dir($f->paths->runtimeDir) && !is_dir($f->paths->path('/etc')), 'Read-only paths must not create runtime state');
};
$tests['same-boot installation failure blocks every GPU mutation entrypoint'] = function (RuntimeFixture $f): void {
    $boot = '11111111-1111-4111-8111-111111111111';
    $f->write('/proc/sys/kernel/random/boot_id', $boot);
    foreach (['installing', 'failed'] as $status) {
        $state = json_encode(['status' => $status, 'boot_id' => $boot, 'message' => 'Installer completion is unknown'], JSON_THROW_ON_ERROR);
        $f->write('/boot/config/plugins/unraid-vgpu-manager/driver-state.json', $state);
        foreach ([false, true] as $driverEnabled) {
            $settings = $f->settings;
            $settings['driver']['enabled'] = $driverEnabled;
            foreach ([
                fn() => $f->runtime->ensure($settings, [$f->device]),
                fn() => $f->runtime->startDevice($f->device, $settings),
                fn() => $f->runtime->stopDevice($f->device),
                fn() => $f->runtime->removeDevice($f->device),
            ] as $operation) {
                $result = $operation();
                runtime_check(!$result['ok'] && $result['code'] === 'installation_incomplete', 'Every GPU mutation must respect the failed/unknown installation regardless of the restore switch');
            }
        }
        runtime_check(!$f->commands && !is_dir($f->paths->path('/etc')), 'Installation barrier must run before commands or runtime configuration writes');
        runtime_check($f->runtime->status($f->settings, [$f->device])['ok'], 'Failure must not block read-only runtime status');
        $settings = $f->settings;
        $settings['runtime']['enabled'] = false;
        runtime_check($f->runtime->ensure($settings, [])['code'] === 'runtime_disabled', 'A disabled no-op remains usable');
        runtime_check(file_get_contents($f->paths->configDir . '/driver-state.json') === $state, 'Runtime entrypoints must never erase installation evidence');
    }
};
$tests['installation barrier expires only with a different boot identity'] = function (RuntimeFixture $f): void {
    $f->write('/proc/sys/kernel/random/boot_id', '22222222-2222-4222-8222-222222222222');
    $f->write('/boot/config/plugins/unraid-vgpu-manager/driver-state.json', json_encode([
        'status' => 'failed', 'boot_id' => '11111111-1111-4111-8111-111111111111',
    ], JSON_THROW_ON_ERROR));
    runtime_check($f->runtime->ensure($f->settings, [])['ok'] && $f->hasCommand('env'), 'An older boot failure must not permanently disable a new cold runtime');
};
$tests['a pending cache selection is not a failed active driver'] = function (RuntimeFixture $f): void {
    $f->write('/boot/config/plugins/unraid-vgpu-manager/driver-state.json', json_encode([
        'status' => 'pending_reboot', 'boot_id' => '11111111-1111-4111-8111-111111111111',
    ], JSON_THROW_ON_ERROR));
    $f->settings['selected_package'] = ['driver' => '580.159.03'];
    runtime_check($f->runtime->ensure($f->settings, [])['ok'], 'A newly cached selection must not gate the existing installed runtime');
};
$tests['unverifiable installation evidence fails closed without mutation'] = function (RuntimeFixture $f): void {
    foreach (['damaged json', json_encode(['status' => 'failed', 'boot_id' => 'unknown'], JSON_THROW_ON_ERROR)] as $state) {
        $f->write('/boot/config/plugins/unraid-vgpu-manager/driver-state.json', $state);
        runtime_check($f->runtime->ensure($f->settings, [])['code'] === 'installation_state_unknown', 'Corrupt installation evidence cannot authorize runtime changes');
        runtime_check(!$f->commands && !is_dir($f->paths->path('/etc')), 'Invalid state must not trigger any runtime write');
    }
};
$tests['legacy plugin prevents takeover'] = function (RuntimeFixture $f): void {
    $f->write('/boot/config/plugins/novidio-vgpu-driver.plg', 'legacy');
    $f->write('/etc/vgpu_unlock/config.toml', "unlock = false\n");
    $r = $f->runtime->ensure($f->settings, [$f->device]);
    runtime_check(!$r['ok'] && $r['code'] === 'migration_required' && !$f->hasCommand('env'), 'Legacy owner must block initialization');
    runtime_check(file_get_contents($f->paths->path('/etc/vgpu_unlock/config.toml')) === "unlock = false\n", 'Foreign config retained');
};
$tests['scheduled legacy script prevents takeover'] = function (RuntimeFixture $f): void {
    $script = '/boot/config/plugins/user.scripts/scripts/vgpu-old/script';
    $f->write($script, 'nvidia-vgpu-mgr');
    $f->write('/boot/config/plugins/user.scripts/schedule.json', json_encode([$script => ['frequency' => 'boot', 'script' => $script]]));
    runtime_check($f->runtime->ensure($f->settings, [])['code'] === 'migration_required', 'Scheduled legacy launcher detected');
};
$tests['relative schedule keys detect only GPU initializers'] = function (RuntimeFixture $f): void {
    $f->write('/boot/config/plugins/user.scripts/scripts/nightly backup/script', 'echo backup');
    $f->write('/boot/config/plugins/user.scripts/schedule.json', json_encode([
        'nightly backup' => ['frequency' => 'daily'],
        'removed backup' => ['frequency' => 'daily'],
        'vgpu-disabled' => ['frequency' => 'disabled'],
    ]));
    runtime_check($f->runtime->ensure($f->settings, [])['ok'], 'Unrelated relative or removed tasks do not own the GPU');
    $f->write('/boot/config/plugins/user.scripts/scripts/gpu-init/script', 'nvidia-vgpud');
    $f->write('/boot/config/plugins/user.scripts/schedule.json', json_encode(['gpu-init' => ['frequency' => 'boot', 'script' => 'gpu-init']]));
    runtime_check($f->runtime->ensure($f->settings, [])['code'] === 'migration_required', 'Relative initializer script is inspected');
};
$tests['scheduled wrapper and go references block before any GPU mutation'] = function (RuntimeFixture $f): void {
    $f->write('/boot/config/plugins/user.scripts/scripts/startup/script', "bash /boot/config/vgpu-cold-init.sh\n");
    $f->write('/boot/config/vgpu-cold-init.sh', "LD_PRELOAD=/test /usr/bin/nvidia-vgpud\n");
    $f->write('/boot/config/plugins/user.scripts/schedule.json', json_encode(['startup' => ['frequency' => 'boot']]));
    $status = $f->runtime->status($f->settings, []);
    runtime_check($status['runtime']['legacy_owners'] === ['user-script:startup'], 'Wrapper must preserve the compatible user-script owner identity');
    runtime_check(isset($status['runtime']['startup_inventory']['references'][0]), 'Read-only status exposes the reference evidence');
    runtime_check($f->runtime->ensure($f->settings, [])['code'] === 'migration_required', 'Enabled wrapper must block runtime initialization');
    runtime_check($f->runtime->installationPreflight()['code'] === 'migration_required', 'Enabled wrapper must block cold package installation');
    runtime_check(!$f->commands && !is_dir($f->paths->path('/etc')), 'Ownership check precedes commands and writes');
    $f->write('/boot/config/plugins/user.scripts/schedule.json', json_encode(['startup' => ['frequency' => 'disabled']]));
    $f->write('/boot/config/go', "bash /boot/config/vgpu-cold-init.sh\n");
    runtime_check($f->runtime->ensure($f->settings, [])['code'] === 'migration_required', 'go is a startup root even with User Scripts disabled');
    runtime_check(!$f->commands, 'go conflict must not trigger commands');
};
$tests['comments and monitoring commands do not claim runtime ownership'] = function (RuntimeFixture $f): void {
    $f->write('/boot/config/plugins/user.scripts/scripts/vgpu-monitor/script', "# nvidia-vgpu-mgr\necho 'nvidia-vgpud'\ngrep nvidia-vgpu-mgr /var/log/syslog\npgrep -af nvidia-vgpud\nmdevctl list\necho \"\$(pgrep -x nvidia-vgpu-mgr)\"\npids=\$(pidof nvidia-vgpud)\n");
    $f->write('/boot/config/plugins/user.scripts/schedule.json', json_encode(['vgpu-monitor' => ['frequency' => 'daily']]));
    runtime_check($f->runtime->status($f->settings, [])['runtime']['legacy_owners'] === [], 'Mentioning daemon names is not executing them');
    runtime_check(!$f->runtime->status($f->settings, [])['runtime']['startup_inventory']['coverage']['complete'], 'Recognized monitoring substitutions remain non-evaluated coverage evidence');
    runtime_check($f->runtime->ensure($f->settings, [])['ok'], 'Read-only monitoring must not prevent a normal managed cold start');
};
$tests['foreign active mdev is not a takeover invitation'] = function (RuntimeFixture $f): void {
    $f->active($f->device);
    $r = $f->runtime->ensure($f->settings, [$f->device]);
    runtime_check(!$r['ok'] && $r['code'] === 'migration_required' && !$f->hasCommand('nvidia-modprobe'), 'Active foreign mdev retained');
};
$tests['unowned manager with correct library is not adopted'] = function (RuntimeFixture $f): void {
    $f->process(600, 'nvidia-vgpu-mgr', 1, '1000-2000 r-xp 0 00:00 1 ' . $f->paths->path('/usr/lib64/libvgpu_unlock_rs.so'));
    runtime_check($f->runtime->ensure($f->settings, [])['code'] === 'migration_required', 'Correct mapping alone does not establish ownership');
    runtime_check(!$f->hasCommand('env'), 'No second manager was started');
};
$tests['cached RM profiles without owned daemon require maintenance'] = function (RuntimeFixture $f): void {
    $f->write('/sys/module/nvidia/version', "595.58.03\n");
    runtime_check($f->runtime->ensure($f->settings, [])['code'] === 'migration_required', 'A daemon-less cached RM is not a cold runtime');
    runtime_check(!$f->hasCommand('env') && !is_dir($f->paths->path('/etc')), 'Cached RM state cannot trigger config overwrite');
};
$tests['module failure stops before daemon launch'] = function (RuntimeFixture $f): void {
    $f->failures['nvidia-modprobe'] = 1;
    runtime_check($f->runtime->ensure($f->settings, [])['code'] === 'module_start_failed', 'Module failure is not swallowed');
    runtime_check(!$f->hasCommand('env'), 'No daemon launch after module failure');
};
$tests['daemon timeout never retries or kills'] = function (RuntimeFixture $f): void {
    $f->failures['env LD_PRELOAD=' . $f->paths->path('/usr/lib64/libvgpu_unlock_rs.so')] = 124;
    $r = $f->runtime->ensure($f->settings, []);
    runtime_check(!$r['ok'] && $r['code'] === 'daemon_start_failed' && $r['exit_code'] === 124, 'Daemon timeout propagated');
    runtime_check(count(array_filter($f->commands, fn($a) => $a[0] === 'env')) === 1, 'Do not launch the next daemon after timeout');
};
$tests['live or reused PID file is never removed'] = function (RuntimeFixture $f): void {
    $f->process(700, 'unrelated-process');
    $f->write('/var/run/nvidia-vgpu-mgr/manager.pid', '700');
    $r = $f->runtime->ensure($f->settings, []);
    runtime_check(!$r['ok'] && is_file($f->paths->path('/var/run/nvidia-vgpu-mgr/manager.pid')), 'PID cleanup requires the PID to be demonstrably absent');
    runtime_check(!$f->hasCommand('env'), 'No daemon starts under a live PID entry');
};
$tests['cold start and repeated ensure are idempotent'] = function (RuntimeFixture $f): void {
    $f->write('/boot/config/plugins/unraid-vgpu-manager/profile_override.toml', "# initial\n");
    $first = $f->runtime->ensure($f->settings, [$f->device]);
    runtime_check($first['ok'] && $first['changed'], 'Cold start must complete: ' . json_encode($first));
    $f->commands = [];
    $again = $f->runtime->ensure($f->settings, [$f->device]);
    runtime_check($again['ok'] && !$again['changed'] && !$f->hasCommand('env') && !$f->hasCommand('modprobe'), 'Healthy active mdev must not restart stack');
    runtime_check(!is_link($f->paths->path('/etc/vgpu_unlock/profile_override.toml')), 'Active override must be a snapshot, not a pending-file link');
};
$tests['pending config does not overwrite active snapshot'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [])['ok'], 'Cold manager start');
    $f->write('/boot/config/plugins/unraid-vgpu-manager/profile_override.toml', '# pending');
    $r = $f->runtime->ensure($f->settings, []);
    runtime_check(!$r['ok'] && $r['code'] === 'maintenance_required', 'Pending configuration must not be declared active');
    runtime_check(file_get_contents($f->paths->path('/etc/vgpu_unlock/profile_override.toml')) === '', 'Running snapshot retained');
};
$tests['external config tamper is not silently repaired'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [])['ok'], 'Cold manager start');
    $f->write('/etc/vgpu_unlock/config.toml', "unlock = false\n");
    runtime_check($f->runtime->ensure($f->settings, [])['code'] === 'maintenance_required', 'External running-config changes require maintenance');
};
$tests['XML drift is not silently adopted'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [])['ok'], 'Cold manager start');
    $f->write('/usr/share/nvidia/vgpu/vgpuConfig.xml', '<old-or-foreign-xml/>');
    runtime_check($f->runtime->ensure($f->settings, [])['code'] === 'maintenance_required', 'Installed XML identity cannot change beneath owned manager');
};
$tests['wrong manager mapping is refused'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [])['ok'], 'Cold manager start');
    $f->write('/proc/600/maps', '1000-2000 r-xp 0 00:00 1 /usr/local/lib/old.so');
    runtime_check($f->runtime->ensure($f->settings, [])['code'] === 'migration_required', 'Unknown preload mapping cannot be adopted');
};
$tests['define failure never starts device'] = function (RuntimeFixture $f): void {
    $f->failures['mdevctl define'] = 1;
    $r = $f->runtime->ensure($f->settings, [$f->device]);
    runtime_check(!$r['ok'] && $r['code'] === 'define_failed', 'Define failure propagated');
    runtime_check(!(bool)array_filter($f->commands, fn($a) => $a[0] === 'mdevctl' && $a[1] === 'start'), 'Failed define must not continue to start');
};
$tests['live VM blocks target removal'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [$f->device])['ok'], 'Create managed idle device');
    $f->domains['Windows'] = '<domain><devices><hostdev type="mdev"><source><address uuid="' . $f->device['uuid'] . '"/></source></hostdev></devices></domain>';
    $f->commands = [];
    $r = $f->runtime->removeDevice($f->device);
    runtime_check(!$r['ok'] && $r['code'] === 'vm_busy', 'Active VM must block mutation');
    runtime_check(!$f->hasCommand('mdevctl') && isset($f->definitions[$f->device['uuid']]), 'Busy target retained');
};
$tests['unknown libvirt state blocks stop'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [$f->device])['ok'], 'Create managed idle device');
    $f->failures['virsh -c'] = 124;
    runtime_check($f->runtime->stopDevice($f->device)['code'] === 'usage_unknown', 'Unknown libvirt state is not proof of idleness');
};
$tests['unknown file descriptors block stop'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [$f->device])['ok'], 'Create managed idle device');
    $f->process(700, 'unknown-process');
    rmdir($f->paths->path('/proc/700/fd'));
    runtime_check($f->runtime->stopDevice($f->device)['code'] === 'usage_unknown', 'A process with uninspectable handles cannot be treated as idle');
};
$tests['Immich CUDA and Beszel NVML handles do not block target stop or removal'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [$f->device])['ok'], 'Create managed idle device');
    foreach ([26316 => 'python', 30853 => 'agent', 700 => 'nvidia-smi'] as $pid => $name) {
        $f->process($pid, $name);
        $f->write('/proc/' . $pid . '/cgroup', '0::/docker/0123456789abcdef0123456789abcdef');
        foreach (['/dev/nvidia0', '/dev/nvidiactl', '/dev/nvidia-uvm', '/dev/nvidia-modeset', '/dev/dri/renderD128'] as $index => $path) {
            symlink($path, $f->paths->path('/proc/' . $pid . '/fd/' . ($index + 4)));
        }
    }
    $f->write('/var/run/nvidia-vgpu-mgr/manager.pid', '600');
    $before = file_get_contents($f->paths->path('/etc/vgpu_unlock/config.toml'));
    $f->commands = [];
    runtime_check($f->runtime->stopDevice($f->device)['ok'], 'Host CUDA/NVML/DRM users do not own the target mdev');
    runtime_check(isset($f->definitions[$f->device['uuid']]), 'Stop retains the persistent definition');
    runtime_check($f->runtime->startDevice($f->device, $f->settings)['ok'], 'The last mdev can be recreated in the fixture without restarting the GPU stack');
    runtime_check($f->runtime->removeDevice($f->device)['ok'] && !$f->definitions, 'Removal must undefine only the now-unused target');
    runtime_check(!$f->hasCommand('env') && !$f->hasCommand('modprobe') && !$f->hasCommand('nvidia-modprobe'), 'Per-device lifecycle must not reinitialize the physical GPU');
    runtime_check(file_get_contents($f->paths->path('/etc/vgpu_unlock/config.toml')) === $before && is_file($f->paths->path('/var/run/nvidia-vgpu-mgr/manager.pid')), 'Active configuration and manager PID file remain untouched');
    foreach ([26316, 30853, 700] as $pid) runtime_check(is_link($f->paths->path('/proc/' . $pid . '/fd/4')), 'Host GPU clients remain untouched');
};
$tests['target VFIO group and mapped cdev holders block stop and removal'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [$f->device])['ok'], 'Create managed idle device');
    $f->process(700, 'unmanaged-qemu');
    $f->write('/proc/700/cgroup', '0::/docker/0123456789abcdef0123456789abcdef');
    $target = '/sys/devices/pci0000:00/' . $f->device['pci'] . '/' . $f->device['uuid'];
    foreach (['/dev/vfio/27', '/dev/vfio/devices/vfio42', 'anon_inode:[vfio-device]'] as $path) {
        symlink($path, $f->paths->path('/proc/700/fd/4'));
        $f->write('/proc/700/fdinfo/4', 'vfio-device-syspath: ' . $target . "\n");
        $f->commands = [];
        foreach (['stopDevice', 'removeDevice'] as $operation) {
            $r = $f->runtime->$operation($f->device);
            runtime_check($r['code'] === 'gpu_busy' && $r['holders'][0]['container'] === '0123456789ab', 'Target device/group remains protected, independent of its process or cdev number');
        }
        runtime_check(!$f->hasCommand('mdevctl') && isset($f->definitions[$f->device['uuid']]), 'Busy target must not be stopped or undefined');
        unlink($f->paths->path('/proc/700/fd/4'));
    }
};
$tests['unknown VFIO ownership is not guessed from cdev numbering'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [$f->device])['ok'], 'Create managed idle device');
    $f->process(700, 'unmanaged-qemu');
    foreach (['/dev/vfio/devices/vfio42', 'anon_inode:[vfio-device]'] as $path) {
        symlink($path, $f->paths->path('/proc/700/fd/4'));
        $f->commands = [];
        $r = $f->runtime->removeDevice($f->device);
        runtime_check($r['code'] === 'usage_unknown' && $r['holders'][0]['pid'] === 700, 'Unmappable VFIO descriptor must remain an explicit unknown, not proof of idleness');
        runtime_check(!$f->hasCommand('mdevctl'), 'Unknown target ownership must cause no device mutation');
        unlink($f->paths->path('/proc/700/fd/4'));
    }
};
$tests['unrelated VFIO groups and mapped device FDs do not block target removal'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [$f->device])['ok'], 'Create first managed device');
    $other = array_replace($f->device, ['uuid' => '868bfe06-47c1-40a3-82df-ffd518be72d3']);
    runtime_check($f->runtime->startDevice($other, $f->settings)['ok'], 'Create unrelated mdev');
    unlink($f->paths->path('/sys/bus/mdev/devices/' . $other['uuid'] . '/iommu_group'));
    symlink('/sys/kernel/iommu_groups/42', $f->paths->path('/sys/bus/mdev/devices/' . $other['uuid'] . '/iommu_group'));
    $f->domains['OtherVM'] = '<domain><devices><hostdev><source><address uuid="' . $other['uuid'] . '"/></source></hostdev></devices></domain>';
    $f->process(700, 'qemu');
    symlink('/dev/vfio/42', $f->paths->path('/proc/700/fd/3'));
    symlink('anon_inode:[vfio-device]', $f->paths->path('/proc/700/fd/4'));
    symlink('/dev/vfio/devices/vfio27', $f->paths->path('/proc/700/fd/5'));
    foreach ([4, 5] as $fd) $f->write('/proc/700/fdinfo/' . $fd, 'vfio-device-syspath: /sys/devices/pci0000:00/' . $other['pci'] . '/' . $other['uuid'] . "\n");
    $f->commands = [];
    runtime_check($f->runtime->removeDevice($f->device)['ok'], 'A different VM/VFIO device on the same physical GPU is not the target');
    runtime_check(isset($f->definitions[$other['uuid']]) && is_link($f->paths->path('/sys/bus/mdev/devices/' . $other['uuid'])), 'Other mdev and persistent definition remain intact');
    foreach ($f->commands as $argv) {
        if ($argv[0] === 'mdevctl') runtime_check($argv[array_search('-u', $argv, true) + 1] === $f->device['uuid'], 'Only the requested UUID may be passed to mdevctl');
    }
};
$tests['whole GPU cold initialization and install checks retain host handle barriers'] = function (RuntimeFixture $f): void {
    $f->process(700, 'python');
    foreach (['/dev/nvidia0', '/dev/nvidiactl', '/dev/nvidia-uvm', '/dev/nvidia-modeset', '/dev/nvidia-caps/nvidia-cap1'] as $path) {
        symlink($path, $f->paths->path('/proc/700/fd/4'));
        $f->commands = [];
        runtime_check($f->runtime->ensure($f->settings, [])['code'] === 'gpu_busy', 'Cold initialization still requires the whole GPU to be unused');
        runtime_check(!$f->hasCommand('env') && !$f->hasCommand('modprobe') && !$f->hasCommand('nvidia-modprobe'), 'Busy whole GPU must not initialize');
        $r = $f->runtime->installationPreflight();
        runtime_check($r['code'] === 'gpu_busy' && $r['holders'][0]['pid'] === 700, 'Cold package installation still checks host GPU ownership');
        unlink($f->paths->path('/proc/700/fd/4'));
    }
};
$tests['whole GPU install retains arbitrary VFIO ownership barrier'] = function (RuntimeFixture $f): void {
    $f->process(700, 'qemu');
    foreach (['/dev/vfio/42', '/dev/vfio/devices/vfio42'] as $path) {
        symlink($path, $f->paths->path('/proc/700/fd/4'));
        runtime_check($f->runtime->installationPreflight()['code'] === 'gpu_busy', 'Whole-stack cold installation must not adopt the target-only filter');
        unlink($f->paths->path('/proc/700/fd/4'));
    }
};
$tests['successful command without verified target disappearance retains definition'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [$f->device])['ok'], 'Create managed idle device');
    $f->stopLeavesActive = true;
    $f->commands = [];
    runtime_check($f->runtime->removeDevice($f->device)['code'] === 'stop_unverified', 'Exit zero alone cannot prove stop');
    runtime_check(isset($f->definitions[$f->device['uuid']]) && !array_filter($f->commands, fn($a) => $a[0] === 'mdevctl' && $a[1] === 'undefine'), 'Unverified stop must never remove the persistent identity');
};
$tests['target VM is rechecked before removing a stopped definition'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [$f->device])['ok'], 'Create managed idle device');
    $f->afterStop = function (RuntimeFixture $f): void {
        $f->domains['RacingVM'] = '<domain><devices><hostdev><source><address uuid="' . $f->device['uuid'] . '"/></source></hostdev></devices></domain>';
    };
    $f->commands = [];
    runtime_check($f->runtime->removeDevice($f->device)['code'] === 'vm_busy', 'A new VM reference must block undefine after stop');
    runtime_check(isset($f->definitions[$f->device['uuid']]) && !array_filter($f->commands, fn($a) => $a[0] === 'mdevctl' && $a[1] === 'undefine'), 'Racing ownership must retain the saved definition');
};
$tests['running and paused target VM references both protect stop and remove'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [$f->device])['ok'], 'Create managed idle device');
    foreach (['RunningVM', 'PausedVM'] as $name) {
        $f->domains = [$name => '<domain><devices><hostdev><source><address uuid="' . $f->device['uuid'] . '"/></source></hostdev></devices></domain>'];
        $f->commands = [];
        runtime_check($f->runtime->stopDevice($f->device)['code'] === 'vm_busy' && $f->runtime->removeDevice($f->device)['code'] === 'vm_busy', 'Every active libvirt target reference blocks mutation, including paused domains');
        runtime_check(!$f->hasCommand('mdevctl'), 'No mdev command is allowed while a target VM is active');
    }
};
$tests['host GPU users do not prevent switching the stopped UUID from 1Q to 2Q'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [$f->device])['ok'], 'Create old 1Q');
    $f->write('/sys/bus/pci/devices/0000:01:00.0/mdev_supported_types/nvidia-763/name', 'NVIDIA RTXA5500-2Q');
    $f->write('/sys/bus/pci/devices/0000:01:00.0/mdev_supported_types/nvidia-763/available_instances', '0');
    $f->process(700, 'python');
    symlink('/dev/nvidia-uvm', $f->paths->path('/proc/700/fd/4'));
    $f->afterStop = fn(RuntimeFixture $f) => $f->write('/sys/bus/pci/devices/0000:01:00.0/mdev_supported_types/nvidia-763/available_instances', '12');
    $f->commands = [];
    runtime_check($f->runtime->removeDevice($f->device)['ok'], 'Unused 1Q must be stopped and undefined without touching host CUDA');
    $two = array_replace($f->device, ['profile' => 'NVIDIA RTXA5500-2Q', 'type' => 'nvidia-763']);
    runtime_check($f->runtime->startDevice($two, $f->settings)['ok'], 'The original UUID may be reused for the now-available 2Q');
    runtime_check($f->definitions[$two['uuid']]['type'] === 'nvidia-763' && basename((string)readlink($f->paths->path('/sys/bus/mdev/devices/' . $two['uuid'] . '/mdev_type'))) === 'nvidia-763', 'Both saved definition and active target must reflect 2Q');
    runtime_check(!$f->hasCommand('env') && !$f->hasCommand('modprobe') && is_link($f->paths->path('/proc/700/fd/4')), 'Profile switch must not restart GPU services or touch the host client');
};
$tests['stop timeout retains definition and PID files'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [$f->device])['ok'], 'Create managed idle device');
    $f->write('/var/run/nvidia-vgpu-mgr/manager.pid', '600');
    $f->failures['mdevctl stop'] = 124;
    $f->commands = [];
    $r = $f->runtime->removeDevice($f->device);
    runtime_check(!$r['ok'] && $r['code'] === 'stop_failed' && isset($f->definitions[$f->device['uuid']]), 'Stop timeout must retain definition');
    runtime_check(is_file($f->paths->path('/var/run/nvidia-vgpu-mgr/manager.pid')), 'Timeout must not remove live PID file');
    runtime_check(!(bool)array_filter($f->commands, fn($a) => $a[0] === 'mdevctl' && $a[1] === 'undefine'), 'No undefine after failed stop');
};
$tests['ordinary stop failures are not reported as timeouts'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [$f->device])['ok'], 'Create managed idle device');
    foreach ([1, 2, 124, 127] as $code) {
        $f->failures['mdevctl stop'] = $code;
        $f->commands = [];
        $r = $f->runtime->removeDevice($f->device);
        runtime_check($r['code'] === 'stop_failed' && $r['exit_code'] === $code && $r['completion_unknown'] === ($code === 124), 'Only exit 124 represents timeout/unknown completion');
        runtime_check($code === 124 ? str_contains($r['message'], 'timed out') : str_contains($r['message'], 'exit code ' . $code) && !str_contains($r['message'], 'timed out'), 'UI must distinguish command failure from timeout without exposing arbitrary callout stderr');
        runtime_check(isset($f->definitions[$f->device['uuid']]) && !array_filter($f->commands, fn($a) => $a[0] === 'mdevctl' && $a[1] === 'undefine'), 'Every failed stop retains the target definition');
        $stop = array_values(array_filter($f->commands, fn($a) => $a[0] === 'mdevctl' && $a[1] === 'stop'));
        runtime_check($stop === [['mdevctl', 'stop', '-u', $f->device['uuid']]], 'The production argv must match the real UUID-only CLI contract');
    }
};
$tests['unused active mdev may be stopped and removed'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [$f->device])['ok'], 'Create managed idle device');
    $r = $f->runtime->removeDevice($f->device);
    runtime_check($r['ok'] && !isset($f->definitions[$f->device['uuid']]) && !is_link($f->paths->path('/sys/bus/mdev/devices/' . $f->device['uuid'])), 'Active alone must not imply VM busy');
};
$tests['unrelated running VM does not block idle creation'] = function (RuntimeFixture $f): void {
    $f->domains['OtherVM'] = '<domain><devices/></domain>';
    runtime_check($f->runtime->ensure($f->settings, [$f->device])['ok'], 'Unrelated VM does not prevent non-destructive creation');
    runtime_check($f->runtime->stopDevice($f->device)['ok'], 'Unrelated VM does not own the target');
};
$tests['VM using another mdev does not prevent explicit idle creation'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [$f->device])['ok'], 'Create first managed device');
    $f->domains['Windows'] = '<domain><devices><hostdev type="mdev"><source><address uuid="' . $f->device['uuid'] . '"/></source></hostdev></devices></domain>';
    $other = $f->device;
    $other['uuid'] = '868bfe06-47c1-40a3-82df-ffd518be72d3';
    runtime_check($f->runtime->startDevice($other, $f->settings)['ok'], 'Non-destructive creation does not stop or reject unrelated active devices');
    runtime_check($f->runtime->stopDevice($other)['ok'], 'Unused target may stop while another VM owns a different target');
};
$tests['existing mismatched definition is never overwritten'] = function (RuntimeFixture $f): void {
    $f->definitions[$f->device['uuid']] = $f->device + [];
    $f->definitions[$f->device['uuid']]['type'] = 'nvidia-763';
    runtime_check($f->runtime->ensure($f->settings, [$f->device])['code'] === 'definition_conflict', 'Definition identity mismatch blocks start');
    runtime_check($f->runtime->removeDevice($f->device)['code'] === 'definition_conflict', 'Definition mismatch cannot be silently undefined either');
};
$tests['profile name resolves a renumbered type cache'] = function (RuntimeFixture $f): void {
    $base = '/sys/bus/pci/devices/0000:01:00.0/mdev_supported_types/';
    rename($f->paths->path($base . 'nvidia-762'), $f->paths->path($base . 'nvidia-999'));
    $r = $f->runtime->ensure($f->settings, [$f->device]);
    runtime_check($r['ok'] && $f->definitions[$f->device['uuid']]['type'] === 'nvidia-999', 'Profile name wins over stale numeric cache');
    runtime_check($f->runtime->stopDevice($f->device)['ok'], 'Stale numeric cache must not prevent stopping the matching named profile');
};
$tests['zero available instances prevents creation'] = function (RuntimeFixture $f): void {
    $f->write('/sys/bus/pci/devices/0000:01:00.0/mdev_supported_types/nvidia-762/available_instances', '0');
    runtime_check($f->runtime->ensure($f->settings, [$f->device])['code'] === 'profile_capacity_unknown', 'Availability must be checked before define');
    runtime_check(!$f->definitions, 'Zero availability must not create a definition');
};
$tests['uninterruptible manager is not reported healthy'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [])['ok'], 'Cold manager start');
    $stat = $f->paths->path('/proc/600/stat');
    file_put_contents($stat, str_replace(') S ', ') D ', (string)file_get_contents($stat)));
    runtime_check($f->runtime->ensure($f->settings, [])['code'] === 'manager_not_ready', 'Manager D state must not pass health validation');
};
$tests['PID reuse prevents ownership adoption'] = function (RuntimeFixture $f): void {
    runtime_check($f->runtime->ensure($f->settings, [])['ok'], 'Cold manager start');
    $stat = $f->paths->path('/proc/600/stat');
    file_put_contents($stat, str_replace('12345', '99999', (string)file_get_contents($stat)));
    runtime_check($f->runtime->ensure($f->settings, [])['code'] === 'migration_required', 'Reused manager PID must not be trusted');
};
$tests['unsafe XML override and old library path are rejected'] = function (RuntimeFixture $f): void {
    $s = $f->settings; $s['runtime']['xml_override'] = true;
    runtime_check(!$f->runtime->ensure($s, [])['ok'], 'XML override is not blindly copied');
    $s = $f->settings; $s['runtime']['unlock_library'] = '/usr/local/lib/libvgpu_unlock_rs.so';
    runtime_check(!$f->runtime->ensure($s, [])['ok'], 'Old library path is not a fallback');
    runtime_check(!$f->hasCommand('env'), 'Unsupported configuration must not start daemon');
};

foreach ($tests as $name => $test) {
    $fixture = new RuntimeFixture();
    try {
        $test($fixture);
        foreach ($fixture->commands as $argv) {
            runtime_check(!in_array($argv[0], ['kill', 'killall', 'rmmod', 'removepkg', 'installpkg', 'upgradepkg'], true), 'Forbidden global/destructive command');
            runtime_check(!($argv[0] === 'modprobe' && in_array('-r', $argv, true)), 'No module unloading');
        }
        echo 'PASS runtime: ' . $name . "\n";
    } finally {
        $fixture->close();
    }
}
echo 'Runtime: ' . count($tests) . " hardware-free scenarios passed\n";
