<?php
declare(strict_types=1);

namespace Vgpu;

/** Synchronous native post-hook adapter; preparation stays in the existing CLI. */
final class UpgradeCheck
{
    public static function run(callable $runner, int $budget = 3600, ?callable $clock = null, ?callable $pause = null): array
    {
        if ($budget < 1 || $budget > 3600) throw new \InvalidArgumentException('Invalid upgrade check timeout');
        $clock ??= static fn(): float => microtime(true);
        $pause ??= static fn() => usleep(250000);
        $deadline = $clock() + $budget;
        do {
            $remaining = (int)ceil($deadline - $clock());
            if ($remaining <= 0) break;
            try {
                $result = $runner([PHP_BINARY, __DIR__ . '/cli.php', 'upgrade-check'], $remaining, null);
                $response = json_decode($result['stdout'] ?? '', true);
            } catch (\Throwable) {
                return ['ok' => false, 'message' => '无法确认驱动准备结果。请在重启前打开插件检查。'];
            }
            if (is_array($response) && ($response['error'] ?? '') === 'Another plugin operation is in progress') {
                // A timed/manual check may already own the transaction. Wait,
                // then reuse its verified result instead of racing downloads.
                $pause();
                continue;
            }
            if (!is_array($response) || !is_bool($response['ok'] ?? null) ||
                ($result['code'] ?? -1) !== ($response['ok'] ? 0 : 1) ||
                ($response['ok'] && (!is_array($response['data'] ?? null) ||
                    !in_array($response['data']['status'] ?? '', ['disabled', 'current', 'ready'], true) ||
                    ($response['data']['ok'] ?? true) !== true))) {
                return ['ok' => false, 'message' => '无法确认驱动准备结果。请在重启前打开插件检查。'];
            }
            $message = $response['data']['message'] ?? $response['error'] ?? '';
            if (!is_string($message) || $message === '') $message = '请在插件中核对目标内核的驱动准备状态。';
            return ['ok' => $response['ok'], 'message' => $message];
        } while ($clock() < $deadline);
        return ['ok' => false, 'message' => '等待驱动准备超时。请在重启前打开插件检查，不要重复提交。'];
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require_once __DIR__ . '/bootstrap.php';
    echo "unraid-vgpu-manager: checking target-kernel GPU driver preparation; please wait.\n";
    $result = UpgradeCheck::run(new Process());
    echo 'unraid-vgpu-manager: ' . $result['message'] . "\n";
    exit($result['ok'] ? 0 : 1);
}
