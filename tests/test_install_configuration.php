<?php
declare(strict_types=1);
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';

use Vgpu\InstallConfiguration;
use Vgpu\Paths;

$configurationChecks = 0;
function configuration_check(bool $condition, string $message): void
{
    global $configurationChecks;
    ++$configurationChecks;
    if (!$condition) throw new RuntimeException($message);
}

final class ConfigurationFixture
{
    public string $root;
    public Paths $paths;
    public InstallConfiguration $transaction;
    public array $calls = [];
    public string $behavior = '';
    public const PACKAGE = ['filename' => 'nvidia-595.58.03-6.18.58-Unraid-2.txz', 'driver' => '595.58.03', 'kernel' => '6.18.58-Unraid', 'build' => 2, 'md5' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/vgpu-config-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        $this->paths = new Paths($this->root);
        $this->transaction = new InstallConfiguration($this->paths, $this->run(...));
    }
    public function write(string $path, string $contents, int $mode = 0600): void
    {
        $path = $this->paths->path($path);
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        if (is_file($path) && !is_link($path)) chmod($path, 0600);
        if (file_put_contents($path, $contents) !== strlen($contents)) throw new RuntimeException('Cannot write test fixture');
        chmod($path, $mode);
    }
    public function read(string $path): string { return file_get_contents($this->paths->path($path)); }
    public function run(array $argv, int $timeout, ?string $stdin): array
    {
        $this->calls[] = $argv;
        configuration_check(array_slice($argv, 0, 4) === ['nvidia-ctk', 'runtime', 'configure', '--runtime=docker'] && count($argv) === 5 && $timeout === 30 && $stdin === null, 'Only NVIDIA runtime configuration is run');
        $temporary = substr($argv[4], strlen('--config='));
        configuration_check(str_starts_with($temporary, $this->root . '/') && str_contains(basename($temporary), '.uvm-docker-'), 'Staged configuration stays inside fixture root');
        if ($this->behavior === 'failure') return ['code' => 1, 'stdout' => 'SECRET IN TOOL OUTPUT', 'stderr' => 'SECRET IN ERROR'];
        $object = json_decode(file_get_contents($temporary), false, 64, JSON_THROW_ON_ERROR);
        $object->runtimes ??= new stdClass();
        $object->runtimes->nvidia = (object)['path' => '/usr/bin/nvidia-container-runtime', 'runtimeArgs' => []];
        if ($this->behavior === 'unrelated') $object->debug = false;
        if ($this->behavior === 'type-change') $object->debug = 1;
        if ($this->behavior === 'reorder') $object = (object)array_reverse(get_object_vars($object), true);
        file_put_contents($temporary, json_encode($object));
        return ['code' => 0];
    }
    public function rejects(callable $call, string $message): void
    {
        try { $call(); } catch (Throwable) { configuration_check(true, $message); return; }
        configuration_check(false, $message);
    }
    public function close(): void
    {
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($walk as $entry) $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->root);
    }
}

$configurationCases = [];
$configurationCases['all existing files and pending templates survive overwrite'] = function (ConfigurationFixture $f): void {
    $original = '{"registry-mirrors":["https://keep.invalid"],"debug":true,"runtimes":{"custom":{"path":"/usr/bin/custom"}},"log-opts":{"max-size":"20m"}}';
    $f->write('/etc/docker/daemon.json', $original, 0640);
    $paths = ['/etc/nvidia-container-runtime/config.toml', '/etc/nvidia/gridd.conf', '/etc/vgpu_unlock/config.toml', '/etc/vgpu_unlock/profile_override.toml', '/etc/modprobe.d/nvidia.conf'];
    foreach ($paths as $path) {
        $f->write($path, 'ORIGINAL ' . $path, 0640);
        $f->write($path . '.new', 'PENDING ' . $path, 0600);
    }
    $f->write('/etc/nvidia/ClientConfigToken/subdir/client.tok', 'PRIVATE LICENSE TOKEN', 0400);
    $transaction = $f->transaction->begin(ConfigurationFixture::PACKAGE);
    $f->write('/etc/docker/daemon.json', '{}');
    foreach ($paths as $path) { $f->write($path, 'changed'); $f->write($path . '.new', 'changed new'); }
    $f->write('/etc/nvidia/ClientConfigToken/subdir/client.tok', 'changed license');
    $f->transaction->restore();
    foreach ($paths as $path) {
        configuration_check($f->read($path) === 'ORIGINAL ' . $path, 'Existing content restored');
        configuration_check($f->read($path . '.new') === 'PENDING ' . $path, 'Pending candidate retained');
        configuration_check((fileperms($f->paths->path($path)) & 0777) === 0640, 'Existing file mode restored');
    }
    configuration_check($f->read('/etc/nvidia/ClientConfigToken/subdir/client.tok') === 'PRIVATE LICENSE TOKEN', 'Nested licensing token preserved');
    configuration_check((fileperms($f->paths->path('/etc/nvidia/ClientConfigToken/subdir/client.tok')) & 0777) === 0400, 'Licensing token permissions preserved');
    $f->transaction->registerDocker();
    $docker = json_decode($f->read('/etc/docker/daemon.json'), true);
    configuration_check($docker['debug'] && $docker['registry-mirrors'] === ['https://keep.invalid'] && isset($docker['runtimes']['custom'], $docker['runtimes']['nvidia']), 'Only NVIDIA runtime added to Docker settings');
    configuration_check((fileperms($f->paths->path('/etc/docker/daemon.json')) & 0777) === 0640, 'Docker mode retained');
    $f->transaction->complete();
    $journal = file_get_contents($transaction . '/transaction.json');
    configuration_check(json_decode($journal, true)['status'] === 'complete', 'Completed transaction recorded');
    configuration_check(!str_contains($journal, 'PRIVATE LICENSE TOKEN') && !str_contains($journal, 'PENDING '), 'Journal never includes configuration contents');
    configuration_check((fileperms($transaction) & 0777) === 0700, 'Transaction is private');
    foreach (glob($transaction . '/files/*') as $file) configuration_check((fileperms($file) & 0777) === 0600, 'Each backup copy is private');
};
$configurationCases['configuration and license symlinks retain targets and contents'] = function (ConfigurationFixture $f): void {
    $f->write('/boot/config/nvidia-vgpu/profile_override.toml', 'CUSTOM PROFILE');
    $f->write('/boot/config/nvidia-vgpu/license.tok', 'PRIVATE LICENSE');
    $f->write('/etc/vgpu_unlock/placeholder', '');
    $f->write('/etc/nvidia/ClientConfigToken/placeholder', '');
    $profile = $f->paths->path('/etc/vgpu_unlock/profile_override.toml');
    $token = $f->paths->path('/etc/nvidia/ClientConfigToken/client.tok');
    symlink('/boot/config/nvidia-vgpu/profile_override.toml', $profile);
    symlink($f->paths->path('/boot/config/nvidia-vgpu/license.tok'), $token);
    $f->transaction->begin(ConfigurationFixture::PACKAGE);
    unlink($profile);
    file_put_contents($profile, 'package copy');
    unlink($token);
    file_put_contents($token, 'package token');
    $f->write('/boot/config/nvidia-vgpu/profile_override.toml', 'overwritten target');
    $f->write('/boot/config/nvidia-vgpu/license.tok', 'overwritten token');
    $f->transaction->restore();
    configuration_check(is_link($profile) && readlink($profile) === '/boot/config/nvidia-vgpu/profile_override.toml', 'Virtual-root profile link restored');
    configuration_check(is_link($token) && readlink($token) === $f->paths->path('/boot/config/nvidia-vgpu/license.tok'), 'License link restored');
    configuration_check($f->read('/boot/config/nvidia-vgpu/profile_override.toml') === 'CUSTOM PROFILE', 'Profile link target contents retained');
    configuration_check($f->read('/boot/config/nvidia-vgpu/license.tok') === 'PRIVATE LICENSE', 'License link target contents retained');
};
$configurationCases['fresh defaults are retained on success and removed on rollback'] = function (ConfigurationFixture $f): void {
    $f->transaction->begin(ConfigurationFixture::PACKAGE);
    $f->write('/etc/vgpu_unlock/config.toml', 'new default');
    $f->write('/etc/nvidia/gridd.conf.new', 'new candidate');
    $f->transaction->restore();
    configuration_check($f->read('/etc/vgpu_unlock/config.toml') === 'new default', 'Fresh package defaults retained');
    $f->transaction->registerDocker();
    $f->transaction->complete();
    configuration_check(isset(json_decode($f->read('/etc/docker/daemon.json'), true)['runtimes']['nvidia']), 'Docker registered for fresh configuration');
    $f->transaction->fail();
    configuration_check(!file_exists($f->paths->path('/etc/docker/daemon.json')) && !file_exists($f->paths->path('/etc/vgpu_unlock/config.toml')), 'Failure restores previously absent configuration files');
};
$configurationCases['invalid Docker JSON fails before installer preparation'] = function (ConfigurationFixture $f): void {
    $f->write('/etc/docker/daemon.json', 'INVALID SECRET');
    $f->rejects(fn() => $f->transaction->begin(ConfigurationFixture::PACKAGE), 'Invalid Docker JSON rejected');
    configuration_check($f->calls === [] && $f->read('/etc/docker/daemon.json') === 'INVALID SECRET', 'No command or config write before valid snapshot');
};
$configurationCases['parent links and escaping final links are refused'] = function (ConfigurationFixture $f): void {
    $f->write('/etc/placeholder', '');
    symlink('/tmp/not-in-installation-root', $f->paths->path('/etc/vgpu_unlock'));
    $f->rejects(fn() => $f->transaction->begin(ConfigurationFixture::PACKAGE), 'Linked configuration parent rejected');
    configuration_check($f->calls === [], 'Unsafe parent never invokes a command');
};
$configurationCases['final link cannot escape temporary root'] = function (ConfigurationFixture $f): void {
    $f->write('/etc/vgpu_unlock/placeholder', '');
    symlink('/tmp/outside-installation-config', $f->paths->path('/etc/vgpu_unlock/config.toml'));
    $f->rejects(fn() => $f->transaction->begin(ConfigurationFixture::PACKAGE), 'External absolute final link rejected');
};
$configurationCases['configuration link loop is bounded'] = function (ConfigurationFixture $f): void {
    $f->write('/etc/vgpu_unlock/placeholder', '');
    symlink('config.toml', $f->paths->path('/etc/vgpu_unlock/config.toml'));
    $f->rejects(fn() => $f->transaction->begin(ConfigurationFixture::PACKAGE), 'Link loop rejected');
};
$configurationCases['multi-hop links are refused before installation'] = function (ConfigurationFixture $f): void {
    $f->write('/etc/vgpu_unlock/target.toml', 'keep');
    symlink('target.toml', $f->paths->path('/etc/vgpu_unlock/intermediate.toml'));
    symlink('intermediate.toml', $f->paths->path('/etc/vgpu_unlock/config.toml'));
    $f->rejects(fn() => $f->transaction->begin(ConfigurationFixture::PACKAGE), 'Intermediate link cannot remain outside the snapshot');
    configuration_check($f->calls === [] && $f->read('/etc/vgpu_unlock/target.toml') === 'keep', 'Multi-hop path rejected without installer actions');
};
$configurationCases['standard runtime directory alias stays inside installation root'] = function (ConfigurationFixture $f): void {
    mkdir($f->root . '/var', 0700);
    mkdir($f->root . '/run', 0700);
    symlink('/run', $f->root . '/var/run');
    $f->write('/etc/vgpu_unlock/config.toml', 'keep');
    $transaction = $f->transaction->begin(ConfigurationFixture::PACKAGE);
    configuration_check(str_starts_with($transaction, $f->root . '/run/unraid-vgpu-manager/install-transactions/'), 'Standard absolute /run alias resolves inside test root');
    configuration_check((fileperms($transaction) & 0777) === 0700, 'Canonical transaction remains private');
    $f->write('/etc/vgpu_unlock/config.toml', 'changed');
    $f->transaction->restore();
    configuration_check($f->read('/etc/vgpu_unlock/config.toml') === 'keep', 'Restore works through standard runtime alias');
};
$configurationCases['nonstandard runtime link is refused'] = function (ConfigurationFixture $f): void {
    mkdir($f->root . '/var', 0700);
    symlink('/tmp/outside-runtime', $f->root . '/var/run');
    $f->rejects(fn() => $f->transaction->begin(ConfigurationFixture::PACKAGE), 'Runtime symlink cannot redirect transactions outside fixture');
    configuration_check($f->calls === [], 'Unsafe runtime path invokes no commands');
};
$configurationCases['hardlinked or oversized configurations are refused'] = function (ConfigurationFixture $f): void {
    $f->write('/etc/vgpu_unlock/config.toml', 'original');
    link($f->paths->path('/etc/vgpu_unlock/config.toml'), $f->root . '/second-link');
    $f->rejects(fn() => $f->transaction->begin(ConfigurationFixture::PACKAGE), 'Hardlinked configuration rejected');
};
$configurationCases['bounded file sizes are enforced'] = function (ConfigurationFixture $f): void {
    $f->write('/etc/vgpu_unlock/config.toml', str_repeat('x', 1048577));
    $f->rejects(fn() => $f->transaction->begin(ConfigurationFixture::PACKAGE), 'Oversized configuration rejected');
};
$configurationCases['Docker registration cannot alter unrelated settings'] = function (ConfigurationFixture $f): void {
    $original = '{"debug":true,"registry-mirrors":["https://keep.invalid"]}';
    $f->write('/etc/docker/daemon.json', $original);
    $f->transaction->begin(ConfigurationFixture::PACKAGE);
    $f->behavior = 'unrelated';
    $f->rejects(fn() => $f->transaction->registerDocker(), 'Unrelated Docker modification rejected');
    configuration_check($f->read('/etc/docker/daemon.json') === $original, 'Failed staged validation leaves original JSON');
    configuration_check(glob($f->paths->path('/etc/docker/.uvm-docker-*')) === [], 'Staged files cleaned');
};
$configurationCases['Docker setting types are strict while object ordering is ignored'] = function (ConfigurationFixture $f): void {
    $original = '{"debug":true,"registry-mirrors":["https://keep.invalid"]}';
    $f->write('/etc/docker/daemon.json', $original);
    $f->transaction->begin(ConfigurationFixture::PACKAGE);
    $f->behavior = 'type-change';
    $f->rejects(fn() => $f->transaction->registerDocker(), 'Boolean-to-integer Docker mutation rejected');
    configuration_check($f->read('/etc/docker/daemon.json') === $original, 'Type mutation never replaces original JSON');
    $f->behavior = 'reorder';
    $f->transaction->registerDocker();
    $f->transaction->complete();
    configuration_check(json_decode($f->read('/etc/docker/daemon.json'))->debug === true, 'Equivalent reordered object is accepted');
};
$configurationCases['tool failure and later failure restore exact original JSON'] = function (ConfigurationFixture $f): void {
    $original = "{\n  \"debug\": true\n}\n";
    $f->write('/etc/docker/daemon.json', $original);
    $f->transaction->begin(ConfigurationFixture::PACKAGE);
    $f->behavior = 'failure';
    try { $f->transaction->registerDocker(); configuration_check(false, 'Tool failure expected'); }
    catch (Throwable $error) { configuration_check(!str_contains($error->getMessage(), 'SECRET'), 'Tool output not included in error'); }
    configuration_check($f->read('/etc/docker/daemon.json') === $original, 'Tool failure preserves exact original JSON');
    $f->behavior = '';
    $f->transaction->registerDocker();
    configuration_check($f->read('/etc/docker/daemon.json') !== $original, 'Registration modifies only the staged JSON on success');
    $result = $f->transaction->fail();
    configuration_check(str_contains($result, 'Original configurations restored') && $f->read('/etc/docker/daemon.json') === $original, 'Post-install rollback restores original byte formatting');
};
$configurationCases['new wrong-type configuration fails validation'] = function (ConfigurationFixture $f): void {
    $f->transaction->begin(ConfigurationFixture::PACKAGE);
    mkdir($f->paths->path('/etc/vgpu_unlock/config.toml'), 0700, true);
    $f->rejects(fn() => $f->transaction->restore(), 'Fresh configuration cannot be directory');
};
$configurationCases['recovery refuses a newly redirected parent'] = function (ConfigurationFixture $f): void {
    $f->write('/etc/vgpu_unlock/config.toml', 'keep');
    $f->transaction->begin(ConfigurationFixture::PACKAGE);
    unlink($f->paths->path('/etc/vgpu_unlock/config.toml'));
    rmdir($f->paths->path('/etc/vgpu_unlock'));
    symlink('/tmp/outside-config-recovery', $f->paths->path('/etc/vgpu_unlock'));
    configuration_check(str_contains($f->transaction->fail(), 'requires attention'), 'Unsafe recovery path keeps backup for manual recovery');
    configuration_check(is_file($f->transaction->path() . '/transaction.json'), 'Recovery metadata retained');
};

foreach ($configurationCases as $name => $case) {
    $fixture = new ConfigurationFixture();
    try { $case($fixture); echo 'PASS configuration: ' . $name . "\n"; }
    finally { $fixture->close(); }
}
echo 'Install configuration: ' . count($configurationCases) . ' scenarios / ' . $configurationChecks . " checks passed (temporary roots and mock nvidia-ctk only)\n";
