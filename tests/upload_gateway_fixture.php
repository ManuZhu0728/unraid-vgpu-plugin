<?php
declare(strict_types=1);
// Only the Python harness includes this fixture. It never lives in the plugin.
require_once '__PLUGIN_PREFIX__/include/bootstrap.php';
require_once '__PLUGIN_PREFIX__/include/Application.php';
require_once '__TEST_ROOT__/tests/ui_template.php';
$store = new Vgpu\Store(new Vgpu\Paths(__DIR__ . '/root'));
$passive = static function (array $argv, int $timeout = 15, ?string $stdin = null): array {
    if ($argv[0] === 'modinfo') return ['code'=>0,'stdout'=>$argv[2] === 'version' ? '595.58.03' : '6.18.58-Unraid SMP preempt mod_unload','stderr'=>''];
    if (!in_array($argv[0], ['tar','xz'], true)) throw new RuntimeException('Fixture forbids GPU, installer and network commands');
    return (new Vgpu\Process())($argv, $timeout, $stdin);
};
$app = new Vgpu\Application($store, new Vgpu\Runtime($store->paths, $passive), new Vgpu\Download($store->paths, $passive), $passive);
if (PHP_SAPI === 'cli') {
    foreach (glob($store->paths->runtimeDir . '/upload-*') ?: [] as $directory) (new Vgpu\Upload($store->paths))->cancel(substr(basename($directory), 7));
    foreach (['browser-mode','lose-finish','reject-auth','calls.jsonl'] as $name) if (is_file(__DIR__.'/'.$name)) unlink(__DIR__.'/'.$name);
    $cache = $store->paths->cacheDir . '/6.18.58';
    if (!is_dir($cache)) mkdir($cache, 0700, true);
    $filename = 'nvidia-595.58.03-6.18.58-Unraid-3.txz';
    file_put_contents($cache.'/'.$filename, 'original selected cache');
    file_put_contents($cache.'/'.$filename.'.md5', md5('original selected cache').'  '.$filename."\n");
    foreach (['nvidia-595.58.03-6.18.58-Unraid-4.txz','nvidia-595.58.03-6.18.58-Unraid-4.txz.md5'] as $name) if (is_file($cache.'/'.$name)) unlink($cache.'/'.$name);
    $settings = Vgpu\Store::defaults();
    $settings['selected_package'] = ['filename'=>$filename,'driver'=>'595.58.03','kernel'=>'6.18.58-Unraid','build'=>3,
        'path'=>$cache.'/'.$filename,'md5'=>md5('original selected cache'),'source'=>'local','provider'=>'local','checksum_verified'=>true];
    $store->saveSettings($settings);
    $proc = $store->paths->path('/proc/sys/kernel');
    if (!is_dir($proc.'/random')) mkdir($proc.'/random', 0700, true);
    file_put_contents($proc.'/osrelease', '6.18.58-Unraid');
    file_put_contents($proc.'/random/boot_id', '11111111-1111-4111-8111-111111111111');
    return;
}
function fixture_mode(): string { return is_file(__DIR__.'/browser-mode') ? trim((string)file_get_contents(__DIR__.'/browser-mode')) : ''; }
function uvm_cli(string $action, array $payload=[]): array {
    global $app, $store;
    if ($action === 'status') return ['settings'=>$store->settings(),'devices'=>[],'credential_configured'=>false,'override_content'=>'','pending_restart'=>false,
        'package_cache'=>(new Vgpu\PackageCache($store))->listing(),'runtime'=>['ok'=>true,'runtime'=>['kernel'=>'6.18.58-Unraid','loaded_driver'=>'595.58.03']]];
    if (!str_starts_with($action, 'source-upload-') && $action !== 'cache-select') throw new RuntimeException('Only upload and explicit cache selection are in this fixture');
    file_put_contents(__DIR__.'/calls.jsonl', json_encode(['action'=>$action,'keys'=>array_keys($payload),'offset'=>$payload['offset']??null,'data_chars'=>strlen($payload['data']??''),
        'content_type'=>$_SERVER['CONTENT_TYPE']??'', 'upload_id'=>$payload['upload_id']??null,
        'native_consumed'=>!isset($_POST['csrf_token']), 'mirror_valid'=>($_POST['uvm_csrf_token']??'')==='fixture-upload-csrf'])."\n", FILE_APPEND);
    $mode = fixture_mode();
    if ($action === 'source-upload-chunk' && in_array($mode, ['cancel','beacon','cancel-failure','cancel-unknown'], true)) usleep(400000);
    if ($action === 'source-upload-cancel' && $mode === 'cancel-failure') throw new RuntimeException('Fixture cancellation refused; staging is retained');
    $result = $app->dispatch($action, $payload);
    if ($action === 'source-upload-cancel' && $mode === 'cancel-unknown') throw new UvmUnknownResultException('Fixture cancellation receipt lost after clearing staging');
    if ($action === 'source-upload-begin' && $mode === 'auth-expired') file_put_contents(__DIR__.'/reject-auth', '1');
    if ($action === 'source-upload-finish' && ($mode === 'finish-unknown' || is_file(__DIR__.'/lose-finish'))) throw new UvmUnknownResultException('Fixture receipt lost after completion');
    return $result;
}
function uvm_profile_scan(): array { return []; }
function uvm_read(string $path, int $limit=65536): string { return ''; }
$var = ['csrf_token'=>'fixture-upload-csrf'];
echo '<!doctype html><meta charset="utf-8"><main data-unraid-wrapper="fixture">';
if (($_SERVER['REQUEST_METHOD']??'') === 'POST') {
    if (!is_string($_POST['csrf_token']??null) || !hash_equals($var['csrf_token'], $_POST['csrf_token'])) {
        echo '<script type="application/json" id="uvm-upload-result">{"ok":false,"outcome":"failed","error":"Native gateway CSRF rejected"}</script>'; return;
    }
    unset($_POST['csrf_token']);
}
foreach (($_SERVER['REQUEST_METHOD']??'') === 'POST' ? ['driver','vgpu','status'] : ['driver'] as $tab) {
    $page = ui_template($tab);
    foreach (['uvm_cli','uvm_profile_scan','uvm_read'] as $function) $page = str_replace('function '.$function.'(', 'function uvm_original_'.substr($function,4).'(', $page);
    eval('?>'.$page);
}
echo '</main>';
