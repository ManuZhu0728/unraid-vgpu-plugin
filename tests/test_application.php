<?php
declare(strict_types=1);

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/Application.php';

use Vgpu\Application;
use Vgpu\Download;
use Vgpu\Paths;
use Vgpu\Process;
use Vgpu\Runtime;
use Vgpu\Store;

$applicationChecks = 0;
function application_check(bool $condition, string $message): void
{
    global $applicationChecks;
    if (!$condition) throw new RuntimeException($message);
    ++$applicationChecks;
}

function application_rejects(callable $operation, string $message): void
{
    try { $operation(); }
    catch (Throwable $error) {
        application_check(str_contains($error->getMessage(), $message), 'Unexpected failure: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure: ' . $message);
}

/** A disposable filesystem and argv-only runner. No command reaches the host. */
final class ApplicationFixture
{
    public const UUID = 'ae9572f6-1259-4136-9aa4-497dda98d2db';
    public const PCI = '0000:01:00.0';
    public const PROFILE = 'NVIDIA RTXA5500-1Q';
    public const TYPE = 'nvidia-762';
    public string $root;
    public Paths $paths;
    public Store $store;
    public Application $application;
    public array $calls = [];
    public array $downloadInputs = [];
    public bool $defined = false;
    public string $failure = '';
    public array $domains = [];

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/vgpu-application-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->paths = new Paths($this->root);
        $this->store = new Store($this->paths);
        $this->put('/proc/sys/kernel/osrelease', "6.18.58-Unraid\n");
        $this->put('/proc/sys/kernel/random/boot_id', "11111111-1111-4111-8111-111111111111\n");
        $this->put('/sys/module/nvidia/version', "595.58.03\n");
        $this->directory('/sys/bus/mdev/devices');
        $this->directory('/sys/bus/pci/drivers/nvidia/' . self::PCI);
        $this->put('/sys/bus/pci/devices/' . self::PCI . '/mdev_supported_types/' . self::TYPE . '/name', self::PROFILE . "\n");
        $this->put('/usr/lib64/libvgpu_unlock_rs.so', 'fixture-library-not-a-real-driver');
        $this->put('/usr/share/nvidia/vgpu/vgpuConfig.xml', '<fixture/>');
        $runtime = new Runtime($this->paths, $this->run(...));
        $download = new Download($this->paths, function (array $argv, int $timeout = 15, ?string $stdin = null): array {
            $this->calls[] = $argv;
            $this->downloadInputs[] = $stdin;
            throw new RuntimeException('Unexpected network/download invocation in application test');
        });
        $this->application = new Application($this->store, $runtime, $download, $this->run(...));
    }

    public function device(): array
    {
        return ['uuid' => self::UUID, 'pci' => self::PCI, 'profile' => self::PROFILE, 'type' => self::TYPE];
    }

    public function source(): array
    {
        return Store::validateSource(array_replace(Store::defaults()['source'], [
            'repository' => 'https://git.example.test/group/driver', 'project_id' => '42', 'driver' => '595.58.03',
        ]));
    }

    public function directory(string $path): string
    {
        $directory = $this->paths->path($path);
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        return $directory;
    }

    public function put(string $path, string $content): void
    {
        $this->directory(dirname($path));
        file_put_contents($this->paths->path($path), $content);
    }

    public function managed(bool $active = true): void
    {
        $settings = $this->store->settings();
        $settings['runtime']['enabled'] = true;
        $this->store->saveSettings($settings);
        $library = ['path' => realpath($this->paths->path('/usr/lib64/libvgpu_unlock_rs.so')),
            'sha256' => hash_file('sha256', $this->paths->path('/usr/lib64/libvgpu_unlock_rs.so'))];
        $fields = array_fill(0, 20, '0');
        $fields[0] = 'S';
        $fields[19] = '100001';
        $this->put('/proc/100/comm', "nvidia-vgpu-mgr\n");
        $this->put('/proc/100/stat', '100 (nvidia-vgpu-mgr) ' . implode(' ', $fields) . "\n");
        $this->put('/proc/100/status', "Name:\tnvidia-vgpu-mgr\nPPid:\t1\n");
        $this->put('/proc/100/maps', '1000-2000 r-xp 00000000 00:00 0 ' . $library['path'] . "\n");
        $this->directory('/proc/100/fd');
        $override = is_file($this->paths->configDir . '/profile_override.toml') ? file_get_contents($this->paths->configDir . '/profile_override.toml') : '';
        $this->put('/etc/vgpu_unlock/config.toml', 'unlock = ' . ($settings['runtime']['unlock'] ? 'true' : 'false') . "\n");
        $this->put('/etc/vgpu_unlock/profile_override.toml', $override);
        $fingerprint = hash('sha256', json_encode(['unlock' => $settings['runtime']['unlock'], 'library' => $library, 'override' => $override], JSON_THROW_ON_ERROR));
        $this->put('/var/run/unraid-vgpu-manager/owner.json', json_encode([
            'manager_pid' => 100, 'manager_start' => '100001', 'library' => $library, 'configuration' => $fingerprint,
            'xml_sha256' => hash_file('sha256', $this->paths->path('/usr/share/nvidia/vgpu/vgpuConfig.xml')),
        ], JSON_THROW_ON_ERROR));
        $this->defined = true;
        if ($active) $this->activate();
    }

    public function activate(): void
    {
        $actual = $this->directory('/sys/devices/pci0000:00/' . self::PCI . '/' . self::UUID);
        $entry = $this->paths->path('/sys/bus/mdev/devices/' . self::UUID);
        if (!is_link($entry)) symlink($actual, $entry);
        if (!is_link($actual . '/mdev_type')) symlink($this->paths->path('/sys/bus/pci/devices/' . self::PCI . '/mdev_supported_types/' . self::TYPE), $actual . '/mdev_type');
        if (!is_link($actual . '/iommu_group')) symlink($this->directory('/sys/kernel/iommu_groups/27'), $actual . '/iommu_group');
    }

    public function run(array $argv, int $timeout = 15, ?string $stdin = null): array
    {
        $this->calls[] = $argv;
        $result = ['code' => 0, 'stdout' => '', 'stderr' => ''];
        if ($argv[0] === 'modinfo') {
            $result['stdout'] = ($argv[2] === 'version' ? '595.58.03' : '6.18.58-Unraid SMP preempt mod_unload') . "\n";
            return $result;
        }
        if ($argv[0] === 'virsh') {
            $result['stdout'] = $argv[3] === 'list' ? implode("\n", array_keys($this->domains)) : ($this->domains[$argv[4]] ?? '');
            return $result;
        }
        if ($argv[0] !== 'mdevctl') throw new RuntimeException('Unexpected runtime command: ' . $argv[0]);
        $operation = $argv[1];
        if ($operation === 'list') {
            $result['stdout'] = $this->defined ? self::UUID . ' ' . self::PCI . ' ' . self::TYPE . " manual\n" : '';
            return $result;
        }
        if ($operation === $this->failure) return ['code' => $operation === 'stop' ? 124 : 1, 'stdout' => '', 'stderr' => 'fixture failure'];
        if ($operation === 'define') $this->defined = true;
        elseif ($operation === 'start') $this->activate();
        elseif ($operation === 'stop') {
            $entry = $this->paths->path('/sys/bus/mdev/devices/' . self::UUID);
            if (is_link($entry)) unlink($entry);
        } elseif ($operation === 'undefine') $this->defined = false;
        else throw new RuntimeException('Unexpected mdev operation');
        return $result;
    }

    public function close(): void
    {
        // Only a random fixture directory created by this instance is removed.
        if (!str_starts_with($this->root, sys_get_temp_dir() . '/vgpu-application-test-')) throw new RuntimeException('Unsafe fixture cleanup');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }
}

$fixtures = [];
try {
    $fixture = $fixtures[] = new ApplicationFixture();
    $app = $fixture->application;
    $status = $app->dispatch('status');
    application_check($status['settings'] === Store::defaults(), 'Status must expose defaults with runtime disabled');
    application_check($fixture->calls === [], 'Initial status must not execute commands or contact a source');
    application_check(!is_dir($fixture->paths->configDir), 'Status must not persist configuration');
    application_check(!$status['credential_configured'] && $status['override_content'] === '', 'Unconfigured source must allow status');
    $plan = $app->dispatch('plan');
    application_check(str_contains($plan['message'], 'installation runs at boot'), 'Plan must describe boot-time installation');
    application_check($fixture->calls === [], 'Plan must be read-only');

    $secret = 'application-fixture-secret-not-a-real-token';
    $source = $fixture->source();
    $saved = $app->dispatch('config-save', ['source' => $source, 'credential' => ['type' => 'private-token', 'token' => $secret, 'username' => 'fixture-user']]);
    application_check($fixture->calls === [], 'Config save must not invoke runtime or download');
    application_check($fixture->store->credentials()['token'] === $secret, 'Credential must persist separately');
    application_check(!str_contains(json_encode($saved), $secret), 'Save response must not return the token');
    $status = $app->dispatch('status');
    application_check($status['credential_configured'] === true && !str_contains(json_encode($status), $secret), 'Status must expose configured boolean, never token');
    application_check(!str_contains((string)file_get_contents($fixture->paths->settingsFile), $secret), 'Token must not enter settings.json');
    application_check((fileperms($fixture->paths->credentialsFile) & 0777) === 0600, 'Credentials need restrictive POSIX permissions');
    $before = [file_get_contents($fixture->paths->settingsFile), file_get_contents($fixture->paths->credentialsFile)];
    application_rejects(fn() => $app->dispatch('config-save', ['source' => array_replace($source, ['driver' => 'bad-version'])]), 'Driver version');
    application_rejects(fn() => $app->dispatch('config-save', ['credential' => ['type' => 'private-token', 'token' => "bad\ntoken"]]), 'Invalid token');
    application_rejects(fn() => $app->dispatch('config-save', ['runtime' => ['xml_override' => true]]), 'XML overrides');
    application_rejects(fn() => $app->dispatch('config-save', ['runtime' => ['unlock_library' => '/tmp/untrusted.so']]), 'UnlockRS paths');
    application_rejects(fn() => $app->dispatch('config-save', ['override_content' => str_repeat('x', 65537)]), 'profile override');
    application_check($before === [file_get_contents($fixture->paths->settingsFile), file_get_contents($fixture->paths->credentialsFile)], 'Validation failures must preserve configuration and credentials');
    application_check($fixture->calls === [], 'Invalid configuration must not invoke runtime or download');
    $changedDriver = array_replace($source, ['driver' => '595.71.05']);
    $app->dispatch('config-save', ['source' => $changedDriver]);
    application_check($fixture->store->credentials()['token'] === $secret, 'Changing only driver must retain same-repository credential');
    $changedRepository = array_replace($changedDriver, ['repository' => 'https://git.example.test/group/another-driver']);
    $changed = $app->dispatch('config-save', ['source' => $changedRepository]);
    application_check($changed['credential_cleared'] && $fixture->store->credentials()['token'] === '', 'Changing repository must clear previous credential');
    $app->dispatch('config-save', ['credential' => ['type' => 'private-token', 'token' => $secret]]);
    $app->dispatch('config-save', ['source' => array_replace($changedRepository, ['project_id' => '43'])]);
    application_check($fixture->store->credentials()['token'] === '', 'Changing GitLab project ID must clear credential');
    $app->dispatch('config-save', ['source' => array_replace($changedRepository, ['provider' => 'github', 'mode' => 'releases', 'repository' => 'https://github.com/fixture/driver', 'project_id' => '']), 'credential' => ['type' => 'bearer', 'token' => $secret]]);
    application_check($fixture->store->credentials()['token'] === $secret && $fixture->store->credentials()['type'] === 'bearer', 'Explicit new-provider credential must be accepted without forwarding old one');
    $app->dispatch('config-save', ['credential' => ['type' => 'none', 'token' => '', 'username' => '']]);
    application_check(!$app->dispatch('status')['credential_configured'], 'Explicit removal must clear configured status');
    $app->dispatch('config-save', ['override_content' => "[fixture]\r\nvalue = true\r\n"]);
    application_check($app->dispatch('status')['override_content'] === "[fixture]\nvalue = true\n", 'Override must roundtrip through status without CR');
    application_check($fixture->store->settings()['pending_restart'], 'Override save must mark pending maintenance');
    application_check(!is_file($fixture->paths->path('/etc/vgpu_unlock/config.toml')), 'Config save must not alter active /etc runtime');
    $ensured = $app->dispatch('ensure');
    application_check($ensured['ok'] && $ensured['code'] === 'runtime_disabled' && $fixture->calls === [], 'Disabled ensure must be harmless and command-free');

    // Simulate credentials/settings being written in different generations
    // (filesystem failure), and a legacy credential lacking the new scope.
    foreach (['different-source', 'missing-source'] as $bindingCase) {
        $bindingFixture = $fixtures[] = new ApplicationFixture();
        $bindingApp = $bindingFixture->application;
        $bindingSource = $bindingFixture->source();
        $bindingApp->dispatch('config-save', ['source' => $bindingSource]);
        $scope = Store::sourceIdentity($bindingSource);
        $otherScope = Store::sourceIdentity(array_replace($bindingSource, ['repository' => 'https://different.example.test/group/driver', 'url' => 'https://different.example.test/group/driver']));
        application_check($scope !== $otherScope, 'Source identity must bind the repository origin/path');
        application_check($scope === Store::sourceIdentity(array_replace($bindingSource, ['driver' => '595.71.05'])), 'Source identity must not change when only driver changes');
        application_check($scope !== Store::sourceIdentity(array_replace($bindingSource, ['project_id' => '43'])), 'Source identity must bind GitLab project ID');
        $stray = ['type' => 'private-token', 'token' => 'mismatched-binding-fixture-secret', 'username' => 'fixture-user'];
        if ($bindingCase === 'different-source') $stray['source_id'] = $otherScope;
        // Write the fixture as-is, bypassing the normal complete save path.
        $bindingFixture->store->write($bindingFixture->paths->credentialsFile, $stray);
        $bindingStatus = $bindingApp->dispatch('status');
        application_check(!$bindingStatus['credential_configured'], 'Mismatched or missing source binding must not be advertised as configured');
        application_check(!str_contains(json_encode($bindingStatus), $stray['token']), 'Unbound credential must not appear in status');
        foreach (['source-test', 'source-download', 'source-versions'] as $sourceAction) {
            application_rejects(fn() => $bindingApp->dispatch($sourceAction), 'Credential source binding mismatch');
        }
        application_check($bindingFixture->calls === [], 'Unbound credentials must be rejected before any download/network runner call');
        $bindingApp->dispatch('config-save', []); // UI's “keep credentials” path.
        application_check($bindingFixture->store->credentials()['token'] === '', 'Keep save must clear an unbound token, never silently rebind it');
        application_check(!$bindingApp->dispatch('status')['credential_configured'], 'Clearing an unbound token must leave configured status false');
        $replacement = 'explicit-new-binding-fixture-secret';
        $bindingApp->dispatch('config-save', ['credential' => ['type' => 'private-token', 'token' => $replacement, 'username' => 'fixture-user']]);
        application_check($bindingFixture->store->credentials()['source_id'] === $scope && $bindingFixture->store->credentials()['token'] === $replacement, 'Explicit new token must bind to the current source');
        application_check($bindingApp->dispatch('status')['credential_configured'], 'Explicit correctly bound token must restore configured status');
        application_rejects(fn() => $bindingApp->dispatch('source-test'), 'Unexpected network/download invocation');
        application_check(count($bindingFixture->calls) === 1 && $bindingFixture->calls[0][0] === 'curl', 'A newly bound token must pass the gate and reach only the mocked transport');
        application_check(!str_contains(implode(' ', $bindingFixture->calls[0]), $replacement), 'Even a newly bound token must not enter transport argv');
    }

    $selectionFixture = $fixtures[] = new ApplicationFixture();
    $selectionApp = $selectionFixture->application;
    $selectionSource = array_replace($selectionFixture->source(), ['mode' => 'generic']);
    $selectionApp->dispatch('config-save', ['source' => $selectionSource, 'credential' => ['type' => 'auto', 'token' => 'selection-fixture-secret']]);
    $settingsBytes = file_get_contents($selectionFixture->paths->settingsFile);
    $credentialBytes = file_get_contents($selectionFixture->paths->credentialsFile);
    application_rejects(fn() => $selectionApp->dispatch('source-download', ['driver' => ['invalid']]), 'Driver version');
    application_rejects(fn() => $selectionApp->dispatch('source-download', ['driver' => 'bad-version']), 'Driver version');
    application_check($selectionFixture->calls === [], 'Invalid per-download selection must be rejected before networking');
    application_rejects(fn() => $selectionApp->dispatch('source-download', ['driver' => '595.71.05']), 'Unexpected network/download invocation');
    application_check(str_contains($selectionFixture->downloadInputs[0] ?? '', 'v6.18.58-595.71.05'), 'Explicit per-download selection must reach the temporary source context');
    application_check(file_get_contents($selectionFixture->paths->settingsFile) === $settingsBytes && file_get_contents($selectionFixture->paths->credentialsFile) === $credentialBytes, 'Failed or temporary per-download selection must preserve saved source and credential binding');
    application_check($selectionFixture->store->settings()['source']['driver'] === '595.58.03', 'Per-download selection must not replace saved source version');
    application_rejects(fn() => $selectionApp->dispatch('source-test', ['driver' => '595.71.05']), 'Unexpected network/download invocation');
    application_check(str_contains($selectionFixture->downloadInputs[1] ?? '', 'v6.18.58-595.58.03'), 'Source test must ignore unsaved per-download selection');

    $legacySource = $selectionFixture->store->settings()['source'];
    $legacySource['repository'] = 'https://different.example.test/group/driver';
    $selectionApp->dispatch('config-save', ['source' => $legacySource]);
    application_check($selectionFixture->store->settings()['source']['url'] === $legacySource['repository'], 'Legacy full snapshots may still update repository without editing url');
    application_check($selectionFixture->store->credentials()['token'] === '', 'A legacy repository update must clear the old source token');

    $device = $fixture->device();
    $app->dispatch('device-save', $device);
    $again = $app->dispatch('device-save', $device);
    application_check(!$again['changed'] && count($fixture->store->devices()) === 1, 'Identical device save must be idempotent');
    $deviceBytes = file_get_contents($fixture->paths->devicesFile);
    application_rejects(fn() => $app->dispatch('device-save', array_replace($device, ['profile' => 'different-profile'])), 'UUID already configured');
    application_check(file_get_contents($fixture->paths->devicesFile) === $deviceBytes, 'Identity conflict must preserve saved device');
    application_rejects(fn() => $app->dispatch('device-start', ['uuid' => ApplicationFixture::UUID]), 'management is disabled');
    application_rejects(fn() => $app->dispatch('device-remove', ['uuid' => 'not-configured']), 'not configured');
    application_rejects(fn() => $app->dispatch('unknown-action'), 'Unknown operation');
    application_check($fixture->calls === [], 'Invalid/disabled device operations must not reach runtime commands');
    $fixture->store->locked(function () use ($app, $device): void {
        application_rejects(fn() => $app->dispatch('device-save', $device), 'Another plugin operation');
        application_check(count($app->dispatch('status')['devices']) === 1, 'Status should remain available during a held transaction lock');
    });
    application_check(!$app->dispatch('device-save', $device)['changed'], 'Transaction lock must release after failure');

    $runtimeFixture = $fixtures[] = new ApplicationFixture();
    $app = $runtimeFixture->application;
    $app->dispatch('device-save', $runtimeFixture->device());
    $runtimeFixture->managed();
    $runtimeBytes = file_get_contents($runtimeFixture->paths->devicesFile);
    $runtimeFixture->failure = 'stop';
    $stopped = $app->dispatch('device-stop', ['uuid' => ApplicationFixture::UUID]);
    application_check(!$stopped['ok'] && $stopped['code'] === 'stop_failed', 'Timed-out stop must report failure');
    application_check(file_get_contents($runtimeFixture->paths->devicesFile) === $runtimeBytes && $runtimeFixture->defined, 'Stop failure must preserve persistent configuration and definition');
    $removed = $app->dispatch('device-remove', ['uuid' => ApplicationFixture::UUID]);
    application_check(!$removed['ok'] && $runtimeFixture->defined && count($runtimeFixture->store->devices()) === 1, 'Failed stop during removal must retain saved device');
    application_check(!array_filter($runtimeFixture->calls, fn($argv) => $argv[0] === 'mdevctl' && $argv[1] === 'undefine'), 'No undefine is allowed after failed stop');
    $runtimeFixture->failure = 'undefine';
    $removed = $app->dispatch('device-remove', ['uuid' => ApplicationFixture::UUID]);
    application_check(!$removed['ok'] && $removed['code'] === 'undefine_failed', 'Undefine failure must be explicit');
    application_check(file_get_contents($runtimeFixture->paths->devicesFile) === $runtimeBytes && $runtimeFixture->defined, 'Undefine failure must retain saved identity and definition');
    $runtimeFixture->failure = '';
    $removed = $app->dispatch('device-remove', ['uuid' => ApplicationFixture::UUID]);
    application_check($removed['ok'] && !$runtimeFixture->defined && $runtimeFixture->store->devices() === [], 'Only verified removal may delete saved configuration');

    $busyFixture = $fixtures[] = new ApplicationFixture();
    $app = $busyFixture->application;
    $app->dispatch('device-save', $busyFixture->device());
    $busyFixture->managed();
    $busyFixture->domains['fixture-vm'] = "<domain><devices><hostdev><source><address uuid='" . ApplicationFixture::UUID . "'/></source></hostdev></devices></domain>";
    $removed = $app->dispatch('device-remove', ['uuid' => ApplicationFixture::UUID]);
    application_check(!$removed['ok'] && $removed['code'] === 'vm_busy', 'Live VM reference must refuse removal');
    application_check($busyFixture->defined && count($busyFixture->store->devices()) === 1, 'VM busy refusal must preserve saved device');
    application_check(!array_filter($busyFixture->calls, fn($argv) => $argv[0] === 'mdevctl' && in_array($argv[1], ['stop', 'undefine'], true)), 'VM busy must cause no destructive mdev calls');

    $sameFixture = $fixtures[] = new ApplicationFixture();
    $app = $sameFixture->application;
    $app->dispatch('device-save', $sameFixture->device());
    $sameFixture->managed();
    $settings = $sameFixture->store->settings();
    $settings['pending_restart'] = true;
    $sameFixture->store->saveSettings($settings);
    $activeConfig = file_get_contents($sameFixture->paths->path('/etc/vgpu_unlock/config.toml'));
    $ensured = $app->dispatch('ensure');
    application_check($ensured['ok'] && !$ensured['changed'], 'Ensure of matching owned runtime must be idempotent');
    application_check(!$sameFixture->store->settings()['pending_restart'], 'Verified enabled ensure may clear pending maintenance');
    $again = $app->dispatch('ensure');
    application_check($again['ok'] && !$again['changed'], 'Repeated ensure must remain idempotent');
    application_check(!array_filter($sameFixture->calls, fn($argv) => in_array($argv[0], ['env', 'modprobe', 'nvidia-modprobe'], true) || ($argv[0] === 'mdevctl' && $argv[1] !== 'list')), 'Healthy ensure must not reload modules, restart daemons or redefine devices');
    application_check(file_get_contents($sameFixture->paths->path('/etc/vgpu_unlock/config.toml')) === $activeConfig, 'Healthy ensure must preserve active config');
    $app->dispatch('config-save', ['runtime' => ['unlock' => false]]);
    $changed = $app->dispatch('ensure');
    application_check(!$changed['ok'] && $changed['code'] === 'maintenance_required', 'Live configuration mismatch must refuse ensure');
    application_check($sameFixture->store->settings()['pending_restart'], 'Refused ensure must retain pending maintenance');

    $upgradeFixture = $fixtures[] = new ApplicationFixture();
    $upgradeApp = $upgradeFixture->application;
    $upgradeSource = $upgradeFixture->source();
    $upgradeSource['driver'] = '';
    $baselineSettings = $upgradeFixture->store->settings();
    $baselineSettings['source'] = $upgradeSource;
    $baselineName = 'nvidia-595.58.03-6.18.58-Unraid-1.txz';
    $baselinePath = $upgradeFixture->paths->cacheDir . '/6.18.58/' . $baselineName;
    $upgradeFixture->put('/boot/config/plugins/unraid-vgpu-manager/packages/6.18.58/' . $baselineName, 'application-cache-fixture');
    $baselineSettings['selected_package'] = ['source' => $upgradeSource['repository'], 'provider' => $upgradeSource['provider'], 'driver' => '595.58.03',
        'kernel' => '6.18.58-Unraid', 'filename' => $baselineName, 'path' => $baselinePath,
        'md5' => hash_file('md5', $baselinePath)];
    $upgradeFixture->store->saveSettings($baselineSettings);
    $upgradeApp->dispatch('config-save', ['source' => $upgradeSource, 'upgrade' => [
        'enabled' => true, 'enabled_boot_id' => 'forged', 'driver_branch' => '580',
    ]]);
    $upgradeSettings = $upgradeFixture->store->settings();
    application_check($upgradeSettings['upgrade']['enabled'] === true, 'Upgrade following must require explicit enablement');
    application_check($upgradeSettings['upgrade']['driver_branch'] === '595', 'Auto policy must pin the installed NVIDIA branch, not client-supplied internal fields');
    application_check($upgradeSettings['upgrade']['enabled_boot_id'] === '11111111-1111-4111-8111-111111111111', 'Enablement must capture the actual current boot');
    application_check($upgradeSettings['runtime']['enabled'] === false && $upgradeFixture->calls === [], 'Enabling upgrade following must not take over vGPU or call a missing helper');
    application_rejects(fn() => $upgradeApp->dispatch('config-save', ['upgrade' => ['enabled' => 'true']]), 'must be a boolean');
    $upgradeFixture->put('/boot/changes.txt', "# Version 7.3.4\n## Linux kernel\n* version: 6.18.58\n");
    $checked = $upgradeApp->dispatch('upgrade-check');
    application_check($checked['status'] === 'current' && $upgradeFixture->calls === [], 'Same-kernel upgrade checks must avoid network and installation');
    application_check($upgradeFixture->store->settings() === $upgradeSettings, 'Upgrade preparation must not replace the selected current package');
    application_rejects(fn() => $upgradeApp->dispatch('upgrade-check', ['target_kernel' => ['bad']]), 'Invalid target');
    application_rejects(fn() => $upgradeApp->dispatch('upgrade-check', ['force' => 'true']), 'must be a boolean');
    $newSource = $upgradeSource;
    $newSource['driver'] = '580.159.03';
    $upgradeApp->dispatch('config-save', ['source' => $newSource]);
    application_check($upgradeFixture->store->settings()['upgrade']['driver_branch'] === '580', 'An explicit driver choice may change the saved branch');
    $upgradeApp->dispatch('config-save', ['source' => $upgradeSource]);
    application_check($upgradeFixture->store->settings()['upgrade']['driver_branch'] === '580', 'Returning to automatic selection must not silently switch the saved branch');

    $reenableFixture = $fixtures[] = new ApplicationFixture();
    $reenableFixture->application->dispatch('config-save', ['upgrade' => ['enabled' => true]]);
    application_check($reenableFixture->store->settings()['upgrade']['driver_branch'] === '595', 'Initial enablement captures the live driver branch');
    $reenableFixture->application->dispatch('config-save', ['upgrade' => ['enabled' => false]]);
    $reenableFixture->put('/sys/module/nvidia/version', "580.159.03\n");
    $reenableFixture->application->dispatch('config-save', ['upgrade' => ['enabled' => true]]);
    application_check($reenableFixture->store->settings()['upgrade']['driver_branch'] === '580', 'Re-enabling after an external rollback must recapture the live branch instead of restoring an obsolete branch');
    application_check($reenableFixture->calls === [], 'Recapturing branch policy must not modify GPU services');

    $receiptFixture = $fixtures[] = new ApplicationFixture();
    $receiptSettings = $receiptFixture->store->settings();
    $receiptSettings['source'] = $receiptFixture->source();
    $receiptSettings['selected_package'] = ['source' => $receiptSettings['source']['repository'], 'driver' => '595.58.03', 'kernel' => '6.18.58-Unraid'];
    $receiptFixture->store->saveSettings($receiptSettings);
    $receiptFixture->application->dispatch('config-save', ['upgrade' => ['enabled' => true]]);
    $receiptSettings = $receiptFixture->store->settings();
    application_check($receiptSettings['selected_package']['saved_boot_id'] === '11111111-1111-4111-8111-111111111111', 'Enabling installation can authorize a legacy cache selection from the same source for the next boot');
    application_check($receiptSettings['selected_package']['source_id'] === Store::sourceIdentity($receiptSettings['source']), 'Legacy cache authorization must retain source binding');
    application_check($receiptSettings['selected_package']['source_fingerprint'] === \Vgpu\Upgrade::sourceFingerprint($receiptSettings['source']), 'Legacy cache authorization must capture all source settings');

    $bootFixture = $fixtures[] = new ApplicationFixture();
    $bootSettings = $bootFixture->store->settings();
    $bootSettings['upgrade'] = ['enabled' => true, 'driver_branch' => '595', 'enabled_boot_id' => '22222222-2222-4222-8222-222222222222'];
    $bootSettings['runtime']['enabled'] = true;
    $bootFixture->store->saveSettings($bootSettings);
    $booted = $bootFixture->application->dispatch('boot');
    application_check(!$booted['ok'] && $booted['status'] === 'missing_package', 'Boot must stop before Runtime.ensure if no package matches the current kernel');
    $bootCommands = array_map(static fn(array $args): string => basename($args[0]), $bootFixture->calls);
    application_check(!array_intersect($bootCommands, ['upgradepkg', 'modprobe', 'nvidia-modprobe', 'mdevctl']), 'Failed boot preparation must not load modules or start mdevs');
    $bootStatus = $bootFixture->application->dispatch('status');
    application_check($bootStatus['driver_installation']['status'] === 'missing_package', 'Installation failure must be visible through the read-only status endpoint');

    $runner = new Process();
    $literal = 'literal; $(not-a-shell-command)';
    $process = $runner([PHP_BINARY, '-n', '-r', 'echo $argv[1];', $literal], 5);
    application_check($process['code'] === 0 && $process['stdout'] === $literal, 'Process must preserve literal argv without shell expansion');
    $process = $runner([PHP_BINARY, '-n', '-r', '$s=stream_get_contents(STDIN);fwrite(STDOUT,hash("sha256",$s));fwrite(STDERR,str_repeat("e",150000));'], 5, str_repeat('stdin-fixture-', 20000));
    application_check($process['code'] === 0 && $process['stdout'] === hash('sha256', str_repeat('stdin-fixture-', 20000)) && strlen($process['stderr']) === 150000, 'Process must handle stdin plus full stdout/stderr pipes');
    application_rejects(fn() => $runner(['bad' . "\0" . 'argument']), 'Invalid command argument');
    application_rejects(fn() => $runner([12]), 'Invalid command argument');
    application_rejects(fn() => $runner([PHP_BINARY], 3601), 'Invalid command or timeout');
    application_rejects(fn() => $runner([PHP_BINARY], 0), 'Invalid command or timeout');
    $process = $runner([PHP_BINARY, '-n', '-r', 'echo "bounded";'], 3600);
    application_check($process['code'] === 0 && $process['stdout'] === 'bounded', 'Upgrade preparation must support a bounded hour for two package downloads');
    $start = microtime(true);
    $process = $runner([PHP_BINARY, '-n', '-r', 'sleep(5);'], 1);
    application_check($process['code'] === 124 && str_contains($process['stderr'], 'completion is unknown'), 'Process timeout must not claim successful completion');
    application_check(microtime(true) - $start < 3, 'A terminating timeout fixture should return promptly');
    echo 'Application/Process integration: ' . $applicationChecks . " checks passed (fake filesystem/runners; no GPU or network)\n";
} finally {
    foreach (array_reverse($fixtures) as $fixture) $fixture->close();
}
