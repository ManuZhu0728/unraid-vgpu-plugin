<?php
declare(strict_types=1);

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/Application.php';

use Vgpu\Application;
use Vgpu\Download;
use Vgpu\Paths;
use Vgpu\Runtime;
use Vgpu\Store;

$lifecycleChecks = 0;
function lifecycle_check(bool $condition, string $message): void
{
    global $lifecycleChecks;
    ++$lifecycleChecks;
    if (!$condition) throw new RuntimeException($message);
}

/** All filesystem and HTTP/package/service boundaries belong to this fixture. */
final class LifecycleFixture
{
    public const OLD_BOOT = '11111111-1111-4111-8111-111111111111';
    public const NEW_BOOT = '22222222-2222-4222-8222-222222222222';
    public const THIRD_BOOT = '33333333-3333-4333-8333-333333333333';
    public const TOKEN = 'lifecycle-fixture-private-token';
    public string $root;
    public Paths $paths;
    public Store $store;
    public Application $application;
    public array $calls = [];
    public array $routes = [];
    public string $installed = '595.58.03';
    public string $installedKernel = '6.18.58-Unraid';
    public int $installedBuild = 1;
    public string $archiveDriver = '';
    public string $archiveKernel = '';
    public bool $failInstall = false;
    public bool $overwriteConfiguration = false;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/vgpu-lifecycle-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        $this->paths = new Paths($this->root);
        $this->store = new Store($this->paths);
        $this->write('/proc/sys/kernel/osrelease', '6.18.58-Unraid');
        $this->write('/proc/sys/kernel/random/boot_id', self::OLD_BOOT);
        $this->write('/sys/module/nvidia/version', '595.58.03');
        $this->write('/usr/local/emhttp/plugins/unraid-vgpu-manager/scripts/upgrade-helper', 'fake helper; never executed');
        $this->release('6.18.58', ['595.58.03']);
        $this->release('6.18.60', ['595.58.03', '595.63.01', '610.1.2']);
        $download = new Download($this->paths, $this->run(...));
        $runtime = new Runtime($this->paths, $this->run(...));
        $this->application = new Application($this->store, $runtime, $download, $this->run(...));
    }

    public function write(string $path, string $body): void
    {
        $path = $this->paths->path($path);
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        file_put_contents($path, $body);
    }

    public function count(string $name): int
    {
        return count(array_filter($this->calls, static fn(array $call): bool => basename($call['argv'][0]) === $name));
    }

    public function release(string $kernel, array $drivers, array $builds = [1]): void
    {
        $links = [];
        foreach ($drivers as $driver) foreach ($builds as $build) {
            $name = 'nvidia-' . $driver . '-' . $kernel . '-Unraid-' . $build . '.txz';
            $url = 'https://git.example.test/api/v4/projects/42/packages/generic/build/v' . $kernel . '-' . $driver . ($build === 1 ? '' : '-build' . $build) . '/' . $name;
            $body = "\xfd7zXZ\x00fixture package " . $name;
            $this->routes[$url] = ['body' => $body];
            $this->routes[$url . '.md5'] = ['body' => md5($body) . '  ' . $name . "\n"];
            $links[] = ['name' => $name, 'url' => $url];
            $links[] = ['name' => $name . '.md5', 'url' => $url . '.md5'];
        }
        $this->routes['https://git.example.test/api/v4/projects/42/releases/' . $kernel . '-Unraid'] = [
            'body' => json_encode(['tag_name' => $kernel . '-Unraid', 'assets' => ['links' => $links]]),
            'type' => 'application/json',
        ];
    }

    public function enableAndSelectCurrent(bool $select = true): array
    {
        $result = $this->application->dispatch('config-save', [
            'source' => ['url' => 'https://git.example.test/owner/repo', 'mode' => 'auto', 'project_id' => '42'],
            'credential' => ['type' => 'private-token', 'token' => self::TOKEN, 'username' => ''],
            'driver' => ['enabled' => true], 'upgrade' => ['enabled' => true], 'runtime' => ['enabled' => false],
        ]);
        lifecycle_check(($result['upgrade_helper']['ok'] ?? false) === true, 'Config-save must request the independent helper through the mock runner');
        $settings = $this->store->settings();
        lifecycle_check($settings['upgrade']['driver_branch'] === '595' && $settings['driver']['enabled_boot_id'] === self::OLD_BOOT, 'Driver enablement must capture current branch and boot identity');
        lifecycle_check($this->store->credentials()['source_id'] === Store::sourceIdentity($settings['source']), 'Saved fake private credentials must be bound to the normalized source');
        if (!$select) return [];
        $download = $this->application->dispatch('source-download');
        lifecycle_check($download['ok'] && $download['package']['kernel'] === '6.18.58-Unraid', 'Current selection must be downloaded through the real Application/Download path');
        lifecycle_check($this->count('upgradepkg') === 0 && $this->count('nvidia-modprobe') === 0, 'Saving settings and downloading must not install or initialize a driver');
        return $this->store->settings()['selected_package'];
    }

    public function prepareTarget(): array
    {
        $this->write('/boot/changes.txt', "# Version 7.3.5 2026-12-01\n## Linux kernel\n* version 6.18.60\n\n# Version 7.3.4\n## Linux kernel\n* version 6.18.99\n");
        return $this->application->dispatch('upgrade-check');
    }

    public function importLocalCurrent(string $driver = '595.58.03'): array
    {
        $filename = 'nvidia-' . $driver . '-6.18.58-Unraid-2.txz';
        $temporary = sys_get_temp_dir() . '/php' . bin2hex(random_bytes(8));
        $this->write($temporary, "\xfd7zXZ\x00fixture package " . $filename);
        return $this->application->dispatch('source-import', ['filename' => $filename, 'path' => $this->paths->path($temporary)])['package'];
    }

    public function reboot(string $boot = self::NEW_BOOT): void
    {
        $this->write('/proc/sys/kernel/osrelease', '6.18.60-Unraid');
        $this->write('/proc/sys/kernel/random/boot_id', $boot);
        $version = $this->paths->path('/sys/module/nvidia/version');
        if (is_file($version)) unlink($version);
        if (is_dir(dirname($version))) rmdir(dirname($version));
        // /usr is volatile on Unraid. Keep only the persistent cache/receipts.
        $this->installed = '';
        $this->installedKernel = '';
        $this->installedBuild = 0;
    }

    private function listing(): string
    {
        $files = [];
        foreach (['nvidia', 'nvidia-modeset', 'nvidia-drm', 'nvidia-uvm', 'nvidia-vgpu-vfio'] as $module) {
            $files[] = 'lib/modules/' . $this->archiveKernel . '/kernel/drivers/video/' . $module . '.ko';
        }
        foreach (['usr/bin/nvidia-vgpud', 'usr/bin/nvidia-vgpu-mgr', 'usr/lib64/libvgpu_unlock_rs.so',
            'usr/share/nvidia/vgpu/vgpuConfig.xml', 'usr/lib64/libcuda.so.1', 'usr/lib64/libnvcuvid.so.1',
            'usr/lib64/libnvidia-encode.so.1', 'usr/lib64/libnvidia-ml.so.1'] as $file) $files[] = $file;
        return implode("\n", array_map(static fn(string $file): string => '-rw-r--r-- 0/0 1 2026-11-02 10:00:00 ' . $file, $files)) . "\n";
    }

    public function run(array $argv, int $timeout = 30, ?string $stdin = null): array
    {
        $this->calls[] = ['argv' => $argv, 'stdin' => $stdin];
        lifecycle_check(!str_contains(implode(' ', $argv), self::TOKEN), 'Credentials must not enter executable arguments');
        $name = basename($argv[0]);
        $output = '';
        if ($name === 'bash') {
            lifecycle_check(str_ends_with($argv[1] ?? '', '/scripts/upgrade-helper') && in_array($argv[2] ?? '', ['start', 'stop'], true), 'Only a helper lifecycle request is allowed through the fake shell runner');
        } elseif ($name === 'notify') {
            // Notification delivery is recorded, never executed.
        } elseif ($name === 'curl') {
            lifecycle_check(preg_match('/^url = "(.*)"$/m', (string)$stdin, $match) === 1, 'HTTP request must use stdin configuration');
            $url = stripcslashes($match[1]);
            lifecycle_check(str_contains((string)$stdin, 'PRIVATE-TOKEN: ' . self::TOKEN), 'Private source must reuse its saved credential');
            $route = $this->routes[$url] ?? ['status' => 404, 'body' => 'not found'];
            $destination = $argv[array_search('--output', $argv, true) + 1];
            file_put_contents($destination, $route['body']);
            return ['code' => 0, 'stdout' => ($route['status'] ?? 200) . "\n" . ($route['type'] ?? 'application/octet-stream') . "\n\n", 'stderr' => ''];
        } elseif ($name === 'xz') {
            // The archive integrity command is mocked; Download still inspects its signature.
        } elseif ($name === 'tar' && in_array('--list', $argv, true)) {
            $archive = $argv[array_search('--file', $argv, true) + 1];
            $body = (string)file_get_contents($archive);
            if (!preg_match('/nvidia-([0-9.]+)-([0-9.]+-Unraid)-([1-9][0-9]*)\.txz/', $body, $identity)) throw new RuntimeException('Unknown fake archive identity');
            $this->archiveDriver = $identity[1];
            $this->archiveKernel = $identity[2];
            $output = $this->listing();
        } elseif ($name === 'tar' && in_array('--extract', $argv, true)) {
            $directory = $argv[array_search('--directory', $argv, true) + 1];
            foreach (array_slice($argv, array_search('--', $argv, true) + 1) as $member) {
                if (!is_dir(dirname($directory . '/' . $member))) mkdir(dirname($directory . '/' . $member), 0700, true);
                file_put_contents($directory . '/' . $member, json_encode(['driver' => $this->archiveDriver, 'kernel' => $this->archiveKernel]));
            }
        } elseif ($name === 'modinfo') {
            if (end($argv) === 'nvidia') {
                if ($this->installed === '') return ['code' => 1, 'stdout' => '', 'stderr' => 'volatile module is absent'];
                $output = $argv[2] === 'version' ? $this->installed : $this->installedKernel . ' SMP preempt mod_unload';
            } else {
                $module = json_decode((string)file_get_contents(end($argv)), true, 16, JSON_THROW_ON_ERROR);
                $output = $argv[2] === 'version' ? $module['driver'] : $module['kernel'] . ' SMP preempt mod_unload';
            }
        } elseif ($name === 'upgradepkg') {
            lifecycle_check(array_slice($argv, 0, 3) === ['upgradepkg', '--install-new', '--reinstall'], 'Only a cold package installation is permitted');
            if ($this->overwriteConfiguration) {
                foreach (['/etc/docker/daemon.json', '/etc/vgpu_unlock/config.toml', '/etc/modprobe.d/nvidia.conf', '/etc/nvidia/ClientConfigToken/client.tok'] as $path) {
                    $this->write($path, $path === '/etc/docker/daemon.json' ? '{}' : 'package replacement');
                }
            }
            if ($this->failInstall) return ['code' => 1, 'stdout' => '', 'stderr' => 'mock package failure'];
            if (!preg_match('/^nvidia-([0-9.]+)-([0-9.]+-Unraid)-([1-9][0-9]*)\.txz$/D', basename($argv[3]), $identity)) throw new RuntimeException('Wrong installation filename');
            $this->installed = $identity[1];
            $this->installedKernel = $identity[2];
            $this->installedBuild = (int)$identity[3];
        } elseif ($name === 'nvidia-ctk') {
            lifecycle_check(array_slice($argv, 1, 3) === ['runtime', 'configure', '--runtime=docker'], 'Docker configuration uses only the runtime registration operation');
            $configuration = array_values(array_filter($argv, static fn(string $arg): bool => str_starts_with($arg, '--config=')));
            lifecycle_check(count($configuration) === 1, 'Docker registration requires an explicit staging file');
            $path = substr($configuration[0], strlen('--config='));
            lifecycle_check(str_starts_with($path, $this->root . '/') && is_file($path), 'Mock registration never accesses the host configuration');
            $json = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            $json['runtimes']['nvidia'] = ['path' => '/usr/bin/nvidia-container-runtime', 'runtimeArgs' => []];
            file_put_contents($path, json_encode($json, JSON_THROW_ON_ERROR));
        } elseif ($name === 'depmod') {
            $runningKernel = trim((string)file_get_contents($this->paths->path('/proc/sys/kernel/osrelease')));
            lifecycle_check($argv === ['depmod', '-a', $runningKernel], 'Dependency update must target the running kernel');
        } elseif ($name === 'nvidia-modprobe') {
            $this->write('/sys/module/nvidia/version', $this->installed);
        } else {
            throw new RuntimeException('Unexpected real service or package command refused: ' . $name);
        }
        return ['code' => 0, 'stdout' => $output, 'stderr' => ''];
    }

    public function close(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->root);
    }
}

$fixture = new LifecycleFixture();
try {
    $selected = $fixture->enableAndSelectCurrent();
    $currentBoot = $fixture->application->dispatch('boot');
    lifecycle_check(!$currentBoot['ok'] && $currentBoot['status'] === 'pending_reboot', 'Enabling preserves the live driver without claiming its unknown package revision');
    lifecycle_check($fixture->count('upgradepkg') === 0, 'The current boot must not install while enabling the feature');
    $prepared = $fixture->prepareTarget();
    lifecycle_check($prepared['ok'] && $prepared['status'] === 'ready' && $prepared['target_kernel'] === '6.18.60-Unraid', 'Changes file must drive target-kernel preparation');
    lifecycle_check($prepared['package']['driver'] === '595.58.03', 'Automatic preparation must preserve the current NVIDIA driver preference');
    lifecycle_check($fixture->store->settings()['selected_package'] === $selected, 'Future-kernel preparation must preserve the current cached selection');
    lifecycle_check($fixture->count('upgradepkg') === 0 && $fixture->count('nvidia-modprobe') === 0, 'Prefetch never mutates the GPU stack');
    lifecycle_check(is_file($selected['path']) && is_file($prepared['package']['path']), 'Both kernel caches must be retained');
    lifecycle_check(!str_contains(json_encode($prepared), LifecycleFixture::TOKEN), 'Preparation result must not expose private credentials');
    $fixture->reboot();
    $boot = $fixture->application->dispatch('boot');
    lifecycle_check($boot['ok'] && $boot['driver_installation']['status'] === 'installed', 'A new target-kernel boot must install the verified prepared receipt');
    lifecycle_check($fixture->count('upgradepkg') === 1 && $fixture->count('nvidia-modprobe') === 2, 'Host-only startup must install once and initialize native devices plus UVM');
    lifecycle_check($fixture->store->settings()['selected_package'] === $selected, 'Boot installation must not overwrite the user selection');
    $again = $fixture->application->dispatch('boot');
    lifecycle_check($again['ok'] && $again['driver_installation']['status'] === 'already_active', 'A duplicate boot invocation must not reinstall');
    lifecycle_check($fixture->count('upgradepkg') === 1, 'Repeated plugin startup is idempotent');
    $fixture->store->write($fixture->paths->configDir . '/upgrade-state.json', ['status' => 'idle']);
    $fixture->reboot(LifecycleFixture::THIRD_BOOT);
    $third = $fixture->application->dispatch('boot');
    lifecycle_check($third['ok'] && $third['driver_installation']['status'] === 'installed', 'Another reboot must restore volatile driver files from the last successful receipt');
    lifecycle_check($fixture->count('upgradepkg') === 2, 'A persistent installed flag cannot skip restoring an empty /usr');
    lifecycle_check(is_file($selected['path']) && is_file($prepared['package']['path']), 'Successful boots must retain both old and new kernel caches');
    echo "PASS lifecycle: config -> private target prefetch -> reboot install -> duplicate boot -> subsequent boot restore\n";
} finally { $fixture->close(); }

$fixture = new LifecycleFixture();
try {
    $fixture->enableAndSelectCurrent();
    lifecycle_check($fixture->prepareTarget()['ok'], 'Failure test must first prepare a real receipt');
    $fixture->application->dispatch('config-save', ['runtime' => ['enabled' => true]]);
    $fixture->reboot();
    $fixture->failInstall = true;
    $failed = $fixture->application->dispatch('boot');
    lifecycle_check(!$failed['ok'] && $failed['status'] === 'failed', 'Installer failure must propagate through real Application boot');
    lifecycle_check($fixture->count('nvidia-modprobe') === 0 && $fixture->count('env') === 0 && $fixture->count('modprobe') === 0, 'Runtime.ensure must not initialize modules or daemons after installation failure');
    lifecycle_check(!is_dir($fixture->paths->path('/etc/vgpu_unlock')), 'Runtime configuration must remain untouched after an installation error');
    $retried = $fixture->application->dispatch('boot');
    lifecycle_check(!$retried['ok'] && $retried['status'] === 'already_attempted' && $fixture->count('upgradepkg') === 1, 'A failed boot cannot repeatedly invoke the package installer');
    echo "PASS lifecycle: failed installation blocks vGPU runtime and duplicate installation\n";
} finally { $fixture->close(); }

$fixture = new LifecycleFixture();
try {
    $selected = $fixture->enableAndSelectCurrent();
    unset($fixture->routes['https://git.example.test/api/v4/projects/42/releases/6.18.60-Unraid']);
    $missing = $fixture->prepareTarget();
    lifecycle_check(!$missing['ok'], 'Unavailable target package must not be reported ready');
    $fixture->application->dispatch('config-save', ['runtime' => ['enabled' => true]]);
    $fixture->reboot();
    $boot = $fixture->application->dispatch('boot');
    lifecycle_check(!$boot['ok'] && $boot['status'] === 'missing_package', 'A new kernel cannot use the old selected package');
    lifecycle_check($fixture->count('upgradepkg') === 0 && $fixture->count('nvidia-modprobe') === 0 && $fixture->count('env') === 0, 'Missing target package must block both installation and runtime');
    lifecycle_check(is_file($selected['path']), 'A missing target must preserve the old kernel cache');
    echo "PASS lifecycle: missing target kernel package preserves old cache and blocks startup\n";
} finally { $fixture->close(); }

$fixture = new LifecycleFixture();
try {
    $fixture->enableAndSelectCurrent(false);
    lifecycle_check($fixture->store->settings()['selected_package'] === null, 'Fresh enablement starts without a manually selected package');
    $fixture->write('/boot/changes.txt', "# Version 7.3.4\n## Linux kernel\n* version: 6.18.58\n");
    $baseline = $fixture->application->dispatch('upgrade-check');
    lifecycle_check($baseline['ok'] && $baseline['status'] === 'current' && $baseline['baseline_prepared'], 'The first helper check must prepare the current kernel recovery cache');
    $selected = $fixture->store->settings()['selected_package'];
    lifecycle_check($selected['kernel'] === '6.18.58-Unraid' && is_file($selected['path']), 'Automatic baseline preparation must persist a selected current-kernel cache');
    lifecycle_check($fixture->count('upgradepkg') === 0 && $fixture->count('nvidia-modprobe') === 0, 'Baseline preparation does not change a running driver');
    $fixture->reboot();
    $fixture->write('/proc/sys/kernel/osrelease', '6.18.58-Unraid');
    $boot = $fixture->application->dispatch('boot');
    lifecycle_check($boot['ok'] && ($boot['driver_installation']['status'] ?? '') === 'installed', 'An ordinary reboot before any OS upgrade must install the baseline cache: ' . json_encode($boot));
    lifecycle_check($fixture->count('upgradepkg') === 1 && $fixture->count('nvidia-modprobe') === 2, 'The baseline boots through the full native driver installation path');
    echo "PASS lifecycle: first enable -> automatic current cache -> ordinary same-kernel reboot\n";
} finally { $fixture->close(); }

$fixture = new LifecycleFixture();
try {
    $fixture->release('6.18.58', ['595.58.03'], [2, 10, 1]);
    $fixture->release('6.18.60', ['595.58.03', '610.1.2'], [2, 10, 1]);
    $fixture->enableAndSelectCurrent(false);
    $versions = $fixture->application->dispatch('source-versions');
    lifecycle_check(array_column($versions['versions'], 'build') === [1, 2, 10], 'Application must expose all revisions in numeric order');
    $selected = $fixture->application->dispatch('source-download', ['filename' => 'nvidia-595.58.03-6.18.58-Unraid-2.txz'])['package'];
    lifecycle_check($selected['build'] === 2 && $fixture->store->settings()['source']['build'] === '', 'Exact selection preserves automatic source settings');
    $fixture->write('/boot/changes.txt', "# Version 7.3.4\n## Linux kernel\n* version: 6.18.58\n");
    $baseline = $fixture->application->dispatch('upgrade-check');
    lifecycle_check($baseline['ok'] && $baseline['package']['build'] === 2 && !$baseline['baseline_prepared'], 'A saved current-kernel build2 must not be replaced by build10');
    $fixture->reboot();
    $fixture->write('/proc/sys/kernel/osrelease', '6.18.58-Unraid');
    $boot = $fixture->application->dispatch('boot');
    lifecycle_check($boot['ok'] && $fixture->installedBuild === 2 && $boot['driver_installation']['package']['build'] === 2, 'Actual selected filename must reach the mock cold installer');
    $again = $fixture->application->dispatch('boot');
    lifecycle_check($again['ok'] && $again['driver_installation']['status'] === 'already_active' && $fixture->count('upgradepkg') === 1, 'Matching current-boot build2 is idempotent');
    $rollback = $fixture->application->dispatch('source-download', ['filename' => 'nvidia-595.58.03-6.18.58-Unraid-1.txz'])['package'];
    lifecycle_check($rollback['build'] === 1 && is_file($selected['path']), 'Rollback selection retains build2 cache');
    $pending = $fixture->application->dispatch('boot');
    lifecycle_check(!$pending['ok'] && $pending['status'] === 'pending_reboot' && $fixture->installedBuild === 2 && $fixture->count('upgradepkg') === 1, 'Rollback must not replace the live same-version build');
    $fixture->reboot(LifecycleFixture::THIRD_BOOT);
    $fixture->write('/proc/sys/kernel/osrelease', '6.18.58-Unraid');
    $boot = $fixture->application->dispatch('boot');
    lifecycle_check($boot['ok'] && $fixture->installedBuild === 1 && $fixture->count('upgradepkg') === 2, 'Next boot installs the explicitly chosen rollback');
    $prepared = $fixture->prepareTarget();
    lifecycle_check($prepared['ok'] && $prepared['package']['driver'] === '595.58.03' && $prepared['package']['build'] === 10, 'New kernel uses highest build within saved R595 branch');
    lifecycle_check($fixture->store->settings()['selected_package']['build'] === 1 && is_file($selected['path']), 'Target preparation retains chosen current-kernel build and previous cache');
    echo "PASS lifecycle: revision selection -> cache -> cold install -> live rollback deferred -> next boot rollback -> target revision\n";
} finally { $fixture->close(); }

$fixture = new LifecycleFixture();
try {
    $fixture->application->dispatch('config-save', [
        'source' => ['url' => 'https://git.example.test/owner/repo', 'project_id' => '42'],
        'credential' => ['type' => 'private-token', 'token' => LifecycleFixture::TOKEN],
        'upgrade' => ['enabled' => true],
    ]);
    lifecycle_check(!$fixture->store->settings()['driver']['enabled'], 'Enabling prefetch alone never enables installation');
    lifecycle_check($fixture->prepareTarget()['status'] === 'ready', 'Prefetch alone can prepare the next kernel');
    $fixture->reboot();
    $boot = $fixture->application->dispatch('boot');
    lifecycle_check($boot['ok'] && !isset($boot['driver_installation']), 'Prepared package is not installed without driver restore enablement');
    lifecycle_check($fixture->count('upgradepkg') === 0 && $fixture->count('nvidia-modprobe') === 0, 'Prefetch-only mode leaves the driver stack untouched at boot');
    echo "PASS lifecycle: prefetch without driver restoration never installs at boot\n";
} finally { $fixture->close(); }

$fixture = new LifecycleFixture();
try {
    $selected = $fixture->importLocalCurrent();
    $fixture->application->dispatch('config-save', ['driver' => ['enabled' => true]]);
    $settings = $fixture->store->settings();
    lifecycle_check($settings['driver']['enabled'] && !$settings['upgrade']['enabled'] && !$settings['runtime']['enabled'], 'Offline native restore is independent of prefetch and vGPU');
    lifecycle_check($settings['source']['url'] === '' && $fixture->count('bash') === 0 && $fixture->count('curl') === 0, 'Local restore requires neither a repository nor the network helper');
    $docker = ['registry-mirrors' => ['https://mirror.example.test'], 'log-driver' => 'json-file', 'runtimes' => ['custom' => ['path' => '/usr/bin/custom-runtime']]];
    $fixture->write('/etc/docker/daemon.json', json_encode($docker));
    chmod($fixture->paths->path('/etc/docker/daemon.json'), 0640);
    $protected = ['/etc/vgpu_unlock/config.toml' => "unlock = true\n", '/etc/modprobe.d/nvidia.conf' => "options nvidia cudahost=1\n", '/etc/nvidia/ClientConfigToken/client.tok' => 'fixture-license-do-not-display'];
    foreach ($protected as $path => $contents) $fixture->write($path, $contents);
    $fixture->overwriteConfiguration = true;
    $fixture->reboot();
    $fixture->write('/proc/sys/kernel/osrelease', '6.18.58-Unraid');
    $boot = $fixture->application->dispatch('boot');
    lifecycle_check($boot['ok'] && $fixture->installedBuild === 2, 'A local-only cold boot installs the chosen build: ' . json_encode($boot));
    foreach ($protected as $path => $contents) lifecycle_check(file_get_contents($fixture->paths->path($path)) === $contents, 'Package replacement must preserve existing ' . $path);
    $actual = json_decode(file_get_contents($fixture->paths->path('/etc/docker/daemon.json')), true);
    lifecycle_check($actual['runtimes']['nvidia']['path'] === '/usr/bin/nvidia-container-runtime', 'Docker NVIDIA runtime is registered after recovery');
    unset($actual['runtimes']['nvidia']);
    lifecycle_check($actual === $docker && (fileperms($fixture->paths->path('/etc/docker/daemon.json')) & 0777) === 0640, 'Docker mirrors, other runtimes, logging and permissions survive');
    lifecycle_check(!str_contains(json_encode($boot), 'fixture-license-do-not-display'), 'Install result never contains license contents');
    $fixture->reboot(LifecycleFixture::THIRD_BOOT);
    $fixture->write('/proc/sys/kernel/osrelease', '6.18.58-Unraid');
    lifecycle_check($fixture->application->dispatch('boot')['ok'], 'Ordinary reboot restores the same local cache with prefetch still off');
    lifecycle_check($fixture->count('upgradepkg') === 2 && $fixture->count('curl') === 0 && $fixture->count('bash') === 0 && is_file($selected['path']), 'Offline restore retains cache and never starts networking');
    echo "PASS lifecycle: offline import -> restore-only boots -> package config recovery -> Docker merge\n";
} finally { $fixture->close(); }

$fixture = new LifecycleFixture();
try {
    $fixture->enableAndSelectCurrent();
    $prepared = $fixture->prepareTarget();
    $fixture->application->dispatch('config-save', ['upgrade' => ['enabled' => false]]);
    lifecycle_check($fixture->store->settings()['driver']['enabled'], 'Disabling prefetch must retain restoration');
    $requests = $fixture->count('curl');
    lifecycle_check($fixture->application->dispatch('upgrade-check')['status'] === 'disabled' && $fixture->count('curl') === $requests, 'Disabled prefetch issues no request');
    $fixture->reboot();
    lifecycle_check($fixture->application->dispatch('boot')['ok'] && $fixture->count('upgradepkg') === 1, 'Already prepared target package still installs with prefetch off');
    lifecycle_check(is_file($prepared['package']['path']), 'Switching controls retains the target cache');
    echo "PASS lifecycle: turning off prefetch preserves target-kernel cold restoration\n";
} finally { $fixture->close(); }

$fixture = new LifecycleFixture();
try {
    $fixture->application->dispatch('config-save', ['runtime' => ['enabled' => true]]);
    $fixture->write('/boot/config/plugins/novidio-vgpu-driver.plg', 'fixture legacy owner');
    $boot = $fixture->application->dispatch('boot');
    lifecycle_check(!$boot['ok'] && $boot['code'] === 'migration_required', 'Runtime-only boot still checks existing startup ownership');
    lifecycle_check(!$fixture->store->settings()['driver']['enabled'] && !$fixture->store->settings()['upgrade']['enabled'], 'Runtime enablement never changes the other controls');
    lifecycle_check($fixture->count('upgradepkg') === 0 && $fixture->count('curl') === 0 && $fixture->count('nvidia-ctk') === 0, 'Runtime-only mode neither downloads nor installs drivers');
    echo "PASS lifecycle: vGPU-only control keeps existing driver and migration checks\n";
} finally { $fixture->close(); }

$fixture = new LifecycleFixture();
try {
    $fixture->importLocalCurrent('580.159.03');
    $fixture->application->dispatch('config-save', ['driver' => ['enabled' => true]]);
    lifecycle_check($fixture->store->settings()['upgrade']['driver_branch'] === '580', 'Explicit local selection takes precedence over the currently loaded R595 branch when enabling restore');
    lifecycle_check($fixture->count('upgradepkg') === 0 && $fixture->installed === '595.58.03', 'Selecting another branch leaves the live driver untouched');
    $fixture->reboot();
    $fixture->write('/proc/sys/kernel/osrelease', '6.18.58-Unraid');
    $boot = $fixture->application->dispatch('boot');
    lifecycle_check($boot['ok'] && $fixture->installed === '580.159.03' && $fixture->installedBuild === 2, 'Cold boot installs the explicitly authorized local branch');
    lifecycle_check($fixture->count('curl') === 0 && $fixture->count('upgradepkg') === 1, 'Branch selection remains offline and installs only once');
    echo "PASS lifecycle: explicit local branch -> restore enablement -> cold installation\n";
} finally { $fixture->close(); }

echo 'Application upgrade lifecycle: 10 scenarios / ' . $lifecycleChecks . " checks passed (fake filesystem/HTTP/installer; no real services)\n";
