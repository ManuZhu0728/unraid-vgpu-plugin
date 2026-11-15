<?php
declare(strict_types=1);
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';

use Vgpu\PackageHistory;
use Vgpu\Paths;
use Vgpu\Store;
use Vgpu\Upgrade;

$historyChecks = 0;
function history_check(bool $condition, string $message): void
{
    global $historyChecks;
    ++$historyChecks;
    if (!$condition) throw new RuntimeException($message);
}
function history_rejects(callable $operation): void
{
    try { $operation(); }
    catch (Throwable) { ++$GLOBALS['historyChecks']; return; }
    throw new RuntimeException('Invalid history was accepted');
}

$directory = sys_get_temp_dir() . '/vgpu-history-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$paths = new Paths($directory);
$store = new Store($paths);
$history = new PackageHistory($store);
$settings = Store::defaults();
$settings['source'] = Store::validateSource(['url' => 'https://git.example.test/owner/repo']);
$settings['upgrade']['driver_branch'] = '595';
$boot = '11111111-1111-4111-8111-111111111111';
$package = static function (string $kernel, int $build = 1) use ($paths, $settings, $boot): array {
    $name = 'nvidia-595.58.03-' . $kernel . '-' . $build . '.txz';
    $path = $paths->cacheDir . '/' . substr($kernel, 0, -7) . '/' . $name;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, 'history fixture ' . $name);
    return ['filename' => $name, 'path' => $path, 'kernel' => $kernel, 'driver' => '595.58.03', 'build' => $build,
        'md5' => md5_file($path), 'source' => $settings['source']['repository'], 'provider' => $settings['source']['provider'],
        'source_id' => Store::sourceIdentity($settings['source']), 'source_fingerprint' => Upgrade::sourceFingerprint($settings['source']), 'saved_boot_id' => $boot];
};
try {
    history_check($history->read('6.18.58-Unraid') === null && !is_dir($paths->configDir), 'Reading missing history must not create state');
    $old = $package('6.18.58-Unraid');
    $candidate = PackageHistory::fromPackage($old);
    $candidate['package']['plan'] = ['must-not-persist' => true];
    $history->remember($candidate);
    $restored = $history->read('6.18.58-Unraid');
    history_check($restored['saved_boot_id'] === $boot && $restored['source_id'] === $old['source_id'] && $restored['source_fingerprint'] === $old['source_fingerprint'], 'History retains original boot and source provenance');
    history_check(!isset($restored['installed_boot_id']) && !isset($restored['package']['plan']), 'A selected package is not an installation proof and transient plans stay private');
    $oldFile = $paths->configDir . '/package-history/6.18.58-Unraid.json';
    history_check((fileperms($oldFile) & 0777) === 0600 && (fileperms(dirname($oldFile)) & 0777) === 0700, 'History uses private atomic Store writes');
    $new = $package('6.18.60-Unraid', 2);
    $newCandidate = PackageHistory::fromPackage($new) + ['installed_boot_id' => '22222222-2222-4222-8222-222222222222'];
    $history->remember($newCandidate);
    history_check($history->read('6.18.58-Unraid')['package']['md5'] === $old['md5'] && $history->read('6.18.60-Unraid')['package']['build'] === 2, 'Writing a new kernel never overwrites an older kernel');
    history_check(PackageHistory::cached($paths, $old, '6.18.58-Unraid') && !PackageHistory::cached($paths, $old, '6.18.60-Unraid'), 'Cache binding uses the receipt kernel, not the running kernel');
    PackageHistory::assertPolicy($restored, $settings, '6.18.58-Unraid');
    foreach (['source_id', 'source_fingerprint', 'branch', 'driver_pin', 'build_pin'] as $case) {
        $bad = $restored;
        $policy = $settings;
        if ($case === 'source_id' || $case === 'source_fingerprint') $bad[$case] = str_repeat('0', 64);
        if ($case === 'branch') $policy['upgrade']['driver_branch'] = '580';
        if ($case === 'driver_pin') $policy['source']['driver'] = '595.63.01';
        if ($case === 'build_pin') $policy['source']['build'] = '2';
        history_rejects(fn() => PackageHistory::assertPolicy($bad, $policy, '6.18.58-Unraid'));
    }
    foreach (['saved_boot_id', 'source_id', 'source_fingerprint', 'local', 'build'] as $case) {
        $bad = $candidate;
        if ($case === 'build') $bad['package']['build'] = 2;
        elseif ($case === 'local') $bad['local'] = true;
        else $bad[$case] = '';
        history_rejects(fn() => $history->remember($bad));
    }
    $original = file_get_contents($oldFile);
    foreach (['damaged json', str_repeat('x', 32769), json_encode(['schema_version' => 1] + $newCandidate, JSON_THROW_ON_ERROR)] as $bad) {
        file_put_contents($oldFile, $bad);
        history_rejects(fn() => $history->read('6.18.58-Unraid'));
    }
    file_put_contents($oldFile, $original);
    history_rejects(fn() => $history->read('../6.18.58-Unraid'));
    $link = $paths->configDir . '/package-history/6.18.61-Unraid.json';
    symlink($oldFile, $link);
    history_rejects(fn() => $history->read('6.18.61-Unraid'));
    $linkedCandidate = PackageHistory::fromPackage($package('6.18.61-Unraid'));
    history_rejects(fn() => $history->remember($linkedCandidate));
    unlink($link);
    file_put_contents($old['path'], 'tampered cache');
    history_check(!PackageHistory::cached($paths, $old, '6.18.58-Unraid'), 'A retained receipt cannot authorize changed cache bytes');
    $offline = PackageHistory::fromPackage(array_replace($new, ['source' => 'local', 'provider' => 'local']));
    $offline['source_id'] = '';
    $offline['source_fingerprint'] = '';
    $history->remember($offline);
    PackageHistory::assertPolicy($history->read('6.18.60-Unraid'), $settings, '6.18.60-Unraid');
    history_check($history->read('6.18.60-Unraid')['local'], 'Explicit offline imports retain their existing local-source exception');
    $historyDirectory = dirname($oldFile);
    $retainedDirectory = $historyDirectory . '-retained';
    rename($historyDirectory, $retainedDirectory);
    symlink($retainedDirectory, $historyDirectory);
    history_rejects(fn() => $history->read('6.18.60-Unraid'));
    history_rejects(fn() => $history->remember($offline));
    unlink($historyDirectory);
    rename($retainedDirectory, $historyDirectory);
    echo 'Per-kernel package history: ' . $historyChecks . " checks passed (temporary receipt/cache files only)\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    rmdir($directory);
}
