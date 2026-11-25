<?php
declare(strict_types=1);

namespace Vgpu;

/** In-memory polling/backoff and redacted, transition-only helper logging. */
final class UpgradeWatch
{
    private ?string $fingerprint = null;
    private int $retryAt = 0;
    private ?string $lastLog = null;

    /** Cooperatively replace only our own watcher after any in-flight check. */
    public static function requestStart(Store $store): string
    {
        $directory = $store->paths->runtimeDir;
        foreach ([$directory, $directory . '/upgrade-watch.start.json', $directory . '/upgrade-watch.stop'] as $path) {
            if (is_link($path)) throw new \RuntimeException('Unsafe watcher handover path');
        }
        $generation = bin2hex(random_bytes(16));
        $store->write($directory . '/upgrade-watch.start.json', ['generation' => $generation]);
        $store->write($directory . '/upgrade-watch.stop', ['stop' => true]);
        return $generation;
    }

    public static function isLatest(Store $store, string $generation): bool
    {
        $file = $store->paths->runtimeDir . '/upgrade-watch.start.json';
        clearstatcache(true, $file);
        if (is_link($file) || !is_file($file) || filesize($file) > 1024) return false;
        $request = json_decode((string)file_get_contents($file), true);
        return is_array($request) && ($request['generation'] ?? '') === $generation;
    }

    public function due(string $fingerprint, array $state, int $now): bool
    {
        if ($fingerprint !== $this->fingerprint) return true;
        if ($this->retryAt > 0) return $now >= $this->retryAt;
        return in_array($state['status'] ?? '', ['missing', 'error', 'downloading', 'staging'], true) &&
            (int)($state['last_attempt'] ?? 0) + Upgrade::RETRY_SECONDS <= $now;
    }

    public function observe(string $fingerprint, array $result, int $now, string $token = ''): ?string
    {
        $response = json_decode((string)($result['stdout'] ?? ''), true);
        $error = is_array($response) && is_string($response['error'] ?? null) ? $response['error'] : '';
        $this->fingerprint = $fingerprint;
        $this->retryAt = 0;
        if ($error === 'Another plugin operation is in progress') {
            $status = 'busy';
            $message = '另一项插件操作正在进行，稍后重试。';
            $this->retryAt = $now + 30;
        } elseif (is_array($response) && is_bool($response['ok'] ?? null) &&
            ($result['code'] ?? -1) === ($response['ok'] ? 0 : 1) &&
            is_array($response['data'] ?? null) &&
            in_array($response['data']['status'] ?? '', $response['ok'] ? ['disabled', 'current', 'ready'] : ['missing', 'error', 'cancelled', 'interrupted', 'downloading', 'staging'], true)) {
            $status = $response['data']['status'];
            $message = $response['data']['message'] ?? '';
            if (in_array($status, ['downloading', 'staging'], true)) { $status = 'waiting'; $this->retryAt = $now + Upgrade::RETRY_SECONDS; }
            if (in_array($status, ['missing', 'error'], true)) $this->retryAt = $now + Upgrade::RETRY_SECONDS;
        } else {
            $status = 'error';
            $message = $error !== '' ? $error : '后台检查未返回完整结果，请核对插件状态；15 分钟后重试。';
            $this->retryAt = $now + Upgrade::RETRY_SECONDS;
        }
        if (!is_string($message)) $message = '';
        if ($token !== '') $message = str_replace($token, '[redacted]', $message);
        $message = preg_replace('/\b(?:glpat-|github_pat_|gh[pousr]_)[A-Za-z0-9_.-]+/', '[redacted]', $message);
        $message = preg_replace('~https?://\S+~i', '[source URL omitted]', $message);
        $message = substr((string)preg_replace('/[\x00-\x1f\x7f]/', ' ', $message), 0, 512);
        $line = 'update-check ' . $status . ($message !== '' ? ' ' . $message : '');
        if ($line === $this->lastLog) return null;
        $this->lastLog = $line;
        return gmdate('c', $now) . ' ' . $line;
    }
}
