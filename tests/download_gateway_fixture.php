<?php
declare(strict_types=1);
// Local nginx/FPM fixture only; not part of the distributed plugin.
require_once '__PLUGIN_PREFIX__/include/bootstrap.php';
require_once '__PLUGIN_PREFIX__/include/Application.php';
require_once '__TEST_ROOT__/tests/ui_template.php';
$store = new Vgpu\Store(new Vgpu\Paths(__DIR__ . '/root'));
$passive = static function (array $argv, int $timeout = 15, ?string $stdin = null, ?callable $monitor = null): array {
    if ($argv[0] === 'modinfo') {
        preg_match('~/lib/modules/([^/]+)/~', $argv[3], $kernel);
        return ['code'=>0, 'stdout'=>$argv[2] === 'version' ? '595.58.03' : ($kernel[1] ?? 'invalid').' SMP preempt mod_unload', 'stderr'=>''];
    }
    if (str_ends_with($argv[0], '/notify')) return ['code'=>0, 'stdout'=>'', 'stderr'=>''];
    if ($argv[0] === 'curl') {
        // Production HTTPS remains strict. Only this fixture maps its one fake
        // source to a loopback HTTP payload server using a non-secret token.
        $stdin = str_replace('https://driver.example.test', '__PAYLOAD_ORIGIN__', (string)$stdin);
        $argv[array_search('--proto', $argv, true) + 1] = '=http';
    } elseif (!in_array($argv[0], ['tar', 'xz'], true)) throw new RuntimeException('Fixture forbids GPU tools, installers and services');
    return (new Vgpu\Process())($argv, $timeout, $stdin, $monitor);
};
$app = new Vgpu\Application($store, new Vgpu\Runtime($store->paths, $passive), new Vgpu\Download($store->paths, $passive), $passive);
if (PHP_SAPI === 'cli') {
    $settings = Vgpu\Store::defaults();
    $settings['source'] = Vgpu\Store::validateSource(['url'=>'https://driver.example.test/owner/repo', 'provider'=>'gitlab', 'mode'=>'auto', 'project_id'=>'42']);
    $settings['upgrade'] = ['enabled'=>true, 'driver_branch'=>'595'];
    $cache = $store->paths->cacheDir . '/6.18.58';
    if (!is_dir($cache)) mkdir($cache, 0700, true);
    $old = 'nvidia-595.58.03-6.18.58-Unraid-1.txz';
    copy(__DIR__.'/package-6.18.58.txz', $cache.'/'.$old);
    $md5 = md5_file($cache.'/'.$old);
    file_put_contents($cache.'/'.$old.'.md5', $md5.'  '.$old."\n");
    $settings['selected_package'] = ['filename'=>$old, 'driver'=>'595.58.03', 'kernel'=>'6.18.58-Unraid', 'build'=>1,
        'path'=>$cache.'/'.$old, 'md5'=>$md5, 'source'=>'local', 'provider'=>'local', 'checksum_verified'=>true,
        'saved_boot_id'=>'11111111-1111-4111-8111-111111111111'];
    $store->saveSettings($settings);
    $store->saveCredentials(['type'=>'private-token', 'token'=>'LOCAL-FAKE-DOWNLOAD-TOKEN', 'username'=>'', 'source_id'=>Vgpu\Store::sourceIdentity($settings['source'])]);
    $files = ['/proc/sys/kernel/osrelease'=>'6.18.58-Unraid', '/proc/sys/kernel/random/boot_id'=>'11111111-1111-4111-8111-111111111111',
        '/sys/module/nvidia/version'=>'595.58.03', '/boot/changes.txt'=>"# Version 7.4.1\n## Linux kernel\n* version 6.18.64\n"];
    foreach ($files as $name=>$value) { $file=$store->paths->path($name); if (!is_dir(dirname($file))) mkdir(dirname($file),0700,true); file_put_contents($file,$value); }
    return;
}
function uvm_cli(string $action, array $payload=[]): array {
    global $app, $store;
    if ($action === 'status') return ['settings'=>$store->settings(),'devices'=>[],'credential_configured'=>true,'override_content'=>'','pending_restart'=>false,
        'upgrade'=>(new Vgpu\Upgrade($store,new Vgpu\Download($store->paths)))->status(), 'download_task'=>(new Vgpu\DownloadTask($store))->status(),
        'runtime'=>['ok'=>true,'runtime'=>['kernel'=>'6.18.58-Unraid','loaded_driver'=>'595.58.03']]];
    if (!in_array($action,['source-download','download-status','download-cancel','upgrade-check'],true)) throw new RuntimeException('Only download controls and preparation are allowed');
    file_put_contents(__DIR__.'/calls.jsonl',json_encode(['action'=>$action,'keys'=>array_keys($payload),'native_consumed'=>!isset($_POST['csrf_token'])])."\n",FILE_APPEND);
    $result=$app->dispatch($action,$payload);
    if (($result['ok']??true)!==true) throw new RuntimeException((string)($result['message']??'Preparation failed'));
    return $result;
}
function uvm_profile_scan(): array { if (($_SERVER['REQUEST_METHOD']??'')==='POST') throw new RuntimeException('Control requests must not inspect a GPU'); return []; }
function uvm_read(string $path,int $limit=65536): string { return ''; }
$var=['csrf_token'=>'fixture-download-control-csrf'];
if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
    if (!is_string($_POST['csrf_token']??null) || !hash_equals($var['csrf_token'],$_POST['csrf_token'])) { http_response_code(403); echo 'Native CSRF rejected'; return; }
    unset($_POST['csrf_token']);
}
echo '<!doctype html><meta charset="utf-8"><main data-unraid-wrapper="fixture">';
foreach (($_SERVER['REQUEST_METHOD']??'')==='POST' ? ['driver','vgpu','status'] : ['driver'] as $tab) {
    $page=ui_template($tab);
    foreach (['uvm_cli','uvm_profile_scan','uvm_read'] as $function) $page=str_replace('function '.$function.'(','function uvm_original_'.substr($function,4).'(',$page);
    eval('?>'.$page);
}
echo '</main>';
