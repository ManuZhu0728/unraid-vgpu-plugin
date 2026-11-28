<?php
declare(strict_types=1);
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/Application.php';

use Vgpu\{Application, Download, PackageCache, PackageHistory, Paths, Runtime, Store};

$checks = 0;
function cache_check(bool $condition, string $message): void
{
    ++$GLOBALS['checks'];
    if (!$condition) throw new RuntimeException($message);
}
function cache_rejects(callable $operation): void
{
    try { $operation(); } catch (Throwable) { ++$GLOBALS['checks']; return; }
    throw new RuntimeException('Unsafe cache deletion was accepted');
}
$root = sys_get_temp_dir() . '/vgpu-cache-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$paths = new Paths($root);
$store = new Store($paths);
$cache = new PackageCache($store);
$runner = static function (): never { throw new RuntimeException('No GPU, process, installer or network command is permitted'); };
$app = new Application($store, new Runtime($paths, $runner), new Download($paths, $runner), $runner);
$make = static function (string $kernel, int $build, string $driver = '595.58.03') use ($paths): array {
    $name = 'nvidia-' . $driver . '-' . $kernel . '-' . $build . '.txz';
    $path = $paths->cacheDir . '/' . substr($kernel, 0, -7) . '/' . $name;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, 'cache fixture ' . $name);
    $md5 = md5_file($path);
    file_put_contents($path . '.md5', $md5 . '  ' . $name . "\n");
    return ['filename' => $name, 'path' => $path, 'driver' => $driver, 'kernel' => $kernel, 'build' => $build,
        'md5' => $md5, 'source' => 'local', 'saved_boot_id' => '11111111-1111-4111-8111-111111111111'];
};
$row = static function (array $list, string $filename): array {
    foreach ($list['packages'] as $item) if ($item['filename'] === $filename) return $item;
    throw new RuntimeException('Cache row not found');
};
try {
    cache_check($cache->listing() === ['available' => true, 'packages' => [], 'message' => ''] && !is_dir($paths->configDir), 'Empty inventory is read-only and creates no configuration');
    $old = $make('6.18.58-Unraid', 1);
    $installed = $make('6.18.58-Unraid', 2);
    $selected = $make('6.18.58-Unraid', 3);
    $attempt = $make('6.18.58-Unraid', 4);
    $prepared = $make('6.18.60-Unraid', 2);
    $historical = $make('6.18.57-Unraid', 1);
    $settings = Store::defaults(); $settings['selected_package'] = $selected;
    $store->saveSettings($settings);
    $store->saveCredentials(['type' => 'none', 'token' => '', 'username' => '', 'source_id' => '']);
    $store->saveDevices([]);
    $upgradePath = $paths->configDir . '/upgrade-state.json';
    $installPath = $paths->configDir . '/driver-state.json';
    $store->write($upgradePath, ['status' => 'ready', 'package' => $prepared, 'baseline_package' => $installed]);
    $store->write($installPath, ['status' => 'installing', 'attempted_package' => $attempt, 'last_success' => PackageHistory::fromPackage($installed)]);
    (new PackageHistory($store))->remember(PackageHistory::fromPackage($historical));
    $list = $cache->listing();
    cache_check($list['available'] && count($list['packages']) === 6, 'All kernels/builds are listed without choosing an install target');
    cache_check($list['packages'][0]['kernel'] === '6.18.60-Unraid', 'Newer kernels are shown first');
    foreach ([$selected['filename'] => '当前选中', $installed['filename'] => '上次成功安装', $prepared['filename'] => '升级预下载', $attempt['filename'] => '安装任务引用', $historical['filename'] => '内核回退记录'] as $name => $reason) {
        $item = $row($list, $name);
        cache_check($item['deletable'] === ($reason === '内核回退记录') && in_array($reason, $item['references'], true), 'Only history-only references offer confirmed cleanup: ' . $reason);
        cache_rejects(fn() => $app->dispatch('cache-delete', ['filename' => $name]));
        cache_check(is_file($paths->cacheDir . '/' . substr($item['kernel'], 0, -7) . '/' . $name), 'Protected bytes remain intact');
    }
    cache_check($row($list, $old['filename'])['deletable'], 'Only the unreferenced build is deletable');
    $preserved = [];
    foreach ([$paths->settingsFile, $paths->credentialsFile, $paths->devicesFile, $upgradePath, $installPath, $paths->configDir . '/package-history/6.18.57-Unraid.json'] as $file) $preserved[$file] = file_get_contents($file);
    $result = $app->dispatch('cache-delete', ['filename' => $old['filename']]);
    cache_check($result['ok'] && $result['changed'] && $result['removed'] === [$old['filename'], $old['filename'] . '.md5'], 'A successful delete reports exactly the TXZ and its MD5');
    cache_check(!file_exists($old['path']) && !file_exists($old['path'] . '.md5') && count($result['package_cache']['packages']) === 5, 'Both named files are removed and the returned list is fresh');
    foreach ($preserved as $file => $bytes) cache_check(file_get_contents($file) === $bytes, 'Deletion never rewrites settings, credentials, devices, installation or rollback state');
    cache_rejects(fn() => $app->dispatch('cache-delete', ['filename' => $old['filename']]));
    foreach (['../' . $old['filename'], $old['path'], 'nvidia-595.58.03-6.18.58-Unraid-01.txz', 'unraid-vgpu-manager.plg', $old['filename'] . '.md5', ''] as $filename) cache_rejects(fn() => $app->dispatch('cache-delete', ['filename' => $filename]));
    foreach ([[], ['filename' => []], ['filename' => $old['filename'], 'path' => $selected['path']]] as $payload) cache_rejects(fn() => $app->dispatch('cache-delete', $payload));

    $stale = $make('6.18.58-Unraid', 5);
    cache_check($row($cache->listing(), $stale['filename'])['deletable'], 'An unreferenced row may initially be deletable');
    $settings['selected_package'] = $stale; $store->saveSettings($settings);
    cache_rejects(fn() => $app->dispatch('cache-delete', ['filename' => $stale['filename']]));
    cache_check(is_file($stale['path']), 'A stale browser cannot delete a newly selected package');
    $settings['selected_package'] = $selected; $store->saveSettings($settings);
    $lock = fopen($paths->lockFile, 'ce'); flock($lock, LOCK_EX | LOCK_NB);
    cache_rejects(fn() => $app->dispatch('cache-delete', ['filename' => $stale['filename']]));
    cache_check(is_file($stale['path']), 'The shared operation lock protects an in-flight download/boot');
    flock($lock, LOCK_UN); fclose($lock);

    foreach ([$upgradePath, $installPath, $paths->configDir . '/package-history/6.18.57-Unraid.json'] as $file) {
        $original = file_get_contents($file);
        foreach (['{broken', str_repeat('x', 131073)] as $bad) {
            file_put_contents($file, $bad);
            cache_check(!$cache->listing()['available'], 'Unreadable reference disables every deletion');
            cache_rejects(fn() => $app->dispatch('cache-delete', ['filename' => $stale['filename']]));
        }
        file_put_contents($file, $original);
    }
    $outside = $root . '/must-preserve'; file_put_contents($outside, 'outside cache');
    $link = $make('6.18.58-Unraid', 6); unlink($link['path']); symlink($outside, $link['path']);
    cache_check(!$row($cache->listing(), $link['filename'])['deletable'], 'A symlink package is never deletable');
    cache_rejects(fn() => $app->dispatch('cache-delete', ['filename' => $link['filename']]));
    unlink($link['path']); unlink($link['path'] . '.md5');
    $link = $make('6.18.58-Unraid', 6); unlink($link['path'] . '.md5'); symlink($outside, $link['path'] . '.md5');
    cache_rejects(fn() => $app->dispatch('cache-delete', ['filename' => $link['filename']]));
    cache_check(is_file($link['path']) && file_get_contents($outside) === 'outside cache', 'Unsafe MD5 is rejected before deleting the TXZ');
    unlink($link['path'] . '.md5'); link($outside, $link['path'] . '.md5');
    cache_rejects(fn() => $app->dispatch('cache-delete', ['filename' => $link['filename']]));
    unlink($link['path'] . '.md5');
    cache_check($app->dispatch('cache-delete', ['filename' => $link['filename']])['removed'] === [$link['filename']], 'An unreferenced TXZ with no MD5 can still be cleaned');
    $alias = $stale['path'] . '.alias'; link($stale['path'], $alias);
    cache_rejects(fn() => $app->dispatch('cache-delete', ['filename' => $stale['filename']]));
    unlink($alias);
    $cacheDirectory = $paths->cacheDir; rename($cacheDirectory, $cacheDirectory . '-original'); symlink($cacheDirectory . '-original', $cacheDirectory);
    cache_check(!$cache->listing()['available'], 'A redirected cache directory disables cleanup');
    cache_rejects(fn() => $app->dispatch('cache-delete', ['filename' => $stale['filename']]));
    unlink($cacheDirectory); rename($cacheDirectory . '-original', $cacheDirectory);

    $ref = $paths->configDir . '/package-history/6.18.57-Unraid.json'; rename($ref, $ref . '-original'); symlink($ref . '-original', $ref);
    cache_check(!$cache->listing()['available'], 'A redirected rollback receipt fails closed');
    cache_rejects(fn() => $app->dispatch('cache-delete', ['filename' => $stale['filename']]));
    unlink($ref); rename($ref . '-original', $ref);
    $failure = new PackageCache($store, static fn(string $path): bool => false);
    cache_rejects(fn() => $failure->delete($stale['filename']));
    cache_check(is_file($stale['path']) && is_file($stale['path'] . '.md5'), 'A TXZ unlink failure must preserve both files');
    $partial = new PackageCache($store, static fn(string $path): bool => str_ends_with($path, '.md5') ? false : unlink($path));
    $result = $partial->delete($stale['filename']);
    cache_check(!$result['ok'] && $result['changed'] && $result['removed'] === [$stale['filename']] && str_contains($result['message'], 'TXZ 已删除'), 'Partial failure accurately reports the removed TXZ, not full success or cancellation');
    cache_check(!file_exists($stale['path']) && is_file($stale['path'] . '.md5'), 'Partial failure leaves the MD5 for explicit diagnosis');
    cache_check(file_get_contents($outside) === 'outside cache', 'No external target was deleted');
    echo 'Package cache cleanup: ' . $checks . " checks passed (temporary files only; no real driver, network or GPU)\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { if ($file->isLink() || !$file->isDir()) unlink($file->getPathname()); else rmdir($file->getPathname()); }
    rmdir($root);
}
