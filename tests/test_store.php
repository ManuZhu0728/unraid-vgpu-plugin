<?php
declare(strict_types=1);
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';

use Vgpu\Paths;
use Vgpu\Store;
use Vgpu\Process;

function check_store(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function reject_store(callable $operation): void {
    try { $operation(); } catch (Throwable $error) { return; }
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
    echo "Store/Process: 18 safety checks passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
}
