<?php
declare(strict_types=1);

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';
require_once __DIR__ . '/ui_template.php';

use Vgpu\Process;
use Vgpu\Store;

$prefix = __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager';

if (($argv[1] ?? '') === '--render-fixture') {
    // Render the actual page in a separate PHP process. Replace only the I/O
    // boundaries in memory; the production file and real host are untouched.
    $ui_fixture = json_decode((string)stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    $ui_calls = [];
    $ui_profile_scans = 0;
    function uvm_cli(string $action, array $payload = []): array
    {
        global $ui_fixture, $ui_calls;
        $ui_calls[] = ['action' => $action, 'payload' => $payload];
        if (isset($ui_fixture['errors'][$action])) throw new RuntimeException($ui_fixture['errors'][$action]);
        return $action === 'status' ? $ui_fixture['status'] : ($ui_fixture['responses'][$action] ?? ['message' => 'fixture operation completed']);
    }
    function uvm_profile_scan(): array
    {
        global $ui_fixture, $ui_profile_scans;
        ++$ui_profile_scans;
        return $ui_fixture['gpus'] ?? [];
    }
    function uvm_read(string $path, int $limit = 65536): string
    {
        return $path === '/sys/module/nvidia/version' ? '595.58.03' : '';
    }
    $var = ['csrf_token' => 'fixture-ui-csrf'];
    $_SERVER = array_replace([
        'REQUEST_METHOD' => 'GET', 'HTTPS' => 'on', 'HTTP_HOST' => 'unraid.example.test',
        'HTTP_ORIGIN' => 'https://unraid.example.test', 'HTTP_SEC_FETCH_SITE' => 'same-origin',
    ], $ui_fixture['server'] ?? []);
    foreach ($_SERVER as $key => $value) if ($value === null) unset($_SERVER[$key]);
    $_POST = $ui_fixture['post'] ?? [];
    $_FILES = $ui_fixture['files'] ?? [];
    $_GET = $ui_fixture['get'] ?? [];
    if (($ui_fixture['gateway_strip_csrf'] ?? false) === true) {
        if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($var['csrf_token'], $_POST['csrf_token'])) {
            throw new RuntimeException('The gateway fixture requires a valid native token');
        }
        unset($_POST['csrf_token']);
    }
    $html = '';
    $panels = [];
    $ui_tabs = isset($ui_fixture['single_tab']) ? [$ui_fixture['single_tab']] : ['driver', 'vgpu', 'status'];
    foreach ($ui_tabs as $ui_tab) {
        $page = ui_template($ui_tab);
        foreach (['uvm_cli', 'uvm_profile_scan', 'uvm_read'] as $function) {
            $page = str_replace('function ' . $function . '(', 'function uvm_original_' . substr($function, 4) . '(', $page);
        }
        ob_start();
        eval('?>' . $page);
        $panels[$ui_tab] = ob_get_clean();
        $html .= '<section id="fixture-' . $ui_tab . '-panel" role="tabpanel">' . $panels[$ui_tab] . '</section>';
    }
    echo json_encode(['html' => $html, 'panels' => $panels, 'calls' => $ui_calls, 'profile_scans' => $ui_profile_scans], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
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

function ui_panel(array $rendered, string $tab): string
{
    return $rendered['panels'][$tab];
}

$defaults = Store::defaults();
$status = [
    'settings' => $defaults, 'devices' => [], 'credential_configured' => false,
    'runtime' => ['ok' => true, 'runtime' => ['kernel' => '6.18.58-Unraid', 'loaded_driver' => '595.58.03']],
    'override_content' => '', 'pending_restart' => false,
];
$base = ['status' => $status];
$config = ['csrf_token' => 'fixture-ui-csrf', 'uvm_csrf_token' => 'fixture-ui-csrf', 'uvm_action' => 'config-save', 'uvm_tab' => 'driver', 'config_section' => 'source'];
foreach ($defaults['source'] as $key => $value) $config['source_' . $key] = $value;
$config += [
    'runtime_enabled' => '0', 'runtime_unlock' => '1',
    'runtime_unlock_library' => '/usr/lib64/libvgpu_unlock_rs.so', 'runtime_xml_override' => '0',
    'credential_change' => 'keep',
];

$parent = (string)file_get_contents($prefix . '/unraid-vgpu-manager.page');
ui_check(str_contains($parent, 'Type="xmenu"') && str_contains($parent, 'Tabs="true"'), 'Parent must use native Unraid tab navigation');
ui_check(!str_contains($parent, '<form') && !str_contains($parent, 'uvm_cli'), 'Parent menu must not duplicate a content page or execute actions');
foreach (['driver' => ['1', '驱动管理'], 'vgpu' => ['2', 'vGPU 管理'], 'status' => ['3', '状态与日志']] as $tab => [$order, $title]) {
    $child = (string)file_get_contents($prefix . '/unraid-vgpu-manager-' . $tab . '.page');
    ui_check(str_contains($child, 'Menu="unraid-vgpu-manager:' . $order . '"') && str_contains($child, 'Title="' . $title . '"'), 'Each tab must have its ordered native menu entry');
    $parts = preg_split('/^---\s*$/m', $child, 2);
    ui_check(preg_match('/^Markdown="false"$/m', $parts[0]) === 1, 'Each PHP-only child must explicitly disable native Markdown');
    ui_check(preg_match('/\A\s*<\?php\b[\s\S]*?\?>\s*\z/', $parts[1]) === 1, 'Each child entrypoint must be a complete, explicitly closed PHP block');
    ui_check(ui_native_page_content($child) === $parts[1], 'Native generation must preserve the declared PHP-only child content');

    // Reproduce the published Alpha 5 bug before replacing I/O boundaries:
    // default Markdown + no closing tag displays PHP rather than running it.
    $entrypoint = "require '/usr/local/emhttp/plugins/unraid-vgpu-manager/include/ui-page.php';";
    $legacyClosed = preg_replace('/^Markdown="false"\R/m', '', $child);
    $legacyClosed = str_replace($entrypoint, '$ui_native_entrypoint_calls++;', $legacyClosed);
    $legacyUnclosed = preg_replace('/\?>\s*\z/', '', $legacyClosed);
    $ui_native_entrypoint_calls = 0;
    $uvm_tab = 'not-executed';
    $unclosedContent = ui_native_page_content($legacyUnclosed);
    ob_start();
    eval('?>' . $unclosedContent);
    $literalOutput = ob_get_clean();
    ui_check($ui_native_entrypoint_calls === 0 && $uvm_tab === 'not-executed', 'The native boundary must reproduce unclosed PHP being inert under default Markdown');
    ui_check(str_contains($literalOutput, '&lt;?php') && str_contains($literalOutput, '$uvm_tab'), 'The broken wrapper must reproduce PHP source shown as text');
    ob_start();
    eval('?>' . ui_native_page_content($legacyClosed));
    $closedOutput = ob_get_clean();
    ui_check($ui_native_entrypoint_calls === 1 && $uvm_tab === $tab && trim($closedOutput) === '', 'Explicitly closed PHP must execute even if native Markdown is enabled');
}

$rendered = ui_render($base);
ui_check(ui_actions($rendered) === ['status', 'logs'], 'All native tab bodies must share one local status read and one log read, without remote work');
ui_check($rendered['profile_scans'] === 1, 'All native tabs must share one profile scan');
ui_check(str_contains($rendered['html'], 'uvm-source-url') && str_contains($rendered['html'], 'GitHub / GitLab'), 'UI must offer a repository or release URL as the primary input');
ui_check(str_contains($rendered['html'], 'name="credential_token"') && preg_match('/name="credential_token"[^>]*value=""/', $rendered['html']) === 1, 'Token must render as an empty password field');
ui_check(str_contains($rendered['html'], '<summary>高级设置</summary>') && str_contains($rendered['html'], '下载到缓存'), 'Protocol options must be folded into advanced settings');
ui_check(str_contains($rendered['html'], '跟随 Unraid 更新驱动') && str_contains($rendered['html'], '下次开机安装'), 'UI must expose opt-in OS-following preparation and boot installation');
ui_check(!str_contains($rendered['html'], '不等于完整 GPU 验收') && !str_contains($rendered['html'], '不执行 ensure'), 'Status copy must not repeat implementation caveats');
ui_check(str_contains($rendered['html'], 'uvm-upload-package') && str_contains($rendered['html'], 'source-upload-chunk'), 'Local package upload must be exposed');
ui_check(str_contains($rendered['html'], 'window.location.replace(') && !str_contains($rendered['html'], 'window.location.reload('), 'Upload completion must navigate with GET rather than replaying an earlier POST');
ui_check(str_contains($rendered['html'], 'name="upgrade_enabled"') && str_contains($rendered['html'], '升级准备'), 'OS following must have one setting and a preparation status panel');
ui_check(str_contains($rendered['html'], 'name="csrf_token"') && str_contains($rendered['html'], 'name="uvm_csrf_token"'), 'Forms must include native gateway and plugin CSRF fields');
ui_check(!str_contains(ui_panel($rendered, 'driver'), 'name="runtime_enabled"') && !str_contains(ui_panel($rendered, 'driver'), 'id="uvm-device-form"'), 'Driver tab must not render runtime controls');
ui_check(str_contains(ui_panel($rendered, 'driver'), 'name="config_section" value="source"'), 'Source form must identify its independent save section');

$vgpuRendered = ui_render($base + ['tab' => 'vgpu']);
ui_check(ui_actions($vgpuRendered) === ['status', 'logs'], 'vGPU tab GET must not start devices or query remote sources');
ui_check(str_contains(ui_panel($vgpuRendered, 'vgpu'), 'name="runtime_enabled"') && str_contains(ui_panel($vgpuRendered, 'vgpu'), 'id="uvm-device-form"'), 'vGPU tab must contain boot settings and the device editor');
ui_check(!str_contains(ui_panel($vgpuRendered, 'vgpu'), 'name="source_url"') && !str_contains(ui_panel($vgpuRendered, 'vgpu'), 'name="credential_token"'), 'vGPU tab must not render source credentials or download settings');
ui_check(preg_match('/<details[^>]*id="uvm-device-editor"[^>]*>\s*<summary[^>]*>添加设备/u', ui_panel($vgpuRendered, 'vgpu'), $editor) === 1 && preg_match('/\bopen\b/', $editor[0]) !== 1, 'Add device editor must start collapsed');
$statusRendered = ui_render($base + ['tab' => 'status']);
ui_check(ui_actions($statusRendered) === ['status', 'logs'], 'Status tab GET must only read local status and whitelisted logs');
ui_check(!str_contains(ui_panel($statusRendered, 'status'), 'name="runtime_enabled"') && !str_contains(ui_panel($statusRendered, 'status'), 'name="source_url"'), 'Status tab must not render settings forms');
foreach (['driver' => $rendered, 'vgpu' => $vgpuRendered, 'status' => $statusRendered] as $tab => $result) {
    ui_check(str_contains(ui_panel($result, $tab), 'data-tab="' . $tab . '"') && str_contains(ui_panel($result, $tab), 'aria-label="GPU 概览"'), 'Each child must render the shared overview and its own tab identity');
    ui_check(str_contains(ui_panel($result, $tab), '595.58.03') && str_contains(ui_panel($result, $tab), '6.18.58-Unraid'), 'Each overview must use the loaded runtime snapshot');
    preg_match_all('/name="uvm_tab" value="([^"]+)"/', ui_panel($result, $tab), $targets);
    foreach ($targets[1] as $target) ui_check($target === $tab, 'Every form must explicitly target its own native tab');
}
preg_match_all('/\bid="([^"]+)"/', $statusRendered['html'], $ids);
ui_check(count($ids[1]) === count(array_unique($ids[1])), 'All native tab bodies must coexist without duplicate DOM IDs');
ui_check(substr_count($statusRendered['html'], 'window.uvmUI =') === 1, 'Shared client state must initialize only once across native tabs');
foreach (['driver', 'vgpu', 'status'] as $tab) {
    $direct = ui_render($base + ['single_tab' => $tab]);
    ui_check(ui_actions($direct) === ($tab === 'status' ? ['status', 'logs'] : ['status']), 'A direct child URL must render independently with only its local reads');
    ui_check(str_contains($direct['html'], 'id="uvm-manager-' . $tab . '"'), 'Direct child rendering must retain its own scoped root');
}
ui_check(preg_match('/<section[^>]*id="uvm-local-panel"[^>]*\bhidden\b/', ui_render($base)['html']) === 1, 'Local upload must be an alternate mode rather than a second expanded panel');
$localRendered = ui_render($base + ['get' => ['driver_mode' => 'local']]);
ui_check(preg_match('/<section[^>]*id="uvm-online-panel"[^>]*\bhidden\b/', $localRendered['html']) === 1 && preg_match('/<section[^>]*id="uvm-local-panel"[^>]*\bhidden\b/', $localRendered['html']) !== 1, 'Local mode must render the upload panel and hide online settings');
foreach (['kernel', 'vgpu', '../../etc/shadow', ['bad']] as $category) {
    $rendered = ui_render($base + ['tab' => 'status', 'get' => ['log_category' => $category]]);
    ui_check($rendered['calls'][1]['payload'] === ['category' => is_string($category) && in_array($category, ['kernel', 'vgpu'], true) ? $category : 'plugin'], 'Log GET must pass only a known category to the local backend');
}
$logAttack = '<img src=x onerror=alert(1)>';
$rendered = ui_render($base + ['tab' => 'status', 'responses' => ['logs' => ['category' => 'plugin', 'available' => true, 'lines' => [$logAttack], 'truncated' => false]]]);
ui_check(!str_contains($rendered['html'], $logAttack) && str_contains($rendered['html'], '&lt;img'), 'Log content must be escaped rather than interpreted as HTML');

$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $config, 'gateway_strip_csrf' => true]);
ui_check(ui_actions($rendered) === ['config-save', 'status', 'logs'], 'A gateway-validated native token may be consumed before the plugin page receives POST');
$withoutMirror = $config;
unset($withoutMirror['uvm_csrf_token']);
foreach ([$withoutMirror, array_replace($config, ['uvm_csrf_token' => 'wrong']), array_replace($config, ['uvm_csrf_token' => ['bad']])] as $badPost) {
    $rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $badPost, 'gateway_strip_csrf' => true]);
    ui_check(ui_actions($rendered) === ['status', 'logs'], 'Missing, invalid and non-scalar plugin CSRF fields must fail after native gateway validation');
}

$attack = '<img src=x onerror="alert(1)"></script><script>alert(2)</script>';
$xss = $base;
$xss['status']['settings']['source']['repository'] = 'https://example.test/' . $attack;
$xss['status']['override_content'] = $attack;
$xss['gpus'] = ['0000:01:00.0' => ['device' => '0x1f82', 'profiles' => [[
    'type' => 'nvidia-762', 'name' => $attack, 'available' => '1', 'framebuffer' => '1024M',
    'heads' => '4', 'resolution' => '5120x2880', 'frl' => '60', 'maximum' => '24',
]]]];
$rendered = ui_render($xss);
ui_check(!str_contains($rendered['html'], $attack) && str_contains($rendered['html'], '&lt;img'), 'Source form content must escape markup');
$rendered = ui_render($xss + ['tab' => 'vgpu']);
ui_check(!str_contains($rendered['html'], $attack), 'Device HTML/attribute/JSON content must escape markup');
ui_check(str_contains($rendered['html'], '\u003C'), 'Profile JSON must use script-safe escaping');
ui_check(str_contains($rendered['html'], '&lt;img'), 'Profile override textarea data must be HTML-escaped');

$post = $config;
$post['credential_change'] = 'replace';
$post['credential_type'] = 'private-token';
$post['credential_token'] = 'ui-fixture-secret-value';
$post['credential_username'] = 'fixture-user';
$post['source_repository'] = 'https://git.example.test/group/driver';
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $post]);
ui_check(ui_actions($rendered) === ['config-save', 'status', 'logs'], 'Explicit config POST must save once then read status');
ui_check($rendered['calls'][0]['payload']['credential']['token'] === 'ui-fixture-secret-value', 'Token must reach backend JSON payload unchanged');
ui_check(!isset($rendered['calls'][0]['payload']['runtime']), 'Saving source must not submit runtime booleans');
ui_check(!str_contains($rendered['html'], 'ui-fixture-secret-value'), 'Submitted token must never reappear in rendered HTML');
ui_check(!isset($rendered['calls'][0]['payload']['override_content']), 'Unchecked override must not overwrite existing file');
$post['credential_change'] = 'keep';
$post['override_replace'] = '1';
$post['override_content'] = "[fixture]\r\nvalue = true\r\n";
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $post]);
ui_check(!isset($rendered['calls'][0]['payload']['credential']), 'Keep credential must omit credential payload, even with stale browser input');
ui_check(!isset($rendered['calls'][0]['payload']['override_content']), 'Source save must ignore posted profile override fields');
$post['credential_change'] = 'remove';
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $post]);
ui_check($rendered['calls'][0]['payload']['credential'] === ['type' => 'none', 'token' => '', 'username' => ''], 'Explicit removal must clear, not retain, credentials');

$runtimePost = array_replace($config, [
    'uvm_tab' => 'vgpu', 'config_section' => 'runtime', 'runtime_enabled' => '1', 'runtime_unlock' => '1',
    'override_replace' => '1', 'override_content' => "[fixture]\r\nvalue = true\r\n",
    'credential_change' => 'replace', 'credential_token' => 'unrelated-source-token',
    'upgrade_enabled' => '0', 'source_connect' => '1',
]);
$rendered = ui_render($base + ['tab' => 'vgpu', 'server' => ['REQUEST_METHOD' => 'POST'], 'post' => $runtimePost]);
ui_check(ui_actions($rendered) === ['config-save', 'status', 'logs'], 'Runtime save must stay on its tab without a source lookup');
ui_check($rendered['calls'][0]['payload']['runtime']['enabled'] === true, 'Runtime save must carry its own booleans');
ui_check($rendered['calls'][0]['payload']['override_content'] === "[fixture]\nvalue = true\n", 'Runtime override replacement must normalize CR');
foreach (['source', 'credential', 'upgrade'] as $otherSection) ui_check(!isset($rendered['calls'][0]['payload'][$otherSection]), 'Runtime save must not alter ' . $otherSection);
ui_check(str_contains($rendered['html'], 'const preferredTab = "vgpu";'), 'Runtime save must select the vGPU native tab after the full page renders');
ui_check(str_contains(ui_panel($rendered, 'vgpu'), 'vGPU 设置已保存。') && !str_contains(ui_panel($rendered, 'driver'), 'vGPU 设置已保存。'), 'Success notices must stay inside the tab that submitted the request');
$withoutOverride = array_replace($runtimePost, ['override_replace' => '0']);
$rendered = ui_render($base + ['tab' => 'vgpu', 'server' => ['REQUEST_METHOD' => 'POST'], 'post' => $withoutOverride]);
ui_check(!isset($rendered['calls'][0]['payload']['override_content']), 'Unchecked runtime override editor must preserve the stored file');
foreach ([
    array_replace($config, ['uvm_tab' => 'vgpu']),
    array_replace($runtimePost, ['uvm_tab' => 'driver']),
    array_replace($config, ['uvm_tab' => 'status']),
    array_replace($config, ['uvm_action' => 'device-start']),
    array_replace($config, ['uvm_tab' => 'vgpu', 'uvm_action' => 'source-download']),
    array_replace($config, ['uvm_tab' => 'unknown']),
    array_replace($config, ['uvm_tab' => ['driver']]),
    array_diff_key($config, ['uvm_tab' => true]),
] as $post) {
    $rendered = ui_render($base + ['post' => $post, 'server' => ['REQUEST_METHOD' => 'POST']]);
    ui_check(ui_actions($rendered) === ['status', 'logs'], 'Cross-tab actions, missing targets and wrong configuration sections must be rejected');
}
foreach (['', 'unknown'] as $section) {
    $rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => array_replace($config, ['config_section' => $section])]);
    ui_check(ui_actions($rendered) === ['status', 'logs'], 'Configuration saves must name the current tab section');
}

$deviceInput = ['uuid' => 'ae9572f6-1259-4136-9aa4-497dda98d2db', 'pci' => '0000:01:00.0', 'profile' => 'NVIDIA RTXA5500-1Q', 'type' => 'nvidia-762'];
foreach (['device-save', 'device-start', 'device-stop', 'device-remove'] as $action) {
    $post = array_replace($config, $deviceInput, ['uvm_tab' => 'vgpu', 'uvm_action' => $action]);
    $rendered = ui_render($base + ['tab' => 'vgpu', 'server' => ['REQUEST_METHOD' => 'POST'], 'post' => $post]);
    ui_check(ui_actions($rendered) === [$action, 'status', 'logs'], 'Each device operation must invoke only its explicit backend action and then read status');
    ui_check($rendered['calls'][0]['payload'] === ($action === 'device-save' ? $deviceInput : ['uuid' => $deviceInput['uuid']]), 'Device operations must not forward unrelated source/runtime fields');
    ui_check(str_contains($rendered['html'], 'data-tab="vgpu"'), 'Device result must remain on the vGPU tab');
}

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
    ui_check(ui_actions($rendered) === ['status', 'logs'], 'Rejected CSRF/origin must cause no mutation');
    ui_check(str_contains($rendered['html'], 'role="alert"'), 'Rejected request must display an honest error');
}
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => null, 'HTTP_REFERER' => 'https://unraid.example.test/Settings/Plugin'], 'post' => $config]);
ui_check(ui_actions($rendered) === ['config-save', 'status', 'logs'], 'Same-origin Referer fallback must work');

foreach (['source-test', 'source-versions', 'source-download'] as $action) {
    $post = array_replace($config, ['uvm_action' => $action, 'source_repository' => 'https://unsaved.example.test/not/saved']);
    $rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $post]);
    ui_check(ui_actions($rendered) === [$action, 'status', 'logs'], 'Manual source operation must not implicitly save configuration');
    ui_check($rendered['calls'][0]['payload'] === [], 'Manual source operation must use saved source, not posted unsaved inputs');
}
$rendered = ui_render($base + [
    'server' => ['REQUEST_METHOD' => 'POST'],
    'post' => ['csrf_token' => 'fixture-ui-csrf', 'uvm_csrf_token' => 'fixture-ui-csrf', 'uvm_tab' => 'driver', 'uvm_action' => 'source-download'],
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
ui_check(ui_actions($rendered) === ['config-save', 'source-versions', 'status', 'logs'], 'Find drivers must explicitly save the two-field form before lookup');
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
    'csrf_token' => 'fixture-ui-csrf', 'uvm_csrf_token' => 'fixture-ui-csrf', 'uvm_tab' => 'driver', 'uvm_action' => 'upgrade-check', 'target_kernel' => '1.2.3-Unraid',
], 'responses' => ['upgrade-check' => ['ok' => true, 'status' => 'ready']]]);
ui_check(ui_actions($rendered) === ['upgrade-check', 'status', 'logs'], 'Checking preparation must not run boot, installation or save an unrelated form');
ui_check($rendered['calls'][0]['payload'] === ['force' => true], 'The WebGUI must retry immediately but derive the upgrade target from OS metadata, not a posted kernel');
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => [
    'csrf_token' => 'wrong', 'uvm_csrf_token' => 'fixture-ui-csrf', 'uvm_tab' => 'driver', 'uvm_action' => 'upgrade-check',
]]);
ui_check(ui_actions($rendered) === ['status', 'logs'], 'Upgrade checks must require native CSRF validation');
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => [
    'csrf_token' => 'fixture-ui-csrf', 'uvm_csrf_token' => 'fixture-ui-csrf', 'uvm_tab' => 'driver', 'uvm_action' => 'source-download', 'download_driver' => '580.159.03',
]]);
ui_check($rendered['calls'][0]['payload'] === ['driver' => '580.159.03'], 'Download version selection must be an explicit per-request override');

$upload = ['csrf_token' => 'fixture-ui-csrf', 'uvm_csrf_token' => 'fixture-ui-csrf', 'uvm_tab' => 'driver', 'uvm_action' => 'source-upload-begin',
    'filename' => 'nvidia-595.58.03-6.18.58-Unraid-1.txz', 'total' => '200'];
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $upload,
    'responses' => ['source-upload-begin' => ['upload_id' => str_repeat('a', 32), 'offset' => 0, 'max_chunk_size' => 1048576]]]);
ui_check(ui_actions($rendered) === ['source-upload-begin'], 'Upload AJAX must avoid repeatedly rendering status and profiles');
ui_check($rendered['profile_scans'] === 0 && substr_count($rendered['html'], 'id="uvm-upload-result"') === 1, 'All native child evaluations must produce one upload result without any profile scan');
ui_check(str_contains($rendered['html'], 'id="uvm-upload-result"') && str_contains($rendered['html'], '"ok":true'), 'Upload result must be readable inside the Unraid HTML wrapper');
ui_check($rendered['calls'][0]['payload']['total'] === 200, 'Upload total must be validated then passed as integer');
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $upload, 'gateway_strip_csrf' => true]);
ui_check(ui_actions($rendered) === ['source-upload-begin'] && str_contains($rendered['html'], '"ok":true'), 'Uploads must also survive the native gateway consuming csrf_token');
$upload['csrf_token'] = 'bad';
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $upload]);
ui_check(ui_actions($rendered) === [] && str_contains($rendered['html'], '"ok":false'), 'Upload CSRF failure must not invoke any backend action');
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => [
    'csrf_token' => 'fixture-ui-csrf', 'uvm_csrf_token' => 'fixture-ui-csrf', 'uvm_tab' => 'driver', 'uvm_action' => 'source-upload-chunk', 'upload_id' => str_repeat('a', 32), 'offset' => '0',
], 'files' => ['chunk' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/phpNotAnHTTPUpload']]]);
ui_check(ui_actions($rendered) === [] && str_contains($rendered['html'], '"ok":false'), 'A forged filesystem path must fail is_uploaded_file before reaching the backend');
$upload['csrf_token'] = 'fixture-ui-csrf';
$upload['uvm_tab'] = 'vgpu';
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $upload]);
ui_check(ui_actions($rendered) === [] && substr_count($rendered['html'], 'id="uvm-upload-result"') === 1 && str_contains($rendered['html'], '"ok":false'), 'Upload requests with the wrong tab target must fail once before calling the backend');

// Keep the security boundary visible in regression tests, without exercising it
// against /usr/bin/php or any Unraid service on the development machine.
$page = (string)file_get_contents($prefix . '/include/ui/common.php')
    . (string)file_get_contents($prefix . '/include/ui-page.php')
    . (string)file_get_contents($prefix . '/include/ui/state.php');
foreach (['driver', 'vgpu', 'status'] as $tab) $page .= (string)file_get_contents($prefix . '/include/ui/' . $tab . '.php');
ui_check(str_contains($page, "proc_open(['/usr/bin/php', \$entrypoint, \$action]"), 'Page must use argv proc_open for the single CLI boundary');
ui_check(str_contains($page, 'hash_equals($expected, uvm_post(\'uvm_csrf_token\'))'), 'Page must explicitly validate its CSRF mirror against the native expected value');
ui_check(str_contains($page, 'hash_equals($expected, uvm_post(\'csrf_token\'))'), 'A retained native CSRF field must still be validated');
ui_check(substr_count($page, "data.append('uvm_csrf_token', csrf)") === 2, 'Both AJAX uploads and cancellation beacons must send the mirror token');
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
    foreach (['all', 'driver', 'vgpu', 'status'] as $tab) {
        $rendered = ui_render($tab === 'all' ? $xss : $xss + ['single_tab' => $tab]);
        preg_match_all('~<script>\s*(.*?)\s*</script>~s', $rendered['html'], $scripts);
        ui_check(count($scripts[1]) >= 1, 'Each tab must include shared form behavior');
        foreach ($scripts[1] as $script) {
            $checked = (new Process())([$node, '--check'], 5, $script);
            ui_check($checked['code'] === 0, 'Rendered ' . $tab . ' JavaScript syntax must be valid: ' . $checked['stderr']);
        }
    }
    $rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $runtimePost]);
    preg_match('~<script>\s*(.*?)\s*</script>~s', $rendered['html'], $sharedScript);
    $sharedJson = json_encode($sharedScript[1], JSON_THROW_ON_ERROR);
    $js = <<<'JS'
const assert = require('node:assert/strict');
const vm = require('node:vm');
for (const modern of [true, false]) {
  const notices = {};
  const callbacks = [];
  const clicks = [];
  const nativeTab = {click: () => clicks.push('vgpu')};
  const document = {
    addEventListener: (event, callback) => { assert.equal(event, 'DOMContentLoaded'); callbacks.push(callback); },
    getElementById: id => {
      if (id.startsWith('uvm-browser-message-')) return notices[id] ||= {};
      if (id === 'uvm-manager-vgpu') return modern ? {closest: () => ({id: 'native-vgpu-panel'})} : null;
      if (id === 'tab2') return nativeTab;
      throw new Error('Unexpected element: ' + id);
    },
    querySelector: selector => {
      assert.equal(selector, '[role="tab"][aria-controls="native-vgpu-panel"]');
      return nativeTab;
    }
  };
  const context = {window: {}, document};
  vm.runInNewContext(SHARED_SCRIPT, context);
  const driver = context.window.uvmUI.forTab('driver');
  const vgpu = context.window.uvmUI.forTab('vgpu');
  assert.equal(vgpu.isBusy(), false);
  driver.setBusy(true);
  assert.equal(vgpu.isBusy(), true);
  driver.message('driver notice', false);
  vgpu.message('runtime notice', true);
  assert.equal(notices['uvm-browser-message-driver'].textContent, 'driver notice');
  assert.equal(notices['uvm-browser-message-vgpu'].textContent, 'runtime notice');
  assert.equal(notices['uvm-browser-message-vgpu'].className, 'uvm-note uvm-error');
  callbacks.forEach(callback => callback());
  assert.deepEqual(clicks, ['vgpu']);
}
JS;
    $checked = (new Process())([$node, '-e', str_replace('SHARED_SCRIPT', $sharedJson, $js)], 5);
    ui_check($checked['code'] === 0, 'Shared JS must preserve native active tab, scope notices and share request busy state: ' . $checked['stderr']);

    $rendered = ui_render($base);
    preg_match_all('~<script>\s*(.*?)\s*</script>~s', ui_panel($rendered, 'status'), $statusScripts);
    $logScript = array_values(array_filter($statusScripts[1], static fn($script) => str_contains($script, 'uvm-log-form')));
    ui_check(count($logScript) === 1, 'Log refresh must have one scoped submit handler');
    $logJson = json_encode($logScript[0], JSON_THROW_ON_ERROR);
    $js = <<<'JS'
const assert = require('node:assert/strict');
const vm = require('node:vm');
const callbacks = [];
const readyCallbacks = [];
const navigations = [];
let prevented = false;
// Model BodyInlineJS injecting CSRF controls even into a GET form.
const elements = {log_category: {value: 'kernel'}, uvm_view: {value: 'status'},
  csrf_token: {value: 'native-injected-secret'}, uvm_csrf_token: {value: 'mirror-secret'}};
const form = {
  elements,
  addEventListener: (event, callback) => { assert.equal(event, 'submit'); callbacks.push(callback); },
  querySelector: selector => {
    assert(selector.includes('log_category'));
    return elements.log_category;
  }
};
const document = {
  getElementById: id => { assert.equal(id, 'uvm-log-form'); return form; },
  addEventListener: (event, callback) => { assert.equal(event, 'DOMContentLoaded'); readyCallbacks.push(callback); }
};
const window = {uvmUI: {isBusy: () => false}, location: {
  get href() { return 'https://unraid.example.test/Settings/unraid-vgpu-manager?csrf_token=stale&other=old#details'; },
  set href(url) { navigations.push(url); },
  assign: url => navigations.push(url)
}};
class FormData { constructor() { throw new Error('Log GET must not serialize native-injected CSRF fields'); } }
vm.runInNewContext(LOG_SCRIPT, {document, window, URL, FormData});
readyCallbacks.forEach(callback => callback());
assert.equal(callbacks.length, 1);
callbacks[0]({preventDefault: () => { prevented = true; }});
assert.equal(prevented, true);
assert.equal(navigations.length, 1);
const target = new URL(navigations[0]);
assert.equal(target.origin, 'https://unraid.example.test');
assert.equal(target.pathname, '/Settings/unraid-vgpu-manager');
assert.deepEqual([...target.searchParams.keys()].sort(), ['log_category', 'uvm_view']);
assert.equal(target.searchParams.get('uvm_view'), 'status');
assert.equal(target.searchParams.get('log_category'), 'kernel');
assert.equal(target.hash, '');
assert(!target.toString().includes('secret'));
JS;
    $checked = (new Process())([$node, '-e', str_replace('LOG_SCRIPT', $logJson, $js)], 5);
    ui_check($checked['code'] === 0, 'Log GET navigation must exclude native-injected CSRF tokens and unrelated URL parameters: ' . $checked['stderr']);
}
echo 'UI integration: ' . $uiChecks . " checks passed (mock CLI/sysfs; no GPU or network)\n";
