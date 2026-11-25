<?php
declare(strict_types=1);
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/Application.php';

$root = sys_get_temp_dir() . '/vgpu-download-control-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$paths = new Vgpu\Paths($root);
$store = new Vgpu\Store($paths);
$worker = new Vgpu\DownloadTask($store);
$control = new Vgpu\DownloadTask($store);
$checks = 0;
function task_check(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); ++$checks; }
function task_reject(callable $operation, string $message): void {
    try { $operation(); } catch (Throwable) { task_check(true, $message); return; }
    task_check(false, $message);
}
try {
    task_check($control->status() === ['status' => 'idle'], 'No task is idle');
    $id = str_repeat('a', 32);
    $worker->begin('manual', '6.18.64-Unraid', $id);
    task_check($control->status()['status'] === 'active', 'A held worker lease proves a live task');
    $forbidden = static function (): array { throw new RuntimeException('No GPU or external command is permitted'); };
    $app = new Vgpu\Application($store, new Vgpu\Runtime($paths, $forbidden), new Vgpu\Download($paths, $forbidden), $forbidden);
    $store->locked(function () use ($app, $id): void {
        task_check($app->dispatch('download-status')['task']['id'] === $id, 'Progress is available while the configuration lock is held');
        task_reject(fn() => $app->dispatch('download-cancel', ['job_id' => str_repeat('b', 32)]), 'Never cancel another task');
        task_reject(fn() => $app->dispatch('download-status', ['source_url' => 'https://not-used.test']), 'Control cannot change download settings');
    });
    $output = $root . '/partial'; $headers = $root . '/headers';
    file_put_contents($output, str_repeat('a', 20));
    file_put_contents($headers, "HTTP/2 302\r\nContent-Length: 777\r\nLocation: https://secret.test/?token=DO-NOT-EXPOSE\r\n\r\nHTTP/2 200\r\nContent-Length: 100\r\n\r\n");
    $worker->stage('downloading');
    task_check($worker->observe($output, $headers, true), 'Known-size transfer continues');
    $state = $control->status();
    task_check($state['downloaded_bytes'] === 20 && $state['total_bytes'] === 100, 'Progress uses actual bytes and final 200 length, not redirect length');
    task_check(!str_contains(json_encode($state), 'DO-NOT-EXPOSE') && !str_contains(json_encode($state), $root), 'No headers, URLs or private file paths in progress');
    $store->locked(function () use ($app, $id): void {
        $reply = $app->dispatch('download-cancel', ['job_id' => $id]);
        task_check($reply['accepted'] && $reply['task']['status'] === 'active', 'Cancel is a request, not a fabricated completion');
    });
    task_check(!$worker->observe($output, $headers, true), 'Only the exact live task sees cancellation');
    task_reject(fn() => $worker->stage('publishing', [], false), 'Cancellation wins if requested before commit');
    $worker->finish('cancelled'); $worker->close();
    task_check($control->status()['status'] === 'cancelled', 'Cancellation is terminal only after worker confirmation');
    task_check(!$control->cancel($id)['accepted'], 'Repeated terminal cancellation is harmless');

    $next = str_repeat('b', 32);
    $worker->begin('upgrade', '6.18.64-Unraid', $next);
    task_reject(fn() => $control->cancel($id), 'A stale browser cannot cancel a replacement task');
    $worker->stage('downloading', ['scope' => 'target', 'kernel' => '6.18.66-Unraid']);
    file_put_contents($headers, "HTTP/2 200\r\nTransfer-Encoding: chunked\r\n\r\n");
    $worker->observe($output, $headers, true);
    task_check($control->status()['total_bytes'] === null, 'Unknown length stays indeterminate');
    $worker->stage('publishing', [], false);
    task_check(!$control->cancel($next)['accepted'], 'Verified pair publication cannot be interrupted halfway');
    $worker->finish('completed'); $worker->close();
    task_check($control->status()['status'] === 'completed', 'A late cancel does not rewrite completion');

    $worker->begin('upgrade', '6.18.64-Unraid'); $worker->close();
    task_check($control->status()['status'] === 'interrupted', 'A lost worker lease must not display an endless live download');
    task_reject(fn() => $control->cancel('../other-task'), 'Task IDs are not file paths');

    $process = new Vgpu\Process();
    $result = $process([PHP_BINARY, '-n', '-r', 'usleep(5000000);'], 10, null, static fn(): bool => false);
    task_check($result['code'] === 125, 'A cooperative cancellation terminates only the owned subprocess and is confirmed');
    task_check((new Vgpu\Process())([PHP_BINARY, '-n', '-r', 'echo "untouched";'], 5)['stdout'] === 'untouched', 'Other commands retain their original behavior');
    echo "Download task: $checks checks passed (private files and owned local child only)\n";
} finally {
    $worker->close();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($root);
}
