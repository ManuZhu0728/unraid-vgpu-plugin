<?php
declare(strict_types=1);
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/Paths.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/StartupInventory.php';

use Vgpu\Paths;
use Vgpu\StartupInventory;

$startupChecks = 0;
function startup_check(bool $condition, string $message): void
{
    global $startupChecks;
    if (!$condition) throw new RuntimeException($message);
    $startupChecks++;
}

final class StartupFixture
{
    public string $root;
    public Paths $paths;
    public StartupInventory $inventory;
    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/vgpu-startup-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->paths = new Paths($this->root);
        $this->inventory = new StartupInventory($this->paths);
    }
    public function put(string $path, string $content): void
    {
        $physical = $this->paths->path($path);
        if (!is_dir(dirname($physical))) mkdir(dirname($physical), 0700, true);
        file_put_contents($physical, $content);
    }
    public function job(string $name, string $content, string $frequency = 'boot'): string
    {
        $path = '/boot/config/plugins/user.scripts/scripts/' . $name . '/script';
        $this->put($path, $content);
        $this->put('/boot/config/plugins/user.scripts/schedule.json', json_encode([$path => ['script' => $path, 'frequency' => $frequency]], JSON_THROW_ON_ERROR));
        return $path;
    }
    public function codes(array $result): array { return array_column($result['findings'], 'code'); }
    public function fingerprint(): array
    {
        $result = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $result[substr($file->getPathname(), strlen($this->root))] = $file->isLink() ? 'link:' . readlink($file->getPathname()) : ($file->isDir() ? 'directory' : ($file->isFile() ? hash_file('sha256', $file->getPathname()) : 'special'));
        }
        ksort($result);
        return $result;
    }
    public function close(): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname());
        }
        rmdir($this->root);
    }
}

$tests = [];
$tests['empty inventory is read-only and explicitly not an absence proof'] = function (StartupFixture $f): void {
    $result = $f->inventory->read();
    startup_check($result['owners'] === [] && $result['entries'] === [] && $result['coverage']['files_read'] === 0, 'Missing roots are empty');
    startup_check($result['coverage']['complete'] && !$result['coverage']['proves_absence'] && $result['coverage']['limitations'] !== [], 'Scope limitations must stay explicit');
};
$tests['legacy plugin markers retain existing owner identities'] = function (StartupFixture $f): void {
    $f->put('/boot/config/plugins/novidio-vgpu-driver.plg', 'marker');
    startup_check($f->inventory->read()['owners'] === ['plugin:novidio-vgpu-driver.plg'], 'Plugin owner unchanged');
};
$tests['enabled plain-name wrapper follows absolute references with evidence'] = function (StartupFixture $f): void {
    $root = $f->job('startup', "#!/bin/bash\nbash /boot/config/vgpu-cold-init.sh\n");
    $f->put('/boot/config/vgpu-cold-init.sh', "#!/bin/bash\nLD_PRELOAD=/usr/lib64/libvgpu_unlock_rs.so /usr/bin/nvidia-vgpud\n");
    $result = $f->inventory->read();
    startup_check($result['owners'] === ['user-script:startup'], 'Neutral wrapper must not hide GPU initializer');
    startup_check($result['references'][0] === ['entry' => 'user-script:startup', 'from' => $root, 'line' => 2, 'to' => '/boot/config/vgpu-cold-init.sh'], 'Reference edge includes caller line');
    startup_check($result['findings'][0]['chain'] === [$root, '/boot/config/vgpu-cold-init.sh'] && $result['findings'][0]['line'] === 2, 'Finding retains full chain and leaf line');
    startup_check(!str_contains(json_encode($result), 'LD_PRELOAD='), 'Inventory must not publish raw script contents');
};
$tests['go and source/direct/exec/env/nohup references are inspected'] = function (StartupFixture $f): void {
    $f->put('/boot/config/go', "#!/bin/bash\nsource '/boot/config/first script.sh'\n");
    $f->put('/boot/config/first script.sh', ". /boot/config/second.sh\n");
    $f->put('/boot/config/second.sh', "/boot/config/third.sh\n");
    $f->put('/boot/config/third.sh', "exec env -i LD_PRELOAD=/test nohup /usr/bin/nvidia-vgpu-mgr >/dev/null 2>&1 &\n");
    $result = $f->inventory->read();
    startup_check($result['owners'] === ['go:/boot/config/go'] && count($result['references']) === 3, 'go wrappers must resolve to an initializer');
};
$tests['comments echo grep and status arguments do not become commands'] = function (StartupFixture $f): void {
    $f->job('vgpu-monitor', <<<'SCRIPT'
#!/bin/bash
# nvidia-vgpu-mgr
echo 'nvidia-vgpud; mdevctl start'
printf '%s\n' 'nvidia-vgpu-mgr'
grep 'nvidia-vgpu-mgr' /var/log/syslog
pgrep -af nvidia-vgpud
command -v nvidia-vgpu-mgr
command -p -V nvidia-vgpud
mdevctl list --defined
nvidia-smi
SCRIPT);
    $result = $f->inventory->read();
    startup_check($result['owners'] === [] && $result['coverage']['complete'], 'Read-only monitoring is not startup ownership');
};
$tests['disabled scripts and unrelated removed backups do not block'] = function (StartupFixture $f): void {
    $f->job('vgpu-disabled', 'nvidia-vgpud', 'disabled');
    $f->put('/boot/config/plugins/user.scripts/schedule.json', json_encode([
        'vgpu-disabled' => 'disabled', 'removed backup' => ['frequency' => 'daily'], 'off-job' => ['frequency' => 'OFF'],
    ], JSON_THROW_ON_ERROR));
    $result = $f->inventory->read();
    startup_check($result['owners'] === [] && count($result['entries']) === 3, 'Disabled entries remain visible without becoming owners');
    startup_check(!$result['coverage']['complete'] && in_array('missing_file', $f->codes($result), true), 'An unrelated missing job is diagnostic, not falsely scanned clean');
};
$tests['line continuations quoted paths and command separators work'] = function (StartupFixture $f): void {
    $f->job('startup', "echo ok; bash \\\n\"/boot/config/child script.sh\"\r\n");
    $f->put('/boot/config/child script.sh', "echo ok && /usr/bin/mdevctl start -u abc\n");
    startup_check($f->inventory->read()['owners'] === ['user-script:startup'], 'Continuation and quotes must preserve an absolute reference');
};
$tests['here document contents are not command positions'] = function (StartupFixture $f): void {
    $f->put('/boot/config/go', "cat <<'EOF'\nnvidia-vgpud\nmdevctl start\nEOF\necho done\n");
    $result = $f->inventory->read();
    startup_check($result['owners'] === [] && $result['coverage']['complete'], 'Documentation heredoc is not an initializer');
    $f->put('/boot/config/go', "cat <<EOF\n\$(nvidia-vgpud)\nEOF\n");
    $result = $f->inventory->read();
    startup_check(!$result['coverage']['complete'] && in_array('unsupported_heredoc_expansion', $f->codes($result), true) && $result['owners'] !== [], 'Unquoted heredoc expansion must not be called clean');
};
$tests['dynamic and relative references are explicit and never evaluated'] = function (StartupFixture $f): void {
    $f->job('backup', 'bash "$BACKUP_SCRIPT"' . "\nbash ./backup.sh\n");
    $result = $f->inventory->read();
    startup_check($result['owners'] === [] && !$result['coverage']['complete'], 'Unrelated unresolved scripts must not globally block');
    startup_check(in_array('dynamic_reference', $f->codes($result), true) && in_array('relative_cwd_unknown', $f->codes($result), true), 'No invented cwd or variable expansion');
    $f->job('startup', 'bash -c "nvidia-vgpud"' . "\n");
    $result = $f->inventory->read();
    startup_check($result['owners'] === ['user-script:startup'] && in_array('unsupported_shell_invocation', $f->codes($result), true), 'Relevant unsupported shell string requires review, not execution');
};
$tests['command substitutions are unresolved execution syntax'] = function (StartupFixture $f): void {
    $f->put('/boot/config/go', 'echo "$(nvidia-vgpud)"' . "\n");
    $result = $f->inventory->read();
    startup_check($result['owners'] === ['go:/boot/config/go'] && in_array('unsupported_command_substitution', $f->codes($result), true), 'Echo is not passive when its argument executes a command');
};
$tests['simple static monitoring substitutions are non-owning for both task names'] = function (StartupFixture $f): void {
    $scripts = [
        'echo "$(pgrep -x nvidia-vgpu-mgr)"', 'pids=$(pgrep -x nvidia-vgpu-mgr)',
        'echo "$(/usr/bin/pgrep -x nvidia-vgpud)"', 'pids=$(pidof nvidia-vgpu-mgr)',
        'echo "$(grep nvidia-vgpud /var/log/syslog)"', 'tool=$(command -v nvidia-vgpu-mgr)',
        'tool=$(command -p -V nvidia-vgpud)', 'pids=`pgrep -x nvidia-vgpu-mgr`',
    ];
    foreach (['monitor', 'vgpu-monitor'] as $name) foreach ($scripts as $script) {
        $f->job($name, $script);
        $result = $f->inventory->read();
        startup_check($result['owners'] === [], 'Static read-only substitution must not claim ownership: ' . $name . ' / ' . $script);
        startup_check(!$result['coverage']['complete'] && in_array('read_only_command_substitution', $f->codes($result), true), 'Non-evaluated monitoring retains explicit coverage evidence');
    }
};
$tests['substitution allowlist does not admit initialization nested dynamic or eval'] = function (StartupFixture $f): void {
    $scripts = [
        'echo "$(nvidia-vgpud)"', 'echo "$(pgrep -x "$(nvidia-vgpud)")"',
        'echo "$(pgrep -x "$GPU_PROCESS")"', 'echo "$(eval pgrep -x nvidia-vgpu-mgr)"',
        'echo "$(pgrep nvidia-vgpu-mgr; nvidia-vgpud)"', 'eval "$(pgrep -x nvidia-vgpu-mgr)"',
        '$(pgrep -x nvidia-vgpu-mgr)', 'echo "$(/boot/config/pgrep nvidia-vgpu-mgr)"',
    ];
    foreach ($scripts as $script) {
        $f->job('vgpu-monitor', $script);
        $result = $f->inventory->read();
        startup_check($result['owners'] === ['user-script:vgpu-monitor'] && !$result['coverage']['complete'], 'Unresolved execution must not be allowed by a reader name: ' . $script);
    }
};
$tests['shell stdin heredocs are not mistaken for passive documents'] = function (StartupFixture $f): void {
    $f->job('startup', "bash <<'EOF'\nnvidia-vgpud\nEOF\n");
    $result = $f->inventory->read();
    startup_check($result['owners'] === ['user-script:startup'] && in_array('unsupported_shell_stdin', $f->codes($result), true), 'Literal GPU command in shell stdin requires review without execution');
    $f->job('startup', "env -i bash <<-EOF\n\t/usr/bin/mdevctl start -u test\n\tEOF\n");
    startup_check($f->inventory->read()['owners'] === ['user-script:startup'], 'Wrapped shell and tab-stripped heredoc retain GPU relevance');
    $f->job('startup', "cat <<'EOF'\nnvidia-vgpud\nEOF\necho <<'END'\nmdevctl start\nEND\n");
    $result = $f->inventory->read();
    startup_check($result['owners'] === [] && $result['coverage']['complete'], 'cat/echo documents must not claim ownership');
};
$tests['unsupported mdevctl options and dynamic actions are not called clean'] = function (StartupFixture $f): void {
    $f->job('startup', 'mdevctl --verbose start -u test');
    $result = $f->inventory->read();
    startup_check($result['owners'] === ['user-script:startup'] && in_array('unsupported_mdevctl_invocation', $f->codes($result), true) && !$result['coverage']['complete'], 'Unknown global options must be explicit rather than ignored');
    $f->job('startup', 'mdevctl "$ACTION" -u test');
    startup_check(!$f->inventory->read()['coverage']['complete'], 'Dynamic mdevctl action must stay unresolved');
    $f->job('startup', "mdevctl --help\nmdevctl list --defined\n");
    startup_check($f->inventory->read()['owners'] === [], 'Help and ordinary list remain non-owning');
};
$tests['missing related script and corrupt schedule retain migration barrier'] = function (StartupFixture $f): void {
    $f->job('startup', 'bash /boot/config/vgpu-missing.sh');
    startup_check($f->inventory->read()['owners'] === ['user-script:startup'], 'Relevant missing target cannot authorize initialization');
    $f->put('/boot/config/plugins/user.scripts/schedule.json', 'not-json');
    startup_check($f->inventory->read()['owners'] === ['unknown:user-scripts-schedule'], 'Unparseable schedule preserves previous barrier');
};
$tests['cycles stop and depth four is the last followed edge'] = function (StartupFixture $f): void {
    $f->put('/boot/config/go', 'bash /boot/config/one.sh');
    $f->put('/boot/config/one.sh', 'bash /boot/config/go');
    $result = $f->inventory->read();
    startup_check(in_array('reference_cycle', $f->codes($result), true) && $result['coverage']['files_read'] === 2, 'Cycle is bounded');
    $f->put('/boot/config/go', 'bash /boot/config/child1.sh');
    for ($i = 1; $i < 4; $i++) $f->put('/boot/config/child' . $i . '.sh', 'bash /boot/config/child' . ($i + 1) . '.sh');
    $f->put('/boot/config/child4.sh', 'nvidia-vgpud');
    startup_check($f->inventory->read()['owners'] === ['go:/boot/config/go'], 'Depth four is inspected');
    $f->put('/boot/config/child4.sh', 'bash /boot/config/vgpu-child5.sh');
    $f->put('/boot/config/vgpu-child5.sh', 'nvidia-vgpud');
    $result = $f->inventory->read();
    startup_check(in_array('depth_limit', $f->codes($result), true) && !$result['coverage']['complete'], 'Depth five must report incomplete coverage');
};
$tests['in-tree symlinks work while escaping links are never read'] = function (StartupFixture $f): void {
    $f->put('/boot/config/go', 'bash /boot/config/link.sh');
    $f->put('/boot/config/real.sh', 'nvidia-vgpud');
    symlink('real.sh', $f->paths->path('/boot/config/link.sh'));
    startup_check($f->inventory->read()['owners'] === ['go:/boot/config/go'], 'A safe relative symlink stays inside scope');
    unlink($f->paths->path('/boot/config/link.sh'));
    symlink('/etc/passwd', $f->paths->path('/boot/config/link.sh'));
    $result = $f->inventory->read();
    startup_check(in_array('reference_outside_scope', $f->codes($result), true) && $result['coverage']['files_read'] === 1, 'Escaping link must not read external content');
};
$tests['a later GPU alias is revisited with stricter relevance'] = function (StartupFixture $f): void {
    $f->put('/boot/config/go', "bash /boot/config/helper.sh\nbash /boot/config/vgpu-alias.sh\n");
    $f->put('/boot/config/helper.sh', 'bash "$NEXT_SCRIPT"');
    symlink('helper.sh', $f->paths->path('/boot/config/vgpu-alias.sh'));
    $result = $f->inventory->read();
    startup_check($result['owners'] === ['go:/boot/config/go'], 'A cached unrelated visit must not hide later GPU-relevant unresolved code');
    $dynamic = array_values(array_filter($result['findings'], static fn(array $finding): bool => $finding['code'] === 'dynamic_reference'));
    startup_check(count($dynamic) === 2 && !$dynamic[0]['blocking'] && $dynamic[1]['blocking'], 'Both relevance contexts must retain their own evidence');
    startup_check($result['coverage']['files_read'] === 2, 'Revisiting context must reuse the bounded file snapshot');
};
$tests['wrapper prefix budget reports its boundary instead of silently stopping'] = function (StartupFixture $f): void {
    $f->job('startup', str_repeat('exec ', 7) . 'nvidia-vgpud');
    $result = $f->inventory->read();
    startup_check($result['owners'] === ['user-script:startup'] && in_array('initializer_command', $f->codes($result), true), 'Seven prefixes still leave one command inspection step');
    $f->job('startup', str_repeat('exec ', 8) . 'nvidia-vgpud');
    $result = $f->inventory->read();
    startup_check($result['owners'] === ['user-script:startup'] && !$result['coverage']['complete'] && in_array('wrapper_depth_limit', $f->codes($result), true), 'Eight prefixes must report unresolved coverage');
};
$tests['special files and oversized files fail before reading'] = function (StartupFixture $f): void {
    $f->put('/boot/config/go', 'bash /boot/config/vgpu-child.sh');
    $f->put('/boot/config/vgpu-child.sh', str_repeat('x', 65537));
    $result = $f->inventory->read();
    startup_check(in_array('file_bytes_limit', $f->codes($result), true) && $result['coverage']['files_read'] === 1, 'Oversized file is bounded');
    unlink($f->paths->path('/boot/config/vgpu-child.sh'));
    $fifo = $f->paths->path('/boot/config/vgpu-child.sh');
    if (function_exists('posix_mkfifo')) startup_check(posix_mkfifo($fifo, 0600), 'Create fixture-only FIFO');
    else {
        $process = proc_open(['mkfifo', '--', $fifo], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        startup_check(is_resource($process) && proc_close($process) === 0, 'Create fixture-only FIFO without the optional PHP posix extension');
    }
    $result = $f->inventory->read();
    startup_check(in_array('not_readable_regular_file', $f->codes($result), true), 'FIFO must be rejected without opening it');
};
$tests['file count and byte budgets are shared across entries'] = function (StartupFixture $f): void {
    $jobs = [];
    for ($i = 0; $i < 65; $i++) {
        $name = 'backup-' . $i;
        $jobs[$name] = 'daily';
        $f->put('/boot/config/plugins/user.scripts/scripts/' . $name . '/script', 'echo backup');
    }
    $f->put('/boot/config/plugins/user.scripts/schedule.json', json_encode($jobs, JSON_THROW_ON_ERROR));
    $result = $f->inventory->read();
    startup_check($result['coverage']['files_read'] === 64 && in_array('files_limit', $f->codes($result), true) && $result['owners'] === [], 'The schedule consumes the same shared file budget');
    for ($i = 0; $i < 65; $i++) $f->put('/boot/config/plugins/user.scripts/scripts/backup-' . $i . '/script', '# ' . str_repeat('x', 65533) . "\n");
    $result = $f->inventory->read();
    startup_check($result['coverage']['bytes_read'] <= 1048576 && in_array('total_bytes_limit', $f->codes($result), true), 'All roots share a total byte budget');
};

foreach ($tests as $name => $test) {
    $fixture = new StartupFixture();
    try {
        $test($fixture);
        $before = $fixture->fingerprint();
        $first = $fixture->inventory->read();
        startup_check($fixture->fingerprint() === $before, 'Reading inventory must not modify the filesystem');
        startup_check($fixture->inventory->read() === $first, 'Repeated reads must reset all scan state');
        echo 'PASS startup inventory: ' . $name . "\n";
    } finally { $fixture->close(); }
}
echo 'Startup inventory: ' . count($tests) . ' scenarios / ' . $startupChecks . " checks passed\n";
