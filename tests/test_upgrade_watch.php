<?php
declare(strict_types=1);
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';
$checks = 0;
function watch_check(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); ++$checks; }
function watch_response(string $status, bool $ok = true, string $message = ''): array {
    return ['code' => $ok ? 0 : 1, 'stdout' => json_encode(['ok' => $ok, 'data' => ['status' => $status, 'message' => $message]])];
}
$watch = new Vgpu\UpgradeWatch();
watch_check($watch->due('source-a', [], 1000), 'Initial check runs');
$busy = ['code' => 1, 'stdout' => '{"ok":false,"error":"Another plugin operation is in progress"}'];
$line = $watch->observe('source-a', $busy, 1000);
watch_check(str_contains($line, 'update-check busy') && !str_contains($line, 'error'), 'A held operation lock is busy, never an error');
for ($now = 1005; $now < 1030; $now += 5) watch_check(!$watch->due('source-a', ['status' => 'error', 'last_attempt' => 0], $now), 'Busy polling is bounded even with obsolete persisted errors');
watch_check($watch->due('source-a', [], 1030), 'Busy retries after 30 seconds');
watch_check($watch->observe('source-a', $busy, 1030) === null, 'Repeated busy messages do not flood the log');
watch_check($watch->due('source-b', [], 1031), 'Changed settings bypass an old source backoff');

$bad = ['code' => 1, 'stdout' => 'invalid CLI receipt'];
watch_check(str_contains($watch->observe('source-b', $bad, 1100), 'update-check error'), 'Invalid IPC is a real diagnostic error');
for ($now = 1105; $now < 2000; $now += 5) watch_check(!$watch->due('source-b', ['status' => 'error', 'last_attempt' => 0], $now), 'CLI failures wait 15 minutes even if no state could be saved');
watch_check($watch->due('source-b', [], 2000), 'Invalid IPC is retried once its own backoff expires');
watch_check($watch->observe('source-b', $bad, 2000) === null, 'Unchanged errors are logged only on transitions');
$line = $watch->observe('source-b', watch_response('error', false, "HTTP 403 github_pat_FIXTURE opaque-secret\nhttps://private.test/file?token=opaque-secret"), 2100, 'opaque-secret');
watch_check(str_contains($line, 'HTTP 403') && !str_contains($line, 'github_pat_FIXTURE') && !str_contains($line, 'opaque-secret') && !str_contains($line, 'https://'), 'Real failure reason is useful but contains no token or source URL');
watch_check(substr_count($line, "\n") === 0, 'Failure reasons cannot forge extra log lines');
$line = $watch->observe('source-b', watch_response('current', true, '当前内核缓存已就绪。'), 2200);
watch_check(str_contains($line, 'update-check current'), 'Recovery is logged');
watch_check(!$watch->due('source-b', ['status' => 'current'], 10000), 'Current readiness does not poll commands every five seconds');
$watch->observe('source-b', watch_response('cancelled', false, '手动取消'), 2300);
watch_check(!$watch->due('source-b', ['status' => 'cancelled'], 100000), 'A cancelled task does not auto-restart');
watch_check($watch->due('source-c', ['status' => 'cancelled'], 2301), 'A genuinely changed source can start a new task');
$watch->observe('source-b', watch_response('interrupted', false, '取消结果未确认'), 2400);
watch_check(!$watch->due('source-b', ['status' => 'interrupted'], 100000), 'Unknown cancellation must remain paused, not retry as a normal error');
echo "Upgrade watcher: $checks checks passed (deterministic clock, no Unraid)\n";
