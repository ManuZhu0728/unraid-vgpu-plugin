<?php
declare(strict_types=1);
require_once __DIR__ . '/native_boot_fixture.php';
$checks = 0;
function native_check(bool $condition, string $message): void { ++$GLOBALS['checks']; if (!$condition) throw new RuntimeException($message); }
$cases = [];
$cases['download only caches a build and never changes an existing native selection'] = function (NativeBootFixture $f): void {
    $f->app->dispatch('config-save', ['source' => Vgpu\Store::validateSource(['url' => 'https://driver.example.test/nvidia-595.58.03-6.18.58-Unraid-2.txz'])]);
    $two = $f->archive(2); $three = $f->archive(3); $f->select($three);
    $before = $f->store->settings()['selected_package'];
    $result = $f->app->dispatch('source-download', ['filename' => $two['filename']]);
    native_check($result['package']['build'] === 2 && $f->store->settings()['selected_package'] === $before, 'Download cannot change next boot build');
    $f->reboot(); native_check($f->nativeInstalled === [$three['filename']], 'Downloading build2 must not replace selected build3');
};
$cases['multiple cached builds yield one native install and accept preloaded verified modules'] = function (NativeBootFixture $f): void {
    $two = $f->archive(2); $three = $f->archive(3);
    $f->select($three);
    native_check(is_file($two['path']) && is_file($three['path']), 'Both cache versions must survive selection');
    native_check($f->app->dispatch('boot')['code'] === 'pending_reboot', 'Selecting a build must not hot-install it');
    $f->reboot(loaded: true);
    native_check($f->nativeInstalled === [$three['filename']], 'Unraid must see ONLY the selected build');
    $result = (new Vgpu\Installer($f->store, $f->download, $f->runtime, $f->run(...)))->boot($f->store->settings());
    native_check($result['ok'] && $result['code'] === 'installed', 'Native preloading must not require a nonexistent self-install receipt: ' . json_encode($result));
    native_check($result['changed'] === false, 'Verification must not claim it installed the package');
};
$cases['switch build3 to build2 preserves all caches and stages only build2'] = function (NativeBootFixture $f): void {
    $two = $f->archive(2); $three = $f->archive(3); $f->select($three); $f->select($two);
    $f->reboot();
    native_check($f->nativeInstalled === [$two['filename']], 'Switching must withdraw the previous native copy');
    native_check(is_file($three['path']) && is_file($three['path'] . '.md5'), 'Old cache must remain recoverable');
    native_check($f->app->dispatch('boot')['ok'], 'Selected build2 must verify and initialize');
};
$cases['per-kernel selections coexist without cross-kernel installs'] = function (NativeBootFixture $f): void {
    $current = $f->archive(3); $future = $f->archive(2, '6.18.60-Unraid');
    $f->select($current); $f->select($future); $f->reboot();
    native_check($f->nativeInstalled === [$current['filename']], 'Current boot ignores target-kernel selection');
    native_check(count($f->native->selections()) === 2, 'Each kernel retains one explicit selection');
};
$cases['disable native restoration removes boot copies but keeps caches'] = function (NativeBootFixture $f): void {
    $three = $f->archive(); $f->select($three);
    $f->app->dispatch('config-save', ['driver' => ['enabled' => false]]);
    $f->reboot();
    native_check($f->nativeInstalled === [] && is_file($three['path']), 'Disabled restoration cannot be bypassed by the native scan');
};
foreach (['missing-record', 'wrong-location', 'wrong-build', 'changed-module', 'changed-loaded-module', 'extra-native-package', 'changed-native-md5', 'changed-cache-md5'] as $fault) {
    $cases['reject native evidence: ' . $fault] = function (NativeBootFixture $f) use ($fault): void {
        $p = $f->archive(); $f->select($p); $f->reboot(loaded: true);
        $record = '/var/lib/pkgtools/packages/' . basename($p['filename'], '.txz');
        if ($fault === 'missing-record') unlink($f->paths->path($record));
        if ($fault === 'wrong-location') $f->put($record, str_replace('/boot/config/plugins/unraid-vgpu-manager/packages', '/some/other/cache', file_get_contents($f->paths->path($record))));
        if ($fault === 'wrong-build') rename($f->paths->path($record), $f->paths->path(str_replace('-3', '-2', $record)));
        if ($fault === 'changed-module') $f->put('/lib/modules/6.18.58-Unraid/kernel/drivers/video/nvidia-drm.ko', 'damaged module');
        if ($fault === 'changed-loaded-module') $f->put('/sys/module/nvidia/srcversion', 'DIFFERENT');
        if ($fault === 'extra-native-package') $f->put('/boot/config/plugins/unraid-vgpu-manager/packages/6.18.58/extra-6.18.58-Unraid-2.txz', 'unexpected install target');
        if ($fault === 'changed-native-md5') file_put_contents($f->paths->bootPackagesDir . '/6.18.58/' . $p['filename'] . '.md5', str_repeat('0', 32));
        if ($fault === 'changed-cache-md5') file_put_contents($p['path'], 'corrupted private cache');
        $result = (new Vgpu\Installer($f->store, $f->download, $f->runtime, $f->run(...)))->boot($f->store->settings());
        native_check(!$result['ok'] && $result['code'] === 'failed', 'Invalid native evidence accepted: ' . $fault);
        native_check(!array_filter($f->calls, fn($argv) => in_array(basename($argv[0]), ['env', 'nvidia-modprobe', 'modprobe', 'mdevctl'], true)), 'Failed verification must not initialize a GPU');
    };
}
$cases['migrate our old multi-build directory and rewrite settings/history references'] = function (NativeBootFixture $f): void {
    $two = $f->archive(2); $three = $f->archive(3);
    foreach ([$two, $three] as $p) {
        $legacy = $f->directory('/boot/config/plugins/unraid-vgpu-manager/packages/6.18.58') . '/' . $p['filename'];
        rename($p['path'], $legacy); rename($p['path'] . '.md5', $legacy . '.md5');
    }
    $settings = $f->store->settings(); $settings['selected_package'] = array_replace($three, ['path' => $f->paths->bootPackagesDir . '/6.18.58/' . $three['filename']]);
    $f->store->saveSettings($settings);
    (new Vgpu\PackageHistory($f->store))->remember(Vgpu\PackageHistory::fromPackage(array_replace($two, ['path' => $f->paths->bootPackagesDir . '/6.18.58/' . $two['filename']])));
    $f->put('/boot/config/plugins/novidio-vgpu-driver/packages/6.18.58/foreign.txz', 'foreign sentinel');
    $f->app->dispatch('boot');
    native_check($f->store->settings()['selected_package']['path'] === $three['path'], 'Migration updates our selected path');
    native_check(is_file($two['path']) && is_file($three['path']), 'Migration must keep every old build');
    native_check((new Vgpu\PackageHistory($f->store))->read('6.18.58-Unraid')['package']['path'] === $two['path'], 'Migration rewrites historical cache references');
    native_check(file_get_contents($f->paths->path('/boot/config/plugins/novidio-vgpu-driver/packages/6.18.58/foreign.txz')) === 'foreign sentinel', 'Never migrate other plugins');
    $f->reboot(); native_check($f->nativeInstalled === [$three['filename']], 'Migrated old caches must not all install');
};
$cases['cache migration resumes after TXZ moved but before its MD5 and references'] = function (NativeBootFixture $f): void {
    $p = $f->archive();
    $legacy = $f->directory('/boot/config/plugins/unraid-vgpu-manager/packages/6.18.58') . '/' . $p['filename'];
    rename($p['path'] . '.md5', $legacy . '.md5');
    $settings = $f->store->settings(); $settings['selected_package'] = array_replace($p, ['path' => $legacy]); $f->store->saveSettings($settings);
    $f->app->dispatch('boot');
    native_check(is_file($p['path']) && is_file($p['path'] . '.md5') && $f->store->settings()['selected_package']['path'] === $p['path'], 'Partial migration resumes without losing package/checksum/selection');
};
$cases['unsafe cache and native links are not followed'] = function (NativeBootFixture $f): void {
    $p = $f->archive(); $f->select($p);
    $file = $f->paths->bootPackagesDir . '/6.18.58/' . $p['filename'];
    unlink($file); symlink($p['path'], $file);
    try { $f->native->verifyPublished($f->native->selection($p['kernel'])); }
    catch (Throwable) { native_check(is_file($p['path']), 'Link rejection preserves the private cache'); return; }
    throw new RuntimeException('Native symlink accepted');
};
$cases['bare external MD5 remains valid through selection and withdrawal'] = function (NativeBootFixture $f): void {
    $p = $f->archive(); file_put_contents($p['path'] . '.md5', $p['md5']);
    $f->select($p);
    $f->app->dispatch('config-save', ['driver' => ['enabled' => false]]);
    native_check(file_get_contents($p['path'] . '.md5') === $p['md5'] && $f->native->selections() === [], 'Withdrawing a native copy must preserve a valid bare external MD5 cache');
};
foreach ($cases as $name => $test) {
    $fixture = new NativeBootFixture();
    try { $test($fixture); echo 'PASS native boot: ' . $name . "\n"; }
    finally { $fixture->close(); }
}
echo 'Native driver verification: ' . count($cases) . ' cases / ' . $checks . " checks; no host GPU or installer invoked.\n";
