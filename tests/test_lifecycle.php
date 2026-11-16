<?php
declare(strict_types=1);
require_once __DIR__ . '/native_boot_fixture.php';
function lifecycle_check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$cases = [];
$cases['running build3 remains untouched when build2 is selected until the next native boot'] = function (NativeBootFixture $f): void {
    $two = $f->archive(2); $three = $f->archive(3); $f->select($three); $f->reboot();
    lifecycle_check($f->app->dispatch('boot')['ok'], 'First native build3 boot must become ready');
    $module = $f->paths->path('/lib/modules/6.18.58-Unraid/kernel/drivers/video/nvidia.ko');
    $hash = hash_file('sha256', $module);
    $before = count(array_filter($f->calls, fn($argv) => $argv[0] === 'env'));
    $f->select($two);
    lifecycle_check(hash_file('sha256', $module) === $hash && is_link($f->paths->path('/sys/bus/mdev/devices/' . $f->device['uuid'])), 'Selection cannot touch the running module or UUID');
    lifecycle_check(count(array_filter($f->calls, fn($argv) => $argv[0] === 'env')) === $before, 'Selection cannot restart GPU services');
    lifecycle_check($f->app->dispatch('boot')['code'] === 'pending_reboot', 'A same-boot build change cannot be hot-applied');
    $f->reboot(NativeBootFixture::THIRD);
    lifecycle_check($f->nativeInstalled === [$two['filename']] && $f->app->dispatch('boot')['ok'], 'Next Unraid boot must install and initialize ONLY build2');
    lifecycle_check(hash_file('sha256', $module) !== $hash && is_file($three['path']), 'Native switch updates installed bytes, not old cached builds');
};
$cases['upgrade preparation stages one target-kernel build while retaining current-kernel selection'] = function (NativeBootFixture $f): void {
    $settings = $f->store->settings();
    $settings['source'] = Vgpu\Store::validateSource(['url' => 'https://github.com/example/drivers']);
    $f->store->saveSettings($settings);
    $current = $f->archive(3); $future = $f->archive(2, '6.18.60-Unraid'); $f->select($current);
    $settings = $f->store->settings();
    $future['source'] = $settings['source']['repository']; $future['provider'] = 'github';
    $prepared = ['status' => 'ready', 'target_kernel' => $future['kernel'], 'package' => $future, 'prepared_boot_id' => NativeBootFixture::FIRST,
        'source_id' => Vgpu\Store::sourceIdentity($settings['source']), 'source_fingerprint' => Vgpu\Upgrade::sourceFingerprint($settings['source'])];
    $f->store->write($f->paths->configDir . '/upgrade-state.json', $prepared);
    $f->native->sync($settings, $prepared);
    lifecycle_check(count($f->native->selections()) === 2, 'Current and upgrade kernels each retain one native package');
    $f->reboot(kernel: '6.18.60-Unraid');
    lifecycle_check($f->nativeInstalled === [$future['filename']] && $f->app->dispatch('boot')['ok'], 'Upgraded kernel must use its own prepared build');
    lifecycle_check(is_file($current['path']) && $f->store->settings()['selected_package']['filename'] === $current['filename'], 'Upgrade preparation does not overwrite the current-kernel choice/cache');
};
$cases['Unraid installs, plugin verifies, then initializes original UUID exactly once'] = function (NativeBootFixture $f): void {
    $p = $f->archive(); $f->select($p); $f->reboot();
    $result = $f->app->dispatch('boot');
    lifecycle_check($result['ok'] && $result['code'] === 'ready', 'Normal native lifecycle failed: ' . json_encode($result));
    lifecycle_check(is_link($f->paths->path('/sys/bus/mdev/devices/' . $f->device['uuid'])), 'Original saved UUID must be restored');
    lifecycle_check(!$f->store->settings()['pending_restart'], 'Successful initialization clears pending settings');
    $before = count(array_filter($f->calls, fn($argv) => $argv[0] === 'env'));
    lifecycle_check($f->app->dispatch('boot')['ok'], 'Later .plg boot remains idempotent');
    lifecycle_check(count(array_filter($f->calls, fn($argv) => $argv[0] === 'env')) === $before, 'Repeated boot must not restart manager');
    lifecycle_check(file_get_contents($f->paths->path('/etc/rc.d/rc.modules')) === 'native rc.modules sentinel: NEVER MODIFIED', 'Native rc.modules must never change');
    lifecycle_check(file_get_contents($f->paths->path('/etc/rc.d/rc.local')) === 'native rc.local sentinel: NEVER MODIFIED', 'Native rc.local must never change');
    lifecycle_check(count(scandir($f->paths->path('/etc/rc.d'))) === 4, 'No extra system startup hook may be created');
};
$cases['loaded native module is compatible with initialization, not an endless reboot request'] = function (NativeBootFixture $f): void {
    $p = $f->archive(); $f->select($p); $f->reboot(loaded: true);
    $result = $f->app->dispatch('boot');
    lifecycle_check($result['ok'], 'Native loaded module blocked initialization: ' . json_encode($result));
};
$cases['GPU client before plugin entrypoint is reported without stopping or taking over'] = function (NativeBootFixture $f): void {
    $p = $f->archive(); $f->select($p); $f->reboot(loaded: true);
    $f->process(700, 'gpu-client'); symlink('/dev/nvidia0', $f->paths->path('/proc/700/fd/3'));
    $result = $f->app->dispatch('boot');
    lifecycle_check(!$result['ok'] && $result['code'] === 'gpu_busy', 'Existing GPU handles must remain protected');
    lifecycle_check(!array_filter($f->calls, fn($argv) => $argv[0] === 'env'), 'Occupied GPU must not start a manager');
};
$cases['missing native install never falls back to plugin-driven upgradepkg'] = function (NativeBootFixture $f): void {
    $p = $f->archive(); $f->select($p);
    $f->put('/proc/sys/kernel/random/boot_id', NativeBootFixture::SECOND);
    $result = $f->app->dispatch('boot');
    lifecycle_check(!$result['ok'] && $result['code'] === 'failed', 'Missing native installation must be explicit');
    lifecycle_check(!array_filter($f->calls, fn($argv) => in_array($argv[0], ['env', 'modprobe', 'mdevctl'], true)), 'No runtime action follows failed native verification');
};
$cases['driver-only mode verifies native installation without vGPU mutation'] = function (NativeBootFixture $f): void {
    $settings = $f->store->settings(); $settings['runtime']['enabled'] = false; $f->store->saveSettings($settings);
    $p = $f->archive(); $f->select($p); $f->reboot(loaded: true);
    $result = $f->app->dispatch('boot');
    lifecycle_check($result['ok'] && $result['code'] === 'runtime_disabled', 'Native host-only installation remains supported');
    lifecycle_check(!array_filter($f->calls, fn($argv) => in_array($argv[0], ['env', 'modprobe', 'mdevctl'], true)), 'Driver-only mode must not create vGPU devices');
};
$cases['runtime failure keeps verified install and does not report device initialization success'] = function (NativeBootFixture $f): void {
    $p = $f->archive(); $f->select($p); $f->reboot(); $f->failure = 'env';
    $result = $f->app->dispatch('boot');
    lifecycle_check(!$result['ok'] && $result['code'] === 'daemon_start_failed', 'Daemon failure must be reported separately');
    lifecycle_check((new Vgpu\Installer($f->store, $f->download, $f->runtime, $f->run(...)))->state()['status'] === 'installed', 'Native installation proof remains distinct from runtime readiness');
};
foreach ($cases as $name => $test) {
    $f = new NativeBootFixture();
    try { $test($f); echo 'PASS native lifecycle: ' . $name . "\n"; }
    finally { $f->close(); }
}
echo 'Native lifecycle: ' . count($cases) . " cases passed; existing Unraid entrypoint only.\n";
