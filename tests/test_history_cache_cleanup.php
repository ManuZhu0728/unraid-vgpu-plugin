<?php
declare(strict_types=1);
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/Application.php';

use Vgpu\{Application, Download, PackageCache, PackageHistory, Paths, Runtime, Store};

$checks = 0;
function history_cache_check(bool $condition, string $message): void {
    ++$GLOBALS['checks'];
    if (!$condition) throw new RuntimeException($message);
}
function history_cache_rejects(callable $operation): void {
    try { $operation(); } catch (Throwable) { ++$GLOBALS['checks']; return; }
    throw new RuntimeException('Unsafe history/cache cleanup was accepted');
}
final class HistoryCacheFixture {
    public string $root;
    public Paths $paths;
    public Store $store;
    public Application $app;
    public array $old;
    public array $other;
    public string $receipt;

    public function __construct() {
        $this->root = sys_get_temp_dir() . '/vgpu-history-cleanup-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->paths = new Paths($this->root);
        $this->store = new Store($this->paths);
        $runner = static function (): never { throw new RuntimeException('No GPU, installer, service or network command is permitted'); };
        $this->app = new Application($this->store, new Runtime($this->paths, $runner), new Download($this->paths, $runner), $runner);
        $this->old = $this->make('6.18.58-Unraid', 3);
        $this->other = $this->make('6.18.60-Unraid', 3);
        $current = $this->make('6.18.64-Unraid', 3);
        $settings = Store::defaults(); $settings['selected_package'] = $current;
        $this->store->saveSettings($settings);
        $this->store->saveCredentials(['type'=>'none','token'=>'','username'=>'','source_id'=>'']);
        $this->store->saveDevices([]);
        $this->store->write($this->paths->configDir . '/driver-state.json', ['status'=>'installed','last_success'=>PackageHistory::fromPackage($current)]);
        $this->store->write($this->paths->configDir . '/upgrade-state.json', ['status'=>'current','package'=>$current,'baseline_package'=>$current]);
        foreach ([$this->old, $this->other] as $p) (new PackageHistory($this->store))->remember(PackageHistory::fromPackage($p));
        $this->receipt = $this->paths->configDir . '/package-history/6.18.58-Unraid.json';
    }
    public function make(string $kernel, int $build): array {
        $name = 'nvidia-595.58.03-' . $kernel . '-' . $build . '.txz';
        $path = $this->paths->cacheDir . '/' . substr($kernel, 0, -7) . '/' . $name;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        file_put_contents($path, 'history cleanup fixture ' . $name);
        file_put_contents($path . '.md5', md5_file($path) . '  ' . $name . "\n");
        return ['filename'=>$name,'path'=>$path,'driver'=>'595.58.03','kernel'=>$kernel,'build'=>$build,
            'md5'=>md5_file($path),'source'=>'local','saved_boot_id'=>'11111111-1111-4111-8111-111111111111'];
    }
    public function row(): array {
        foreach ((new PackageCache($this->store))->listing()['packages'] as $row) if ($row['filename'] === $this->old['filename']) return $row;
        throw new RuntimeException('Missing fixture row');
    }
    public function delete(mixed $confirmation = true): array {
        return $this->app->dispatch('cache-delete', ['filename'=>$this->old['filename'],'forget_history'=>$confirmation]);
    }
    public function intact(): void {
        foreach ([$this->old['path'], $this->old['path'].'.md5', $this->receipt] as $path) history_cache_check(is_file($path), 'Rejected cleanup preserves every old file');
    }
    public function close(): void {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { if ($file->isLink() || !$file->isDir()) unlink($file->getPathname()); else rmdir($file->getPathname()); }
        rmdir($this->root);
    }
}

$cases = [];
$cases['confirmation deletes exactly one cache/MD5/history and no configuration'] = static function (HistoryCacheFixture $f): void {
    $row = $f->row();
    history_cache_check($row['deletable'] && $row['requires_history_confirmation'] && $row['references'] === ['内核回退记录'], 'History-only row advertises explicit confirmation');
    history_cache_rejects(fn()=>$f->app->dispatch('cache-delete',['filename'=>$f->old['filename']]));
    history_cache_rejects(fn()=>$f->delete(false)); $f->intact();
    $preserved = [];
    foreach ([$f->paths->settingsFile,$f->paths->credentialsFile,$f->paths->devicesFile,$f->paths->configDir.'/driver-state.json',$f->paths->configDir.'/upgrade-state.json',
        $f->paths->configDir.'/package-history/6.18.60-Unraid.json',$f->other['path'],$f->other['path'].'.md5'] as $p) $preserved[$p]=file_get_contents($p);
    $result=$f->delete();
    history_cache_check($result['ok'] && $result['changed'] && $result['removed']===[$f->old['filename'],$f->old['filename'].'.md5','package-history/6.18.58-Unraid.json'], 'Success reports all three exact removals');
    foreach ([$f->old['path'],$f->old['path'].'.md5',$f->receipt] as $p) history_cache_check(!file_exists($p), 'Confirmed old file removed');
    foreach ($preserved as $p=>$bytes) history_cache_check(file_get_contents($p)===$bytes, 'Other kernels, selections and configuration remain byte-identical');
    history_cache_check($result['package_cache']['available'] && count($result['package_cache']['packages'])===2, 'Fresh inventory remains usable');
};
foreach (['selected','native','upgrade','baseline','installed','installing'] as $protection) {
    $cases['confirmation cannot bypass '.$protection] = static function (HistoryCacheFixture $f) use ($protection): void {
        history_cache_check($f->row()['requires_history_confirmation'], 'Browser initially sees history-only row');
        if ($protection==='selected') { $s=$f->store->settings();$s['selected_package']=$f->old;$f->store->saveSettings($s); }
        if ($protection==='native') {
            $dir=$f->paths->bootPackagesDir.'/6.18.58';mkdir($dir,0700,true);
            $f->store->write($dir.'/selection.json',['schema_version'=>1,'authorization'=>PackageHistory::fromPackage($f->old)]);
        }
        if ($protection==='upgrade'||$protection==='baseline') $f->store->write($f->paths->configDir.'/upgrade-state.json',[$protection==='upgrade'?'package':'baseline_package'=>$f->old]);
        if ($protection==='installed'||$protection==='installing') $f->store->write($f->paths->configDir.'/driver-state.json',
            $protection==='installed'?['last_success'=>PackageHistory::fromPackage($f->old)]:['status'=>'installing','attempted_package'=>$f->old]);
        $row=$f->row();history_cache_check(!$row['deletable']&&!$row['requires_history_confirmation'], 'A newly added hard reference disables history cleanup');
        history_cache_rejects(fn()=>$f->delete());$f->intact();
    };
}
$cases['strict confirmation and shared lock'] = static function (HistoryCacheFixture $f): void {
    foreach (['1',1,[],null,'true'] as $bad) history_cache_rejects(fn()=>$f->delete($bad));
    history_cache_rejects(fn()=>$f->app->dispatch('cache-delete',['filename'=>$f->old['filename'],'forget_history'=>true,'path'=>$f->receipt]));
    $lock=fopen($f->paths->lockFile,'ce');flock($lock,LOCK_EX|LOCK_NB);
    try { history_cache_rejects(fn()=>$f->delete()); } finally { flock($lock,LOCK_UN);fclose($lock); }
    $f->intact();
};
$cases['same-kernel other build receipt survives stale confirmation'] = static function (HistoryCacheFixture $f): void {
    $next=$f->make('6.18.58-Unraid',4);
    (new PackageHistory($f->store))->remember(PackageHistory::fromPackage($next));$before=file_get_contents($f->receipt);
    history_cache_check(!$f->row()['requires_history_confirmation'] && $f->row()['deletable'], 'Old build is now unreferenced');
    $result=$f->delete();
    history_cache_check($result['ok']&&count($result['removed'])===2&&file_get_contents($f->receipt)===$before&&is_file($next['path']), 'Stale confirmation does not remove another build history');
};
$cases['missing MD5 can still clean the matching history'] = static function (HistoryCacheFixture $f): void {
    unlink($f->old['path'].'.md5');$result=$f->delete();
    history_cache_check($result['ok']&&$result['removed']===[$f->old['filename'],'package-history/6.18.58-Unraid.json'], 'Only files actually removed are reported');
};
foreach (['invalid-json','symlink-receipt','hardlink-receipt','symlink-md5','symlink-parent'] as $unsafe) {
    $cases['unsafe '.$unsafe.' fails before removal'] = static function (HistoryCacheFixture $f) use ($unsafe): void {
        $outside=$f->root.'/outside';file_put_contents($outside,'must preserve');
        if($unsafe==='invalid-json')file_put_contents($f->receipt,'{broken');
        if($unsafe==='symlink-receipt'){unlink($f->receipt);symlink($outside,$f->receipt);}
        if($unsafe==='hardlink-receipt')link($f->receipt,$outside.'.link');
        if($unsafe==='symlink-md5'){unlink($f->old['path'].'.md5');symlink($outside,$f->old['path'].'.md5');}
        if($unsafe==='symlink-parent'){$dir=dirname($f->receipt);rename($dir,$dir.'-real');symlink($dir.'-real',$dir);}
        history_cache_rejects(fn()=>$f->delete());
        history_cache_check(is_file($f->old['path'])&&file_get_contents($outside)==='must preserve', 'Unsafe cleanup does not remove cache or external file');
    };
}
foreach (['txz','md5','history'] as $failure) {
    $cases[$failure.' unlink failure is accurately reported'] = static function (HistoryCacheFixture $f) use ($failure): void {
        $target=['txz'=>$f->old['path'],'md5'=>$f->old['path'].'.md5','history'=>$f->receipt][$failure];
        $cache=new PackageCache($f->store,static fn(string $p):bool=>$p===$target?false:unlink($p));
        if($failure==='txz'){history_cache_rejects(fn()=>$cache->delete($f->old['filename'],true));$f->intact();return;}
        $result=$cache->delete($f->old['filename'],true);
        history_cache_check(!$result['ok']&&$result['changed']&&!file_exists($f->old['path'])&&is_file($f->receipt), 'Partial failure never claims complete deletion or loses receipt');
        history_cache_check(count($result['removed'])===($failure==='md5'?1:2), 'Partial receipt lists only successful removals');
    };
}
$cases['replacement history during deletion is not removed'] = static function (HistoryCacheFixture $f): void {
    $next=$f->make('6.18.58-Unraid',4);
    $cache=new PackageCache($f->store,static function(string $p)use($f,$next):bool{
        if($p===$f->old['path'].'.md5')(new PackageHistory($f->store))->remember(PackageHistory::fromPackage($next));
        return unlink($p);
    });
    $result=$cache->delete($f->old['filename'],true);
    history_cache_check(!$result['ok']&&$result['changed']&&(new PackageHistory($f->store))->read('6.18.58-Unraid')['package']['filename']===$next['filename'], 'External replacement receipt survives with partial-failure message');
};
foreach($cases as $name=>$test){$fixture=new HistoryCacheFixture();try{$test($fixture);}catch(Throwable $e){throw new RuntimeException($name.': '.$e->getMessage(),0,$e);}finally{$fixture->close();}}
echo 'Confirmed history cache cleanup: '.count($cases).' cases, '.$checks." checks passed (temporary files only; no real GPU or services)\n";
