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

/** A separate, bounded PHP child supplies the injected result, not pkgtools. */
function install_fault_child(string $mode, string $artifacts, callable $execute): array
{
    install_assert(in_array($mode, ['failure', 'timeout'], true), 'Invalid fault child mode');
    $scope = 'INJECTED post-upgrade classification: genuine pkgtools already exited 0; this is a separate controlled PHP child, not a pkgtools/GPU failure';
    if ($mode === 'failure') {
        $response = $execute(['integration-only-post-upgrade-failure'],
            [PHP_BINARY, '-n', '-r', 'fwrite(STDOUT, "controlled post-upgrade failure\\n"); exit(9);'], 5, null, $scope);
        install_assert($response['code'] === 9, 'Controlled failure child did not exit 9');
        return ['response' => $response, 'mode' => $mode, 'child_reaped' => true,
            'reap_method' => 'production Process normal proc_close after direct PHP child exit', 'scope' => $scope];
    }
    install_assert(function_exists('pcntl_waitpid'), 'pcntl_waitpid is required to reap the controlled timeout child');
    $pidFile = $artifacts . '/injected-timeout-child.json';
    $finished = $artifacts . '/injected-timeout-natural-finish';
    install_assert(!file_exists($pidFile) && !is_link($pidFile) && !file_exists($finished) && !is_link($finished), 'Fault child artifacts already exist');
    // No shell, descendants, signals handlers, driver files, or GPU calls.
    // Even a failed SIGTERM is bounded by this five-second sleep; waitpid below
    // must reap the direct child before the adapter can return to Installer.
    $program = <<<'PHP'
$status = (string)file_get_contents('/proc/self/status');
if (!preg_match('/^PPid:\s+([0-9]+)/m', $status, $parent)) exit(70);
$identity = json_encode(['pid' => getmypid(), 'ppid' => (int)$parent[1]], JSON_THROW_ON_ERROR);
if (file_put_contents($argv[1], $identity) !== strlen($identity)) exit(71);
fwrite(STDOUT, "controlled post-upgrade timeout child started\n");
usleep(5000000);
if (file_put_contents($argv[2], 'natural finish') === false) exit(72);
PHP;
    $started = microtime(true);
    $response = $execute(['integration-only-post-upgrade-timeout'],
        [PHP_BINARY, '-n', '-r', $program, $pidFile, $finished], 1, null, $scope);
    install_assert(is_file($pidFile) && !is_link($pidFile), 'Controlled timeout child did not record its identity');
    $identity = json_decode((string)file_get_contents($pidFile), true, 8, JSON_THROW_ON_ERROR);
    $pid = $identity['pid'] ?? 0;
    install_assert(is_int($pid) && $pid > 1 && ($identity['ppid'] ?? 0) === getmypid(), 'Timeout PID is not this adapter direct child');
    $status = 0;
    $waited = pcntl_waitpid($pid, $status, 0);
    clearstatcache();
    $absent = !is_dir('/proc/' . $pid);
    install_assert(($waited === $pid || $waited === -1) && $absent, 'Controlled timeout child was not reaped');
    install_assert($response['code'] === 124 && !file_exists($finished), 'Process did not terminate the controlled child before natural completion');
    if ($waited === $pid) install_assert(pcntl_wifsignaled($status) && pcntl_wtermsig($status) === 15, 'Controlled timeout child was not terminated by Process SIGTERM');
    return ['response' => $response, 'mode' => $mode, 'child_pid' => $pid, 'child_reaped' => true,
        'reap_method' => $waited === $pid ? 'explicit pcntl_waitpid after production Process SIGTERM' : 'Process resource already reaped child; waitpid ECHILD and namespace PID absent',
        'waitpid_result' => $waited, 'seconds_including_reap' => round(microtime(true) - $started, 3),
        'natural_finish_marker_absent' => true, 'scope' => $scope];
}

function install_verify_transaction(string $transaction, int $uid): array
{
    $directory = dirname($transaction);
    foreach ([$directory, $directory . '/files'] as $path) {
        $info = lstat($path);
        install_assert(!is_link($path) && is_dir($path) && ($info['mode'] & 0777) === 0700 && $info['uid'] === $uid,
            'Configuration transaction directory is not private');
    }
    $info = lstat($transaction);
    install_assert(!is_link($transaction) && ($info['mode'] & 0777) === 0600 && $info['uid'] === $uid, 'Configuration journal is not private');
    $receipt = json_decode((string)file_get_contents($transaction), true, 32, JSON_THROW_ON_ERROR);
    $backups = 0;
    foreach ($receipt['configurations'] as $record) {
        if (($record['kind'] ?? '') !== 'file') continue;
        install_assert(preg_match('#^files/[0-9]+$#D', $record['backup'] ?? '') === 1, 'Invalid transaction backup path');
        $path = $directory . '/' . $record['backup'];
        $info = lstat($path);
        install_assert(!is_link($path) && is_file($path) && ($info['mode'] & 0777) === 0600 && $info['uid'] === $uid &&
            $info['nlink'] === 1 && hash_file('sha256', $path) === $record['sha256'], 'Configuration backup hash or privacy differs');
        ++$backups;
    }
    install_assert($backups > 0, 'No protected configuration files were backed up');
    return ['receipt' => $receipt, 'backups_verified' => $backups];
}

$commands = [];
$result = ['ok' => false, 'scope' => 'production plugin Installer.boot, genuine pkgtools and passive tools in a fixture ROOT; not Unraid/GPU runtime evidence'];
$artifacts = null;
$safeArtifacts = false;
try {
    if (!in_array($argc, [5, 7], true)) throw new InvalidArgumentException('Usage: php -n install-driver.php PACKAGE.txz PRIVATE_ROOT HOST_TOOLS ARTIFACT_DIRECTORY [--fault-after-upgrade none|failure|timeout]');
    $faultAfterUpgrade = 'none';
    if ($argc === 7) {
        if ($argv[5] !== '--fault-after-upgrade' || !in_array($argv[6], ['none', 'failure', 'timeout'], true)) {
            throw new InvalidArgumentException('Invalid --fault-after-upgrade; choose none, failure, or timeout');
        }
        $faultAfterUpgrade = $argv[6];
    }
    [$script, $package, $requestedRoot, $tools, $artifacts] = array_slice($argv, 0, 5);
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
    install_assert($faultAfterUpgrade !== 'timeout' || function_exists('pcntl_waitpid'), 'pcntl_waitpid is required for the controlled timeout case');
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
    $injection = null;
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
    $runner = static function (array $requested, int $timeout = 30, ?string $stdin = null) use ($execute, &$commands, &$injection, $faultAfterUpgrade, $root, $tools, $kernel, $artifacts): array {
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
            if ($faultAfterUpgrade !== 'none') {
                install_assert($injection === null, 'Fault injection was invoked more than once');
                $injection = install_fault_child($faultAfterUpgrade, $artifacts, $execute);
                $injection['genuine_upgrade_returncode'] = $response['code'];
                install_json($artifacts . '/injected-fault.json', $injection);
                // Preserve the genuine command's recorded code/stdout/stderr.
                // Only this separate child result is passed back to Installer.
                return $injection['response'];
            }
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
    $transactionsBefore = glob($paths->runtimeDir . '/install-transactions/*/transaction.json') ?: [];
    $bootResult = $store->locked(fn() => $installer->boot($settings));
    $result += ['fixture_boot_id' => $boot, 'preflight' => $preflight, 'package' => $import['package']];
    $result['installer'] = $bootResult;
    $result['fault_after_upgrade'] = $faultAfterUpgrade;
    $result['injection'] = $injection;
    $expectedStatus = $faultAfterUpgrade === 'none' ? 'installed' : 'failed';
    install_assert(($bootResult['ok'] ?? false) === ($faultAfterUpgrade === 'none') && ($bootResult['status'] ?? '') === $expectedStatus,
        'Unexpected production boot result: ' . ($bootResult['message'] ?? 'unknown'));
    install_assert($installer->state()['status'] === $expectedStatus, 'Production installation state did not commit');
    install_assert(!is_file($paths->runtimeDir . '/owner.json') && !is_dir($root . '/sys/module/nvidia'), 'Hardware/runtime activation occurred');
    $transactions = array_values(array_diff(glob($paths->runtimeDir . '/install-transactions/*/transaction.json') ?: [], $transactionsBefore));
    install_assert(count($transactions) === 1, 'Expected exactly one new configuration transaction');
    $verifiedTransaction = install_verify_transaction($transactions[0], lstat($root)['uid']);
    $expectedJournal = match ($faultAfterUpgrade) { 'failure' => 'failed_restored', 'timeout' => 'completion_unknown', default => 'complete' };
    install_assert($verifiedTransaction['receipt']['status'] === $expectedJournal, 'Unexpected configuration journal outcome');
    $result['transaction'] = $transactions[0];
    $result['transaction_status'] = $expectedJournal;
    $result['transaction_backups_verified'] = $verifiedTransaction['backups_verified'];
    if ($faultAfterUpgrade === 'none') {
        install_assert(is_file($root . '/etc/ld.so.cache') && filesize($root . '/etc/ld.so.cache') > 0 &&
            is_file($root . '/lib/modules/' . $kernel . '/modules.dep'), 'Real passive linker/module outputs missing');
    } else {
        install_assert(is_array($injection) && $injection['child_reaped'] === true && $injection['genuine_upgrade_returncode'] === 0,
            'Expected a reaped, separate injected child after successful genuine pkgtools');
        $commandsBeforeRetry = count($commands);
        $stateBeforeRetry = $installer->state();
        $retry = $store->locked(fn() => $installer->boot($settings));
        install_assert(($retry['ok'] ?? false) === false && ($retry['status'] ?? '') === 'already_attempted' &&
            count($commands) === $commandsBeforeRetry && $installer->state() === $stateBeforeRetry, 'Same-boot retry was not blocked before new commands');
        $result['same_boot_retry'] = $retry;
        $result['same_boot_retry_command_count_unchanged'] = true;
        $result['package_payload_rollback_claimed'] = false;
    }
    install_assert(hash_file('sha256', $package) === $inputHash, 'Input archive changed');
    $result['ok'] = true;
    $result['checks'] = ['production local import and inspectCached', 'production Runtime.installationPreflight on fixture proc/sys',
        'production Installer.boot and private hash-verified InstallConfiguration backups', 'genuine upgradepkg/installpkg/removepkg',
        'real ldconfig -n private-library link processing, compatibility aliases unchanged', 'no native activation or Runtime.ensure'];
    if ($faultAfterUpgrade === 'none') $result['checks'] = array_merge($result['checks'], [
        'actual installed nvidia-ctk writes only temporary private Docker JSON', 'real depmod -b private ROOT',
        'real ldconfig -X with private cache', 'actual modinfo of all installed module files']);
    else $result['checks'] = array_merge($result['checks'], ['separate controlled PHP child result injected after real upgrade success',
        'controlled child terminated/reaped before Installer receives injected result', 'same-boot retry blocked without any new command',
        'configuration outcome checked; no claim of package payload rollback']);
    $result['complete_transactions'] = $faultAfterUpgrade === 'none' ? $transactions : [];
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
