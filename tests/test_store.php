<?php
declare(strict_types=1);
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';

use Vgpu\Paths;
use Vgpu\Store;
use Vgpu\Process;

$storeChecks = 0;
function check_store(bool $condition, string $message): void {
    global $storeChecks;
    if (!$condition) throw new RuntimeException($message);
    ++$storeChecks;
}
function reject_store(callable $operation): void {
    try { $operation(); } catch (Throwable $error) { check_store(true, 'Rejected invalid input'); return; }
    throw new RuntimeException('Expected validation failure');
}
$directory = sys_get_temp_dir() . '/vgpu-store-test-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$paths = new Paths($directory);
$store = new Store($paths);
try {
    check_store(!Store::defaults()['runtime']['enabled'], 'Runtime must default off');
    check_store(!is_dir($paths->configDir), 'Reading defaults must not create files');
    check_store($store->settings() === Store::defaults(), 'Missing settings must be valid');
    check_store(Store::defaults()['schema_version'] === 2 && !Store::defaults()['driver']['enabled'] && !Store::defaults()['upgrade']['enabled'], 'New configurations must separate restoration and prefetch with both off');
    $legacyBoot = '11111111-1111-4111-8111-111111111111';
    foreach ([true, false] as $legacyEnabled) {
        foreach ([true, false] as $hasSchema) {
            $legacy = ['upgrade' => ['enabled' => $legacyEnabled, 'driver_branch' => '595', 'enabled_boot_id' => $legacyBoot],
                'runtime' => ['enabled' => !$legacyEnabled], 'selected_package' => ['fixture' => 'retain']];
            if ($hasSchema) $legacy['schema_version'] = 1;
            $store->saveSettings($legacy);
            $before = file_get_contents($paths->settingsFile);
            $normalized = $store->settings();
            check_store($normalized['driver'] === ['enabled' => $legacyEnabled, 'enabled_boot_id' => $legacyBoot], 'Legacy combined behavior and boot authorization must survive in-memory migration');
            check_store($normalized['upgrade'] === ['enabled' => $legacyEnabled, 'driver_branch' => '595'] && $normalized['runtime']['enabled'] === !$legacyEnabled, 'Migration must retain prefetch, branch and runtime settings independently');
            check_store($normalized['schema_version'] === 2 && $normalized['selected_package'] === ['fixture' => 'retain'], 'Migration must retain cache selection');
            check_store(file_get_contents($paths->settingsFile) === $before && !file_exists($paths->credentialsFile), 'Reading legacy settings must never persist migration or create credentials');
        }
    }
    foreach ([1, 2] as $schema) {
        $store->saveSettings(['schema_version' => $schema, 'driver' => ['enabled' => false], 'upgrade' => ['enabled' => true, 'enabled_boot_id' => $legacyBoot]]);
        check_store($store->settings()['driver'] === Store::defaults()['driver'], 'Explicit restoration disablement must never be overridden by prefetch');
    }
    $store->saveSettings(['schema_version' => 2, 'upgrade' => ['enabled' => true]]);
    check_store(!$store->settings()['driver']['enabled'], 'Missing driver section in schema2 must default off, not use legacy coupling');
    $store->saveSettings(['upgrade' => ['enabled' => true, 'enabled_boot_id' => ['invalid']]]);
    check_store($store->settings()['driver']['enabled_boot_id'] === '', 'Malformed legacy authorization must not become a valid boot identity');
    $store->saveSettings(['driver' => false]);
    reject_store(fn() => $store->settings());
    $store->saveSettings(Store::defaults());
    $source = Store::validateSource(['repository' => 'https://git.example.test/group/unraid-vgpu.git']);
    check_store($source['repository'] === 'https://git.example.test/group/unraid-vgpu', 'URL normalization');
    foreach (['http://example.com/a/b', 'https://secret@example.com/a/b', 'https://example.com/a/b?token=secret', 'https://example.com/a/../b'] as $bad) {
        reject_store(fn() => Store::validateSource(['repository' => $bad]));
    }
    reject_store(fn() => Store::validateSource(['driver' => '595; reboot']));
    reject_store(fn() => Store::validateSource(['tag_template' => '{shell}']));
    $device = Store::validateDevice(['uuid' => 'ae9572f6-1259-4136-9aa4-497dda98d2db', 'pci' => '0000:01:00.0', 'profile' => 'NVIDIA RTXA5500-1Q']);
    check_store($device['type'] === '', 'Profile type may be resolved at runtime');
    reject_store(fn() => Store::validateDevice(['uuid' => '../../wrong']));
    $store->locked(function () use ($store) {
        $store->saveSettings(Store::defaults());
        $store->saveCredentials(['type' => 'private-token', 'token' => 'test-only-secret']);
        reject_store(fn() => $store->locked(fn() => null));
    });
    check_store($store->credentials()['token'] === 'test-only-secret', 'Credential persistence');
    check_store((fileperms($paths->credentialsFile) & 0777) === 0600, 'Credential permissions on POSIX filesystem');
    reject_store(fn() => $store->saveCredentials(['type' => 'private-token', 'token' => "line\nbreak"]));
    $runner = new Process();
    $result = $runner([PHP_BINARY, '-n', '-r', 'echo stream_get_contents(STDIN);'], 5, 'stdin-only');
    check_store($result['code'] === 0 && $result['stdout'] === 'stdin-only', 'Process input/output');
    $result = $runner([PHP_BINARY, '-n', '-r', 'fwrite(STDERR,"failure");exit(7);'], 5);
    check_store($result['code'] === 7 && $result['stderr'] === 'failure', 'Process failure code');
    echo 'Store/Process: ' . $storeChecks . " checks passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
}
