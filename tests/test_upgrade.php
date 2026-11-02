<?php
declare(strict_types=1);

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/Upgrade.php';

use Vgpu\Download;
use Vgpu\Paths;
use Vgpu\Store;
use Vgpu\Upgrade;

$upgradeChecks = 0;
function upgrade_check(bool $condition, string $message): void
{
    global $upgradeChecks;
    if (!$condition) throw new RuntimeException($message);
    ++$upgradeChecks;
}

function upgrade_failure(callable $operation, string $message): void
{
    try { $operation(); }
    catch (Throwable $error) { upgrade_check(str_contains($error->getMessage(), $message), 'Unexpected error: ' . $error->getMessage()); return; }
    throw new RuntimeException('Expected failure: ' . $message);
}

final class UpgradeFixture
{
    public string $root;
    public Paths $paths;
    public Store $store;
    public Download $download;
    public Upgrade $upgrade;
    public array $settings;
    public array $credentials;
    public array $calls = [];
    public array $routes = [];
    public string $activeDriver = '595.58.03';
    public string $activeKernel = '6.18.60';
    public string $target = '6.18.60';
    public string $throwMessage = '';

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/vgpu-upgrade-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->paths = new Paths($this->root);
        $this->store = new Store($this->paths);
        foreach (['/proc/sys/kernel/osrelease' => '6.18.58-Unraid', '/proc/sys/kernel/random/boot_id' => '11111111-1111-4111-8111-111111111111',
            '/sys/module/nvidia/version' => '595.58.03', '/boot/changes.txt' => "# Version 7.3.5 2026-12-01\n## Linux kernel\n* version 6.18.60\n\n# Version 7.3.4\n## Linux kernel\n* version 6.18.99\n"] as $name => $contents) {
            $path = $this->paths->path($name);
            if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
            file_put_contents($path, $contents);
        }
        $this->settings = Store::defaults();
        $this->settings['source'] = Store::validateSource(array_replace($this->settings['source'], [
            'url' => 'https://git.example.test/owner/repo', 'project_id' => '42',
        ]));
        $this->settings['upgrade'] = ['enabled' => true, 'driver_branch' => '595', 'enabled_boot_id' => '11111111-1111-4111-8111-111111111111'];
        $this->credentials = ['type' => 'private-token', 'token' => 'upgrade-fixture-secret', 'username' => '',
            'source_id' => Store::sourceIdentity($this->settings['source'])];
        $this->addVersions(['595.58.03', '595.63.01', '580.159.03'], '6.18.58');
        $this->addVersions(['595.58.03', '595.63.01', '580.159.03']);
        $this->settings['selected_package'] = $this->currentCache();
        $this->store->saveSettings($this->settings);
        $this->download = new Download($this->paths, $this->run(...));
        $this->upgrade = new Upgrade($this->store, $this->download, $this->run(...));
    }

    public function addVersions(array $drivers, ?string $kernel = null): void
    {
        $kernel ??= $this->target;
        $assets = [];
        foreach ($drivers as $driver) {
            $filename = 'nvidia-' . $driver . '-' . $kernel . '-Unraid-1.txz';
            $url = 'https://git.example.test/api/v4/projects/42/packages/generic/build/v' . $kernel . '-' . $driver . '/' . $filename;
            $body = "\xfd7zXZ\x00package-" . $filename;
            $this->routes[$url] = ['body' => $body];
            $this->routes[$url . '.md5'] = ['body' => md5($body) . '  ' . $filename . "\n"];
            $assets[] = ['name' => $filename, 'url' => $url];
            $assets[] = ['name' => $filename . '.md5', 'url' => $url . '.md5'];
        }
        $this->routes['https://git.example.test/api/v4/projects/42/releases/' . $kernel . '-Unraid'] = [
            'body' => json_encode(['tag_name' => $kernel . '-Unraid', 'assets' => ['links' => $assets]]), 'type' => 'application/json',
        ];
    }

    private function currentCache(): array
    {
        $filename = 'nvidia-595.58.03-6.18.58-Unraid-1.txz';
        $path = $this->paths->cacheDir . '/6.18.58/' . $filename;
        mkdir(dirname($path), 0700, true);
        $body = "\xfd7zXZ\x00package-" . $filename;
        file_put_contents($path, $body);
        return ['path' => $path, 'filename' => $filename, 'kernel' => '6.18.58-Unraid', 'driver' => '595.58.03',
            'md5' => md5($body), 'checksum_verified' => true, 'source' => $this->settings['source']['repository'],
            'provider' => $this->settings['source']['provider'], 'source_id' => Store::sourceIdentity($this->settings['source']),
            'source_fingerprint' => Upgrade::sourceFingerprint($this->settings['source']),
            'saved_boot_id' => '11111111-1111-4111-8111-111111111111'];
    }

    public function run(array $argv, int $timeout = 15, ?string $stdin = null): array
    {
        $this->calls[] = ['argv' => $argv, 'stdin' => $stdin];
        upgrade_check(!str_contains(implode(' ', $argv), 'upgrade-fixture-secret'), 'Token must not enter command arguments');
        if (str_ends_with($argv[0], '/notify')) return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        if ($argv[0] === 'curl') {
            if ($this->throwMessage !== '') throw new RuntimeException($this->throwMessage);
            if (!preg_match('/^url = "(.*)"$/m', (string)$stdin, $match)) throw new RuntimeException('No request URL');
            $url = stripcslashes($match[1]);
            $route = $this->routes[$url] ?? ['status' => 404, 'body' => 'missing'];
            $path = $argv[array_search('--output', $argv, true) + 1];
            file_put_contents($path, $route['body'] ?? 'missing');
            if (preg_match('/\/nvidia-([0-9]+\.[0-9]+\.[0-9]+)-([0-9.]+)-Unraid-1\.txz$/D', $url, $driver)) {
                $this->activeDriver = $driver[1]; $this->activeKernel = $driver[2];
            }
            return ['code' => 0, 'stdout' => ($route['status'] ?? 200) . "\n" . ($route['type'] ?? 'application/octet-stream') . "\n\n", 'stderr' => ''];
        }
        if ($argv[0] === 'xz') return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        if ($argv[0] === 'tar' && in_array('--list', $argv, true)) {
            $archive = basename($argv[array_search('--file', $argv, true) + 1]);
            if (preg_match('/^nvidia-([0-9]+\.[0-9]+\.[0-9]+)-([0-9.]+)-Unraid-1\.txz$/D', $archive, $identity)) {
                $this->activeDriver = $identity[1]; $this->activeKernel = $identity[2];
            }
            $names = [];
            foreach (['nvidia', 'nvidia-modeset', 'nvidia-drm', 'nvidia-uvm', 'nvidia-vgpu-vfio'] as $module) $names[] = 'lib/modules/' . $this->activeKernel . '-Unraid/kernel/drivers/video/' . $module . '.ko';
            foreach (['usr/bin/nvidia-vgpud', 'usr/bin/nvidia-vgpu-mgr', 'usr/lib64/libvgpu_unlock_rs.so',
                'usr/share/nvidia/vgpu/vgpuConfig.xml', 'usr/lib64/libcuda.so.1', 'usr/lib64/libnvcuvid.so.1',
                'usr/lib64/libnvidia-encode.so.1', 'usr/lib64/libnvidia-ml.so.1'] as $name) $names[] = $name;
            return ['code' => 0, 'stdout' => implode("\n", array_map(static fn($name) => '-rw-r--r-- 0/0 1 2026-11-02 10:00:00 ' . $name, $names)) . "\n", 'stderr' => ''];
        }
        if ($argv[0] === 'tar' && in_array('--extract', $argv, true)) {
            $directory = $argv[array_search('--directory', $argv, true) + 1];
            foreach (array_slice($argv, array_search('--', $argv, true) + 1) as $member) {
                $path = $directory . '/' . $member;
                if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
                file_put_contents($path, 'fixture module');
            }
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        }
        if ($argv[0] === 'modinfo') return ['code' => 0,
            'stdout' => $argv[2] === 'version' ? $this->activeDriver : $this->activeKernel . '-Unraid SMP preempt mod_unload', 'stderr' => ''];
        throw new RuntimeException('Unexpected mutation command: ' . $argv[0]);
    }

    public function count(string $name): int
    {
        return count(array_filter($this->calls, static fn($call) => $call['argv'][0] === $name || str_ends_with($call['argv'][0], '/' . $name)));
    }

    public function check(?string $kernel = null, bool $force = false): array
    {
        return $this->store->locked(fn() => $this->upgrade->check($this->settings, $this->credentials, $kernel, $force));
    }

    public function cleanup(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname());
            else unlink($item->getPathname());
        }
        rmdir($this->root);
    }
}

$fixtures = [];
$fixture = static function () use (&$fixtures): UpgradeFixture { $f = new UpgradeFixture(); $fixtures[] = $f; return $f; };
try {
    foreach ([
        "# Version 7.3.5\n## Linux kernel\n* version 6.18.60\n# Version 7.3.4\n## Linux kernel\n* version 6.18.99" => '6.18.60-Unraid',
        "Version 7.3.5\nLinux kernel:\n- version: 6.18.60-Unraid" => '6.18.60-Unraid',
        "# Unraid OS Version 7.4.0-beta.1\n## Linux kernel 6.20.1" => '6.20.1-Unraid',
    ] as $notes => $kernel) upgrade_check(Upgrade::targetFromChanges($notes)['kernel'] === $kernel, 'Must parse only the first release kernel');
    upgrade_failure(fn() => Upgrade::targetFromChanges("# Version 7.3.5\nMissing kernel\n# Version 7.3.4\n## Linux kernel\n* version 6.18.99"), '目标内核');
    upgrade_failure(fn() => Upgrade::targetFromChanges("## Linux kernel\n* version 6.18.60"), '版本信息');

    $f = $fixture();
    $f->settings['upgrade']['enabled'] = false;
    upgrade_check($f->check()['status'] === 'disabled' && $f->calls === [], 'Disabled upgrades must not read source or execute commands');
    upgrade_check(!is_file($f->paths->configDir . '/upgrade-state.json'), 'Disabled upgrades must not write state');

    $f = $fixture();
    upgrade_check($f->check('6.18.58-Unraid')['status'] === 'current' && $f->calls === [], 'Same-kernel updates must not download');
    $before = file_get_contents($f->paths->settingsFile);
    $result = $f->check();
    upgrade_check($result['ok'] === true && $result['status'] === 'ready', 'New kernel must be prepared');
    upgrade_check($result['package']['kernel'] === '6.18.60-Unraid' && $result['package']['driver'] === '595.58.03', 'Prefer the current driver on the target kernel');
    upgrade_check($result['prepared_boot_id'] === '11111111-1111-4111-8111-111111111111', 'Receipt must identify the preparing boot');
    upgrade_check($result['source_fingerprint'] === Upgrade::sourceFingerprint($f->settings['source']), 'Receipt must bind to the configured source');
    upgrade_check(file_get_contents($f->paths->settingsFile) === $before, 'Prefetch must not modify current selection or settings');
    upgrade_check($f->count('notify') === 2, 'First preparation must notify start and completion');
    $calls = count($f->calls);
    upgrade_check($f->check()['unchanged'] === true && count($f->calls) === $calls, 'Ready cache must avoid repeated download and notifications');
    file_put_contents($f->paths->path('/proc/sys/kernel/osrelease'), '6.18.60-Unraid');
    upgrade_check($f->check()['status'] === 'current' && $f->upgrade->status()['status'] === 'ready', 'New boot must retain the package receipt for the installer');

    $f = $fixture();
    $f->addVersions(['595.63.01', '580.159.03', '610.1.2']);
    $result = $f->check();
    upgrade_check($result['package']['driver'] === '595.63.01', 'Choose the highest available version only within the saved branch');

    $f = $fixture();
    $f->settings['source']['driver'] = '580.159.03';
    $f->credentials['source_id'] = Store::sourceIdentity($f->settings['source']);
    $result = $f->check();
    upgrade_check($result['package']['driver'] === '580.159.03' && $result['driver_branch'] === '580', 'Explicit driver choice must supersede the prior branch');

    $f = $fixture();
    file_put_contents($f->paths->path('/sys/module/nvidia/version'), '580.159.03');
    $result = $f->check();
    upgrade_check($result['ok'] === false && $result['status'] === 'error' && str_contains($result['message'], '更新分支 R595'), 'An externally rolled-back driver must not silently restore a different saved branch');
    upgrade_check($f->count('curl') === 0, 'A stale saved branch must fail before network requests');

    $f = $fixture();
    file_put_contents($f->paths->path('/sys/module/nvidia/version'), '580.159.03');
    $f->settings['upgrade']['driver_branch'] = '580';
    // A one-off package selection alone does not change the running branch.
    $result = $f->check();
    upgrade_check($result['ok'] && $result['package']['driver'] === '580.159.03' && $result['driver_branch'] === '580', 'A newer selected cache must not override the running driver branch when no explicit pin was saved');

    $f = $fixture();
    $f->settings['source']['driver'] = '595.70.01';
    $result = $f->check();
    upgrade_check($result['ok'] === false && $result['status'] === 'missing', 'An unavailable exact pin must not choose another version from the same branch');

    $f = $fixture();
    $f->addVersions(['580.159.03', '610.1.2']);
    $result = $f->check();
    upgrade_check($result['ok'] === false && $result['status'] === 'missing', 'Missing saved branch must not silently switch branches');
    upgrade_check($f->count('xz') === 0, 'A rejected driver branch must not download a package');

    $f = $fixture();
    $f->credentials['source_id'] = str_repeat('0', 64);
    $result = $f->check();
    upgrade_check($result['ok'] === false && $f->count('curl') === 0, 'Wrong credential binding must fail before network');

    $f = $fixture();
    $f->throwMessage = 'Transport failed upgrade-fixture-secret glpat-ALSOSECRET';
    $result = $f->check();
    upgrade_check(!str_contains(json_encode($result), 'upgrade-fixture-secret') && !str_contains(json_encode($result), 'glpat-ALSOSECRET'), 'Status must redact tokens');
    $curl = $f->count('curl'); $notify = $f->count('notify');
    upgrade_check(($f->check()['deferred'] ?? false) === true && $f->count('curl') === $curl, 'Failed automatic attempts must back off');
    $state = $f->upgrade->status(); $state['last_attempt'] = time() - Upgrade::RETRY_SECONDS - 1;
    $f->store->write($f->paths->configDir . '/upgrade-state.json', $state);
    $f->check();
    upgrade_check($f->count('curl') === $curl + 1 && $f->count('notify') === $notify, 'Retry must not repeat the same start/error notification');
    $f->check('6.18.60-Unraid');
    upgrade_check($f->count('curl') === $curl + 2, 'Manual explicit retry may bypass the automatic backoff');
    $f->check(null, true);
    upgrade_check($f->count('curl') === $curl + 3, 'An explicit force action must immediately retry the detected target');

    $f = $fixture();
    $f->store->saveCredentials($f->credentials);
    $f->throwMessage = 'Download HTTP 401';
    $f->check();
    $curl = $f->count('curl');
    $oldStamp = $f->upgrade->status()['credential_stamp'];
    $f->credentials['token'] = 'rotated-test-credential';
    $f->store->saveCredentials($f->credentials);
    $retried = $f->check();
    upgrade_check($f->count('curl') === $curl + 1 && !isset($retried['deferred']), 'Replacing credentials must allow an immediate automatic retry');
    upgrade_check($retried['credential_stamp'] !== $oldStamp && !str_contains(json_encode($retried), 'rotated-test-credential'), 'The persisted credential stamp must not contain token content');

    $f = $fixture();
    $f->settings['source']['url'] = 'https://git.example.test/owner/repo/-/releases/6.18.58-Unraid';
    $f->settings['source'] = Store::validateSource($f->settings['source']);
    $f->credentials['source_id'] = Store::sourceIdentity($f->settings['source']);
    upgrade_check($f->check()['package']['kernel'] === '6.18.60-Unraid', 'A release-page link must locate the target kernel release, not the old release');

    $f = $fixture();
    $f->settings['source'] = Store::validateSource(array_replace($f->settings['source'], [
        'url' => 'https://git.example.test/api/v4/projects/42/packages/generic/build/v6.18.58-595.58.03/nvidia-595.58.03-6.18.58-Unraid-1.txz',
    ]));
    $f->credentials['source_id'] = Store::sourceIdentity($f->settings['source']);
    upgrade_check($f->check()['package']['kernel'] === '6.18.60-Unraid', 'Known Generic link layout must retarget the kernel without changing project');

    $f = $fixture();
    $f->settings['source'] = Store::validateSource(array_replace($f->settings['source'], [
        'url' => 'https://files.example.test/nvidia-595.58.03-6.18.58-Unraid-1.txz', 'provider' => 'auto',
    ]));
    $f->settings['selected_package']['source'] = $f->settings['source']['repository'];
    $f->settings['selected_package']['provider'] = $f->settings['source']['provider'];
    $f->settings['selected_package']['source_id'] = Store::sourceIdentity($f->settings['source']);
    $f->settings['selected_package']['source_fingerprint'] = Upgrade::sourceFingerprint($f->settings['source']);
    $f->credentials = ['type' => 'none', 'token' => '', 'source_id' => ''];
    $result = $f->check();
    upgrade_check($result['ok'] === false && str_contains($result['message'], '固定文件链接') && $f->count('curl') === 0, 'Unknown direct link layout must not be guessed');

    $f = $fixture();
    $f->check();
    $state = $f->upgrade->status();
    unlink($state['package']['path']);
    $curl = $f->count('curl');
    upgrade_check($f->check()['ok'] === true && $f->count('curl') > $curl, 'A missing ready cache must be downloaded again');

    // First enablement must make an ordinary restart self-contained, even
    // before an Unraid update has been staged.
    $f = $fixture();
    unlink($f->settings['selected_package']['path']);
    $f->settings['selected_package'] = null;
    $f->store->saveSettings($f->settings);
    file_put_contents($f->paths->path('/boot/changes.txt'), "# Version 7.3.4\n## Linux kernel\n* version 6.18.58\n");
    $result = $f->check();
    $baseline = $f->store->settings()['selected_package'];
    upgrade_check($result['ok'] === true && $result['status'] === 'current' && $result['baseline_prepared'], 'First enablement must prepare the running kernel without a staged upgrade');
    upgrade_check($baseline['kernel'] === '6.18.58-Unraid' && $baseline['driver'] === '595.58.03' && is_file($baseline['path']), 'The current package must become the selected recovery cache');
    upgrade_check($baseline['saved_boot_id'] === '11111111-1111-4111-8111-111111111111' && $baseline['source_id'] === Store::sourceIdentity($f->settings['source']), 'Baseline receipt must authorize a later boot and bind to the source');
    upgrade_check(!is_dir($f->paths->cacheDir . '/6.18.60'), 'Ordinary restart preparation must not download an unrelated future kernel');
    $f->settings = $f->store->settings();
    $calls = count($f->calls);
    upgrade_check($f->check()['baseline_prepared'] === false && count($f->calls) === $calls, 'A valid selected baseline must not be downloaded again');

    // A not-yet-published future package must not prevent a normal restart on
    // the current system after enabling the new installer.
    $f = $fixture();
    unlink($f->settings['selected_package']['path']);
    $f->settings['selected_package'] = null;
    $f->store->saveSettings($f->settings);
    $f->addVersions([]);
    $result = $f->check();
    $baseline = $f->store->settings()['selected_package'];
    upgrade_check($result['ok'] === false && $result['status'] === 'missing' && $result['target_kernel'] === '6.18.60-Unraid', 'An absent future package must remain an explicit missing-target result: ' . json_encode($result));
    upgrade_check($baseline['kernel'] === '6.18.58-Unraid' && is_file($baseline['path']), 'Missing target package must retain the freshly prepared current baseline');
    upgrade_check($result['baseline_package']['md5'] === $baseline['md5'], 'The failed target job must still expose its prepared baseline');

    $f = $fixture();
    $last = $f->settings['selected_package'];
    $f->settings['selected_package'] = null;
    $f->store->saveSettings($f->settings);
    $f->store->write($f->paths->configDir . '/driver-state.json', ['last_success' => [
        'package' => $last, 'saved_boot_id' => $last['saved_boot_id'],
        'source_id' => $last['source_id'], 'source_fingerprint' => $last['source_fingerprint'], 'local' => false,
    ]]);
    $result = $f->check('6.18.58-Unraid');
    upgrade_check($result['ok'] && $f->store->settings()['selected_package']['md5'] === $last['md5'] && $f->calls === [], 'A source-bound last-success package may restore the current baseline without network');

    $f = $fixture();
    $ready = $f->settings['selected_package'];
    $f->settings['selected_package'] = null;
    $f->store->saveSettings($f->settings);
    $f->store->write($f->paths->configDir . '/upgrade-state.json', [
        'status' => 'ready', 'target_kernel' => '6.18.58-Unraid', 'package' => $ready,
        'prepared_boot_id' => $ready['saved_boot_id'], 'source_id' => $ready['source_id'], 'source_fingerprint' => $ready['source_fingerprint'],
        'driver_branch' => '595', 'reference_driver' => '595.58.03',
    ]);
    $result = $f->check('6.18.58-Unraid');
    upgrade_check($result['ok'] && $f->store->settings()['selected_package']['md5'] === $ready['md5'] && $f->calls === [], 'A ready receipt for the running kernel may establish the selected baseline');
    upgrade_check($f->upgrade->status()['status'] === 'ready', 'Establishing the baseline must retain the ready receipt for Installer');

    // A changed source must not leave an older ready receipt in front of a
    // valid new selection during the next boot.
    $f = $fixture();
    $old = $f->settings['selected_package'];
    $f->store->write($f->paths->configDir . '/upgrade-state.json', [
        'status' => 'ready', 'target_kernel' => '6.18.58-Unraid', 'package' => $old,
        'prepared_boot_id' => $old['saved_boot_id'], 'source_id' => $old['source_id'],
        'source_fingerprint' => $old['source_fingerprint'], 'driver_branch' => '595', 'reference_driver' => '595.58.03',
    ]);
    $f->settings['source'] = Store::validateSource(array_replace($f->settings['source'], ['url' => 'https://git.example.test/new/repo', 'project_id' => '43']));
    $f->credentials['source_id'] = Store::sourceIdentity($f->settings['source']);
    $f->settings['selected_package']['source'] = $f->settings['source']['repository'];
    $f->settings['selected_package']['source_id'] = Store::sourceIdentity($f->settings['source']);
    $f->settings['selected_package']['source_fingerprint'] = Upgrade::sourceFingerprint($f->settings['source']);
    $f->store->saveSettings($f->settings);
    $result = $f->check('6.18.58-Unraid');
    $state = $f->upgrade->status();
    upgrade_check($result['ok'] && $f->calls === [] && $state['status'] === 'current', 'A changed source with a valid baseline must replace an obsolete ready job without downloading');
    upgrade_check($state['source_fingerprint'] === Upgrade::sourceFingerprint($f->settings['source']) && $state['package']['source_id'] === Store::sourceIdentity($f->settings['source']), 'Current recovery state must identify the new source, not an old ready receipt');

    $f = $fixture();
    file_put_contents($f->paths->path('/boot/changes.txt'), "# Version 7.3.4\n## Linux kernel\n* version 6.18.58\n");
    $f->check();
    $state = $f->upgrade->status();
    $state['status'] = 'error'; $state['message'] = 'Obsolete failure'; $state['last_attempt'] = time();
    $f->store->write($f->paths->configDir . '/upgrade-state.json', $state);
    $result = $f->check();
    upgrade_check($result['ok'] && !isset($result['deferred']) && $f->upgrade->status()['status'] === 'current' && $f->calls === [], 'A valid reused current baseline must clear an obsolete error without waiting for backoff');

    $f = $fixture();
    unlink($f->settings['selected_package']['path']);
    $f->settings['selected_package'] = null;
    $f->store->saveSettings($f->settings);
    unlink($f->paths->path('/boot/changes.txt'));
    $result = $f->check();
    $baseline = $f->store->settings()['selected_package'];
    upgrade_check($baseline['kernel'] === '6.18.58-Unraid' && is_file($baseline['path']), 'Missing upgrade notes must not prevent initial current-kernel caching');
    upgrade_check($result['ok'] === false && $result['phase'] === 'baseline', 'Unreadable notes must remain visible after the baseline is prepared');

    $f = $fixture();
    unlink($f->settings['selected_package']['path']);
    $f->settings['selected_package'] = null;
    $f->store->saveSettings($f->settings);
    $f->store->saveCredentials($f->credentials);
    file_put_contents($f->paths->path('/boot/changes.txt'), "# Version 7.3.4\n## Linux kernel\n* version 6.18.58\n");
    $f->throwMessage = 'Download HTTP 503';
    $result = $f->check();
    upgrade_check($result['status'] === 'error' && $result['phase'] === 'baseline', 'Baseline transport failure must be visible');
    $calls = $f->count('curl');
    upgrade_check(($f->check()['deferred'] ?? false) === true && $f->count('curl') === $calls, 'Baseline failure must use the same automatic retry backoff');
    $f->credentials['token'] = 'baseline-rotated-credential';
    $f->store->saveCredentials($f->credentials);
    $f->check();
    upgrade_check($f->count('curl') === $calls + 1, 'New credentials must permit immediate retry of baseline preparation');

    $watch = file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/upgrade-watch.php');
    $shell = file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/scripts/upgrade-helper');
    upgrade_check(str_contains($watch, 'LOCK_EX | LOCK_NB') && str_contains($watch, 'upgrade-check'), 'Monitor must be single-instance and use the current CLI');
    upgrade_check(!str_contains($watch, 'inotifywait') && !str_contains($shell, '/usr/bin/plugin_update_helper'), 'Monitor must not replace or match the global helper');
    upgrade_check(str_contains($shell, 'nohup') && str_contains($shell, '</dev/null'), 'Monitor must detach its input and use its own log');
    upgrade_check(!preg_match('/\bkill(?:all)?\b/', $watch . $shell), 'Helper lifecycle must not kill other processes');
    upgrade_check(str_contains($watch, '$settingsHash') && str_contains($watch, '$credentialStamp') && str_contains($watch, 'clearstatcache()'), 'Monitor must observe configuration and credential changes without cached filesystem state');
    upgrade_check(!str_contains($watch, '$changesHash !=='), 'Initial baseline checks must also run without upgrade release notes');
    upgrade_check(str_contains($watch, "is_array(\$response) && array_key_exists('ok', \$response)"), 'Invalid CLI JSON must remain retryable');
    upgrade_check(preg_match("~ -r '([^']+)'~", $shell, $snippet) === 1, 'Helper must contain a bounded PHP enablement check');
    $lint = (new \Vgpu\Process())([PHP_BINARY, '-n', '-l'], 5, "<?php\n" . $snippet[1]);
    upgrade_check($lint['code'] === 0, 'Embedded PHP enablement snippet must be valid PHP: ' . $lint['stderr']);
} finally {
    foreach ($fixtures as $f) $f->cleanup();
}
echo 'Upgrade preparation: ' . $upgradeChecks . " checks passed (fake filesystem/HTTP/commands; no service was started)\n";
