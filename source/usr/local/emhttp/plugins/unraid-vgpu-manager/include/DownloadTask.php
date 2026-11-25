<?php
declare(strict_types=1);

namespace Vgpu;

final class DownloadCancelled extends \RuntimeException {}

/** One plugin-owned transfer. Control requests never take the configuration lock. */
final class DownloadTask
{
    private $lease = null;
    private string $id = '';
    private float $lastProgress = 0;
    private float $transferStart = 0;

    public function __construct(private Store $store) {}

    public function begin(string $kind, string $kernel, string $id = ''): void
    {
        if (!in_array($kind, ['manual', 'upgrade'], true) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+-Unraid$/D', $kernel)) throw new \InvalidArgumentException('Invalid download task');
        $id = $id === '' ? bin2hex(random_bytes(16)) : self::validId($id);
        $this->locked(function () use ($kind, $kernel, $id): void {
            $lease = $this->open('download-worker.lock');
            if (!flock($lease, LOCK_EX | LOCK_NB)) { fclose($lease); throw new \RuntimeException('Another download task is in progress'); }
            $this->lease = $lease;
            $this->id = $id;
            $this->save(['id' => $id, 'kind' => $kind, 'status' => 'active', 'scope' => $kind === 'manual' ? 'manual' : 'baseline',
                'phase' => 'resolving', 'kernel' => $kernel, 'filename' => '', 'downloaded_bytes' => 0,
                'total_bytes' => null, 'speed_bytes_per_second' => 0, 'cancellable' => true,
                'cancel_requested' => false, 'started_at' => time(), 'updated_at' => time()]);
        });
    }

    public function stage(string $phase, array $fields = [], bool $cancellable = true): void
    {
        if (!in_array($phase, ['resolving', 'downloading', 'verifying', 'publishing'], true) || array_diff(array_keys($fields), ['scope', 'kernel', 'filename'])) throw new \InvalidArgumentException('Invalid download phase');
        $this->locked(function () use ($phase, $fields, $cancellable): void {
            $state = $this->owned();
            if ($state['cancel_requested']) throw new DownloadCancelled('下载已手动取消。已完成的缓存和运行中的驱动未改变。');
            if ((isset($fields['scope']) && $fields['scope'] !== $state['scope']) || (isset($fields['kernel']) && $fields['kernel'] !== $state['kernel'])) {
                $state = array_replace($state, ['downloaded_bytes' => 0, 'total_bytes' => null, 'speed_bytes_per_second' => 0]);
            }
            $this->save(array_replace($state, $fields, ['phase' => $phase, 'cancellable' => $cancellable, 'updated_at' => time()]));
        });
        if ($phase === 'downloading') { $this->transferStart = microtime(true); $this->lastProgress = 0; }
    }

    public function checkpoint(): void
    {
        $this->locked(function (): void {
            if ($this->owned()['cancel_requested']) throw new DownloadCancelled('下载已手动取消。已完成的缓存和运行中的驱动未改变。');
        });
    }

    public function cancellationRequested(): bool
    {
        return $this->locked(fn(): bool => (bool)$this->owned()['cancel_requested']);
    }

    /** Called only while observing this request's own curl output file. */
    public function observe(string $output, string $headers, bool $payload): bool
    {
        try { $this->checkpoint(); } catch (DownloadCancelled) { return false; }
        if (!$payload || microtime(true) - $this->lastProgress < 0.5) return true;
        $this->lastProgress = microtime(true);
        clearstatcache(true, $output);
        $bytes = is_file($output) ? (int)filesize($output) : 0;
        $total = null;
        $text = is_file($headers) ? file_get_contents($headers, false, null, 0, 65536) : '';
        // Use only the final successful response, never a redirect body's size.
        $blocks = preg_split('/\r?\n\r?\n/', (string)$text);
        foreach ($blocks as $block) {
            if (preg_match('~^HTTP/\S+ 200\b~', $block) && preg_match('/^content-length:\s*([0-9]+)\s*$/mi', $block, $match)) {
                $length = (int)$match[1];
                if ($length > 0 && $length <= 2147483648) $total = $length;
            }
        }
        $this->locked(function () use ($bytes, $total): void {
            $state = $this->owned();
            $this->save(array_replace($state, ['downloaded_bytes' => $bytes, 'total_bytes' => $total,
                'speed_bytes_per_second' => (int)($bytes / max(1, microtime(true) - $this->transferStart)), 'updated_at' => time()]));
        });
        return true;
    }

    public function finish(string $status): void
    {
        if (!in_array($status, ['completed', 'cancelled', 'failed', 'interrupted'], true)) throw new \InvalidArgumentException('Invalid terminal download state');
        $this->locked(function () use ($status): void {
            $state = $this->owned();
            $this->save(array_replace($state, ['status' => $status, 'cancellable' => false, 'updated_at' => time()]));
        });
    }

    public function close(): void
    {
        if (is_resource($this->lease)) { flock($this->lease, LOCK_UN); fclose($this->lease); }
        $this->lease = null;
    }

    public function status(): array
    {
        if (!is_dir($this->store->paths->runtimeDir) && !is_link($this->store->paths->runtimeDir)) return ['status' => 'idle'];
        return $this->locked(fn(): array => $this->snapshot());
    }

    public function cancel(string $id): array
    {
        self::validId($id);
        return $this->locked(function () use ($id): array {
            $state = $this->snapshot();
            if (($state['id'] ?? '') !== $id) throw new \RuntimeException('下载任务已变化，请刷新进度，不要取消其他任务。');
            if ($state['status'] !== 'active') return ['accepted' => false, 'task' => $state];
            if (!$state['cancellable']) return ['accepted' => false, 'task' => $state, 'message' => '正在提交已校验结果，不能在此阶段中断。'];
            $state['cancel_requested'] = true;
            $this->save($state);
            return ['accepted' => true, 'task' => $state, 'message' => '已请求取消，正在等待下载任务确认。'];
        });
    }

    private function snapshot(): array
    {
        $state = $this->read();
        if (!$state) return ['status' => 'idle'];
        if ($state['status'] === 'active') {
            $lease = $this->open('download-worker.lock');
            if (flock($lease, LOCK_EX | LOCK_NB)) {
                $state['status'] = 'interrupted';
                $state['cancellable'] = false;
                flock($lease, LOCK_UN);
            }
            fclose($lease);
        }
        // No credentials, URLs, headers, process IDs or filesystem paths leave this boundary.
        return array_intersect_key($state, array_flip(['id', 'kind', 'status', 'scope', 'phase', 'kernel', 'filename',
            'downloaded_bytes', 'total_bytes', 'speed_bytes_per_second', 'cancellable', 'cancel_requested', 'started_at', 'updated_at']));
    }

    private function owned(): array
    {
        $state = $this->read();
        if ($this->id === '' || ($state['id'] ?? '') !== $this->id || ($state['status'] ?? '') !== 'active') throw new \RuntimeException('Download task ownership changed');
        return $state;
    }

    private function read(): array
    {
        $path = $this->path('download-state.json');
        if (!is_file($path)) return [];
        if (filesize($path) > 65536) throw new \RuntimeException('Invalid download state');
        $state = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($state) || !is_string($state['id'] ?? null)) throw new \RuntimeException('Invalid download state');
        self::validId($state['id']);
        return $state;
    }

    private function save(array $state): void { $this->store->write($this->path('download-state.json'), $state); }

    private function locked(callable $operation): mixed
    {
        $dir = $this->store->paths->runtimeDir;
        if (is_link($dir) || (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir))) throw new \RuntimeException('Unsafe download runtime directory');
        $handle = $this->open('download-control.lock');
        if (!flock($handle, LOCK_EX)) { fclose($handle); throw new \RuntimeException('Cannot lock download control'); }
        try { return $operation(); } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function path(string $name): string
    {
        $path = $this->store->paths->runtimeDir . '/' . $name;
        clearstatcache(true, $path);
        if (is_link($path) || (file_exists($path) && (!is_file($path) || (lstat($path)['nlink'] ?? 0) !== 1))) throw new \RuntimeException('Unsafe download state file');
        return $path;
    }

    private function open(string $name)
    {
        $path = $this->path($name);
        $handle = fopen($path, 'ce');
        if (!$handle) throw new \RuntimeException('Cannot open download control');
        chmod($path, 0600);
        return $handle;
    }

    private static function validId(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new \InvalidArgumentException('Invalid download task ID');
        return $id;
    }
}
