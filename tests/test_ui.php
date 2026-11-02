<?php
declare(strict_types=1);

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';

use Vgpu\Process;
use Vgpu\Store;

$pagePath = __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/unraid-vgpu-manager.page';
$page = (string)file_get_contents($pagePath);

if (($argv[1] ?? '') === '--render-fixture') {
    // Render the actual page in a separate PHP process. Replace only the I/O
    // boundaries in memory; the production file and real host are untouched.
    $ui_fixture = json_decode((string)stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    $ui_calls = [];
    function uvm_cli(string $action, array $payload = []): array
    {
        global $ui_fixture, $ui_calls;
        $ui_calls[] = ['action' => $action, 'payload' => $payload];
        if (isset($ui_fixture['errors'][$action])) throw new RuntimeException($ui_fixture['errors'][$action]);
        return $action === 'status' ? $ui_fixture['status'] : ($ui_fixture['responses'][$action] ?? ['message' => 'fixture operation completed']);
    }
    function uvm_profile_scan(): array
    {
        global $ui_fixture;
        return $ui_fixture['gpus'] ?? [];
    }
    function uvm_read(string $path, int $limit = 65536): string
    {
        return $path === '/sys/module/nvidia/version' ? '595.58.03' : '';
    }
    foreach (['uvm_cli', 'uvm_profile_scan', 'uvm_read'] as $function) {
        $page = str_replace('function ' . $function . '(', 'function uvm_original_' . substr($function, 4) . '(', $page);
    }
    $var = ['csrf_token' => 'fixture-ui-csrf'];
    $_SERVER = array_replace([
        'REQUEST_METHOD' => 'GET', 'HTTPS' => 'on', 'HTTP_HOST' => 'unraid.example.test',
        'HTTP_ORIGIN' => 'https://unraid.example.test', 'HTTP_SEC_FETCH_SITE' => 'same-origin',
    ], $ui_fixture['server'] ?? []);
    foreach ($_SERVER as $key => $value) if ($value === null) unset($_SERVER[$key]);
    $_POST = $ui_fixture['post'] ?? [];
    $_FILES = $ui_fixture['files'] ?? [];
    ob_start();
    eval('?>' . $page);
    $html = ob_get_clean();
    echo json_encode(['html' => $html, 'calls' => $ui_calls], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$uiChecks = 0;
function ui_check(bool $condition, string $message): void
{
    global $uiChecks;
    if (!$condition) throw new RuntimeException($message);
    ++$uiChecks;
}

function ui_render(array $fixture): array
{
    $response = (new Process())([PHP_BINARY, '-n', __FILE__, '--render-fixture'], 10, json_encode($fixture, JSON_THROW_ON_ERROR));
    ui_check($response['code'] === 0 && $response['stderr'] === '', 'UI fixture renderer must complete cleanly: ' . $response['stderr']);
    $data = json_decode($response['stdout'], true, 64, JSON_THROW_ON_ERROR);
    ui_check(is_array($data) && isset($data['html'], $data['calls']), 'UI fixture renderer must return HTML and recorded calls');
    return $data;
}

function ui_actions(array $rendered): array
{
    return array_column($rendered['calls'], 'action');
}

$defaults = Store::defaults();
$status = [
    'settings' => $defaults, 'devices' => [], 'credential_configured' => false,
    'runtime' => ['ok' => true, 'runtime' => ['kernel' => '6.18.58-Unraid', 'loaded_driver' => '595.58.03']],
    'override_content' => '', 'pending_restart' => false,
];
$base = ['status' => $status];
$config = ['csrf_token' => 'fixture-ui-csrf', 'uvm_action' => 'config-save'];
foreach ($defaults['source'] as $key => $value) $config['source_' . $key] = $value;
$config += [
    'runtime_enabled' => '0', 'runtime_unlock' => '1',
    'runtime_unlock_library' => '/usr/lib64/libvgpu_unlock_rs.so', 'runtime_xml_override' => '0',
    'credential_change' => 'keep',
];

$rendered = ui_render($base);
ui_check(ui_actions($rendered) === ['status'], 'GET must only call status; no automatic network, import or ensure');
ui_check(str_contains($rendered['html'], 'uvm-source-url') && str_contains($rendered['html'], 'GitHub / GitLab'), 'UI must offer a repository or release URL as the primary input');
ui_check(str_contains($rendered['html'], 'name="credential_token"') && preg_match('/name="credential_token"[^>]*value=""/', $rendered['html']) === 1, 'Token must render as an empty password field');
ui_check(str_contains($rendered['html'], '<summary>高级设置</summary>') && str_contains($rendered['html'], '下载到缓存'), 'Protocol options must be folded into advanced settings');
ui_check(str_contains($rendered['html'], '跟随 Unraid 更新驱动') && str_contains($rendered['html'], '下次开机安装'), 'UI must expose opt-in OS-following preparation and boot installation');
ui_check(!str_contains($rendered['html'], '不等于完整 GPU 验收') && !str_contains($rendered['html'], '不执行 ensure'), 'Status copy must not repeat implementation caveats');
ui_check(str_contains($rendered['html'], 'uvm-upload-package') && str_contains($rendered['html'], 'source-upload-chunk'), 'Local package upload must be exposed');
ui_check(str_contains($rendered['html'], 'window.location.replace(') && !str_contains($rendered['html'], 'window.location.reload('), 'Upload completion must navigate with GET rather than replaying an earlier POST');
ui_check(str_contains($rendered['html'], 'name="upgrade_enabled"') && str_contains($rendered['html'], '<h2>系统升级</h2>'), 'OS following must have one setting and a preparation status panel');

$attack = '<img src=x onerror="alert(1)"></script><script>alert(2)</script>';
$xss = $base;
$xss['status']['settings']['source']['repository'] = 'https://example.test/' . $attack;
$xss['status']['override_content'] = $attack;
$xss['gpus'] = ['0000:01:00.0' => ['device' => '0x1f82', 'profiles' => [[
    'type' => 'nvidia-762', 'name' => $attack, 'available' => '1', 'framebuffer' => '1024M',
    'heads' => '4', 'resolution' => '5120x2880', 'frl' => '60', 'maximum' => '24',
]]]];
$rendered = ui_render($xss);
ui_check(!str_contains($rendered['html'], $attack), 'HTML/attribute/JSON content must escape markup');
ui_check(substr_count($rendered['html'], '<script>') === 1, 'Embedded data must not inject another script element');
ui_check(str_contains($rendered['html'], '\u003C'), 'Profile JSON must use script-safe escaping');
ui_check(str_contains($rendered['html'], '&lt;img'), 'Form and textarea data must be HTML-escaped');

$post = $config;
$post['credential_change'] = 'replace';
$post['credential_type'] = 'private-token';
$post['credential_token'] = 'ui-fixture-secret-value';
$post['credential_username'] = 'fixture-user';
$post['source_repository'] = 'https://git.example.test/group/driver';
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $post]);
ui_check(ui_actions($rendered) === ['config-save', 'status'], 'Explicit config POST must save once then read status');
ui_check($rendered['calls'][0]['payload']['credential']['token'] === 'ui-fixture-secret-value', 'Token must reach backend JSON payload unchanged');
ui_check($rendered['calls'][0]['payload']['runtime']['enabled'] === false, 'Runtime boolean must preserve disabled default');
ui_check(!str_contains($rendered['html'], 'ui-fixture-secret-value'), 'Submitted token must never reappear in rendered HTML');
ui_check(!isset($rendered['calls'][0]['payload']['override_content']), 'Unchecked override must not overwrite existing file');
$post['credential_change'] = 'keep';
$post['override_replace'] = '1';
$post['override_content'] = "[fixture]\r\nvalue = true\r\n";
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $post]);
ui_check(!isset($rendered['calls'][0]['payload']['credential']), 'Keep credential must omit credential payload, even with stale browser input');
ui_check($rendered['calls'][0]['payload']['override_content'] === "[fixture]\nvalue = true\n", 'Explicit override replacement must normalize CR');
$post['credential_change'] = 'remove';
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $post]);
ui_check($rendered['calls'][0]['payload']['credential'] === ['type' => 'none', 'token' => '', 'username' => ''], 'Explicit removal must clear, not retain, credentials');

foreach ([
    ['post' => array_replace($config, ['csrf_token' => 'wrong'])],
    ['server' => ['HTTP_ORIGIN' => 'https://attacker.example.test']],
    ['server' => ['HTTP_ORIGIN' => 'null']],
    ['server' => ['HTTP_ORIGIN' => 'http://unraid.example.test']],
    ['server' => ['HTTP_SEC_FETCH_SITE' => 'same-site']],
    ['server' => ['HTTP_ORIGIN' => null, 'HTTP_REFERER' => null]],
] as $case) {
    $fixture = $base;
    $fixture['server'] = array_replace(['REQUEST_METHOD' => 'POST'], $case['server'] ?? []);
    $fixture['post'] = $case['post'] ?? $config;
    $rendered = ui_render($fixture);
    ui_check(ui_actions($rendered) === ['status'], 'Rejected CSRF/origin must cause no mutation');
    ui_check(str_contains($rendered['html'], 'role="alert"'), 'Rejected request must display an honest error');
}
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => null, 'HTTP_REFERER' => 'https://unraid.example.test/Settings/Plugin'], 'post' => $config]);
ui_check(ui_actions($rendered) === ['config-save', 'status'], 'Same-origin Referer fallback must work');

foreach (['source-test', 'source-versions', 'source-download'] as $action) {
    $post = array_replace($config, ['uvm_action' => $action, 'source_repository' => 'https://unsaved.example.test/not/saved']);
    $rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $post]);
    ui_check(ui_actions($rendered) === [$action, 'status'], 'Manual source operation must not implicitly save configuration');
    ui_check($rendered['calls'][0]['payload'] === [], 'Manual source operation must use saved source, not posted unsaved inputs');
}
$rendered = ui_render($base + [
    'server' => ['REQUEST_METHOD' => 'POST'],
    'post' => ['csrf_token' => 'fixture-ui-csrf', 'uvm_action' => 'source-download'],
    'errors' => ['source-download' => 'Download failed glpat-UIFIXTURETOKEN <script>not-safe</script>'],
]);
ui_check(str_contains($rendered['html'], 'Download failed [redacted] &lt;script&gt;'), 'Backend errors must escape markup and redact recognized token formats');
ui_check(!str_contains($rendered['html'], 'glpat-UIFIXTURETOKEN') && !str_contains($rendered['html'], '驱动包下载操作已完成'), 'Failed download must not leak token or claim success');

// The basic form needs a link and, for private repositories, one token input.
$simple = array_replace($config, [
    'source_url' => 'https://git.example.test/group/driver', 'source_simple' => '1',
    'credential_change' => 'auto', 'credential_type' => 'auto',
    'credential_token' => 'ui-auto-fixture-token', 'source_connect' => '1',
]);
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $simple,
    'responses' => ['source-versions' => ['versions' => [['driver' => '595.58.03', 'kernel' => '6.18.58-Unraid', 'filename' => 'nvidia-595.58.03-6.18.58-Unraid-1.txz']]]]]);
ui_check(ui_actions($rendered) === ['config-save', 'source-versions', 'status'], 'Find drivers must explicitly save the two-field form before lookup');
ui_check($rendered['calls'][0]['payload']['source']['url'] === $simple['source_url'], 'Basic URL must reach the source resolver');
ui_check($rendered['calls'][0]['payload']['credential']['type'] === 'auto', 'Basic token must use automatic authentication');
ui_check(!str_contains($rendered['html'], 'ui-auto-fixture-token'), 'Auto token must not be echoed');
$simple['credential_token'] = '';
$simple['source_connect'] = '0';
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $simple]);
ui_check(!isset($rendered['calls'][0]['payload']['credential']), 'Blank basic token must preserve saved credentials');
$simple['source_url'] = '';
$simple['source_repository'] = 'https://old.example.test/group/driver';
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $simple]);
ui_check($rendered['calls'][0]['payload']['source']['repository'] === '', 'Clearing the basic URL must not restore a hidden stale repository');
$simple['upgrade_enabled'] = '1';
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $simple]);
ui_check($rendered['calls'][0]['payload']['upgrade'] === ['enabled' => true], 'Upgrade following must be an explicit saved boolean');
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => [
    'csrf_token' => 'fixture-ui-csrf', 'uvm_action' => 'upgrade-check', 'target_kernel' => '1.2.3-Unraid',
], 'responses' => ['upgrade-check' => ['ok' => true, 'status' => 'ready']]]);
ui_check(ui_actions($rendered) === ['upgrade-check', 'status'], 'Checking preparation must not run boot, installation or save an unrelated form');
ui_check($rendered['calls'][0]['payload'] === ['force' => true], 'The WebGUI must retry immediately but derive the upgrade target from OS metadata, not a posted kernel');
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => [
    'csrf_token' => 'wrong', 'uvm_action' => 'upgrade-check',
]]);
ui_check(ui_actions($rendered) === ['status'], 'Upgrade checks must require native CSRF validation');
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => [
    'csrf_token' => 'fixture-ui-csrf', 'uvm_action' => 'source-download', 'download_driver' => '580.159.03',
]]);
ui_check($rendered['calls'][0]['payload'] === ['driver' => '580.159.03'], 'Download version selection must be an explicit per-request override');

$upload = ['csrf_token' => 'fixture-ui-csrf', 'uvm_action' => 'source-upload-begin',
    'filename' => 'nvidia-595.58.03-6.18.58-Unraid-1.txz', 'total' => '200'];
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $upload,
    'responses' => ['source-upload-begin' => ['upload_id' => str_repeat('a', 32), 'offset' => 0, 'max_chunk_size' => 1048576]]]);
ui_check(ui_actions($rendered) === ['source-upload-begin'], 'Upload AJAX must avoid repeatedly rendering status and profiles');
ui_check(str_contains($rendered['html'], 'id="uvm-upload-result"') && str_contains($rendered['html'], '"ok":true'), 'Upload result must be readable inside the Unraid HTML wrapper');
ui_check($rendered['calls'][0]['payload']['total'] === 200, 'Upload total must be validated then passed as integer');
$upload['csrf_token'] = 'bad';
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $upload]);
ui_check(ui_actions($rendered) === [] && str_contains($rendered['html'], '"ok":false'), 'Upload CSRF failure must not invoke any backend action');
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => [
    'csrf_token' => 'fixture-ui-csrf', 'uvm_action' => 'source-upload-chunk', 'upload_id' => str_repeat('a', 32), 'offset' => '0',
], 'files' => ['chunk' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/phpNotAnHTTPUpload']]]);
ui_check(ui_actions($rendered) === [] && str_contains($rendered['html'], '"ok":false'), 'A forged filesystem path must fail is_uploaded_file before reaching the backend');

// Keep the security boundary visible in regression tests, without exercising it
// against /usr/bin/php or any Unraid service on the development machine.
ui_check(str_contains($page, "proc_open(['/usr/bin/php', \$entrypoint, \$action]"), 'Page must use argv proc_open for the single CLI boundary');
ui_check(str_contains($page, 'hash_equals($expected, uvm_post(\'csrf_token\'))'), 'Page must explicitly validate native CSRF token');
$forbidden = ['shell_exec', 'system', 'exec', 'passthru', 'file_put_contents', 'unlink', 'mkdir', 'rmdir'];
foreach ($forbidden as $function) ui_check(preg_match('/\b' . preg_quote($function, '/') . '\s*\(/i', $page) !== 1, 'Page must not directly execute commands or write files: ' . $function);
$start = strpos($page, 'function uvm_cli(');
$end = strpos($page, 'function uvm_csrf_token(', $start);
$boundary = substr($page, $start, $end - $start);
ui_check(str_contains($boundary, "\$entrypoint = '/usr/local/emhttp/plugins/unraid-vgpu-manager/include/cli.php';"), 'CLI helper must use the absolute installed entrypoint under the independent plugin ID');
ui_check(!str_contains($boundary, '__DIR__'), 'CLI helper must not depend on the directory of an evaluated/cached Unraid page');
ui_check(!str_contains($boundary, "'ensure'") && !str_contains($boundary, "'import-legacy'"), 'Web action allowlist must not offer startup or automatic migration');

$node = null;
foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
    if ($directory !== '' && is_executable($directory . '/node')) { $node = $directory . '/node'; break; }
}
if ($node !== null) {
    $rendered = ui_render($xss);
    ui_check(preg_match('~<script>\s*(.*?)\s*</script>~s', $rendered['html'], $script) === 1, 'Rendered page must contain one JS block');
    $checked = (new Process())([$node, '--check'], 5, $script[1]);
    ui_check($checked['code'] === 0, 'Rendered JavaScript syntax must be valid: ' . $checked['stderr']);
}
echo 'UI integration: ' . $uiChecks . " checks passed (mock CLI/sysfs; no GPU or network)\n";
