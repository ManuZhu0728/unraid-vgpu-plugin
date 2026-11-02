#!/usr/bin/php
<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Vgpu\Paths;
use Vgpu\Process;
use Vgpu\Store;
use Vgpu\Upgrade;
use Vgpu\Download;

if (PHP_SAPI !== 'cli' || !is_file('/etc/unraid-version')) exit(1);
$uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
if ($uid === null && preg_match('/^Uid:\s+\d+\s+(\d+)/m', (string)file_get_contents('/proc/self/status'), $match)) $uid = (int)$match[1];
if ($uid !== 0) exit(1);
$paths = new Paths();
$store = new Store($paths);
$stop = $paths->runtimeDir . '/upgrade-watch.stop';
if (is_link($paths->runtimeDir)) exit(1);
if (in_array($argv[1] ?? '', ['--stop', '--reset-stop'], true)) {
    if (($argv[1] ?? '') === '--stop' && is_dir($paths->runtimeDir)) {
        if (is_link($stop)) exit(1);
        file_put_contents($stop, 'stop');
    } elseif (is_file($stop) && !is_link($stop)) unlink($stop);
    exit(0);
}
if (($store->settings()['upgrade']['enabled'] ?? false) !== true) exit(0);
if (!is_dir($paths->runtimeDir) && !mkdir($paths->runtimeDir, 0700, true)) exit(1);
$lockFile = $paths->runtimeDir . '/upgrade-watch.lock';
if (is_link($lockFile)) exit(1);
$lock = fopen($lockFile, 'ce');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);
chmod($paths->runtimeDir . '/upgrade-watch.lock', 0600);
$pidFile = $paths->runtimeDir . '/upgrade-watch.json';
$self = getmypid();
$stat = (string)file_get_contents('/proc/self/stat');
$fields = preg_split('/\s+/', trim(substr($stat, strrpos($stat, ')') + 1)));
$store->write($pidFile, ['pid' => $self, 'start_time' => $fields[19] ?? '', 'script' => __FILE__]);
$previous = null;
$runner = new Process();
$upgrade = new Upgrade($store, new Download($paths));
try {
    while (is_file(__FILE__) && !is_file($stop)) {
        try {
            clearstatcache();
            if (($store->settings()['upgrade']['enabled'] ?? false) !== true) break;
            $changes = '/boot/changes.txt';
            $changesHash = is_file($changes) ? hash_file('sha256', $changes) : '';
            $settingsHash = is_file($paths->settingsFile) ? hash_file('sha256', $paths->settingsFile) : '';
            $credentialStat = @stat($paths->credentialsFile);
            $credentialStamp = $credentialStat === false ? [] : [$credentialStat['ino'], $credentialStat['mtime'], $credentialStat['size']];
            $fingerprint = hash('sha256', json_encode([$changesHash, $settingsHash, $credentialStamp], JSON_THROW_ON_ERROR));
            $state = $upgrade->status();
            $retry = in_array($state['status'] ?? '', ['missing', 'error', 'downloading'], true) &&
                (int)($state['last_attempt'] ?? 0) + Upgrade::RETRY_SECONDS <= time();
            if ($previous !== $fingerprint || $retry) {
                $result = $runner(['/usr/bin/php', __DIR__ . '/cli.php', 'upgrade-check'], 3600, null);
                $response = json_decode($result['stdout'], true);
                if (is_array($response) && array_key_exists('ok', $response) && !str_contains((string)($response['error'] ?? ''), 'Another plugin operation')) {
                    $previous = $fingerprint;
                }
                $status = $response['data']['status'] ?? 'error';
                if (!is_string($status) || !preg_match('/^[a-z_-]+$/D', $status)) $status = 'error';
                echo gmdate('c') . ' update-check ' . $status . "\n";
                flush();
            }
        } catch (Throwable $error) {
            // Only a fixed message is logged; credentials stay in the CLI stdin transport.
            echo gmdate('c') . " update-check failed\n";
            flush();
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
