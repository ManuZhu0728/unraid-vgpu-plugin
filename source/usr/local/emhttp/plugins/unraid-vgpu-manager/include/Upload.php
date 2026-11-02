<?php
declare(strict_types=1);

namespace Vgpu;

/** Chunk staging only; Application holds the transaction lock. */
final class Upload
{
    public const MAX_PACKAGE = 2147483648;
    public const MAX_CHUNK = 1048576;

    public function __construct(private Paths $paths) {}

    public function filename(mixed $filename, string $kernel): string
    {
        if (!is_string($filename) || !preg_match('/^nvidia-[0-9]+\.[0-9]+\.[0-9]+-' . preg_quote($kernel, '/') . '-Unraid-1\.txz$/D', $filename)) {
            throw new \InvalidArgumentException('Choose an NVIDIA TXZ package for the current kernel');
        }
        return $filename;
    }

    public function uploadedFile(mixed $path, int $limit): string
    {
        if (!is_string($path) || $path === '' || str_contains($path, "\0") || !preg_match('/^php[A-Za-z0-9]+$/D', basename($path))) {
            throw new \InvalidArgumentException('Invalid PHP upload file');
        }
        $directories = [];
        foreach (array_unique(array_filter([(string)ini_get('upload_tmp_dir'), sys_get_temp_dir()])) as $directory) {
            $real = realpath($this->paths->path($directory));
            if ($real !== false) $directories[] = $real;
        }
        $real = realpath($path);
        $stat = @lstat($path);
        if ($real === false || $real !== $path || !in_array(dirname($real), $directories, true) || !$this->regularOwnedFile($stat) || $stat['size'] < 1 || $stat['size'] > $limit) {
            throw new \InvalidArgumentException('Upload file is missing, unsafe or too large');
        }
        return $real;
    }

    public function begin(array $input, string $kernel): array
    {
        $filename = $this->filename($input['filename'] ?? null, $kernel);
        $total = $this->integer($input['total'] ?? null, 'upload size');
        if ($total < 1 || $total > self::MAX_PACKAGE) throw new \InvalidArgumentException('Package upload must be between 1 byte and 2 GiB');
        $checksum = $input['checksum'] ?? '';
        if (!is_string($checksum) || strlen($checksum) > 4096) throw new \InvalidArgumentException('Invalid MD5 file');
        $checksum = trim($checksum);
        if ($checksum !== '' && !preg_match('/^[a-fA-F0-9]{32}(?:[ \t]+\*?' . preg_quote($filename, '/') . ')?$/D', $checksum)) {
            throw new \InvalidArgumentException('MD5 must contain a hash and, optionally, this package filename');
        }
        $this->privateDirectory($this->paths->runtimeDir);
        $id = bin2hex(random_bytes(16));
        $directory = $this->directory($id);
        if (!mkdir($directory, 0700)) throw new \RuntimeException('Cannot create upload staging directory');
        try {
            $handle = fopen($directory . '/data.tmp', 'xb');
            if ($handle === false) throw new \RuntimeException('Cannot create upload staging file');
            fclose($handle);
            chmod($directory . '/data.tmp', 0600);
            $record = ['filename' => $filename, 'kernel' => $kernel, 'total' => $total, 'offset' => 0, 'checksum' => $checksum];
            (new Store($this->paths))->write($directory . '/metadata.json', $record);
        } catch (\Throwable $error) {
            $this->removeDirectory($directory);
            throw $error;
        }
        return ['ok' => true, 'upload_id' => $id, 'offset' => 0, 'total' => $total, 'max_chunk_size' => self::MAX_CHUNK];
    }

    public function chunk(array $input, string $kernel): array
    {
        [$directory, $record] = $this->record($input['upload_id'] ?? null, $kernel);
        $offset = $this->integer($input['offset'] ?? null, 'upload offset');
        if ($offset !== $record['offset']) throw new \RuntimeException('Upload offset differs; retry the upload');
        $path = $this->uploadedFile($input['path'] ?? null, self::MAX_CHUNK);
        $size = filesize($path);
        if ($size === false || $offset + $size > $record['total']) throw new \RuntimeException('Upload chunk exceeds the declared package size');
        $data = $directory . '/data.tmp';
        $this->stagedFile($data, $record['offset']);
        $source = fopen($path, 'rb');
        $destination = fopen($data, 'r+b');
        if ($source === false || $destination === false) {
            if (is_resource($source)) fclose($source);
            if (is_resource($destination)) fclose($destination);
            throw new \RuntimeException('Cannot read or append upload chunk');
        }
        try {
            if (fseek($destination, $offset) !== 0 || stream_copy_to_stream($source, $destination, $size) !== $size || !fflush($destination)) {
                throw new \RuntimeException('Upload chunk could not be written completely');
            }
            $record['offset'] += $size;
            (new Store($this->paths))->write($directory . '/metadata.json', $record);
        } catch (\Throwable $error) {
            ftruncate($destination, $offset);
            throw $error;
        } finally {
            fclose($source);
            fclose($destination);
        }
        return ['ok' => true, 'upload_id' => $input['upload_id'], 'offset' => $record['offset'], 'total' => $record['total']];
    }

    public function completed(mixed $id, string $kernel): array
    {
        [$directory, $record] = $this->record($id, $kernel);
        if ($record['offset'] !== $record['total']) throw new \RuntimeException('Upload is incomplete');
        $this->stagedFile($directory . '/data.tmp', $record['total']);
        $checksumPath = null;
        if ($record['checksum'] !== '') {
            $checksumPath = $directory . '/package.md5';
            if (is_link($checksumPath) || (file_exists($checksumPath) && !$this->regularOwnedFile(@lstat($checksumPath)))) throw new \RuntimeException('Invalid staged MD5 file');
            if (file_put_contents($checksumPath, $record['checksum'] . "\n") !== strlen($record['checksum']) + 1) {
                throw new \RuntimeException('Cannot prepare MD5 verification');
            }
            chmod($checksumPath, 0600);
        }
        return ['path' => $directory . '/data.tmp', 'filename' => $record['filename'], 'checksum_path' => $checksumPath];
    }

    public function cancel(mixed $id): array
    {
        $directory = $this->directory($id);
        if (file_exists($this->paths->runtimeDir) || is_link($this->paths->runtimeDir)) $this->privateDirectory($this->paths->runtimeDir, false);
        if (is_link($directory)) throw new \RuntimeException('Invalid upload staging directory');
        if (is_dir($directory)) {
            $this->privateDirectory($directory);
            $this->removeDirectory($directory);
        }
        return ['ok' => true, 'message' => 'Upload staging cleared'];
    }

    private function record(mixed $id, string $kernel): array
    {
        $this->privateDirectory($this->paths->runtimeDir, false);
        $directory = $this->directory($id);
        $this->privateDirectory($directory, false);
        $metadata = $directory . '/metadata.json';
        $stat = @lstat($metadata);
        if (!$this->regularOwnedFile($stat) || $stat['size'] > 8192) throw new \RuntimeException('Upload metadata is unavailable');
        $record = json_decode((string)file_get_contents($metadata), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($record) || ($record['kernel'] ?? '') !== $kernel) throw new \RuntimeException('Upload belongs to another kernel');
        $this->filename($record['filename'] ?? null, $kernel);
        if (!is_int($record['total'] ?? null) || $record['total'] < 1 || $record['total'] > self::MAX_PACKAGE ||
            !is_int($record['offset'] ?? null) || $record['offset'] < 0 || $record['offset'] > $record['total'] ||
            !is_string($record['checksum'] ?? null) || strlen($record['checksum']) > 4096) {
            throw new \RuntimeException('Invalid upload metadata');
        }
        return [$directory, $record];
    }

    private function directory(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) throw new \InvalidArgumentException('Invalid upload ID');
        return $this->paths->runtimeDir . '/upload-' . $id;
    }

    private function stagedFile(string $path, int $expected): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!$this->regularOwnedFile($stat) || $stat['size'] !== $expected) throw new \RuntimeException('Upload staging size differs');
    }

    private function privateDirectory(string $path, bool $create = true): void
    {
        if (is_link($path)) throw new \RuntimeException('Invalid upload staging directory');
        if (!is_dir($path) && (!$create || !mkdir($path, 0700, true))) throw new \RuntimeException('Upload staging directory is unavailable');
        $stat = @lstat($path);
        if (!$stat || ($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== $this->uid()) throw new \RuntimeException('Upload staging directory has an unexpected owner');
        chmod($path, 0700);
    }

    private function regularOwnedFile(array|false $stat): bool
    {
        return $stat !== false && ($stat['mode'] & 0170000) === 0100000 && $stat['uid'] === $this->uid() && $stat['nlink'] === 1;
    }

    private function uid(): int
    {
        if (function_exists('posix_geteuid')) return posix_geteuid();
        if (preg_match('/^Uid:\s+\d+\s+(\d+)/m', (string)file_get_contents('/proc/self/status'), $match)) return (int)$match[1];
        throw new \RuntimeException('Cannot determine upload owner');
    }

    private function integer(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 0) throw new \InvalidArgumentException('Invalid ' . $label);
        return $value;
    }

    private function removeDirectory(string $directory): void
    {
        // A session has only these fixed files; never recursively remove input paths.
        foreach (['data.tmp', 'metadata.json', 'package.md5'] as $name) {
            $path = $directory . '/' . $name;
            if (is_link($path) || (is_file($path) && !$this->regularOwnedFile(@lstat($path)))) throw new \RuntimeException('Unexpected upload staging file');
            if (is_file($path) && !unlink($path)) throw new \RuntimeException('Cannot clear upload staging file');
        }
        if (!rmdir($directory)) throw new \RuntimeException('Cannot clear upload staging directory');
    }
}
