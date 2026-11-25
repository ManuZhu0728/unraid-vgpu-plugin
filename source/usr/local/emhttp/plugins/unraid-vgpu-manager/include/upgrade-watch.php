#!/usr/bin/php
<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Vgpu\Paths;
use Vgpu\Process;
use Vgpu\Store;
use Vgpu\Upgrade;
use Vgpu\Download;
use Vgpu\UpgradeWatch;

if (PHP_SAPI !== 'cli' || !is_file('/etc/unraid-version')) exit(1);
$uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
if ($uid === null && preg_match('/^Uid:\s+\d+\s+(\d+)/m', (string)file_get_contents('/proc/self/status'), $match)) $uid = (int)$match[1];
if ($uid !== 0) exit(1);
$paths = new Paths();
$store = new Store($paths);
$stop = $paths->runtimeDir . '/upgrade-watch.stop';
if (is_link($paths->runtimeDir)) exit(1);
if (($argv[1] ?? '') === '--request-start') {
    echo UpgradeWatch::requestStart($store) . "\n";
    exit(0);
}
if (in_array($argv[1] ?? '', ['--stop', '--reset-stop'], true)) {
    if (($argv[1] ?? '') === '--stop' && is_dir($paths->runtimeDir)) {
        if (is_link($stop)) exit(1);
        file_put_contents($stop, 'stop');
        $request = $paths->runtimeDir . '/upgrade-watch.start.json';
        if (is_file($request) && !is_link($request)) unlink($request);
    } elseif (is_file($stop) && !is_link($stop)) unlink($stop);
    exit(0);
}
if (($store->settings()['upgrade']['enabled'] ?? false) !== true) exit(0);
if (!is_dir($paths->runtimeDir) && !mkdir($paths->runtimeDir, 0700, true)) exit(1);
$lockFile = $paths->runtimeDir . '/upgrade-watch.lock';
if (is_link($lockFile)) exit(1);
$lock = fopen($lockFile, 'ce');
if (!$lock) exit(1);
$generation = ($argv[1] ?? '') === '--generation' ? ($argv[2] ?? '') : '';
if ($generation !== '') {
    if (!preg_match('/^[a-f0-9]{32}$/D', $generation)) exit(1);
    $deadline = microtime(true) + 3610;
    while (!flock($lock, LOCK_EX | LOCK_NB)) {
        if (!UpgradeWatch::isLatest($store, $generation) || microtime(true) >= $deadline) exit(0);
        usleep(250000);
        clearstatcache();
    }
    if (!UpgradeWatch::isLatest($store, $generation) || ($store->settings()['upgrade']['enabled'] ?? false) !== true) exit(0);
    if (is_link($stop)) exit(1);
    if (is_file($stop)) unlink($stop);
} elseif (!flock($lock, LOCK_EX | LOCK_NB)) exit(0);
chmod($paths->runtimeDir . '/upgrade-watch.lock', 0600);
$pidFile = $paths->runtimeDir . '/upgrade-watch.json';
$self = getmypid();
$stat = (string)file_get_contents('/proc/self/stat');
$fields = preg_split('/\s+/', trim(substr($stat, strrpos($stat, ')') + 1)));
$store->write($pidFile, ['pid' => $self, 'start_time' => $fields[19] ?? '', 'script' => __FILE__]);
$poll = new UpgradeWatch();
$runner = new Process();
$upgrade = new Upgrade($store, new Download($paths));
try {
    while (is_file(__FILE__) && !is_file($stop)) {
        try {
            clearstatcache();
            if (($store->settings()['upgrade']['enabled'] ?? false) !== true) break;
            $changes = $paths->path('/boot/changes.txt');
            $changesHash = is_file($changes) ? hash_file('sha256', $changes) : '';
            $settingsHash = is_file($paths->settingsFile) ? hash_file('sha256', $paths->settingsFile) : '';
            $credentialStat = @stat($paths->credentialsFile);
            $credentialStamp = $credentialStat === false ? [] : [$credentialStat['ino'], $credentialStat['mtime'], $credentialStat['size']];
            $fingerprint = hash('sha256', json_encode([$changesHash, $settingsHash, $credentialStamp], JSON_THROW_ON_ERROR));
            $state = $upgrade->status();
            if ($poll->due($fingerprint, $state, time())) {
                $result = $runner(['/usr/bin/php', __DIR__ . '/cli.php', 'upgrade-check'], 3600, null);
                $line = $poll->observe($fingerprint, $result, time(), (string)($store->credentials()['token'] ?? ''));
                if ($line !== null) { echo $line . "\n"; flush(); }
            }
        } catch (Throwable $error) {
            // Never print raw exceptions, request details or credentials.
            $line = $poll->observe($fingerprint ?? '', ['code' => 1, 'stdout' => ''], time());
            if ($line !== null) { echo $line . "\n"; flush(); }
        }
        sleep(5);
        clearstatcache();
    }
} finally {
    if (is_file($pidFile) && !is_link($pidFile)) {
        $owner = json_decode((string)file_get_contents($pidFile), true);
        if (($owner['pid'] ?? null) === $self) unlink($pidFile);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}
