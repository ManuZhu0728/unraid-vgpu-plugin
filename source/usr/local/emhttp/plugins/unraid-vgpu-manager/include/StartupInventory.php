<?php
declare(strict_types=1);

namespace Vgpu;

/**
 * A read-only, deliberately partial inventory, not a shell interpreter.
 * Only literal absolute references below /boot/config are followed. A complete
 * scan means complete within that scope, never proof that no other launcher exists.
 */
final class StartupInventory
{
    private const MAX_DEPTH = 4;
    private const MAX_FILES = 64;
    private const MAX_FILE_BYTES = 65536;
    private const MAX_TOTAL_BYTES = 1048576;
    private array $entries = [];
    private array $findings = [];
    private array $references = [];
    private array $owners = [];
    private array $files = [];
    private array $visited = [];
    private int $bytes = 0;
    private bool $complete = true;

    public function __construct(private Paths $paths) {}

    public function read(): array
    {
        $this->entries = $this->findings = $this->references = $this->owners = $this->files = $this->visited = [];
        $this->bytes = 0;
        $this->complete = true;
        foreach (['novidio-vgpu-driver.plg', 'nvidia-vgpu-driver.plg'] as $plugin) {
            $path = '/boot/config/plugins/' . $plugin;
            if (@lstat($this->paths->path($path)) === false) continue;
            $owner = 'plugin:' . $plugin;
            $this->entries[] = ['id' => $owner, 'kind' => 'plugin', 'path' => $path, 'enabled' => true];
            $this->finding($owner, $path, 0, [$path], 'legacy_plugin', true, true);
        }
        $schedule = '/boot/config/plugins/user.scripts/schedule.json';
        if (@lstat($this->paths->path($schedule)) !== false) {
            $id = 'unknown:user-scripts-schedule';
            $loaded = $this->loadFile($schedule);
            $jobs = isset($loaded['contents']) ? json_decode($loaded['contents'], true, 32) : null;
            if (!is_array($jobs)) {
                $this->unresolved($id, $schedule, 0, [$schedule], $loaded['error'] ?? 'invalid_schedule', true);
            } else {
                foreach ($jobs as $key => $job) {
                    if (is_string($job)) $job = ['frequency' => $job];
                    $name = is_array($job) ? ($job['script'] ?? $key) : $key;
                    $name = is_string($name) ? $name : (string)$key;
                    $related = $this->gpuHint($name);
                    $frequency = is_array($job) ? ($job['frequency'] ?? 'disabled') : null;
                    if (!is_string($frequency)) {
                        $this->unresolved($id, $schedule, 0, [$schedule], 'invalid_schedule_entry', $related);
                        continue;
                    }
                    $enabled = !in_array(strtolower(trim($frequency)), ['', 'disabled', 'off', 'none'], true);
                    $script = $name;
                    if (preg_match('#^[A-Za-z0-9_ .-]+(?:/script)?$#D', $script) && !str_contains($script, '..')) {
                        $script = '/boot/config/plugins/user.scripts/scripts/' . preg_replace('#/script$#', '', $script) . '/script';
                    }
                    $owner = 'user-script:' . basename(dirname($script));
                    $this->entries[] = ['id' => $owner, 'kind' => 'user-script', 'path' => $script, 'enabled' => $enabled, 'frequency' => $frequency];
                    if (!$enabled) continue;
                    if (!str_starts_with($script, '/boot/config/plugins/user.scripts/scripts/') || str_contains($script, '..')) {
                        $this->unresolved($owner, $script, 0, [$script], 'schedule_path_outside_scope', $related);
                        continue;
                    }
                    $this->walk($script, $owner, $related, 0, [], []);
                }
            }
        }
        $go = '/boot/config/go';
        if (@lstat($this->paths->path($go)) !== false) {
            $id = 'go:' . $go;
            $this->entries[] = ['id' => $id, 'kind' => 'go', 'path' => $go, 'enabled' => true];
            $this->walk($go, $id, false, 0, [], []);
        }
        return [
            'owners' => array_values(array_unique($this->owners)), 'entries' => $this->entries,
            'findings' => $this->findings, 'references' => $this->references,
            'files' => array_values(array_map(static fn(array $file): array => array_diff_key($file, ['contents' => true]), $this->files)),
            'coverage' => [
                'complete' => $this->complete, 'scope' => 'literal-absolute-shell-references-under-/boot/config',
                'proves_absence' => false, 'files_read' => count($this->files), 'bytes_read' => $this->bytes,
                'limits' => ['depth' => self::MAX_DEPTH, 'files' => self::MAX_FILES, 'file_bytes' => self::MAX_FILE_BYTES, 'total_bytes' => self::MAX_TOTAL_BYTES],
                'limitations' => ['No shell evaluation, PATH lookup, function reachability or runtime cwd inference', 'Recognized static read-only substitutions are non-blocking but are not evaluated', 'No inventory of other plugin event hooks, cron, boot/extra or plugins-nextboot'],
            ],
        ];
    }

    private function walk(string $path, string $owner, bool $related, int $depth, array $chain, array $stack): void
    {
        $chain[] = $path;
        if ($depth > self::MAX_DEPTH) {
            $this->unresolved($owner, $path, 0, $chain, 'depth_limit', $related);
            return;
        }
        $loaded = $this->loadFile($path);
        if (isset($loaded['error'])) {
            $this->unresolved($owner, $path, 0, $chain, $loaded['error'], $related);
            return;
        }
        $canonical = $loaded['path'];
        if (in_array($canonical, $stack, true)) {
            $this->unresolved($owner, $path, 0, $chain, 'reference_cycle', $related);
            return;
        }
        // A GPU-named alias can reach the same file with stricter relevance.
        // Do not let an earlier unrelated traversal suppress that evidence.
        $context = $related ? 1 : 0;
        if (isset($this->visited[$owner][$canonical][$context])) return;
        $this->visited[$owner][$canonical][$context] = true;
        $stack[] = $canonical;
        $parsed = $this->commands($loaded['contents']);
        foreach ($parsed['issues'] as $issue) {
            if (($issue['read_only'] ?? false) === true) {
                $this->complete = false;
                $this->finding($owner, $path, $issue['line'], $chain, $issue['code'], $related || $issue['gpu'], false);
                continue;
            }
            $this->unresolved($owner, $path, $issue['line'], $chain, $issue['code'], $related || $issue['gpu']);
        }
        foreach ($parsed['commands'] as $command) {
            $this->inspectCommand($command['words'], $owner, $path, $command['line'], $related, $depth, $chain, $stack);
        }
    }

    private function inspectCommand(array $words, string $owner, string $path, int $line, bool $related, int $depth, array $chain, array $stack): void
    {
        for ($prefix = 0; $prefix < 8 && $words; $prefix++) {
            while ($words && preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $words[0]['value'])) array_shift($words);
            if (!$words) return;
            $name = basename($words[0]['value']);
            if (in_array($name, ['if', 'then', 'elif', 'while', 'until', 'do', '!'], true)) { array_shift($words); continue; }
            if (!in_array($name, ['exec', 'command', 'env', 'nohup'], true)) break;
            array_shift($words);
            while ($words && in_array($words[0]['value'], $name === 'env' ? ['--', '-i', '--ignore-environment'] : ['--', '-p'], true)) array_shift($words);
            if ($name === 'command' && $words && in_array($words[0]['value'], ['-v', '-V'], true)) return;
            if ($words && str_starts_with($words[0]['value'], '-')) {
                $this->unresolved($owner, $path, $line, $chain, 'unsupported_wrapper_options', $related || $this->wordsHint($words));
                return;
            }
        }
        if (!$words) return;
        if ($prefix >= 8) {
            $this->unresolved($owner, $path, $line, $chain, 'wrapper_depth_limit', $related || $this->wordsHint($words));
            return;
        }
        $first = $words[0];
        $name = basename($first['value']);
        if (!$first['literal']) {
            $this->unresolved($owner, $path, $line, $chain, 'dynamic_command', $related || $this->wordsHint($words));
            return;
        }
        if (in_array($name, ['nvidia-vgpu-mgr', 'nvidia-vgpud'], true) ||
            ($name === 'mdevctl' && isset($words[1]) && $words[1]['literal'] && in_array($words[1]['value'], ['define', 'start'], true))) {
            $this->finding($owner, $path, $line, $chain, 'initializer_command', true, true);
            return;
        }
        if ($name === 'mdevctl' && isset($words[1]) &&
            (!$words[1]['literal'] || (str_starts_with($words[1]['value'], '-') && !in_array($words[1]['value'], ['--help', '-h', '--version'], true)))) {
            $this->unresolved($owner, $path, $line, $chain, 'unsupported_mdevctl_invocation', true);
            return;
        }
        $target = null;
        if (in_array($name, ['bash', 'sh', 'dash', 'ksh', 'zsh', 'source', '.'], true)) {
            array_shift($words);
            while ($words && in_array($words[0]['value'], ['--', '-e', '-u', '-x', '-eu', '-ue', '--noprofile', '--norc'], true)) array_shift($words);
            if (!$words || str_starts_with($words[0]['value'], '-')) {
                $this->unresolved($owner, $path, $line, $chain, 'unsupported_shell_invocation', $related || $this->wordsHint($words));
                return;
            }
            $target = $words[0];
        } elseif ($name === 'eval') {
            $this->unresolved($owner, $path, $line, $chain, 'dynamic_eval', $related || $this->wordsHint($words));
            return;
        } elseif (str_starts_with($first['value'], '/boot/config/') || str_starts_with($first['value'], './') || str_starts_with($first['value'], '../')) {
            $target = $first;
        }
        if ($target === null) return; // Arguments to echo, grep, pgrep, etc. are not launch commands.
        $targetRelated = $related || $this->gpuHint($target['value']);
        if (!$target['literal'] || !str_starts_with($target['value'], '/')) {
            $this->unresolved($owner, $path, $line, $chain, $target['literal'] ? 'relative_cwd_unknown' : 'dynamic_reference', $targetRelated);
            return;
        }
        $this->references[] = ['entry' => $owner, 'from' => $path, 'line' => $line, 'to' => $target['value']];
        $this->walk($target['value'], $owner, $targetRelated, $depth + 1, $chain, $stack);
    }

    private function finding(string $owner, string $path, int $line, array $chain, string $code, bool $gpu, bool $blocking): void
    {
        $this->findings[] = ['entry' => $owner, 'path' => $path, 'line' => $line, 'chain' => $chain, 'code' => $code, 'gpu_related' => $gpu, 'blocking' => $blocking];
        if ($blocking) $this->owners[] = $owner;
    }

    private function unresolved(string $owner, string $path, int $line, array $chain, string $code, bool $related): void
    {
        $this->complete = false;
        $this->finding($owner, $path, $line, $chain, $code, $related, $related);
    }

    private function gpuHint(string $text): bool { return preg_match('/vgpu|nvidia|mdevctl/i', $text) === 1; }
    private function wordsHint(array $words): bool
    {
        foreach ($words as $word) if ($this->gpuHint($word['value'])) return true;
        return false;
    }

    private function shellStdin(array $words): bool
    {
        for ($prefix = 0; $prefix < 8 && $words; $prefix++) {
            $word = array_shift($words);
            if (!$word['literal']) return false;
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $word['value']) ||
                in_array($word['value'], ['--', '-i', '--ignore-environment'], true)) continue;
            $name = basename($word['value']);
            if (in_array($name, ['exec', 'command', 'env', 'nohup'], true)) continue;
            return in_array($name, ['bash', 'sh', 'dash', 'ksh', 'zsh'], true);
        }
        return false;
    }

    private function initializerHint(string $line): bool
    {
        return preg_match('#(?:^|[;&|])\s*(?:(?:exec|env|nohup)\s+|[A-Za-z_][A-Za-z0-9_]*=\S+\s+)*(?:/[A-Za-z0-9_./-]+/)?(?:nvidia-vgpu-mgr|nvidia-vgpud|mdevctl\s+(?:define|start))(?=\s|$)#', $line) === 1;
    }

    /** Only one simple static reader; never allow nested expansion or shell code. */
    private function readOnlySubstitution(string $raw): bool
    {
        if (str_starts_with($raw, '$(') && str_ends_with($raw, ')')) $body = substr($raw, 2, -1);
        elseif (str_starts_with($raw, '`') && str_ends_with($raw, '`') && strlen($raw) >= 2) $body = substr($raw, 1, -1);
        else return false;
        if (strpbrk($body, '$`;&|<>(){}\\' . "\r\n") !== false) return false;
        $parsed = $this->commands($body);
        if ($parsed['issues'] || count($parsed['commands']) !== 1) return false;
        $words = $parsed['commands'][0]['words'];
        foreach ($words as $word) if (!$word['literal']) return false;
        $command = $words[0]['value'];
        foreach (['pgrep', 'pidof', 'grep'] as $reader) {
            if (in_array($command, [$reader, '/bin/' . $reader, '/usr/bin/' . $reader], true)) return true;
        }
        if ($command !== 'command') return false;
        array_shift($words);
        if ($words && $words[0]['value'] === '-p') array_shift($words);
        return count($words) >= 2 && in_array($words[0]['value'], ['-v', '-V'], true);
    }

    /** Resolve at most eight symlinks, never outside the permitted logical tree. */
    private function resolve(string $path): array
    {
        for ($links = 0; $links <= 8; $links++) {
            $parts = [];
            if (!str_starts_with($path, '/') || strlen($path) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $path)) return ['error' => 'unsafe_path'];
            foreach (explode('/', $path) as $part) {
                if ($part === '' || $part === '.') continue;
                if ($part === '..') { if (!$parts) return ['error' => 'unsafe_path']; array_pop($parts); }
                else $parts[] = $part;
            }
            $path = '/' . implode('/', $parts);
            if (!str_starts_with($path, '/boot/config/') || str_contains($path, '..')) return ['error' => 'reference_outside_scope'];
            $prefix = '';
            foreach ($parts as $index => $part) {
                $prefix .= '/' . $part;
                $physical = $this->paths->path($prefix);
                clearstatcache(true, $physical);
                $stat = @lstat($physical);
                if (!$stat) return ['error' => 'missing_file'];
                $type = $stat['mode'] & 0170000;
                if ($type === 0120000) {
                    if ($links === 8) return ['error' => 'symlink_limit'];
                    $target = @readlink($physical);
                    if ($target === false) return ['error' => 'unreadable_symlink'];
                    // Tests may use absolute physical targets inside their private root.
                    if ($this->paths->root !== '' && str_starts_with($target, $this->paths->root . '/')) $target = substr($target, strlen($this->paths->root));
                    $path = (str_starts_with($target, '/') ? $target : dirname($prefix) . '/' . $target) . '/' . implode('/', array_slice($parts, $index + 1));
                    continue 2;
                }
                if ($index < count($parts) - 1 && $type !== 0040000) return ['error' => 'not_directory'];
            }
            return ['path' => $path, 'stat' => $stat];
        }
        return ['error' => 'symlink_limit'];
    }

    private function loadFile(string $path): array
    {
        $resolved = $this->resolve($path);
        if (isset($resolved['error'])) return $resolved;
        $canonical = $resolved['path'];
        if (isset($this->files[$canonical])) return $this->files[$canonical];
        $before = $resolved['stat'];
        if (($before['mode'] & 0170000) !== 0100000 || ($before['mode'] & 0444) === 0) return ['error' => 'not_readable_regular_file'];
        if ($before['size'] > self::MAX_FILE_BYTES) return ['error' => 'file_bytes_limit'];
        if (count($this->files) >= self::MAX_FILES) return ['error' => 'files_limit'];
        if ($this->bytes + $before['size'] > self::MAX_TOTAL_BYTES) return ['error' => 'total_bytes_limit'];
        $handle = @fopen($this->paths->path($canonical), 'rb');
        if ($handle === false) return ['error' => 'unreadable_file'];
        try {
            $opened = fstat($handle);
            if (!$opened || ($opened['mode'] & 0170000) !== 0100000 || $before['dev'] !== $opened['dev'] || $before['ino'] !== $opened['ino']) return ['error' => 'file_identity_changed'];
            $contents = stream_get_contents($handle, self::MAX_FILE_BYTES + 1);
            $after = fstat($handle);
        } finally { fclose($handle); }
        if ($contents === false || !$after || $opened['size'] !== $after['size'] || $opened['mtime'] !== $after['mtime'] || strlen($contents) !== $after['size']) return ['error' => 'file_changed_or_unreadable'];
        if (strlen($contents) > self::MAX_FILE_BYTES) return ['error' => 'file_bytes_limit'];
        if ($this->bytes + strlen($contents) > self::MAX_TOTAL_BYTES) return ['error' => 'total_bytes_limit'];
        if (str_contains($contents, "\0")) return ['error' => 'binary_script'];
        $this->bytes += strlen($contents);
        return $this->files[$canonical] = ['path' => $canonical, 'sha256' => hash('sha256', $contents), 'bytes' => strlen($contents), 'contents' => $contents];
    }

    /** Small non-evaluating lexer. Unsupported execution syntax is evidence, not permission. */
    private function commands(string $source): array
    {
        $commands = $issues = $tokens = [];
        $value = ''; $literal = true; $quoted = false; $started = false; $quote = ''; $line = 1; $startLine = 1;
        $length = strlen($source);
        $word = function () use (&$tokens, &$value, &$literal, &$quoted, &$started, &$startLine): void {
            if ($started) $tokens[] = ['value' => $value, 'literal' => $literal, 'quoted' => $quoted, 'line' => $startLine];
            $value = ''; $literal = true; $quoted = false; $started = false;
        };
        $flush = function () use (&$tokens, &$commands): array {
            $words = []; $documents = [];
            for ($i = 0; $i < count($tokens); $i++) {
                if (isset($tokens[$i]['redirection'])) {
                    if (isset($tokens[$i + 1])) {
                        if (in_array($tokens[$i]['value'], ['<<', '<<-'], true)) $documents[] = $tokens[$i + 1] + ['strip_tabs' => $tokens[$i]['value'] === '<<-'];
                        $i++;
                    }
                    continue;
                }
                $words[] = $tokens[$i];
            }
            $shellStdin = $this->shellStdin($words);
            foreach ($documents as &$document) $document['shell_stdin'] = $shellStdin;
            unset($document);
            if ($words) $commands[] = ['words' => $words, 'line' => $words[0]['line']];
            $tokens = [];
            return $documents;
        };
        $documents = [];
        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
            if ($quote === "'") {
                if ($char === "'") $quote = ''; else $value .= $char;
                if ($char === "\n") $line++;
                continue;
            }
            if ($char === '\\' && $i + 1 < $length && ($quote === '' || str_contains("\"\\$`\n", $source[$i + 1]))) {
                $next = $source[++$i];
                if ($next === "\n") $line++;
                else { if (!$started) $startLine = $line; $started = true; $value .= $next; }
                continue;
            }
            if ($quote === '"' && $char === '"') { $quote = ''; continue; }
            if (($char === "'" || $char === '"') && $quote === '') {
                if (!$started) $startLine = $line;
                $started = true; $quoted = true; $quote = $char;
                continue;
            }
            if ($char === '$' || $char === '`') {
                if (!$started) $startLine = $line;
                $started = true; $literal = false;
                if ($char === '`' || ($i + 1 < $length && $source[$i + 1] === '(')) {
                    $begin = $i; $balance = $char === '`' ? 0 : 1;
                    if ($balance) $i++;
                    while (++$i < $length) {
                        if ($source[$i] === "\n") $line++;
                        if ($source[$i] === '\\' && $i + 1 < $length) { $i++; continue; }
                        if ($char === '`' && $source[$i] === '`') break;
                        if ($char !== '`' && $source[$i] === '(') $balance++;
                        if ($char !== '`' && $source[$i] === ')' && --$balance === 0) break;
                    }
                    $raw = substr($source, $begin, $i - $begin + 1);
                    $readOnly = $this->readOnlySubstitution($raw);
                    $issues[] = ['line' => $startLine, 'code' => $readOnly ? 'read_only_command_substitution' : 'unsupported_command_substitution', 'gpu' => $this->gpuHint($raw), 'read_only' => $readOnly];
                    $value .= $raw;
                } else $value .= $char;
                continue;
            }
            if ($quote === '"') { $value .= $char; if ($char === "\n") $line++; continue; }
            if ($char === '#' && !$started) { while ($i < $length && $source[$i] !== "\n") $i++; $i--; continue; }
            if ($char === '<' || $char === '>') {
                if ($started && preg_match('/^[0-9]+$/D', $value)) { $value = ''; $started = false; }
                $word(); $operator = $char;
                while ($i + 1 < $length && str_contains('<>&-', $source[$i + 1])) $operator .= $source[++$i];
                $tokens[] = ['value' => $operator, 'redirection' => true];
                if ($operator === '<<<') $issues[] = ['line' => $line, 'code' => 'unsupported_here_string', 'gpu' => false];
                continue;
            }
            if (str_contains(" \t\r\n;&|(){}", $char)) {
                $word();
                if (str_contains("\n;&|(){}", $char)) {
                    $documents = array_merge($documents, $flush());
                    if (str_contains('(){}', $char)) $issues[] = ['line' => $line, 'code' => 'unsupported_shell_structure', 'gpu' => false];
                }
                if ($char === "\n") {
                    $line++;
                    foreach ($documents as $document) {
                        $closed = false;
                        while ($i + 1 < $length) {
                            $end = strpos($source, "\n", $i + 1);
                            if ($end === false) $end = $length;
                            $body = rtrim(substr($source, $i + 1, $end - $i - 1), "\r");
                            $i = $end; $line++;
                            if (($document['strip_tabs'] ? ltrim($body, "\t") : $body) === $document['value']) { $closed = true; break; }
                            if ($document['shell_stdin'] && $this->initializerHint($body)) $issues[] = ['line' => $line - 1, 'code' => 'unsupported_shell_stdin', 'gpu' => true];
                            if (!$document['quoted'] && (str_contains($body, '$(') || str_contains($body, '`'))) $issues[] = ['line' => $line - 1, 'code' => 'unsupported_heredoc_expansion', 'gpu' => $this->gpuHint($body)];
                        }
                        if (!$closed) $issues[] = ['line' => $line, 'code' => 'unterminated_heredoc', 'gpu' => false];
                    }
                    $documents = [];
                }
                continue;
            }
            if (!$started) $startLine = $line;
            $started = true; $value .= $char;
        }
        $word(); $documents = array_merge($documents, $flush());
        if ($quote !== '' || $documents) $issues[] = ['line' => $line, 'code' => 'unterminated_shell_text', 'gpu' => false];
        return ['commands' => $commands, 'issues' => $issues];
    }
}
