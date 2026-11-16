<?php
declare(strict_types=1);

// Exercise the actual UI process/receipt boundary with a local PHP child, not
// the installed CLI, production configuration, GPU tools or network transport.
$directory = sys_get_temp_dir() . '/vgpu-ui-transport-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$entrypoint = $directory . '/cli.php';
$fixture = <<<'PHP'
<?php
$input = json_decode(stream_get_contents(STDIN), true);
$case = $input['case'] ?? 'success';
if ($case === 'timeout') usleep(1000000);
if ($case === 'malformed') { echo '{invalid'; exit; }
if ($case === 'oversized') { echo str_repeat('x', 2097153); exit; }
if ($case === 'missing-ok') { echo '{"data":{}}'; exit; }
if ($case === 'nonboolean-ok') { echo '{"ok":"true","data":{}}'; exit; }
if ($case === 'invalid-data') { echo '{"ok":true,"data":"invalid"}'; exit; }
if ($case === 'failure') { echo '{"ok":false,"error":"MD5 mismatch glpat-FIXTUREONLY"}'; exit(1); }
echo json_encode(['ok'=>true, 'data'=>['action'=>$argv[1], 'received'=>$input]]);
if ($case === 'failed-exit') exit(1);
PHP;
file_put_contents($entrypoint, $fixture);
$uvm_secrets = [];
$checks = 0;
function transport_check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
}
try {
    $common = (string)file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/ui/common.php');
    $common = str_replace("'/usr/local/emhttp/plugins/unraid-vgpu-manager/include/cli.php'", var_export($entrypoint, true), $common, $entryCount);
    $common = str_replace("'/usr/bin/php'", var_export(PHP_BINARY, true), $common, $phpCount);
    $common = str_replace('$deadline = microtime(true) + $timeout;', '$deadline = microtime(true) + 0.2;', $common, $deadlineCount);
    transport_check($entryCount === 1 && $phpCount === 2 && $deadlineCount === 1, 'Only the fixed process paths/deadline are replaced in this local fixture');
    eval('?>' . $common);
    $payload = ['case'=>'success', 'filename'=>'nvidia-595.58.03-6.18.58-Unraid-3.txz'];
    $result = uvm_cli('source-download', $payload);
    transport_check($result === ['action'=>'source-download', 'received'=>$payload], 'A successful zero-exit CLI returns its exact data and receives JSON stdin');
    foreach (['malformed', 'oversized', 'missing-ok', 'nonboolean-ok', 'invalid-data', 'failed-exit', 'timeout', 'failure'] as $case) {
        $caught = null;
        try { uvm_cli('source-download', ['case'=>$case]); }
        catch (Throwable $exception) { $caught = $exception; }
        transport_check($caught instanceof RuntimeException, $case . ' must not be treated as a successful backend receipt');
        transport_check(($caught instanceof UvmUnknownResultException) === ($case !== 'failure'), $case . ': only an explicit terminal failure is safe to retry');
        transport_check(!str_contains($caught->getMessage(), 'glpat-FIXTUREONLY'), 'No child failure can expose a fixture credential');
    }
    unlink($entrypoint);
    $caught = null;
    try { uvm_cli('source-download'); }
    catch (Throwable $exception) { $caught = $exception; }
    transport_check($caught instanceof RuntimeException && !$caught instanceof UvmUnknownResultException, 'A missing CLI fails before dispatch and is a known failure');
    echo 'UI process transport: ' . $checks . " checks passed (local PHP fixture; no installed CLI, NAS or GPU)\n";
} finally {
    if (is_file($entrypoint)) unlink($entrypoint);
    rmdir($directory);
}
