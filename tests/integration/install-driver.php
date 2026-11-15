<?php
declare(strict_types=1);

// Production Installer.boot in an explicitly marked, private bubblewrap ROOT.
// There are no fake pkgtools, modinfo, depmod, ldconfig, or nvidia-ctk commands.
// /proc and /sys below ROOT are fixtures, not a real Unraid/GPU environment.
require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';

use Vgpu\Download;
use Vgpu\Installer;
use Vgpu\PackageIdentity;
use Vgpu\Paths;
use Vgpu\Process;
use Vgpu\Runtime;
use Vgpu\Store;

function install_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function install_json(string $path, array $value): void
{
    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    install_assert(file_put_contents($path, $encoded) === strlen($encoded), 'Cannot write integration artifact');
}

function install_fixture(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    install_assert(!is_link($path) && file_put_contents($path, $contents) === strlen($contents), 'Cannot write private fixture');
}

function install_aliases(string $root): array
{
    $result = [];
    foreach (['libEGL_nvidia.so.0', 'libcuda.so.1', 'libnvidia-ml.so.1'] as $name) {
        $path = $root . '/usr/lib64/' . $name;
        if (!is_link($path)) continue;
        $result[$name] = ['target' => readlink($path), 'sha256' => hash_file('sha256', $path),
                          'inode' => stat($path)['ino'], 'nlink' => stat($path)['nlink']];
    }
    return $result;
}

$commands = [];
$result = ['ok' => false, 'scope' => 'production plugin Installer.boot, genuine pkgtools and passive tools in a fixture ROOT; not Unraid/GPU runtime evidence'];
$artifacts = null;
$safeArtifacts = false;
try {
    if ($argc !== 5) throw new InvalidArgumentException('Usage: php -n install-driver.php PACKAGE.txz PRIVATE_ROOT HOST_TOOLS ARTIFACT_DIRECTORY');
    [$script, $package, $requestedRoot, $tools, $artifacts] = $argv;
    $root = realpath($requestedRoot);
    install_assert($root !== false && $root === $requestedRoot && !is_link($root) && basename($root) === 'root' &&
        str_starts_with(basename(dirname($root)), 'pkgtools-lifecycle.'), 'An exact private lifecycle ROOT is required');
    $work = dirname($root);
    install_assert(getenv('VGPU_REAL_PKGTOOLS_ISOLATED') === '1' && getenv('ROOT') === $root,
        'Only run through the read-only-host bubblewrap integration harness');
    install_assert(hash_file('sha256', '/sbin/upgradepkg') === 'cb7890a88f09fbe7ff0709a44f739bdeb339a235350fcd907514a23268f731c2',
        'Real, pinned Slackware upgradepkg is required');
    install_assert(realpath($tools) === $work . '/host-tools' && realpath($artifacts) === $artifacts &&
        str_starts_with($artifacts, $work . '/plugin-cases/') && is_dir($artifacts), 'Private tools/artifact paths are required');
    $safeArtifacts = true;
    install_assert(is_file($tools . '/modinfo') && is_file($tools . '/depmod') && is_file($tools . '/ldconfig-real'), 'Real passive host tools are required');
    install_assert(realpath($package) === $package && is_file($package) && !is_link($package), 'Regular absolute TXZ required');
    $identity = PackageIdentity::parse(basename($package));
    $inputHash = hash_file('sha256', $package);
    $kernel = $identity['kernel'];
    $oldBoot = '11111111-1111-4111-8111-111111111111';
    $hex = bin2hex(random_bytes(16));
    $boot = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    install_fixture($root . '/proc/sys/kernel/osrelease', $kernel . "\n");
    install_fixture($root . '/proc/sys/kernel/random/boot_id', $boot . "\n");
    if (!is_dir($root . '/sys')) mkdir($root . '/sys', 0700);
    install_assert(!is_dir($root . '/sys/module/nvidia'), 'Test root unexpectedly contains a loaded NVIDIA fixture');
    $paths = new Paths($root);
    $store = new Store($paths);
    $process = new Process();
    $execute = static function (array $requested, array $executed, int $timeout, ?string $stdin, string $scope = 'real-command') use ($process, &$commands, $artifacts): array {
        $index = count($commands);
        $start = microtime(true);
        $response = $process($executed, $timeout, $stdin);
        file_put_contents($artifacts . '/command-' . $index . '.stdout.log', (string)($response['stdout'] ?? ''));
        file_put_contents($artifacts . '/command-' . $index . '.stderr.log', (string)($response['stderr'] ?? ''));
        $commands[] = ['requested' => $requested, 'executed' => $executed, 'scope' => $scope,
                       'code' => $response['code'], 'seconds' => round(microtime(true) - $start, 3),
                       'stdout_log' => 'command-' . $index . '.stdout.log', 'stderr_log' => 'command-' . $index . '.stderr.log'];
        install_json($artifacts . '/commands.json', $commands);
        return $response;
    };
    $runner = static function (array $requested, int $timeout = 30, ?string $stdin = null) use ($execute, &$commands, $root, $tools, $kernel, $artifacts): array {
        $executed = $requested;
        switch ($requested[0]) {
            case 'tar':
            case 'xz':
                break;
            case 'modinfo':
                install_assert(count($requested) === 4 && $requested[1] === '-F' &&
                    in_array($requested[2], ['version', 'vermagic'], true) && str_starts_with($requested[3], $root . '/'),
                    'Only passive modinfo of a private module file is allowed');
                $executed[0] = $tools . '/modinfo';
                break;
            case 'upgradepkg':
                install_assert(count($requested) === 4 && array_slice($requested, 1, 2) === ['--install-new', '--reinstall'], 'Unexpected pkgtools arguments');
                $executed[0] = '/sbin/upgradepkg';
                break;
            case 'nvidia-ctk':
                install_assert(count($requested) === 5 && array_slice($requested, 1, 3) === ['runtime', 'configure', '--runtime=docker'] &&
                    str_starts_with($requested[4], '--config=' . $root . '/'), 'Only private-file Docker registration is allowed');
                $executed[0] = $root . '/usr/bin/nvidia-ctk';
                install_assert(is_executable($executed[0]), 'Actual installed nvidia-ctk is missing');
                break;
            case 'depmod':
                install_assert($requested === ['depmod', '-a', $kernel], 'Unexpected depmod invocation');
                $executed = [$tools . '/depmod', '-b', $root, '-a', $kernel];
                break;
            case 'ldconfig':
                install_assert($requested === ['ldconfig', '-X'], 'Contract must use ldconfig -X');
                // Without privileged chroot, this cache contains private-root
                // paths (and host trusted directories). It is test evidence of
                // a real -X/cache write, not a bootable target cache or ABI test.
                install_fixture($root . '/etc/ld.so.conf', $root . "/usr/lib64\n" . $root . "/usr/lib\n");
                $executed = [$tools . '/ldconfig-real', '-X', '-C', $root . '/etc/ld.so.cache', '-f', $root . '/etc/ld.so.conf'];
                break;
            default:
                $commands[] = ['requested' => $requested, 'scope' => 'denied-notification-or-hardware-operation'];
                install_json($artifacts . '/commands.json', $commands);
                throw new RuntimeException('No notification, service, GPU or module-loading command is allowed in this integration test');
        }
        $response = $execute($requested, $executed, $timeout, $stdin);
        if ($requested[0] === 'upgradepkg' && $response['code'] === 0) {
            $before = install_aliases($root);
            // Real pkgtools with nonempty ROOT skips its implicit ldconfig.
            // Exercise real link processing separately and only on private
            // libraries. This is explicitly not an Unraid ROOT='' execution.
            $implicit = [$tools . '/ldconfig-real', '-n', $root . '/usr/lib64', $root . '/usr/lib'];
            $simulation = $execute(['integration-only-implicit-ldconfig'], $implicit, 60, null, 'private-library link-processing simulation; pkgtools ROOT remains nonempty');
            $after = install_aliases($root);
            install_json($artifacts . '/library-aliases.json', ['before' => $before, 'after' => $after,
                'unchanged' => $before === $after, 'scope' => 'real ldconfig -n on private libraries only, not the full Unraid linker environment']);
            install_assert($simulation['code'] === 0 && $before && $before === $after, 'Private implicit ldconfig changed a compatibility alias');
        }
        return $response;
    };
    $download = new Download($paths, $runner);
    $import = $download->importLocal($package, basename($package), $package . '.md5', $kernel);
    install_assert(($import['ok'] ?? false) === true, 'Production local import failed');
    $settings = Store::defaults();
    $settings['driver'] = ['enabled' => true, 'enabled_boot_id' => $oldBoot];
    // This bypasses Installer.activateNative; Runtime.ensure is never called.
    // It does NOT activate the GPU runtime merely by setting this fixture flag.
    $settings['runtime']['enabled'] = true;
    $settings['selected_package'] = $import['package'] + ['saved_boot_id' => $oldBoot];
    $runtime = new Runtime($paths, $runner);
    $preflight = $runtime->installationPreflight();
    install_assert(($preflight['ok'] ?? false) === true, 'Production fixture preflight failed');
    $installer = new Installer($store, $download, $runtime, $runner);
    $bootResult = $store->locked(fn() => $installer->boot($settings));
    $result += ['fixture_boot_id' => $boot, 'preflight' => $preflight, 'package' => $import['package']];
    $result['installer'] = $bootResult;
    install_assert(($bootResult['ok'] ?? false) === true && $bootResult['status'] === 'installed', 'Production boot installation failed: ' . ($bootResult['message'] ?? 'unknown'));
    install_assert($installer->state()['status'] === 'installed', 'Production install state did not commit');
    install_assert(!is_file($paths->runtimeDir . '/owner.json') && !is_dir($root . '/sys/module/nvidia'), 'Hardware/runtime activation occurred');
    install_assert(is_file($root . '/etc/ld.so.cache') && filesize($root . '/etc/ld.so.cache') > 0 &&
        is_file($root . '/lib/modules/' . $kernel . '/modules.dep'), 'Real passive linker/module outputs missing');
    $transactions = glob($paths->runtimeDir . '/install-transactions/*/transaction.json') ?: [];
    $complete = [];
    foreach ($transactions as $transaction) {
        $receipt = json_decode((string)file_get_contents($transaction), true, 32, JSON_THROW_ON_ERROR);
        if (($receipt['status'] ?? '') === 'complete') $complete[] = $transaction;
    }
    install_assert((bool)$complete, 'No production configuration transaction completed');
    install_assert(hash_file('sha256', $package) === $inputHash, 'Input archive changed');
    $result['ok'] = true;
    $result['checks'] = ['production local import and inspectCached', 'production Runtime.installationPreflight on fixture proc/sys',
        'production Installer.boot and InstallConfiguration transaction', 'genuine upgradepkg/installpkg/removepkg',
        'actual installed nvidia-ctk writes only temporary private Docker JSON', 'real depmod -b private ROOT',
        'real ldconfig -X with private cache', 'real ldconfig -n private-library link processing, compatibility aliases unchanged',
        'actual modinfo of all installed module files', 'no native activation or Runtime.ensure'];
    $result['complete_transactions'] = $complete;
    $result['commands'] = $commands;
    install_json($artifacts . '/result.json', $result);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    $result['error'] = $error->getMessage();
    $result['commands'] = $commands;
    if ($safeArtifacts) install_json($artifacts . '/result.json', $result);
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
