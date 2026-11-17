<?php
declare(strict_types=1);
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/upgrade-check.php';

use Vgpu\UpgradeCheck;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};
$response = static fn(bool $ok, string $message): array => ['code' => $ok ? 0 : 1,
    'stdout' => json_encode(['ok' => $ok, 'data' => ['ok' => $ok, 'status' => $ok ? 'ready' : 'missing', 'message' => $message]]), 'stderr' => ''];
foreach ([[true, '目标启动包已核验'], [true, '系统升级联动已关闭。'], [false, '目标内核驱动暂未发布']] as [$ok, $message]) {
    $calls = [];
    $result = UpgradeCheck::run(static function (array $argv, int $timeout, ?string $stdin) use (&$calls, $response, $ok, $message): array {
        $calls[] = [$argv, $timeout, $stdin];
        return $response($ok, $message);
    });
    $assert($result === ['ok' => $ok, 'message' => $message], 'Preserve confirmed backend outcome');
    $assert(count($calls) === 1 && $calls[0][0][2] === 'upgrade-check' && $calls[0][2] === null, 'Use the existing CLI without credentials or target overrides');
}

$now = 0.0;
$calls = 0;
$result = UpgradeCheck::run(static function (array $argv, int $timeout) use (&$calls, $assert, $response): array {
    ++$calls;
    $assert($timeout >= 1 && $timeout <= 2, 'All child commands share a bounded total budget');
    return $calls === 1 ? ['code' => 1, 'stdout' => '{"ok":false,"error":"Another plugin operation is in progress"}'] : $response(true, '复用已准备的驱动');
}, 2, static function () use (&$now): float { return $now; }, static function () use (&$now): void { $now += 0.25; });
$assert($result['ok'] && $calls === 2 && $now === 0.25, 'Native post-hook waits for a competing timed/manual transaction, then reuses it');

$now = 0.0;
$calls = 0;
$result = UpgradeCheck::run(static function () use (&$calls): array {
    ++$calls;
    return ['code' => 1, 'stdout' => '{"ok":false,"error":"Another plugin operation is in progress"}'];
}, 1, static function () use (&$now): float { return $now; }, static function () use (&$now): void { $now += 0.25; });
$assert(!$result['ok'] && $calls === 4 && str_contains($result['message'], '超时'), 'A held transaction cannot block the OS hook forever');
foreach ([['code' => 0, 'stdout' => '<html>private server response</html>'],
    ['code' => 124, 'stdout' => '{"ok":true}'], ['code' => 0, 'stdout' => '{"ok":false}'],
    ['code' => 0, 'stdout' => '{"ok":"true"}'], ['code' => 1, 'stdout' => 'null'],
    ['code' => 0, 'stdout' => '{"ok":true}'], ['code' => 0, 'stdout' => '{"ok":true,"data":{"status":"staging"}}'],
    ['code' => 0, 'stdout' => '{"ok":true,"data":{"ok":false,"status":"ready"}}']] as $invalid) {
    $result = UpgradeCheck::run(static fn(): array => $invalid);
    $assert(!$result['ok'] && !str_contains($result['message'], 'private'), 'Invalid or interrupted responses must never imply readiness or dump response bodies');
}
$result = UpgradeCheck::run(static function (): array { throw new RuntimeException('secret child diagnostic'); });
$assert(!$result['ok'] && !str_contains($result['message'], 'secret'), 'Do not expose process exceptions in OS update logs');
echo "Native upgrade adapter: $checks checks passed (no system commands).\n";
