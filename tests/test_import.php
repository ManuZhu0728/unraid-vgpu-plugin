<?php
declare(strict_types=1);

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/Upload.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/Application.php';

use Vgpu\Application;
use Vgpu\Download;
use Vgpu\Paths;
use Vgpu\Runtime;
use Vgpu\Store;
use Vgpu\Upload;

$importChecks = 0;
function import_check(bool $condition, string $message): void
{
    global $importChecks;
    if (!$condition) throw new RuntimeException($message);
    ++$importChecks;
}
function import_rejects(callable $operation, string $message): void
{
    try { $operation(); }
    catch (Throwable $error) {
        import_check(str_contains($error->getMessage(), $message), 'Unexpected import error: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected import rejection: ' . $message);
}

final class ImportFixture
{
    public string $root;
    public Paths $paths;
    public Store $store;
    public Upload $upload;
    public Application $application;
    public array $commands = [];
    public string $filename = 'nvidia-595.58.03-6.18.58-Unraid-1.txz';
    public string $package = "\xfd7zXZ\x00local-fixture-package";

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/vgpu-import-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->paths = new Paths($this->root);
        $this->store = new Store($this->paths);
        $this->upload = new Upload($this->paths);
        $this->put('/proc/sys/kernel/osrelease', "6.18.58-Unraid\n");
        $this->directory(sys_get_temp_dir());
        $runtime = new Runtime($this->paths, function () { throw new RuntimeException('Runtime command during upload'); });
        $this->application = new Application($this->store, $runtime, new Download($this->paths, $this->runner(...)));
    }

    public function directory(string $path): string
    {
        $directory = $this->paths->path($path);
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        return $directory;
    }

    public function put(string $path, string $data): string
    {
        $this->directory(dirname($path));
        $destination = $this->paths->path($path);
        file_put_contents($destination, $data);
        return $destination;
    }

    public function phpFile(string $bytes): string
    {
        return $this->put(sys_get_temp_dir() . '/php' . bin2hex(random_bytes(8)), $bytes);
    }

    public function listing(): string
    {
        $members = [];
        foreach (['nvidia', 'nvidia-modeset', 'nvidia-drm', 'nvidia-uvm', 'nvidia-vgpu-vfio'] as $module) {
            $members[] = 'lib/modules/6.18.58-Unraid/kernel/drivers/video/' . $module . '.ko';
        }
        array_push($members, 'usr/bin/nvidia-vgpud', 'usr/bin/nvidia-vgpu-mgr', 'usr/lib64/libvgpu_unlock_rs.so',
            'usr/share/nvidia/vgpu/vgpuConfig.xml', 'usr/lib64/libcuda.so.1', 'usr/lib64/libnvcuvid.so.1',
            'usr/lib64/libnvidia-encode.so.1', 'usr/lib64/libnvidia-ml.so.1');
        return implode("\n", array_map(fn($name) => '-rw-r--r-- 0/0 1 2026-11-02 10:00:00 ' . $name, $members)) . "\n";
    }

    public function runner(array $argv): array
    {
        $this->commands[] = $argv;
        if ($argv[0] === 'curl' || in_array($argv[0], ['installpkg', 'upgradepkg', 'modprobe', 'mdevctl'], true)) throw new RuntimeException('Active or network command during import');
        $output = '';
        if ($argv[0] === 'tar' && in_array('--list', $argv, true)) $output = $this->listing();
        if ($argv[0] === 'tar' && in_array('--extract', $argv, true)) {
            $directory = $argv[array_search('--directory', $argv, true) + 1];
            foreach (array_slice($argv, array_search('--', $argv, true) + 1) as $member) {
                $target = $directory . '/' . $member;
                if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
                file_put_contents($target, 'passive module fixture');
            }
        }
        if ($argv[0] === 'modinfo') $output = $argv[2] === 'version' ? '595.58.03' : '6.18.58-Unraid SMP preempt mod_unload';
        return ['code' => 0, 'stdout' => $output, 'stderr' => ''];
    }

    public function close(): void
    {
        if (!str_starts_with($this->root, sys_get_temp_dir() . '/vgpu-import-test-')) throw new RuntimeException('Unsafe fixture cleanup');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->root);
    }
}

$fixtures = [];
try {
    $fixture = $fixtures[] = new ImportFixture();
    $upload = $fixture->upload;
    $path = $fixture->phpFile('abc');
    import_check($upload->uploadedFile($path, 3) === $path, 'A current-user PHP temporary file should be accepted');
    import_rejects(fn() => $upload->uploadedFile($path, 2), 'too large');
    import_rejects(fn() => $upload->uploadedFile('/etc/passwd', 4096), 'PHP upload');
    $arbitrary = $fixture->put(sys_get_temp_dir() . '/not-a-php-upload', 'abc');
    import_rejects(fn() => $upload->uploadedFile($arbitrary, 4096), 'PHP upload');
    $outside = $fixture->put('/outside/phpOutside', 'abc');
    import_rejects(fn() => $upload->uploadedFile($outside, 4096), 'unsafe');
    $linked = $fixture->paths->path(sys_get_temp_dir() . '/phpSymlink');
    symlink($path, $linked);
    import_rejects(fn() => $upload->uploadedFile($linked, 4096), 'unsafe');
    $hard = $fixture->paths->path(sys_get_temp_dir() . '/phpHardlink');
    link($path, $hard);
    import_rejects(fn() => $upload->uploadedFile($hard, 4096), 'unsafe');
    unlink($hard);
    import_rejects(fn() => $upload->filename('../' . $fixture->filename, '6.18.58'), 'current kernel');
    import_rejects(fn() => $upload->filename(str_replace('6.18.58', '6.18.54', $fixture->filename), '6.18.58'), 'current kernel');
    import_rejects(fn() => $upload->begin(['filename' => $fixture->filename, 'total' => Upload::MAX_PACKAGE + 1], '6.18.58'), '2 GiB');
    import_rejects(fn() => $upload->begin(['filename' => $fixture->filename, 'total' => 0], '6.18.58'), '2 GiB');
    import_rejects(fn() => $upload->begin(['filename' => $fixture->filename, 'total' => 3, 'checksum' => 'bad'], '6.18.58'), 'MD5');
    import_rejects(fn() => $upload->begin(['filename' => $fixture->filename, 'total' => '3'], '6.18.58'), 'upload size');
    import_rejects(fn() => $upload->cancel('../outside'), 'upload ID');

    $app = $fixture->application;
    $started = $app->dispatch('source-upload-begin', ['filename' => $fixture->filename, 'total' => 6]);
    $id = $started['upload_id'];
    import_check($started['offset'] === 0 && $started['max_chunk_size'] === Upload::MAX_CHUNK, 'Begin must advertise a bounded chunk size');
    import_check($fixture->commands === [], 'Begin must not contact a source or GPU');
    $chunk = $app->dispatch('source-upload-chunk', ['upload_id' => $id, 'offset' => 0, 'path' => $path]);
    import_check($chunk['offset'] === 3 && $chunk['total'] === 6, 'Chunk must advance the expected offset');
    import_rejects(fn() => $app->dispatch('source-upload-chunk', ['upload_id' => $id, 'offset' => 0, 'path' => $path]), 'offset differs');
    import_rejects(fn() => $app->dispatch('source-upload-finish', ['upload_id' => $id]), 'incomplete');
    import_check(is_dir($fixture->paths->runtimeDir . '/upload-' . $id), 'Incomplete finish must retain the session for continued upload');
    $chunk = $app->dispatch('source-upload-chunk', ['upload_id' => $id, 'offset' => 3, 'path' => $path]);
    import_check($chunk['offset'] === 6, 'Second chunk must complete the declared size');
    import_rejects(fn() => $app->dispatch('source-upload-chunk', ['upload_id' => $id, 'offset' => 6, 'path' => $path]), 'exceeds');
    $other = $app->dispatch('source-upload-begin', ['filename' => $fixture->filename, 'total' => 1]);
    $app->dispatch('source-upload-cancel', ['upload_id' => $id]);
    import_check(!is_dir($fixture->paths->runtimeDir . '/upload-' . $id), 'Cancel must remove only the selected staging session');
    import_check(is_dir($fixture->paths->runtimeDir . '/upload-' . $other['upload_id']), 'Cancel must retain other sessions');
    $app->dispatch('source-upload-cancel', ['upload_id' => $other['upload_id']]);
    $app->dispatch('source-upload-cancel', ['upload_id' => $other['upload_id']]);
    import_check($fixture->commands === [], 'Chunk and cancel must remain command-free');

    $bounded = $app->dispatch('source-upload-begin', ['filename' => $fixture->filename, 'total' => Upload::MAX_CHUNK * 2]);
    import_rejects(fn() => $app->dispatch('source-upload-chunk', ['upload_id' => $bounded['upload_id'], 'offset' => 0, 'path' => $fixture->phpFile(str_repeat('x', Upload::MAX_CHUNK + 1))]), 'too large');
    import_rejects(fn() => $app->dispatch('source-upload-chunk', ['upload_id' => $bounded['upload_id'], 'offset' => -1, 'path' => $path]), 'upload offset');
    $staged = $fixture->paths->runtimeDir . '/upload-' . $bounded['upload_id'] . '/data.tmp';
    import_check(filesize($staged) === 0, 'Rejected large or negative-offset chunks must not append bytes');
    file_put_contents($staged, 'unexpected bytes');
    import_rejects(fn() => $app->dispatch('source-upload-chunk', ['upload_id' => $bounded['upload_id'], 'offset' => 0, 'path' => $path]), 'staging size differs');
    import_check(file_get_contents($staged) === 'unexpected bytes', 'Staging tamper rejection must not overwrite data');
    $app->dispatch('source-upload-cancel', ['upload_id' => $bounded['upload_id']]);

    $boundary = $app->dispatch('source-upload-begin', ['filename' => $fixture->filename, 'total' => Upload::MAX_PACKAGE]);
    import_check($boundary['total'] === Upload::MAX_PACKAGE, 'The declared size limit must be accepted without preallocating the entire file');
    $app->dispatch('source-upload-cancel', ['upload_id' => $boundary['upload_id']]);

    $invalid = $fixtures[] = new ImportFixture();
    $bad = $invalid->application->dispatch('source-upload-begin', ['filename' => $invalid->filename, 'total' => 3]);
    $invalid->application->dispatch('source-upload-chunk', ['upload_id' => $bad['upload_id'], 'offset' => 0, 'path' => $invalid->phpFile('bad')]);
    import_rejects(fn() => $invalid->application->dispatch('source-upload-finish', ['upload_id' => $bad['upload_id']]), 'XZ');
    import_check(!is_dir($invalid->paths->runtimeDir . '/upload-' . $bad['upload_id']), 'Failed verification must clear only its own staging session');
    import_check(!is_file($invalid->paths->settingsFile), 'Failed import must not advertise a selected package');

    $wrongHash = $fixtures[] = new ImportFixture();
    $started = $wrongHash->application->dispatch('source-upload-begin', ['filename' => $wrongHash->filename, 'total' => strlen($wrongHash->package), 'checksum' => str_repeat('0', 32)]);
    $wrongHash->application->dispatch('source-upload-chunk', ['upload_id' => $started['upload_id'], 'offset' => 0, 'path' => $wrongHash->phpFile($wrongHash->package)]);
    import_rejects(fn() => $wrongHash->application->dispatch('source-upload-finish', ['upload_id' => $started['upload_id']]), 'MD5');
    import_check(!is_dir($wrongHash->paths->runtimeDir . '/upload-' . $started['upload_id']) && !is_file($wrongHash->paths->settingsFile), 'Checksum failure must clear the session without selecting a driver');

    $valid = $fixtures[] = new ImportFixture();
    $app = $valid->application;
    $app->dispatch('config-save', ['source' => ['provider' => 'gitlab', 'mode' => 'generic', 'repository' => 'https://git.example.test/group/driver', 'driver' => '595.58.03'],
        'credential' => ['type' => 'auto', 'token' => 'import-fixture-secret']]);
    $settingsBefore = $valid->store->settings();
    $credentialsBefore = file_get_contents($valid->paths->credentialsFile);
    $started = $app->dispatch('source-upload-begin', ['filename' => $valid->filename, 'total' => strlen($valid->package), 'checksum' => md5($valid->package)]);
    $app->dispatch('source-upload-chunk', ['upload_id' => $started['upload_id'], 'offset' => 0, 'path' => $valid->phpFile($valid->package)]);
    $result = $app->dispatch('source-upload-finish', ['upload_id' => $started['upload_id']]);
    import_check($result['ok'] && $result['package']['source'] === 'local' && $result['package']['provider'] === 'local', 'Verified local package must be selected as local');
    import_check($result['package']['checksum_verified'] === true, 'An independently supplied MD5 must be marked verified');
    import_check(is_file($result['package']['path']) && file_get_contents($result['package']['path']) === $valid->package, 'Imported cache must contain the original bytes');
    import_check(!is_dir($valid->paths->runtimeDir . '/upload-' . $started['upload_id']), 'Successful import must clear its staging session');
    import_check($valid->store->settings()['runtime'] === $settingsBefore['runtime'] && $valid->store->settings()['runtime']['enabled'] === false, 'Local import must preserve disabled runtime');
    import_check($valid->store->settings()['source'] === $settingsBefore['source'] && file_get_contents($valid->paths->credentialsFile) === $credentialsBefore, 'Local import must preserve remote source and credentials');
    import_check(!is_file($valid->paths->devicesFile) && !is_dir($valid->paths->path('/etc/vgpu_unlock')), 'Local import must not create devices or change active config');
    import_check(!array_filter($valid->commands, fn($argv) => !in_array($argv[0], ['xz', 'tar', 'modinfo'], true)), 'Local import only permits passive package checks');

    $single = $fixtures[] = new ImportFixture();
    $result = $single->application->dispatch('source-import', ['filename' => $single->filename, 'path' => $single->phpFile($single->package)]);
    import_check($result['ok'] && $result['package']['checksum_verified'] === false, 'No-MD5 single upload must clearly identify a locally computed checksum');
    import_check($single->store->settings()['selected_package']['provider'] === 'local', 'Single-upload fallback must select the cached package');
    echo 'Local import/upload: ' . $importChecks . " checks passed (mock package inspection; no GPU or network)\n";
} finally {
    foreach (array_reverse($fixtures) as $fixture) $fixture->close();
}
