<?php
declare(strict_types=1);

namespace Vgpu;

/** A bounded RAM snapshot around pkgtools; no service management belongs here. */
final class InstallConfiguration
{
    private const CONFIGS = [
        '/etc/docker/daemon.json', '/etc/nvidia-container-runtime/config.toml',
        '/etc/nvidia/gridd.conf', '/etc/vgpu_unlock/config.toml',
        '/etc/vgpu_unlock/profile_override.toml', '/etc/modprobe.d/nvidia.conf',
    ];
    private const TOKENS = '/etc/nvidia/ClientConfigToken';
    private array $records = [];
    private int $bytes = 0;
    private string $transaction = '';
    private string $transactionBase = '';
    private array $package = [];
    private ?string $registeredDocker = null;
    private $runner;

    public function __construct(private Paths $paths, ?callable $runner = null)
    {
        $this->runner = $runner ?? new Process();
    }

    public function begin(array $package): string
    {
        if ($this->transaction !== '') throw new \RuntimeException('Configuration transaction already exists');
        $base = $this->runtimeDirectory() . '/install-transactions';
        $this->transactionBase = $base;
        $this->directory($base, 0700);
        $this->transaction = $base . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8));
        $this->directory($this->transaction, 0700);
        $this->directory($this->transaction . '/files', 0700);
        $this->package = array_intersect_key($package, array_flip(['filename', 'driver', 'kernel', 'build', 'md5']));
        try {
            foreach (self::CONFIGS as $path) {
                $this->snapshot($path);
                $this->snapshot($path . '.new');
            }
            $this->snapshot(self::TOKENS, true);
            $docker = $this->resolve('/etc/docker/daemon.json', true);
            if (file_exists($docker)) $this->dockerObject($this->contents($docker));
            $this->journal('prepared');
            return $this->transaction;
        } catch (\Throwable $error) {
            $this->journal('backup_failed');
            throw new \RuntimeException('Configuration backup failed; transaction: ' . $this->transaction, 0, $error);
        }
    }

    public function path(): string { return $this->transaction; }

    public function restore(bool $rollback = false): void
    {
        $records = $this->records;
        $rank = ['directory' => 0, 'file' => 1, 'link' => 2, 'absent' => 3];
        uasort($records, static fn(array $left, array $right): int => $rank[$left['kind']] <=> $rank[$right['kind']]);
        foreach ($records as $relative => $record) {
            $path = $this->paths->root . $relative;
            if ($record['kind'] === 'absent') {
                if (!$rollback) continue;
                $this->safeParents($path);
                if (is_link($path) || is_file($path)) {
                    if (!unlink($path)) throw new \RuntimeException('Cannot restore an absent configuration');
                } elseif (is_dir($path) && !@rmdir($path)) {
                    throw new \RuntimeException('New configuration directory requires manual recovery');
                }
                continue;
            }
            $this->directory(dirname($path), 0755);
            if ($record['kind'] === 'directory') {
                $this->directory($path, $record['mode']);
                $this->metadata($path, $record);
                continue;
            }
            if (is_dir($path) && !is_link($path)) throw new \RuntimeException('Configuration was replaced by a directory');
            $temporary = tempnam(dirname($path), '.uvm-restore-');
            if ($temporary === false) throw new \RuntimeException('Cannot stage configuration recovery');
            try {
                if ($record['kind'] === 'link') {
                    if (!unlink($temporary) || !symlink($record['target'], $temporary)) throw new \RuntimeException('Cannot recover configuration link');
                } else {
                    $backup = $this->transaction . '/' . $record['backup'];
                    if (!is_file($backup) || is_link($backup) || hash_file('sha256', $backup) !== $record['sha256'] || !copy($backup, $temporary)) {
                        throw new \RuntimeException('Configuration recovery copy is invalid');
                    }
                }
                $this->metadata($temporary, $record);
                if (!rename($temporary, $path)) throw new \RuntimeException('Cannot publish recovered configuration');
            } finally {
                if (is_file($temporary) || is_link($temporary)) unlink($temporary);
            }
        }
        clearstatcache();
        $this->verify($rollback);
    }

    public function registerDocker(): void
    {
        $path = $this->resolve('/etc/docker/daemon.json', true);
        $this->directory(dirname($path), 0755);
        $this->safeParents($path);
        $exists = file_exists($path);
        if (is_link($path) || ($exists && !is_file($path))) throw new \RuntimeException('Docker configuration is not a regular file');
        $before = $exists ? $this->contents($path) : "{}\n";
        $original = $this->dockerObject($before);
        $metadata = $exists ? $this->stat($path) : ['mode' => 0644, 'uid' => $this->uid(), 'gid' => null, 'mtime' => time()];
        $temporary = tempnam(dirname($path), '.uvm-docker-');
        if ($temporary === false) throw new \RuntimeException('Cannot stage Docker runtime registration');
        try {
            chmod($temporary, 0600);
            if (file_put_contents($temporary, $before) !== strlen($before)) throw new \RuntimeException('Cannot stage Docker configuration');
            $result = ($this->runner)(['nvidia-ctk', 'runtime', 'configure', '--runtime=docker', '--config=' . $temporary], 30, null);
            if (($result['code'] ?? -1) !== 0) throw new \RuntimeException('Docker NVIDIA runtime registration failed');
            if (is_link($temporary) || !is_file($temporary) || (lstat($temporary)['nlink'] ?? 0) !== 1) throw new \RuntimeException('Staged Docker configuration changed type');
            $updated = $this->dockerObject($this->contents($temporary));
            $runtime = $updated->runtimes->nvidia ?? null;
            if (!is_object($runtime) || !is_string($runtime->path ?? null) || !preg_match('#(^|/)nvidia-container-runtime$#D', $runtime->path) ||
                $this->otherDockerSettings($original) !== $this->otherDockerSettings($updated)) {
                throw new \RuntimeException('Docker registration changed unrelated settings');
            }
            clearstatcache();
            $this->safeParents($path);
            if (is_link($path) || file_exists($path) !== $exists || ($exists && $this->contents($path) !== $before)) {
                throw new \RuntimeException('Docker configuration changed during registration');
            }
            $this->metadata($temporary, $metadata);
            if (!rename($temporary, $path)) throw new \RuntimeException('Cannot publish Docker runtime registration');
            $this->registeredDocker = hash_file('sha256', $path);
        } finally {
            if (is_file($temporary) || is_link($temporary)) unlink($temporary);
        }
    }

    public function complete(): void
    {
        $this->verify(false, true);
        $this->journal('complete');
    }

    public function fail(bool $completionUnknown = false): string
    {
        if ($completionUnknown) {
            $this->journal('completion_unknown');
            return 'Installer completion is unknown; configuration backup: ' . $this->transaction;
        }
        try {
            $this->restore(true);
            $this->journal('failed_restored');
            return 'Original configurations restored; transaction: ' . $this->transaction;
        } catch (\Throwable) {
            $this->journal('recovery_required');
            return 'Configuration recovery requires attention; backup: ' . $this->transaction;
        }
    }

    private function snapshot(string $logical, bool $directoryAllowed = false): void
    {
        $this->safeParents($this->paths->root . $logical);
        $path = $this->resolve($logical);
        $relative = substr($path, strlen($this->paths->root));
        if (isset($this->records[$relative])) return;
        if (count($this->records) >= 512) throw new \RuntimeException('Too many configuration entries');
        if (!file_exists($path) && !is_link($path)) {
            $this->records[$relative] = ['kind' => 'absent', 'directory' => $directoryAllowed];
            return;
        }
        $record = $this->stat($path);
        if (is_link($path)) {
            $target = readlink($path);
            if ($target === false) throw new \RuntimeException('Cannot read configuration link');
            $this->records[$relative] = $record + ['kind' => 'link', 'target' => $target];
            $resolved = $this->resolve($logical, true, true);
            if ($resolved === $path) throw new \RuntimeException('Configuration link loop');
            $this->snapshot(substr($resolved, strlen($this->paths->root)), $directoryAllowed);
        } elseif (is_dir($path) && $directoryAllowed) {
            $this->records[$relative] = $record + ['kind' => 'directory'];
            foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
                $this->snapshot($relative . '/' . $entry->getFilename(), true);
            }
        } else {
            if (!is_file($path) || (lstat($path)['nlink'] ?? 0) !== 1) throw new \RuntimeException('Configuration must be a regular single-link file');
            $contents = $this->contents($path);
            $this->bytes += strlen($contents);
            if ($this->bytes > 16777216) throw new \RuntimeException('Configuration backup is too large');
            $backup = 'files/' . count($this->records);
            if (file_put_contents($this->transaction . '/' . $backup, $contents) !== strlen($contents) || !chmod($this->transaction . '/' . $backup, 0600)) {
                throw new \RuntimeException('Cannot back up configuration');
            }
            $this->records[$relative] = $record + ['kind' => 'file', 'backup' => $backup, 'sha256' => hash('sha256', $contents)];
        }
    }

    private function verify(bool $rollback, bool $registered = false): void
    {
        $docker = $registered ? $this->resolve('/etc/docker/daemon.json', true) : null;
        foreach ($this->records as $relative => $record) {
            $path = $this->paths->root . $relative;
            $this->safeParents($path);
            if ($record['kind'] === 'absent') {
                if ($rollback && (file_exists($path) || is_link($path))) throw new \RuntimeException('Absent configuration was not restored');
                if (!$rollback && (file_exists($path) || is_link($path)) &&
                    (is_link($path) || ($record['directory'] ? !is_dir($path) : !is_file($path)))) throw new \RuntimeException('New configuration has an unexpected type');
                continue;
            }
            $expectedHash = $registered && $path === $docker ? $this->registeredDocker : ($record['sha256'] ?? null);
            if ($record['kind'] === 'file' && (is_link($path) || !is_file($path) || hash_file('sha256', $path) !== $expectedHash)) throw new \RuntimeException('Configuration recovery content mismatch');
            if ($record['kind'] === 'link' && (!is_link($path) || readlink($path) !== $record['target'])) throw new \RuntimeException('Configuration recovery link mismatch');
            if ($record['kind'] === 'directory' && (is_link($path) || !is_dir($path))) throw new \RuntimeException('Licensing directory changed type');
            $now = $this->stat($path);
            foreach (['uid', 'gid', 'mode'] as $key) {
                if ($now[$key] !== $record[$key]) throw new \RuntimeException('Configuration recovery permissions mismatch');
            }
        }
        if ($registered && ($this->registeredDocker === null || !is_file($docker) || hash_file('sha256', $docker) !== $this->registeredDocker)) throw new \RuntimeException('Registered Docker configuration changed');
    }

    private function resolve(string $logical, bool $followFinal = false, bool $singleLink = false): string
    {
        if (!str_starts_with($logical, '/') || str_contains($logical, "\0")) throw new \RuntimeException('Invalid configuration path');
        $pending = explode('/', $logical);
        $parts = [];
        $links = 0;
        while ($pending) {
            $part = array_shift($pending);
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                if (!$parts) throw new \RuntimeException('Configuration path escapes its root');
                array_pop($parts);
                continue;
            }
            $path = $this->paths->root . '/' . implode('/', [...$parts, $part]);
            if (is_link($path) && ($pending || $followFinal)) {
                if (++$links > 32) throw new \RuntimeException('Configuration link loop');
                if ($singleLink && $links > 1) throw new \RuntimeException('Multi-hop configuration links require manual preparation');
                $target = readlink($path);
                if ($target === false) throw new \RuntimeException('Cannot resolve configuration link');
                if (str_starts_with($target, '/')) {
                    if ($this->paths->root !== '') {
                        if (str_starts_with($target, $this->paths->root . '/')) $target = substr($target, strlen($this->paths->root));
                        elseif (!str_starts_with($target, '/etc/') && !str_starts_with($target, '/boot/config/')) throw new \RuntimeException('Configuration link leaves its installation root');
                    }
                    $parts = [];
                }
                $pending = [...explode('/', $target), ...$pending];
            } else {
                if ($pending && file_exists($path) && !is_dir($path)) throw new \RuntimeException('Configuration parent is not a directory');
                $parts[] = $part;
            }
        }
        if (!$parts) throw new \RuntimeException('Configuration resolves to the installation root');
        return $this->paths->root . '/' . implode('/', $parts);
    }

    private function safeParents(string $path): void
    {
        if (!str_starts_with($path, $this->paths->root . '/') || str_contains($path, '/../')) throw new \RuntimeException('Configuration path escapes its root');
        $relative = substr(dirname($path), strlen($this->paths->root));
        $parent = $this->paths->root;
        foreach (explode('/', $relative) as $part) {
            if ($part === '') continue;
            $parent .= '/' . $part;
            if (is_link($parent) || (file_exists($parent) && !is_dir($parent))) throw new \RuntimeException('Configuration parent is not a regular directory');
        }
    }

    private function directory(string $path, int $mode): void
    {
        $this->safeParents($path);
        if (is_link($path) || (file_exists($path) && !is_dir($path))) throw new \RuntimeException('Configuration directory has an unexpected type');
        if (!is_dir($path) && !mkdir($path, $mode, true)) throw new \RuntimeException('Cannot create configuration directory');
        if ($this->transactionBase !== '' && ($path === $this->transactionBase || str_starts_with($path, $this->transactionBase . '/'))) {
            if (!chmod($path, 0700)) throw new \RuntimeException('Cannot protect configuration transaction directory');
        }
    }

    private function runtimeDirectory(): string
    {
        $path = $this->paths->runtimeDir;
        $alias = $this->paths->root . '/var/run';
        $this->safeParents($alias);
        if (is_link($alias)) {
            $target = readlink($alias);
            if (!in_array($target, ['/run', '../run', $this->paths->root . '/run'], true)) throw new \RuntimeException('Unsupported runtime directory link');
            if (!str_starts_with($path, $alias . '/')) throw new \RuntimeException('Runtime path is outside its expected directory');
            $path = $this->paths->root . '/run' . substr($path, strlen($alias));
        }
        $this->safeParents($path);
        return $path;
    }

    private function stat(string $path): array
    {
        clearstatcache(true, $path);
        $stat = lstat($path);
        if ($stat === false) throw new \RuntimeException('Cannot read configuration metadata');
        return ['mode' => $stat['mode'] & 07777, 'uid' => $stat['uid'], 'gid' => $stat['gid'], 'mtime' => $stat['mtime']];
    }

    private function metadata(string $path, array $record): void
    {
        if (is_link($path)) {
            if ($this->uid() === 0 && (!lchown($path, $record['uid']) || !lchgrp($path, $record['gid']))) throw new \RuntimeException('Cannot recover link ownership');
            return;
        }
        if ($this->uid() === 0 && (!chown($path, $record['uid']) || ($record['gid'] !== null && !chgrp($path, $record['gid'])))) throw new \RuntimeException('Cannot recover configuration ownership');
        if (!chmod($path, $record['mode']) || !touch($path, $record['mtime'])) throw new \RuntimeException('Cannot recover configuration permissions');
    }

    private function contents(string $path): string
    {
        if (!is_file($path) || filesize($path) > 1048576) throw new \RuntimeException('Configuration is not a bounded regular file');
        $contents = file_get_contents($path);
        if ($contents === false || strlen($contents) > 1048576) throw new \RuntimeException('Cannot read configuration');
        return $contents;
    }

    private function dockerObject(string $contents): \stdClass
    {
        try { $object = json_decode($contents, false, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new \RuntimeException('Docker configuration must be a JSON object'); }
        if (!$object instanceof \stdClass || (isset($object->runtimes) && !$object->runtimes instanceof \stdClass)) throw new \RuntimeException('Docker configuration must be a JSON object');
        return $object;
    }

    private function otherDockerSettings(\stdClass $object): string
    {
        $object = unserialize(serialize($object), ['allowed_classes' => [\stdClass::class]]);
        if (isset($object->runtimes)) unset($object->runtimes->nvidia);
        if (property_exists($object, 'runtimes') && ($object->runtimes === null || (array)$object->runtimes === [])) unset($object->runtimes);
        return json_encode($this->sortedJson($object), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function sortedJson(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            foreach ($properties as &$property) $property = $this->sortedJson($property);
            return (object)$properties;
        }
        if (is_array($value)) return array_map($this->sortedJson(...), $value);
        return $value;
    }

    private function journal(string $status): void
    {
        if ($this->transaction === '') return;
        $data = ['status' => $status, 'updated_at' => time(), 'package' => $this->package, 'configurations' => $this->records];
        (new Store($this->paths))->write($this->transaction . '/transaction.json', $data);
    }

    private function uid(): int
    {
        if (function_exists('posix_geteuid')) return posix_geteuid();
        if (preg_match('/^Uid:\s+\d+\s+(\d+)/m', (string)file_get_contents('/proc/self/status'), $match)) return (int)$match[1];
        throw new \RuntimeException('Cannot identify configuration owner');
    }
}
