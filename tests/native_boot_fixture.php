<?php
declare(strict_types=1);
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/Application.php';

/** Real tar/xz and a private filesystem. GPU, modinfo and pkgtools are passive fixtures. */
final class NativeBootFixture
{
    public const FIRST = '11111111-1111-4111-8111-111111111111';
    public const SECOND = '22222222-2222-4222-8222-222222222222';
    public const THIRD = '33333333-3333-4333-8333-333333333333';
    public string $root;
    public Vgpu\Paths $paths;
    public Vgpu\Store $store;
    public Vgpu\Download $download;
    public Vgpu\Runtime $runtime;
    public Vgpu\Application $app;
    public Vgpu\NativeBoot $native;
    public array $calls = [];
    public array $definitions = [];
    public array $nativeInstalled = [];
    public string $failure = '';
    public array $device = ['uuid' => 'ae9572f6-1259-4136-9aa4-497dda98d2db', 'pci' => '0000:01:00.0', 'profile' => 'NVIDIA RTXA5500-1Q', 'type' => 'nvidia-762'];

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/vgpu-native-boot-' . bin2hex(random_bytes(7));
        mkdir($this->root, 0700);
        $this->paths = new Vgpu\Paths($this->root);
        $this->store = new Vgpu\Store($this->paths);
        $this->download = new Vgpu\Download($this->paths, $this->run(...));
        $this->runtime = new Vgpu\Runtime($this->paths, $this->run(...));
        $this->app = new Vgpu\Application($this->store, $this->runtime, $this->download, $this->run(...));
        $this->native = new Vgpu\NativeBoot($this->store, $this->download);
        $this->put('/proc/sys/kernel/osrelease', '6.18.58-Unraid');
        $this->put('/proc/sys/kernel/random/boot_id', self::FIRST);
        $this->process(1, 'init');
        $this->directory('/sys/bus/mdev/devices');
        $this->put('/etc/rc.d/rc.modules', 'native rc.modules sentinel: NEVER MODIFIED');
        $this->put('/etc/rc.d/rc.local', 'native rc.local sentinel: NEVER MODIFIED');
        $settings = Vgpu\Store::defaults();
        $settings['driver'] = ['enabled' => true, 'enabled_boot_id' => self::FIRST];
        $settings['runtime']['enabled'] = true;
        $this->store->saveSettings($settings);
        $this->store->saveDevices([$this->device]);
    }

    public function directory(string $name): string
    {
        $file = $this->paths->path($name);
        if (!is_dir($file)) mkdir($file, 0700, true);
        return $file;
    }

    public function put(string $name, string $data): string
    {
        $file = $this->paths->path($name);
        if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
        file_put_contents($file, $data);
        return $file;
    }

    public function archive(int $build = 3, string $kernel = '6.18.58-Unraid'): array
    {
        $filename = Vgpu\PackageIdentity::filename('595.58.03', $kernel, $build);
        $tree = $this->directory('/fixture-archives/' . $kernel . '-' . $build);
        foreach (Vgpu\InstallContract::REQUIRED_MODULES as $module) $this->put(substr($tree, strlen($this->root)) . '/lib/modules/' . $kernel . '/kernel/drivers/video/' . $module . '.ko', 'fixture:' . $kernel . ':595.58.03:' . $build . ':' . $module);
        foreach (['usr/bin/nvidia-vgpud', 'usr/bin/nvidia-vgpu-mgr', 'usr/lib64/libvgpu_unlock_rs.so', 'usr/share/nvidia/vgpu/vgpuConfig.xml', 'usr/lib64/libcuda.so.1', 'usr/lib64/libnvcuvid.so.1', 'usr/lib64/libnvidia-encode.so.1', 'usr/lib64/libnvidia-ml.so.1'] as $member) $this->put(substr($tree, strlen($this->root)) . '/' . $member, 'passive paired fixture ' . $build);
        $directory = $this->directory('/boot/config/plugins/unraid-vgpu-manager/driver-cache/' . substr($kernel, 0, -7));
        $package = $directory . '/' . $filename;
        $process = new Vgpu\Process();
        $result = $process(['tar', '--create', '--xz', '--file', $package, '--owner=0', '--group=0', '--numeric-owner', '--directory', $tree, '.'], 30);
        if ($result['code'] !== 0) throw new RuntimeException('Cannot create passive native archive: ' . $result['stderr']);
        $md5 = md5_file($package);
        file_put_contents($package . '.md5', $md5 . '  ' . $filename . "\n");
        return ['filename' => $filename, 'path' => $package, 'driver' => '595.58.03', 'build' => $build, 'kernel' => $kernel, 'md5' => $md5, 'source' => 'local', 'provider' => 'local', 'checksum_verified' => true, 'saved_boot_id' => self::FIRST];
    }

    public function select(array $package): void
    {
        $this->native->remember($package);
        $this->app->dispatch('cache-select', ['filename' => $package['filename']]);
    }

    /** Model Unraid's exact package path/filter/MD5, before any .plg entrypoint. */
    public function reboot(string $boot = self::SECOND, string $kernel = '6.18.58-Unraid', bool $loaded = false): void
    {
        // Model a genuinely fresh RAM filesystem across multiple boot cycles.
        foreach (['/sys', '/var/run/unraid-vgpu-manager', '/var/lib/pkgtools/packages', '/etc/vgpu_unlock'] as $relative) $this->removeTree($this->paths->path($relative));
        foreach (glob($this->paths->path('/proc/[0-9]*')) ?: [] as $process) $this->removeTree($process);
        $this->process(1, 'init');
        $this->directory('/sys/bus/mdev/devices');
        $this->definitions = [];
        if (is_file($this->root . '/native-order.log')) unlink($this->root . '/native-order.log');
        $this->put('/proc/sys/kernel/random/boot_id', $boot);
        $this->put('/proc/sys/kernel/osrelease', $kernel);
        $this->nativeInstalled = [];
        $realNative = getenv('NATIVE_RC_MODULES') === '1';
        if ($realNative) {
            $result = (new Vgpu\Process())(['python3', __DIR__ . '/native_rc_runner.py', '--root', $this->root, '--kernel', $kernel], 90);
            if ($result['code'] !== 0) throw new RuntimeException('Real native startup fixture failed: ' . $result['stdout'] . $result['stderr']);
            $order = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR)['order'];
            if (end($order) !== 'depmod') throw new RuntimeException('Native depmod must precede plugin initialization');
        }
        foreach (glob($this->paths->bootPackagesDir . '/' . substr($kernel, 0, -7) . '/*') ?: [] as $package) {
            if (!is_file($package) || !fnmatch('*-' . $kernel . '-*.t?z', $package)) continue;
            if (substr(trim((string)@file_get_contents($package . '.md5')), 0, 32) !== md5_file($package)) continue;
            $this->nativeInstalled[] = basename($package);
            if ($realNative) continue;
            $process = new Vgpu\Process();
            $response = $process(['tar', '--extract', '--xz', '--file', $package, '--directory', $this->root, '--no-same-owner'], 30);
            if ($response['code'] !== 0) throw new RuntimeException('Native fixture extraction failed');
            $list = $process(['tar', '--list', '--xz', '--file', $package], 30)['stdout'];
            $this->put('/var/lib/pkgtools/packages/' . basename($package, '.txz'), 'PACKAGE NAME: ' . basename($package, '.txz') . "\nPACKAGE LOCATION: " . $package . "\nFILE LIST:\n" . $list);
        }
        if ($loaded) {
            $this->put('/sys/module/nvidia/version', '595.58.03');
            $this->put('/sys/module/nvidia/srcversion', $this->sourceVersion($this->paths->path('/lib/modules/' . $kernel . '/kernel/drivers/video/nvidia.ko')));
        }
        $this->calls = [];
        clearstatcache();
    }

    public function sourceVersion(string $module): string { return strtoupper(substr(sha1_file($module), 0, 24)); }

    public function run(array $argv, int $timeout = 30, ?string $stdin = null): array
    {
        $this->calls[] = $argv;
        $name = basename($argv[0]);
        if (in_array($name, ['installpkg', 'upgradepkg', 'depmod', 'ldconfig', 'kill', 'killall', 'rmmod', 'reboot'], true)) throw new RuntimeException('Plugin attempted a forbidden installation/system command: ' . $name);
        if ($name === $this->failure) return ['code' => 1, 'stdout' => '', 'stderr' => 'injected passive failure'];
        if ($name === 'curl') {
            if (!preg_match('/^url = "(https:\/\/[^"\\r\\n]+)"/m', (string)$stdin, $match)) throw new RuntimeException('Unexpected fixture HTTP configuration');
            $filename = basename(parse_url($match[1], PHP_URL_PATH));
            $file = $this->paths->cacheDir . '/6.18.58/' . $filename;
            if (!is_file($file)) throw new RuntimeException('Unexpected fixture HTTP URL');
            file_put_contents($argv[array_search('--output', $argv, true) + 1], file_get_contents($file));
            return ['code' => 0, 'stdout' => "200\napplication/octet-stream\n\n", 'stderr' => ''];
        }
        if (in_array($name, ['tar', 'xz'], true)) return (new Vgpu\Process())($argv, $timeout, $stdin);
        $output = '';
        $kernel = trim((string)file_get_contents($this->paths->path('/proc/sys/kernel/osrelease')));
        if ($name === 'modinfo') {
            $file = $argv[3] === 'nvidia' ? $this->paths->path('/lib/modules/' . $kernel . '/kernel/drivers/video/nvidia.ko') : $argv[3];
            $contents = (string)@file_get_contents($file);
            if (!str_starts_with($contents, 'fixture:')) return ['code' => 1, 'stdout' => '', 'stderr' => 'missing fixture module'];
            $parts = explode(':', $contents);
            $output = match ($argv[2]) { 'version' => $parts[2], 'vermagic' => $parts[1] . ' SMP preempt mod_unload', 'srcversion' => $this->sourceVersion($file), default => '' };
        } elseif ($name === 'nvidia-modprobe') {
            $this->put('/sys/module/nvidia/version', '595.58.03');
            $this->put('/sys/module/nvidia/srcversion', $this->sourceVersion($this->paths->path('/lib/modules/' . $kernel . '/kernel/drivers/video/nvidia.ko')));
        } elseif ($name === 'modprobe') {
            if (in_array('-r', $argv, true)) throw new RuntimeException('Module unload is forbidden');
        } elseif ($name === 'env' && end($argv) === 'nvidia-vgpu-mgr') {
            $this->directory('/sys/bus/pci/drivers/nvidia/' . $this->device['pci']);
            $this->process(600, 'nvidia-vgpu-mgr');
            $this->put('/proc/600/maps', '1000-2000 r-xp 0 00:00 1 ' . $this->paths->path('/usr/lib64/libvgpu_unlock_rs.so') . "\n");
            $this->put('/sys/bus/pci/devices/' . $this->device['pci'] . '/mdev_supported_types/' . $this->device['type'] . '/name', $this->device['profile']);
            $this->put('/sys/bus/pci/devices/' . $this->device['pci'] . '/mdev_supported_types/' . $this->device['type'] . '/available_instances', '4');
        } elseif ($name === 'env' && end($argv) === 'nvidia-vgpud') {
            // Passive daemon result only; nothing is executed on the host.
        } elseif ($name === 'mdevctl') {
            $uuid = $argv[array_search('-u', $argv, true) + 1];
            if ($argv[1] === 'list' && isset($this->definitions[$uuid])) $output = $uuid . ' ' . $this->device['pci'] . ' ' . $this->device['type'] . " manual\n";
            elseif ($argv[1] === 'define') $this->definitions[$uuid] = true;
            elseif ($argv[1] === 'start') {
                $actual = $this->directory('/sys/devices/pci0000:00/' . $this->device['pci'] . '/' . $uuid);
                symlink($this->paths->path('/sys/bus/pci/devices/' . $this->device['pci'] . '/mdev_supported_types/' . $this->device['type']), $actual . '/mdev_type');
                symlink($actual, $this->paths->path('/sys/bus/mdev/devices/' . $uuid));
            }
        } else throw new RuntimeException('Unexpected plugin command refused by fixture: ' . $name);
        return ['code' => 0, 'stdout' => $output, 'stderr' => ''];
    }

    public function process(int $pid, string $name): void
    {
        $this->put('/proc/' . $pid . '/comm', $name);
        $this->put('/proc/' . $pid . '/status', "PPid:\t1\n");
        $fields = array_fill(0, 20, '0'); $fields[0] = 'S'; $fields[1] = '1'; $fields[19] = '12345';
        $this->put('/proc/' . $pid . '/stat', $pid . ' (' . $name . ') ' . implode(' ', $fields));
        $this->directory('/proc/' . $pid . '/fd');
    }

    public function close(): void
    {
        if (!str_starts_with($this->root, sys_get_temp_dir() . '/vgpu-native-boot-')) throw new RuntimeException('Unsafe native fixture cleanup');
        $this->removeTree($this->root);
    }

    private function removeTree(string $directory): void
    {
        if ($directory !== $this->root && !str_starts_with($directory, $this->root . '/')) throw new RuntimeException('Fixture cleanup escaped its private root');
        if (!file_exists($directory) && !is_link($directory)) return;
        if (is_link($directory)) { unlink($directory); return; }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($directory);
    }
}
