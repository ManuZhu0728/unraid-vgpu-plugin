<?php
declare(strict_types=1);

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/Upgrade.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/Installer.php';

use Vgpu\Download;
use Vgpu\Installer;
use Vgpu\Paths;
use Vgpu\Runtime;
use Vgpu\Store;
use Vgpu\Upgrade;

$installerChecks = 0;
function installer_check(bool $condition, string $message): void
{
    global $installerChecks;
    ++$installerChecks;
    if (!$condition) throw new RuntimeException($message);
}

final class InstallerFixture
{
    public const OLD_BOOT = '11111111-1111-4111-8111-111111111111';
    public const BOOT = '22222222-2222-4222-8222-222222222222';
    public const NEXT_BOOT = '33333333-3333-4333-8333-333333333333';
    public string $root;
    public Paths $paths;
    public Store $store;
    public Installer $installer;
    public array $settings;
    public array $package;
    public array $calls = [];
    public string $installed = '';
    public string $installedMagic = '6.18.58-Unraid SMP preempt mod_unload';
    public string $archiveDriver = '595.58.03';
    public string $archiveMagic = '6.18.58-Unraid SMP preempt mod_unload';
    public string $fail = '';
    public bool $badAfterInstall = false;
    public bool $loadAfterVerify = false;
    public bool $overwriteConfigs = false;
    public int $installCode = 0;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/vgpu-installer-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        $this->paths = new Paths($this->root);
        $this->store = new Store($this->paths);
        $this->write('/proc/sys/kernel/osrelease', "6.18.58-Unraid\n");
        $this->write('/proc/sys/kernel/random/boot_id', self::BOOT . "\n");
        $name = 'nvidia-595.58.03-6.18.58-Unraid-1.txz';
        $path = $this->paths->cacheDir . '/6.18.58/' . $name;
        $this->write('/boot/config/plugins/unraid-vgpu-manager/packages/6.18.58/' . $name, "\xfd7zXZ\x00fake driver archive");
        $md5 = md5_file($path);
        file_put_contents($path . '.md5', $md5 . '  ' . $name . "\n");
        $this->package = ['filename' => $name, 'path' => $path, 'kernel' => '6.18.58-Unraid',
            'driver' => '595.58.03', 'md5' => $md5, 'source' => 'local', 'saved_boot_id' => self::OLD_BOOT];
        $this->settings = Store::defaults();
        $this->settings['upgrade'] = ['enabled' => true, 'driver_branch' => '595', 'enabled_boot_id' => self::OLD_BOOT];
        $this->settings['driver'] = ['enabled' => true, 'enabled_boot_id' => self::OLD_BOOT];
        $this->settings['source'] = Store::validateSource(['url' => 'https://git.example.test/owner/repo', 'mode' => 'auto']);
        $this->settings['selected_package'] = $this->package;
        $download = new Download($this->paths, $this->run(...));
        $runtime = new Runtime($this->paths, $this->run(...));
        $this->installer = new Installer($this->store, $download, $runtime, $this->run(...));
    }

    public function write(string $path, string $body): void
    {
        $path = $this->paths->path($path);
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        file_put_contents($path, $body);
    }

    public function prepared(): array
    {
        $package = $this->package;
        $package['source'] = $this->settings['source']['repository'];
        return ['status' => 'ready', 'target_kernel' => '6.18.58-Unraid', 'package' => $package,
            'prepared_boot_id' => self::OLD_BOOT, 'source_id' => Store::sourceIdentity($this->settings['source']),
            'source_fingerprint' => Upgrade::sourceFingerprint($this->settings['source'])];
    }

    public function remotePackage(): array
    {
        return array_replace($this->package, ['source' => $this->settings['source']['repository'],
            'source_id' => Store::sourceIdentity($this->settings['source']),
            'source_fingerprint' => Upgrade::sourceFingerprint($this->settings['source'])]);
    }

    public function selectBuild(int $build): void
    {
        $this->package['build'] = $build;
        $this->package['filename'] = 'nvidia-' . $this->package['driver'] . '-6.18.58-Unraid-' . $build . '.txz';
        $this->package['path'] = dirname($this->package['path']) . '/' . $this->package['filename'];
        file_put_contents($this->package['path'], "\xfd7zXZ\x00fake driver archive build " . $build);
        $this->package['md5'] = md5_file($this->package['path']);
        file_put_contents($this->package['path'] . '.md5', $this->package['md5'] . '  ' . $this->package['filename'] . "\n");
        $this->settings['selected_package'] = $this->package;
    }

    public function process(int $pid, string $comm, ?string $handle = null): void
    {
        $this->write('/proc/' . $pid . '/comm', $comm . "\n");
        if (!is_dir($this->paths->path('/proc/' . $pid . '/fd'))) mkdir($this->paths->path('/proc/' . $pid . '/fd'), 0700, true);
        if ($handle !== null) symlink($handle, $this->paths->path('/proc/' . $pid . '/fd/3'));
    }

    public function count(string $command): int
    {
        return count(array_filter($this->calls, static fn(array $argv): bool => basename($argv[0]) === $command));
    }

    private function listing(): string
    {
        $names = [];
        foreach (['nvidia', 'nvidia-modeset', 'nvidia-drm', 'nvidia-uvm', 'nvidia-vgpu-vfio'] as $module) {
            $names[] = 'lib/modules/6.18.58-Unraid/kernel/drivers/video/' . $module . '.ko';
        }
        foreach (['usr/bin/nvidia-vgpud', 'usr/bin/nvidia-vgpu-mgr', 'usr/lib64/libvgpu_unlock_rs.so',
            'usr/share/nvidia/vgpu/vgpuConfig.xml', 'usr/lib64/libcuda.so.1', 'usr/lib64/libnvcuvid.so.1',
            'usr/lib64/libnvidia-encode.so.1', 'usr/lib64/libnvidia-ml.so.1'] as $name) $names[] = $name;
        return implode("\n", array_map(static fn(string $name): string => '-rw-r--r-- 0/0 1 2026-11-02 10:00:00 ' . $name, $names)) . "\n";
    }

    public function run(array $argv, int $timeout = 30, ?string $stdin = null): array
    {
        $this->calls[] = $argv;
        $name = basename($argv[0]);
        if ($name === $this->fail) return ['code' => 1, 'stdout' => '', 'stderr' => 'fixture-only failure'];
        $output = '';
        if ($name === 'upgradepkg') {
            installer_check($argv === ['upgradepkg', '--install-new', '--reinstall', $this->package['path']], 'Only the exact canonical package may be installed');
            $this->installed = $this->badAfterInstall ? '580.159.03' : $this->package['driver'];
            if ($this->overwriteConfigs) {
                $this->write('/etc/docker/daemon.json', '{"registry-mirrors":["https://overwritten.invalid"]}');
                $this->write('/etc/vgpu_unlock/config.toml', "unlock = false\n");
                $this->write('/etc/modprobe.d/nvidia.conf', "options nvidia cudahost=0\n");
            }
            if ($this->installCode !== 0) return ['code' => $this->installCode, 'stdout' => '', 'stderr' => 'fixture install failure'];
        } elseif ($name === 'nvidia-ctk') {
            installer_check(array_slice($argv, 0, 4) === ['nvidia-ctk', 'runtime', 'configure', '--runtime=docker'], 'Only staged Docker runtime registration is allowed');
            $path = substr($argv[4], strlen('--config='));
            installer_check(str_starts_with($path, $this->root . '/etc/docker/.uvm-docker-'), 'Docker registration must target a temporary root file');
            $config = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            $config['runtimes']['nvidia'] = ['path' => '/usr/bin/nvidia-container-runtime', 'runtimeArgs' => []];
            file_put_contents($path, json_encode($config));
        } elseif ($name === 'depmod') {
            installer_check($argv === ['depmod', '-a', '6.18.58-Unraid'], 'depmod must use the running kernel');
        } elseif ($name === 'modinfo') {
            if (end($argv) === 'nvidia') {
                if ($this->installed === '') return ['code' => 1, 'stdout' => '', 'stderr' => 'not installed'];
                $output = $argv[2] === 'version' ? $this->installed : $this->installedMagic;
            } else {
                $output = $argv[2] === 'version' ? $this->archiveDriver : $this->archiveMagic;
                if ($this->loadAfterVerify && $argv[2] === 'vermagic') $this->write('/sys/module/nvidia/version', "580.159.03\n");
            }
        } elseif ($name === 'xz' || $name === 'notify') {
            // Only fixtures: no executable from the host is called.
        } elseif ($name === 'nvidia-modprobe') {
            $this->write('/sys/module/nvidia/version', $this->installed . "\n");
        } elseif ($name === 'tar' && in_array('--list', $argv, true)) {
            $output = $this->listing();
        } elseif ($name === 'tar' && in_array('--extract', $argv, true)) {
            $directory = $argv[array_search('--directory', $argv, true) + 1];
            foreach (array_slice($argv, array_search('--', $argv, true) + 1) as $member) {
                if (!is_dir(dirname($directory . '/' . $member))) mkdir(dirname($directory . '/' . $member), 0700, true);
                file_put_contents($directory . '/' . $member, 'fake module');
            }
        } else throw new RuntimeException('Unexpected real command refused: ' . $name);
        return ['code' => 0, 'stdout' => $output, 'stderr' => ''];
    }

    public function close(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->root);
    }
}

$cases = [];
$cases['disabled leaves the filesystem untouched'] = function (InstallerFixture $f): void {
    $f->settings['driver']['enabled'] = false;
    installer_check($f->installer->boot($f->settings)['status'] === 'disabled', 'Disabled upgrade must return immediately');
    installer_check(!$f->calls && !is_file($f->paths->configDir . '/driver-state.json'), 'Disabled startup must not write state or execute commands');
};
$cases['invalid existing state is preserved for diagnosis'] = function (InstallerFixture $f): void {
    file_put_contents($f->paths->configDir . '/driver-state.json', 'damaged state');
    installer_check(!$f->installer->boot($f->settings)['ok'], 'Invalid state must block installation');
    installer_check(file_get_contents($f->paths->configDir . '/driver-state.json') === 'damaged state', 'Corrupted prior state must not be overwritten by the error path');
    installer_check($f->count('upgradepkg') === 0, 'No installation without a readable previous receipt');
};
$cases['enablement and a fresh download require a later boot'] = function (InstallerFixture $f): void {
    $f->settings['driver']['enabled_boot_id'] = InstallerFixture::BOOT;
    installer_check($f->installer->boot($f->settings)['status'] === 'pending_reboot', 'First enable cannot install in the same boot');
    $f->settings['driver']['enabled_boot_id'] = InstallerFixture::OLD_BOOT;
    $f->settings['selected_package']['saved_boot_id'] = InstallerFixture::BOOT;
    installer_check($f->installer->boot($f->settings)['status'] === 'pending_reboot', 'Newly selected cache cannot install in the same boot');
    installer_check($f->count('upgradepkg') === 0, 'No package install during configuration or plugin reinstall');
};
$cases['prepared remote package installs and activates the native host stack'] = function (InstallerFixture $f): void {
    $f->settings['selected_package'] = null;
    $result = $f->installer->boot($f->settings, $f->prepared());
    installer_check($result['ok'] && $result['status'] === 'installed' && $result['changed'], 'New boot must install a matching prepared package');
    installer_check($f->count('upgradepkg') === 1 && $f->count('nvidia-modprobe') === 2, 'Host-only operation needs device nodes and UVM');
    installer_check(isset($f->installer->state()['last_success']), 'Successful installation must retain a reusable receipt');
    installer_check(is_file($f->package['path']), 'Cached package must remain for future boots');
};
$cases['vGPU initialization keeps ownership of its first module load'] = function (InstallerFixture $f): void {
    $f->settings['runtime']['enabled'] = true;
    installer_check($f->installer->boot($f->settings)['ok'], 'Driver installation may precede vGPU ensure');
    installer_check($f->count('nvidia-modprobe') === 0, 'Do not initialize RM profiles ahead of the vGPU runtime');
};
$cases['same boot does not reinstall and a later empty usr is restored'] = function (InstallerFixture $f): void {
    installer_check($f->installer->boot($f->settings)['ok'], 'First boot installation');
    installer_check($f->installer->boot($f->settings)['status'] === 'already_active', 'Repeated PLG install is idempotent');
    installer_check($f->count('upgradepkg') === 1, 'Only one installation per boot');
    unlink($f->paths->path('/sys/module/nvidia/version'));
    rmdir($f->paths->path('/sys/module/nvidia'));
    $f->write('/proc/sys/kernel/random/boot_id', InstallerFixture::NEXT_BOOT);
    $f->installed = '';
    $f->settings['selected_package'] = null;
    installer_check($f->installer->boot($f->settings)['ok'], 'A new boot must restore files even when persistent state says installed');
    installer_check($f->count('upgradepkg') === 2, 'Cached success receipt must support every reboot');
};
$cases['loaded matching and mismatching drivers are never replaced'] = function (InstallerFixture $f): void {
    $f->write('/sys/module/nvidia/version', "595.58.03\n");
    $f->installed = '595.58.03';
    installer_check($f->installer->boot($f->settings)['status'] === 'pending_reboot', 'Version-only evidence cannot confirm the selected package revision');
    installer_check($f->installer->state()['status'] === 'pending_reboot' && $f->installer->state()['kernel'] === '6.18.58-Unraid' && $f->installer->state()['boot_id'] === InstallerFixture::BOOT, 'The page must show the pending selection rather than invent an active package revision');
    $f->write('/sys/module/nvidia/version', "580.159.03\n");
    installer_check(!$f->installer->boot($f->settings)['ok'], 'Other loaded driver must block');
    installer_check($f->count('upgradepkg') === 0, 'No loaded driver replacement');
};
$cases['ordinary NVIDIA and legacy vGPU plugins block dual ownership'] = function (InstallerFixture $f): void {
    foreach (['nvidia-driver.plg', 'novidio-vgpu-driver.plg', 'nvidia-vgpu-driver.plg'] as $plugin) {
        $f->write('/boot/config/plugins/' . $plugin, 'old owner');
        installer_check($f->installer->boot($f->settings)['status'] === 'blocked', 'Installed legacy plugin must block startup');
        unlink($f->paths->path('/boot/config/plugins/' . $plugin));
    }
    installer_check($f->count('upgradepkg') === 0, 'No dual installation');
};
$cases['an enabled legacy User Script blocks installation'] = function (InstallerFixture $f): void {
    $f->write('/boot/config/plugins/user.scripts/scripts/vgpu-init/script', 'nvidia-vgpu-mgr');
    $f->write('/boot/config/plugins/user.scripts/schedule.json', json_encode(['vgpu-init' => ['frequency' => 'boot']]));
    installer_check($f->installer->boot($f->settings)['status'] === 'blocked', 'Scheduled legacy initializer must be migrated first');
    installer_check($f->count('upgradepkg') === 0, 'No installer under a second boot owner');
};
$cases['GPU client processes and all VFIO group types block installation'] = function (InstallerFixture $f): void {
    foreach (['/dev/nvidia0', '/dev/vfio/27', '/dev/vfio/devices/vfio8'] as $handle) {
        $f->process(800, 'GPU client', $handle);
        installer_check($f->installer->boot($f->settings)['status'] === 'blocked', 'Open host or physical-GPU VFIO client must block');
        unlink($f->paths->path('/proc/800/fd/3'));
    }
    installer_check($f->count('upgradepkg') === 0, 'A busy GPU is never reinstalled');
};
$cases['manager and unknown process state block installation'] = function (InstallerFixture $f): void {
    $f->process(800, 'nvidia-vgpu-mgr');
    installer_check($f->installer->boot($f->settings)['status'] === 'blocked', 'Manager process blocks installation');
    $f->write('/proc/800/comm', 'other');
    rmdir($f->paths->path('/proc/800/fd'));
    installer_check($f->installer->boot($f->settings)['status'] === 'blocked', 'Unreadable process FD state blocks installation');
    installer_check($f->count('upgradepkg') === 0, 'Unknown usage cannot start an installation');
};
$cases['an active mdev blocks installation even without a manager'] = function (InstallerFixture $f): void {
    mkdir($f->paths->path('/sys/devices/pci0000:00/0000:01:00.0/ae9572f6-1259-4136-9aa4-497dda98d2db'), 0700, true);
    mkdir($f->paths->path('/sys/bus/mdev/devices'), 0700, true);
    symlink($f->paths->path('/sys/devices/pci0000:00/0000:01:00.0/ae9572f6-1259-4136-9aa4-497dda98d2db'), $f->paths->path('/sys/bus/mdev/devices/ae9572f6-1259-4136-9aa4-497dda98d2db'));
    installer_check($f->installer->boot($f->settings)['status'] === 'blocked', 'An active mediated device must block the installer');
    installer_check($f->count('upgradepkg') === 0, 'No active mdev replacement');
};
$cases['a physical GPU bound to nouveau blocks installation'] = function (InstallerFixture $f): void {
    mkdir($f->paths->path('/sys/module/nouveau'), 0700, true);
    installer_check($f->installer->boot($f->settings)['status'] === 'blocked', 'Alternative loaded GPU driver cannot be replaced');
    installer_check($f->count('upgradepkg') === 0, 'No package overwrite under nouveau');
};
$cases['missing and wrong-kernel caches do not fall back'] = function (InstallerFixture $f): void {
    $f->settings['selected_package']['kernel'] = '6.18.54-Unraid';
    installer_check($f->installer->boot($f->settings)['status'] === 'missing_package', 'Old kernel cache cannot satisfy a new boot');
    installer_check($f->count('upgradepkg') === 0, 'No old-kernel install');
};
$cases['branch and source changes invalidate a prepared package'] = function (InstallerFixture $f): void {
    $f->settings['selected_package'] = null;
    $prepared = $f->prepared();
    $f->settings['upgrade']['driver_branch'] = '580';
    installer_check(!$f->installer->boot($f->settings, $prepared)['ok'], 'NVIDIA branch cannot change implicitly');
    $f->settings['upgrade']['driver_branch'] = '595';
    $f->write('/proc/sys/kernel/random/boot_id', InstallerFixture::NEXT_BOOT);
    $f->settings['source']['driver'] = '595.60.01';
    installer_check(!$f->installer->boot($f->settings, $prepared)['ok'], 'Pinned version mismatch blocks');
    $f->settings['source']['driver'] = '';
    $prepared['source_id'] = str_repeat('0', 64);
    installer_check(!$f->installer->boot($f->settings, $prepared)['ok'], 'Changed repository binding blocks');
    installer_check($f->count('upgradepkg') === 0, 'No package mutation after policy change');
};
$cases['an obsolete-source preparation cannot shadow the current selection'] = function (InstallerFixture $f): void {
    $prepared = $f->prepared();
    $f->settings['source'] = Store::validateSource(['url' => 'https://git.example.test/new-owner/new-repo', 'mode' => 'auto']);
    $f->settings['selected_package'] = $f->remotePackage();
    $result = $f->installer->boot($f->settings, $prepared);
    installer_check($result['ok'] && $result['status'] === 'installed', 'The current repository selection must supersede an obsolete prepared receipt');
    installer_check($f->installer->state()['last_success']['source_id'] === Store::sourceIdentity($f->settings['source']), 'The installed receipt must bind the current repository');
    installer_check($f->count('upgradepkg') === 1, 'Only the current selection is installed');
};
$cases['an obsolete-branch preparation cannot shadow the current selection'] = function (InstallerFixture $f): void {
    $prepared = $f->prepared();
    $prepared['package']['driver'] = '580.159.03';
    $prepared['package']['filename'] = 'nvidia-580.159.03-6.18.58-Unraid-1.txz';
    $prepared['package']['path'] = dirname($f->package['path']) . '/' . $prepared['package']['filename'];
    $f->settings['selected_package'] = $f->remotePackage();
    $result = $f->installer->boot($f->settings, $prepared);
    installer_check($result['ok'] && $result['package']['driver'] === '595.58.03', 'The current branch selection must supersede an older branch');
    installer_check($f->count('upgradepkg') === 1, 'The missing obsolete-branch cache must never be inspected or installed');
};
$cases['an explicit newer selection supersedes a valid same-policy preparation'] = function (InstallerFixture $f): void {
    $prepared = $f->prepared();
    $f->package['driver'] = '595.63.01';
    $f->package['filename'] = 'nvidia-595.63.01-6.18.58-Unraid-1.txz';
    $f->package['path'] = dirname($f->package['path']) . '/' . $f->package['filename'];
    file_put_contents($f->package['path'], "\xfd7zXZ\x00newer driver archive");
    $f->package['md5'] = md5_file($f->package['path']);
    file_put_contents($f->package['path'] . '.md5', $f->package['md5'] . '  ' . $f->package['filename'] . "\n");
    $f->archiveDriver = '595.63.01';
    $f->settings['selected_package'] = $f->remotePackage();
    $result = $f->installer->boot($f->settings, $prepared);
    installer_check($result['ok'] && $result['package']['driver'] === '595.63.01', 'An explicit same-source same-branch selection must win over the earlier prepared version');
    installer_check($f->installer->state()['last_success']['package']['filename'] === $f->package['filename'], 'The newer selection must be saved for subsequent boots');
    installer_check(is_file($prepared['package']['path']) && $f->count('upgradepkg') === 1, 'The old prepared cache is retained without installation');
};
$cases['an obsolete selected receipt falls back to the current preparation'] = function (InstallerFixture $f): void {
    $f->settings['selected_package'] = $f->remotePackage();
    $f->settings['selected_package']['source_fingerprint'] = str_repeat('0', 64);
    $prepared = $f->prepared();
    $result = $f->installer->boot($f->settings, $prepared);
    installer_check($result['ok'] && $result['status'] === 'installed', 'An invalid higher-priority receipt must not block a current preparation');
    installer_check($f->installer->state()['last_success']['source_fingerprint'] === $prepared['source_fingerprint'], 'The preparation retains its current source binding');
    installer_check($f->count('upgradepkg') === 1, 'Only the policy-valid preparation is installed');
};
$cases['obsolete selected and prepared receipts fall back to the current last success'] = function (InstallerFixture $f): void {
    $package = $f->remotePackage();
    $last = ['package' => $package, 'saved_boot_id' => InstallerFixture::OLD_BOOT,
        'source_id' => $package['source_id'], 'source_fingerprint' => $package['source_fingerprint'], 'local' => false];
    $f->store->write($f->paths->configDir . '/driver-state.json', ['status' => 'installed', 'boot_id' => InstallerFixture::OLD_BOOT, 'last_success' => $last]);
    $f->settings['selected_package'] = $package;
    $f->settings['selected_package']['source_id'] = str_repeat('0', 64);
    $prepared = $f->prepared();
    $prepared['source_fingerprint'] = str_repeat('0', 64);
    $result = $f->installer->boot($f->settings, $prepared);
    installer_check($result['ok'] && $result['status'] === 'installed', 'A current last-success receipt must remain usable when higher-priority receipts are stale');
    $success = $f->installer->state()['last_success'];
    installer_check($success['source_id'] === $last['source_id'] && $success['source_fingerprint'] === $last['source_fingerprint'] &&
        $success['package']['build'] === 1 && $success['installed_boot_id'] === InstallerFixture::BOOT, 'The installed historical receipt keeps its authenticated binding and records the restored build and boot');
    installer_check($f->count('upgradepkg') === 1, 'Historical restoration still performs a full installation on a new boot');
};
$cases['no current-policy receipt preserves the primary validation error'] = function (InstallerFixture $f): void {
    $prepared = $f->prepared();
    $prepared['source_id'] = str_repeat('0', 64);
    $f->settings['selected_package']['driver'] = '580.159.03';
    $f->settings['selected_package']['filename'] = 'nvidia-580.159.03-6.18.58-Unraid-1.txz';
    $result = $f->installer->boot($f->settings, $prepared);
    installer_check(!$result['ok'] && $result['status'] === 'failed', 'All stale receipts must still refuse installation');
    installer_check(str_contains($result['message'], 'another NVIDIA branch'), 'The explicit selection validation error must be retained instead of a missing-package error');
    installer_check($f->count('upgradepkg') === 0, 'No invalid-policy fallback may install');
};
$cases['a selected cache verification failure never falls back to an older preparation'] = function (InstallerFixture $f): void {
    $prepared = $f->prepared();
    $f->settings['selected_package'] = $f->remotePackage();
    $f->settings['selected_package']['md5'] = str_repeat('0', 32);
    $result = $f->installer->boot($f->settings, $prepared);
    installer_check(!$result['ok'] && $result['status'] === 'failed', 'A metadata-valid but corrupted selection must fail its full cache validation');
    installer_check($f->count('upgradepkg') === 0, 'Cache verification failure must not silently select and install another version');
};
$cases['tampered cache bytes fail before installation'] = function (InstallerFixture $f): void {
    file_put_contents($f->package['path'], "\xfd7zXZ\x00changed");
    installer_check(!$f->installer->boot($f->settings)['ok'], 'Changed cached bytes must fail');
    installer_check($f->count('upgradepkg') === 0, 'MD5 mismatch prevents installer');
};
$cases['a changed MD5 record fails before installation'] = function (InstallerFixture $f): void {
    file_put_contents($f->package['path'] . '.md5', str_repeat('0', 32) . "\n");
    installer_check(!$f->installer->boot($f->settings)['ok'], 'Independent checksum record must remain consistent');
    installer_check($f->count('upgradepkg') === 0, 'Bad MD5 record prevents installer');
};
$cases['symlinked and hard-linked packages are refused'] = function (InstallerFixture $f): void {
    link($f->package['path'], $f->package['path'] . '.another');
    installer_check(!$f->installer->boot($f->settings)['ok'], 'Cached install file must be independently owned');
    installer_check($f->count('upgradepkg') === 0, 'Hard links cannot be handed to the package installer');
};
$cases['incorrect archive module version fails before installation'] = function (InstallerFixture $f): void {
    $f->archiveDriver = '580.159.03';
    installer_check(!$f->installer->boot($f->settings)['ok'], 'Incorrect embedded module must fail');
    installer_check($f->count('upgradepkg') === 0, 'Invalid embedded module never reaches upgradepkg');
};
$cases['a mismatched embedded kernel fails before installation'] = function (InstallerFixture $f): void {
    $f->archiveMagic = '6.18.54-Unraid SMP preempt mod_unload';
    installer_check(!$f->installer->boot($f->settings)['ok'], 'Embedded module must match this exact kernel');
    installer_check($f->count('upgradepkg') === 0, 'Incorrect vermagic cannot reach upgradepkg');
};
$cases['a GPU appearing during verification blocks installation'] = function (InstallerFixture $f): void {
    $f->loadAfterVerify = true;
    installer_check($f->installer->boot($f->settings)['status'] === 'blocked', 'Occupancy is rechecked after full archive validation');
    installer_check($f->count('upgradepkg') === 0, 'No install after a new loader appears');
};
$cases['failed installation cannot be retried by a PLG update in the same boot'] = function (InstallerFixture $f): void {
    $f->fail = 'upgradepkg';
    installer_check($f->installer->boot($f->settings)['status'] === 'failed', 'upgradepkg error propagates');
    installer_check($f->installer->boot($f->settings)['status'] === 'already_attempted', 'Repeated installation is blocked');
    installer_check($f->count('upgradepkg') === 1 && is_file($f->package['path']), 'Failed attempt retains cache and is not repeated');
};
$cases['post-install mismatch blocks runtime initialization'] = function (InstallerFixture $f): void {
    $f->badAfterInstall = true;
    installer_check(!$f->installer->boot($f->settings)['ok'], 'Success exit alone is not a verified driver');
    installer_check($f->installer->state()['status'] === 'failed' && $f->count('nvidia-modprobe') === 0, 'Invalid installation must not initialize devices');
};
$cases['device initialization failure retains the verified cache'] = function (InstallerFixture $f): void {
    $f->fail = 'nvidia-modprobe';
    installer_check($f->installer->boot($f->settings)['status'] === 'failed', 'Native device initialization failure propagates');
    installer_check(is_file($f->package['path']), 'Cache survives a device initialization failure');
};
$cases['a failed later boot preserves the last successful receipt'] = function (InstallerFixture $f): void {
    installer_check($f->installer->boot($f->settings)['ok'], 'Initial successful installation');
    $success = $f->installer->state()['last_success'];
    unlink($f->paths->path('/sys/module/nvidia/version'));
    rmdir($f->paths->path('/sys/module/nvidia'));
    $f->write('/proc/sys/kernel/random/boot_id', InstallerFixture::NEXT_BOOT);
    $f->installed = '';
    $f->settings['selected_package'] = null;
    $f->fail = 'upgradepkg';
    installer_check(!$f->installer->boot($f->settings)['ok'], 'A package command failure is reported');
    installer_check($f->installer->state()['last_success'] === $success, 'Failure does not discard the reusable successful receipt');
};
$cases['build two is installed with an exact current-boot receipt'] = function (InstallerFixture $f): void {
    $old = $f->package;
    $f->selectBuild(2);
    $result = $f->installer->boot($f->settings);
    installer_check($result['status'] === 'installed' && $result['package']['build'] === 2, 'Build two must reach the installer unchanged');
    $success = $f->installer->state()['last_success'];
    installer_check($success['package']['filename'] === $f->package['filename'] && $success['installed_boot_id'] === InstallerFixture::BOOT, 'Receipt must retain the exact package revision and installation boot');
    installer_check($f->installer->boot($f->settings)['status'] === 'already_active' && $f->count('upgradepkg') === 1, 'A verified build two must be idempotent');
    installer_check(is_file($old['path']), 'Build one remains available for rollback');
};
$cases['same-version build changes wait for reboot and never claim already active'] = function (InstallerFixture $f): void {
    installer_check($f->installer->boot($f->settings)['ok'], 'Install legacy build one');
    $success = $f->installer->state()['last_success'];
    $f->selectBuild(2);
    installer_check($f->installer->boot($f->settings)['status'] === 'pending_reboot', 'Loaded build one cannot satisfy selected build two despite matching modinfo');
    installer_check($f->installer->state()['last_success'] === $success && $f->count('upgradepkg') === 1, 'Pending selection must not replace the known installed receipt or live files');
    $f->settings['selected_package'] = $success['package'];
    installer_check($f->installer->boot($f->settings)['status'] === 'already_active', 'Returning to the exact current-boot installed build is recognized');
};
$cases['same-build changed digest or source cannot masquerade as the installed package'] = function (InstallerFixture $f): void {
    $f->settings['selected_package'] = $f->remotePackage();
    installer_check($f->installer->boot($f->settings)['ok'], 'Install a source-bound package');
    $success = $f->installer->state()['last_success'];
    $f->settings['selected_package']['md5'] = str_repeat('a', 32);
    installer_check($f->installer->boot($f->settings)['status'] === 'pending_reboot', 'Same filename with another digest is not active');
    $f->settings['source'] = Store::validateSource(['url' => 'https://git.example.test/another/repo']);
    $f->settings['selected_package'] = $f->remotePackage();
    installer_check($f->installer->boot($f->settings)['status'] === 'pending_reboot', 'Same filename and digest from a different source is not silently accepted as installed');
    installer_check($f->installer->state()['last_success'] === $success && $f->count('upgradepkg') === 1, 'Mismatched selections preserve the known installation');
};
$cases['an old-boot receipt does not identify an externally loaded build'] = function (InstallerFixture $f): void {
    installer_check($f->installer->boot($f->settings)['ok'], 'Record one successful boot');
    $f->write('/proc/sys/kernel/random/boot_id', InstallerFixture::NEXT_BOOT);
    installer_check($f->installer->boot($f->settings)['status'] === 'pending_reboot', 'After a reboot, a matching version alone does not identify who installed its package');
    installer_check($f->count('upgradepkg') === 1, 'Unknown current-boot revision is not hot-replaced');
};
$cases['legacy installed receipts are distinct from legacy version-only active status'] = function (InstallerFixture $f): void {
    $last = ['package' => $f->package, 'saved_boot_id' => InstallerFixture::OLD_BOOT,
        'source_id' => '', 'source_fingerprint' => '', 'local' => true];
    $f->write('/sys/module/nvidia/version', "595.58.03\n");
    $f->installed = '595.58.03';
    $f->store->write($f->paths->configDir . '/driver-state.json', ['status' => 'already_active', 'boot_id' => InstallerFixture::BOOT, 'last_success' => $last]);
    installer_check($f->installer->boot($f->settings)['status'] === 'pending_reboot', 'Legacy already_active may retain a prior-boot receipt and does not prove its build');
    $f->store->write($f->paths->configDir . '/driver-state.json', ['status' => 'installed', 'boot_id' => InstallerFixture::BOOT, 'last_success' => $last]);
    installer_check($f->installer->boot($f->settings)['status'] === 'already_active', 'A legacy successful install in this boot can identify build one without a new field');
    installer_check($f->installer->boot($f->settings)['status'] === 'already_active' &&
        $f->installer->state()['last_success']['installed_boot_id'] === InstallerFixture::BOOT, 'A proven legacy installation must keep its boot identity after recording active status');
    installer_check($f->count('upgradepkg') === 0, 'Legacy identity checks never reinstall a loaded driver');
};
$cases['unloading modules does not authorize a different build in the same boot'] = function (InstallerFixture $f): void {
    installer_check($f->installer->boot($f->settings)['ok'], 'Install one build for the boot');
    installer_check($f->installer->boot($f->settings)['status'] === 'already_active', 'Record its active status');
    unlink($f->paths->path('/sys/module/nvidia/version'));
    rmdir($f->paths->path('/sys/module/nvidia'));
    $f->selectBuild(2);
    installer_check($f->installer->boot($f->settings)['status'] === 'pending_reboot', 'A different build is deferred even when the previous modules are unloaded');
    installer_check($f->count('upgradepkg') === 1, 'No second package installation in the same boot');
};
$cases['build two missing or conflicting metadata is rejected'] = function (InstallerFixture $f): void {
    $f->selectBuild(2);
    unset($f->settings['selected_package']['build']);
    installer_check($f->installer->boot($f->settings)['status'] === 'failed', 'Only legacy build one may omit the build field');
    $f->write('/proc/sys/kernel/random/boot_id', InstallerFixture::NEXT_BOOT);
    $f->settings['selected_package']['build'] = 10;
    installer_check($f->installer->boot($f->settings)['status'] === 'failed', 'Filename and build metadata must agree');
    installer_check($f->count('upgradepkg') === 0, 'Ambiguous build metadata never reaches the installer');
};
$cases['explicit build pin rejects another same-version package'] = function (InstallerFixture $f): void {
    $f->selectBuild(2);
    $f->settings['source']['driver'] = '595.58.03';
    $f->settings['source']['build'] = '1';
    $result = $f->installer->boot($f->settings);
    installer_check($result['status'] === 'failed' && str_contains($result['message'], 'selected package build'), 'Build rollback pin must not install build two');
    installer_check($f->count('upgradepkg') === 0, 'Build pin mismatch prevents installation');
};

$cases['boot restoration is independent of upgrade prefetch'] = function (InstallerFixture $f): void {
    $f->settings['upgrade']['enabled'] = false;
    installer_check($f->installer->boot($f->settings)['status'] === 'installed', 'Disabled prefetch must not disable current-kernel restoration');
};
$cases['package overwrites do not replace existing settings'] = function (InstallerFixture $f): void {
    $f->write('/etc/docker/daemon.json', '{"registry-mirrors":["https://keep.invalid"],"runtimes":{"custom":{"path":"/bin/custom"}},"debug":true}');
    $f->write('/etc/vgpu_unlock/config.toml', "unlock = true\n");
    $f->write('/etc/modprobe.d/nvidia.conf', "options nvidia cudahost=1\n");
    $f->overwriteConfigs = true;
    $result = $f->installer->boot($f->settings);
    installer_check($result['status'] === 'installed', 'Install completes after settings recovery');
    $docker = json_decode(file_get_contents($f->paths->path('/etc/docker/daemon.json')), true);
    installer_check($docker['registry-mirrors'] === ['https://keep.invalid'] && $docker['debug'] === true && isset($docker['runtimes']['custom'], $docker['runtimes']['nvidia']), 'Preserve Docker settings and add only NVIDIA runtime');
    installer_check(file_get_contents($f->paths->path('/etc/vgpu_unlock/config.toml')) === "unlock = true\n", 'Unlock setting retained');
    installer_check(file_get_contents($f->paths->path('/etc/modprobe.d/nvidia.conf')) === "options nvidia cudahost=1\n", 'Host CUDA module setting retained');
};
$cases['failed package installation restores settings before returning'] = function (InstallerFixture $f): void {
    $original = '{"registry-mirrors":["https://keep.invalid"]}';
    $f->write('/etc/docker/daemon.json', $original);
    $f->write('/etc/vgpu_unlock/config.toml', "unlock = true\n");
    $f->overwriteConfigs = true;
    $f->installCode = 1;
    $result = $f->installer->boot($f->settings);
    installer_check($result['status'] === 'failed' && str_contains($result['message'], 'Original configurations restored'), 'Failure explains configuration recovery');
    installer_check(file_get_contents($f->paths->path('/etc/docker/daemon.json')) === $original, 'Failed pkgtools restores exact Docker bytes');
    installer_check($f->count('nvidia-ctk') === 0 && $f->count('nvidia-modprobe') === 0, 'Failed installation does not register or activate');
};
$cases['late post-install failure restores original Docker bytes'] = function (InstallerFixture $f): void {
    $original = '{"debug":true}';
    $f->write('/etc/docker/daemon.json', $original);
    $f->badAfterInstall = true;
    $result = $f->installer->boot($f->settings);
    installer_check($result['status'] === 'failed' && $f->count('nvidia-ctk') === 1, 'Post-install mismatch follows Docker staging');
    installer_check(file_get_contents($f->paths->path('/etc/docker/daemon.json')) === $original, 'Later verification failure restores pre-install settings');
};
$cases['installation timeout retains backups without racing the child'] = function (InstallerFixture $f): void {
    $f->write('/etc/docker/daemon.json', '{"debug":true}');
    $f->overwriteConfigs = true;
    $f->installCode = 124;
    $result = $f->installer->boot($f->settings);
    installer_check($result['status'] === 'failed' && str_contains($result['message'], 'completion is unknown'), 'Unknown child completion requests manual recovery');
    installer_check(str_contains(file_get_contents($f->paths->path('/etc/docker/daemon.json')), 'overwritten.invalid'), 'Do not race a possibly running installer with configuration writes');
    $journal = glob($f->paths->runtimeDir . '/install-transactions/*/transaction.json');
    installer_check(count($journal) === 1 && json_decode(file_get_contents($journal[0]), true)['status'] === 'completion_unknown', 'Recovery transaction records uncertain completion');
    installer_check($f->count('nvidia-ctk') === 0 && $f->count('nvidia-modprobe') === 0, 'Timeout blocks subsequent commands');
};

foreach ($cases as $name => $case) {
    $fixture = new InstallerFixture();
    try {
        $case($fixture);
        foreach ($fixture->calls as $call) installer_check(!in_array(basename($call[0]), ['kill', 'killall', 'rmmod', 'modprobe', 'virsh', 'mdevctl'], true), 'Installer must never unload modules or change VM/mdev state');
        echo 'PASS installer: ' . $name . "\n";
    } finally { $fixture->close(); }
}
echo 'Cold boot installer: ' . count($cases) . ' scenarios / ' . $installerChecks . " checks passed (mock installer/GPU; no real package commands)\n";
