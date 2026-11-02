<?php
declare(strict_types=1);

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/Application.php';

use Vgpu\Application;
use Vgpu\Download;
use Vgpu\Logs;
use Vgpu\Paths;
use Vgpu\Runtime;
use Vgpu\Store;

$logsChecks = 0;
function logs_check(bool $condition, string $message): void
{
    global $logsChecks;
    if (!$condition) throw new RuntimeException($message);
    ++$logsChecks;
}

function logs_rejects(callable $operation, string $message): void
{
    try { $operation(); }
    catch (InvalidArgumentException $error) {
        logs_check(str_contains($error->getMessage(), $message), 'Unexpected rejection: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}

$fixture = sys_get_temp_dir() . '/vgpu-logs-test-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700);
$paths = new Paths($fixture);
mkdir($paths->path('/var/log'), 0700, true);
$pluginLog = $paths->path('/var/log/unraid-vgpu-manager-upgrade.log');
$syslog = $paths->path('/var/log/syslog');
$logs = new Logs($paths, 'opaque-fixture-secret');
try {
    $missing = $logs->read();
    logs_check($missing['category'] === 'plugin' && $missing['available'] === false && $missing['lines'] === [], 'Missing log must be an empty, non-error result');
    logs_check(!is_file($pluginLog) && !is_dir($paths->configDir) && !is_file($paths->lockFile), 'Reading a missing log must create no files or config directories');
    foreach (['../../etc/passwd', '/var/log/syslog', '', 'syslog', 'Kernel'] as $bad) logs_rejects(fn() => $logs->read($bad), 'Unknown log category');

    file_put_contents($pluginLog, "first\nsecond\n");
    $result = $logs->read();
    logs_check($result['available'] && $result['lines'] === ['first', 'second'] && !$result['truncated'], 'Plugin log must preserve chronological line order');
    file_put_contents($syslog, "Nov 2 kernel: nvidia: module loaded\nNov 2 dnsmasq[1]: unrelated\nNov 2 nvidia-vgpu-mgr[42]: manager started\nNov 2 nvidia-vgpud[43]: initialized\nNov 2 kernel: WARNING vma_start_write\n");
    $result = $logs->read('vgpu');
    logs_check(count($result['lines']) === 2 && str_contains($result['lines'][0], 'mgr[42]') && str_contains($result['lines'][1], 'vgpud[43]'), 'Manager category must include both vGPU services but not kernel or unrelated messages');
    $result = $logs->read('kernel');
    logs_check(count($result['lines']) === 2 && str_contains($result['lines'][1], 'vma_start_write'), 'Kernel category must include known MMIO warnings and NVIDIA messages only');
    file_put_contents($syslog, "Nov 2 dnsmasq[1]: unrelated\n");
    logs_check($logs->read('kernel')['available'] && $logs->read('kernel')['lines'] === [], 'An existing log with no matching events must not be reported missing');

    $secrets = ['glpat-fixture-secret', 'github_pat_fixture-secret', 'ghp_fixturesensitive', 'opaque-fixture-secret', 'query-sensitive', 'bearer-sensitive', 'header-sensitive', 'json-sensitive', 'single-sensitive'];
    file_put_contents($pluginLog, implode("\n", [
        'Tokens: ' . implode(' ', array_slice($secrets, 0, 4)),
        'download https://user:password@example.test/file?access_token=query-sensitive&ok=1',
        'Authorization: Bearer bearer-sensitive',
        'PRIVATE-TOKEN=header-sensitive',
        '{"token":"json-sensitive"}',
        "{'password':'single-sensitive'}",
        "control\x1b[31m\x00chars",
    ]) . "\n");
    $result = $logs->read();
    $text = implode("\n", $result['lines']);
    foreach ($secrets as $secret) logs_check(!str_contains($text, $secret), 'Log viewer must redact ' . $secret);
    logs_check(!str_contains($text, 'user:password') && !str_contains($text, "\x1b") && !str_contains($text, "\0"), 'Credentials in URLs and terminal control characters must be removed');
    logs_check(str_contains($text, '&ok=1'), 'Redaction must preserve unrelated URL fields');

    file_put_contents($pluginLog, implode("\n", array_map(fn($number) => 'line ' . $number, range(1, 300))) . "\n");
    $result = $logs->read();
    logs_check($result['truncated'] && count($result['lines']) === 200 && $result['lines'][0] === 'line 101' && end($result['lines']) === 'line 300', 'Viewer must cap output at the 200 most recent lines');
    file_put_contents($pluginLog, str_repeat('x', 600000) . "\nlatest\n");
    $result = $logs->read();
    logs_check($result['truncated'] && $result['lines'] === ['latest'], 'Seeked partial lines must be discarded rather than exposed');
    file_put_contents($pluginLog, "start\n" . str_repeat('y', 5000) . "\nend\n");
    $result = $logs->read();
    logs_check($result['truncated'] && strlen($result['lines'][1]) < 2100 && $result['lines'][2] === 'end', 'Very long lines must be truncated without hiding subsequent events');
    file_put_contents($pluginLog, str_repeat(str_repeat('z', 2000) . "\n", 100));
    logs_check(strlen(implode("\n", $logs->read()['lines'])) < 132000, 'Total rendered output must stay bounded');

    unlink($pluginLog);
    symlink($syslog, $pluginLog);
    logs_check(!$logs->read()['available'], 'Fixed log sources must not follow a symlink to another file');
    unlink($pluginLog);
    mkdir($pluginLog);
    logs_check(!$logs->read()['available'], 'Log reader must reject non-regular files');
    rmdir($pluginLog);

    $store = new Store($paths);
    $runner = function (): array { throw new RuntimeException('A log read must never execute a command'); };
    $application = new Application($store, new Runtime($paths, $runner), new Download($paths, $runner), $runner);
    logs_check($application->dispatch('logs', ['category' => 'kernel'])['category'] === 'kernel', 'Application must pass the requested log category to the read-only backend');
    logs_check(!is_file($paths->lockFile) && !is_dir($paths->configDir), 'Log actions must not acquire a mutation lock or write settings');
    logs_rejects(fn() => $application->dispatch('logs', ['category' => ['kernel']]), 'Invalid log category');
    logs_rejects(fn() => $application->dispatch('logs', ['category' => 'arbitrary']), 'Unknown log category');

    $cli = file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/cli.php');
    logs_check(str_contains($cli, "['source-download', 'upgrade-check', 'logs']"), 'CLI must parse the piped category payload for read-only logs');
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($fixture);
}
echo "Log tests passed ($logsChecks checks).\n";
