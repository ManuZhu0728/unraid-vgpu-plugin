<?php
declare(strict_types=1);

namespace Vgpu;

/** A bounded, read-only view of the plugin's fixed log sources. */
final class Logs
{
    private const TAIL_BYTES = 524288;
    private const MAX_LINES = 200;
    private const MAX_LINE_BYTES = 2048;
    private const MAX_OUTPUT_BYTES = 131072;

    public function __construct(private Paths $paths, private string $secret = '') {}

    public function read(string $category = 'plugin'): array
    {
        $sources = [
            'plugin' => ['label' => '插件升级助手', 'path' => '/var/log/unraid-vgpu-manager-upgrade.log', 'filter' => null],
            'vgpu' => ['label' => 'vGPU 管理器', 'path' => '/var/log/syslog', 'filter' => '/\bnvidia-vgpu(?:-mgr|d)\b/i'],
            'kernel' => ['label' => 'NVIDIA 内核', 'path' => '/var/log/syslog', 'filter' => '/\bkernel:.*(?:nvidia|NVRM|\bXid\b|vma_start_write|vgpu_mmio_fault)/i'],
        ];
        if (!isset($sources[$category])) throw new \InvalidArgumentException('Unknown log category');
        $source = $sources[$category];
        $result = ['category' => $category, 'label' => $source['label'], 'available' => false, 'lines' => [], 'truncated' => false, 'message' => '日志尚未生成。'];
        $path = $this->paths->path($source['path']);
        $before = @lstat($path);
        if (!$before || ($before['mode'] & 0170000) !== 0100000 || !is_readable($path)) return $result;
        $handle = @fopen($path, 'rb');
        if ($handle === false) return $result;
        try {
            $opened = fstat($handle);
            if (!$opened || ($opened['mode'] & 0170000) !== 0100000 || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']) return $result;
            $offset = max(0, $opened['size'] - self::TAIL_BYTES);
            if ($offset > 0 && fseek($handle, $offset) !== 0) return $result;
            $tail = stream_get_contents($handle, self::TAIL_BYTES);
            if ($tail === false) return $result;
        } finally {
            fclose($handle);
        }
        $result['available'] = true;
        $result['truncated'] = $offset > 0;
        // A seek may start in the middle of a line (including a credential).
        if ($offset > 0) {
            $newline = strpos($tail, "\n");
            $tail = $newline === false ? '' : substr($tail, $newline + 1);
        }
        $lines = preg_split('/\r?\n/', $tail);
        $bytes = 0;
        for ($index = count($lines) - 1; $index >= 0; --$index) {
            $line = $lines[$index];
            if ($line === '' || ($source['filter'] !== null && preg_match($source['filter'], $line) !== 1)) continue;
            $line = $this->redact($line);
            if (strlen($line) > self::MAX_LINE_BYTES) {
                $line = substr($line, 0, self::MAX_LINE_BYTES) . ' …';
                $result['truncated'] = true;
            }
            if (count($result['lines']) >= self::MAX_LINES || $bytes + strlen($line) > self::MAX_OUTPUT_BYTES) {
                $result['truncated'] = true;
                break;
            }
            $result['lines'][] = $line;
            $bytes += strlen($line);
        }
        $result['lines'] = array_reverse($result['lines']);
        $result['message'] = $result['lines'] ? '' : '最近的日志中没有相关记录。';
        return $result;
    }

    private function redact(string $line): string
    {
        if ($this->secret !== '') $line = str_replace($this->secret, '[redacted]', $line);
        $line = preg_replace('/\b(?:glpat-|github_pat_|gh[pousr]_)[A-Za-z0-9_.-]+/', '[redacted]', $line);
        $line = preg_replace('~(https?://)[^\s/@]+(?::[^\s/@]*)?@~i', '$1[redacted]@', $line);
        $line = preg_replace('/\b(Authorization\s*[:=]\s*)(?:(?:Bearer|Basic)\s+)?[^\s,;]+/i', '$1[redacted]', $line);
        $line = preg_replace('/\b((?:[A-Za-z0-9_-]*(?:token|password|secret)|PRIVATE-TOKEN|DEPLOY-TOKEN)\s*[=:]\s*)[^\s,;&]+/i', '$1[redacted]', $line);
        $line = preg_replace('~((?:"|\')(?:[A-Za-z0-9_-]*(?:token|password|secret)|authorization)(?:"|\')\s*:\s*(?:"|\'))[^"\']*~i', '$1[redacted]', $line);
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $line);
    }
}
