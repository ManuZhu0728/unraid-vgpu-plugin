<?php
declare(strict_types=1);

// Exercise the production importer with a real TXZ. No network, pkgtools,
// module loading, GPU access, or live plugin configuration is used.
require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';

use Vgpu\Download;
use Vgpu\PackageIdentity;
use Vgpu\Paths;
use Vgpu\Process;

function cache_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function cache_reject(callable $operation, string $expected): void
{
    try { $operation(); }
    catch (Throwable $error) {
        cache_assert(str_contains($error->getMessage(), $expected), 'Unexpected rejection: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Invalid cache receipt was accepted');
}

try {
    if ($argc !== 3) throw new InvalidArgumentException('Usage: php -n verify-driver-cache.php PACKAGE.txz EMPTY_TEST_DIRECTORY');
    $package = realpath($argv[1]);
    $root = realpath($argv[2]);
    cache_assert($package !== false && is_file($package) && !is_link($argv[1]), 'Package must be a regular file');
    cache_assert($root !== false && !is_link($argv[2]) && basename($root) !== '' &&
        str_starts_with(basename($root), 'vgpu-cache-integration.') && is_dir($root),
        'Use a newly created vgpu-cache-integration.XXXXXXXX directory');
    cache_assert(count(scandir($root)) === 2, 'Test directory must be empty');
    $identity = PackageIdentity::parse(basename($package));
    $before = hash_file('md5', $package);
    $checksum = $package . '.md5';
    cache_assert(is_file($checksum) && !is_link($checksum), 'Matching MD5 sidecar is required');
    $paths = new Paths($root);
    $commands = [];
    $process = new Process();
    $runner = static function (array $argv, int $timeout = 30, ?string $stdin = null) use ($process, &$commands): array {
        cache_assert(in_array($argv[0], ['tar', 'xz', 'modinfo'], true), 'Unexpected non-passive command');
        $commands[] = $argv;
        return $process($argv, $timeout, $stdin);
    };
    $download = new Download($paths, $runner);
    $import = $download->importLocal($package, basename($package), $checksum, $identity['kernel']);
    cache_assert(($import['ok'] ?? false) === true, 'Import failed');
    $receipt = $import['package'];
    foreach ($identity as $field => $value) cache_assert($receipt[$field] === $value, 'Incorrect imported ' . $field);
    cache_assert($receipt['checksum_verified'] === true && $receipt['source'] === 'local', 'Missing import verification');
    cache_assert(hash_file('md5', $receipt['path']) === $before, 'Cached contents differ');
    $verified = $download->verifyCached($receipt, $identity['kernel']);
    cache_assert($verified === $receipt, 'Receipt changed on verification');
    $bad = $receipt;
    $bad['build'] = $identity['build'] + 1;
    cache_reject(fn() => $download->verifyCached($bad, $identity['kernel']), 'build differs');
    $bad = $receipt;
    $bad['md5'] = str_repeat('0', 32);
    cache_reject(fn() => $download->verifyCached($bad, $identity['kernel']), 'checksum differs');
    cache_reject(fn() => $download->verifyCached($receipt, '0.0.0-Unraid'), 'another kernel');
    $cached = lstat($receipt['path']);
    $again = $download->importLocal($package, basename($package), $checksum, $identity['kernel']);
    clearstatcache();
    $unchanged = lstat($receipt['path']);
    cache_assert($again['package'] === $receipt && $cached['ino'] === $unchanged['ino'] &&
        $cached['mtime'] === $unchanged['mtime'], 'Reimport replaced the existing cache');
    cache_assert(!file_exists($paths->settingsFile) && !file_exists($paths->credentialsFile) &&
        !file_exists($paths->configDir . '/driver-state.json'), 'Passive import changed configuration or install state');
    cache_assert(hash_file('md5', $package) === $before, 'Input package changed');
    $result = ['ok' => true, 'scope' => 'real TXZ passive import/cache verification only',
        'package' => $receipt, 'input_unchanged' => true,
        'checks' => ['local import with MD5', 'kernel/driver/build identity', 'cached archive revalidation',
            'wrong build rejected', 'wrong checksum rejected', 'wrong kernel rejected',
            'repeat import preserves cache', 'no configuration or installation side effects'],
        'command_count' => count($commands), 'timestamp' => gmdate('c')];
    $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    cache_assert(file_put_contents($root . '/result.json', $encoded) === strlen($encoded), 'Cannot save result');
    echo $encoded;
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
