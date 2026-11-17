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
        if (isset($ui_fixture['unknown_errors'][$action])) throw new UvmUnknownResultException($ui_fixture['unknown_errors'][$action]);
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
        // Native MainContentTabbed.php supplies these classes. In particular,
        // .tab-content form:first-of-type introduces host layout rules.
        $html .= '<section id="fixture-' . $ui_tab . '-panel" class="tab-content content" role="tabpanel">' . $panels[$ui_tab] . '</section>';
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

function ui_form(array $rendered, string $id): string
{
    ui_check(preg_match('~<form\b(?=[^>]*\bid="' . preg_quote($id, '~') . '")[^>]*>.*?</form>~s', $rendered['html'], $form) === 1, 'Expected independent form: ' . $id);
    return $form[0];
}

function ui_inventory_fixture(array $base, array $rows, array $checks = ['active_known' => true, 'defined_known' => true], array $messages = []): array
{
    $base['status']['device_inventory'] = ['devices' => $rows, 'checks' => $checks, 'messages' => $messages];
    $base['status']['devices'] = array_values(array_filter(array_map(static fn($row) => $row['configuration'] ?? null, $rows), 'is_array'));
    $base['status']['runtime']['runtime']['checks'] = ['processes_known' => true, 'mdevs_known' => $checks['active_known']];
    $base['status']['runtime']['runtime']['active_mdevs'] = [];
    foreach ($rows as $row) {
        if (($row['active'] ?? false) === true) {
            $base['status']['runtime']['runtime']['active_mdevs'][$row['uuid']] = ['pci' => $row['pci'], 'type' => $row['active_type'], 'group' => $row['iommu_group']];
        }
    }
    return $base;
}

function ui_inventory_table(array $rendered): string
{
    $matched = preg_match('~<table\b(?=[^>]*\bid="uvm-inventory-table")[^>]*>.*?</table>~s', ui_panel($rendered, 'vgpu'), $table);
    ui_check($matched === 1, 'Discovered devices must be rendered in the dedicated inventory table');
    return $table[0];
}

function ui_inventory_row(string $table, string $uuid, string $pci): string
{
    $pattern = '~<tr\b(?=[^>]*\bdata-uuid="' . preg_quote($uuid, '~') . '")(?=[^>]*\bdata-pci="' . preg_quote($pci, '~') . '")[^>]*>.*?</tr>~s';
    ui_check(preg_match($pattern, $table, $row) === 1, 'Inventory must retain each PCI + UUID identity: ' . $pci . ' / ' . $uuid);
    return $row[0];
}

function ui_readonly_panel(array $rendered, string $row, string $kind, string $uuid, string $pci): array
{
    $pattern = '~<button\b(?=[^>]*\bclass="[^"]*\buvm-toggle-' . preg_quote($kind, '~') . '\b)[^>]*>.*?</button>~s';
    ui_check(preg_match($pattern, $row, $toggle) === 1, 'Each inventory identity must expose its own ' . $kind . ' toggle');
    ui_check(str_contains($toggle[0], 'type="button"') && str_contains($toggle[0], 'aria-expanded="false"'), 'Read-only toggles must start collapsed and never submit a form');
    ui_check(str_contains($toggle[0], 'data-device-key="' . htmlspecialchars($pci . '|' . $uuid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'), 'Read-only toggles must target the exact PCI/UUID pair');
    ui_check(preg_match('/\baria-controls="([A-Za-z0-9_-]+)"/', $toggle[0], $target) === 1, 'Every read-only toggle must declare a safe unique target ID');
    $pattern = '~<section\b(?=[^>]*\bid="' . preg_quote($target[1], '~') . '")[^>]*>.*?</section>~s';
    ui_check(preg_match($pattern, ui_panel($rendered, 'vgpu'), $panel) === 1, 'Each read-only toggle must resolve to an actual panel');
    ui_check(!str_contains(ui_inventory_table($rendered), 'id="' . $target[1] . '"'), 'Expanded content must be outside inventory table column sizing');
    ui_check(str_contains($panel[0], 'uvm-readonly-panel') && preg_match('/\bhidden(?:\s|>)/', $panel[0]) === 1 && str_contains($panel[0], 'tabindex="-1"'), 'Read-only panels must start hidden and provide a programmatic focus destination');
    ui_check(str_contains($panel[0], 'data-device-key="' . htmlspecialchars($pci . '|' . $uuid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'), 'Panel identity must match the exact toggle identity');
    ui_check(preg_match('/\baria-labelledby="([A-Za-z0-9_-]+)"/', $panel[0], $label) === 1 && str_contains($panel[0], 'id="' . $label[1] . '"'), 'Each panel must be labelled by its own visible heading');
    ui_check(!preg_match('/<(?:form|input|select|textarea)\b/i', $panel[0]) && !str_contains($panel[0], 'type="submit"'), 'Read-only content must contain no writable field or mutation form');
    ui_check(str_contains($panel[0], 'uvm-close-readonly'), 'Each panel must provide a non-mutating close control');
    ui_check(preg_match('/\bid="([A-Za-z0-9_-]+)"/', $toggle[0], $control) === 1 && str_contains($panel[0], 'data-readonly-control="' . $control[1] . '"'), 'Closing each panel must return to its own disclosure control');
    return ['id' => $target[1], 'html' => $panel[0], 'toggle' => $toggle[0]];
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
}

$rendered = ui_render($base);
ui_check(ui_actions($rendered) === ['status', 'logs'], 'All native tab bodies must share one local status read and one log read, without remote work');
ui_check($rendered['profile_scans'] === 1, 'All native tabs must share one profile scan');
ui_check(str_contains($rendered['html'], 'uvm-source-url') && str_contains($rendered['html'], 'GitHub / GitLab'), 'UI must offer a repository or release URL as the primary input');
ui_check(str_contains($rendered['html'], 'name="credential_token"') && preg_match('/name="credential_token"[^>]*value=""/', $rendered['html']) === 1, 'Token must render as an empty password field');
ui_check(str_contains($rendered['html'], '<summary>高级设置</summary>') && str_contains($rendered['html'], '下载到缓存'), 'Protocol options must be folded into advanced settings');
ui_check(str_contains($rendered['html'], '开机恢复驱动') && str_contains($rendered['html'], '系统升级预下载') && str_contains($rendered['html'], '下次开机安装'), 'UI must distinguish cached boot installation from upgrade prefetch');
ui_check(str_contains($rendered['html'], 'uvm-upload-package') && str_contains($rendered['html'], 'source-upload-chunk'), 'Local package upload must be exposed');
ui_check(preg_match('/id="uvm-upload-checksum"[^>]*required/', $rendered['html']) === 1 && str_contains($rendered['html'], 'MD5 文件（必选）'), 'The independent MD5 file must be mandatory, not synthesized from the uploaded package');
ui_check(!str_contains(ui_form($rendered, 'uvm-upload-form'), 'multipart/form-data'), 'Browser chunks must not inherit the incompatible multipart encoding');
ui_check(str_contains($rendered['html'], 'window.location.replace(') && !str_contains($rendered['html'], 'window.location.reload('), 'Upload completion must navigate with GET rather than replaying an earlier POST');
ui_check(substr_count($rendered['html'], 'name="upgrade_enabled"') === 1 && str_contains($rendered['html'], '升级准备'), 'Upgrade prefetch must have one setting and a preparation status panel');
ui_check(str_contains($rendered['html'], 'name="csrf_token"') && str_contains($rendered['html'], 'name="uvm_csrf_token"'), 'Forms must include native gateway and plugin CSRF fields');
ui_check(!str_contains(ui_panel($rendered, 'driver'), 'name="runtime_enabled"') && !str_contains(ui_panel($rendered, 'driver'), 'id="uvm-device-form"'), 'Driver tab must not render runtime controls');
ui_check(str_contains(ui_panel($rendered, 'driver'), 'name="config_section" value="source"'), 'Source form must identify its independent save section');
$sourceForm = ui_form($rendered, 'uvm-source-form');
$policyForm = ui_form($rendered, 'uvm-driver-policy-form');
foreach (['driver_enabled', 'upgrade_enabled', 'runtime_enabled'] as $field) ui_check(!str_contains($sourceForm, 'name="' . $field . '"'), 'Source form must not own lifecycle control ' . $field);
ui_check(str_contains($policyForm, 'name="config_section" value="driver"'), 'The boot and prefetch policy must save through its own driver section');
foreach (['driver_enabled', 'upgrade_enabled'] as $field) ui_check(str_contains($policyForm, 'name="' . $field . '"'), 'Policy form must expose ' . $field);
foreach (['source_url', 'credential_token', 'runtime_enabled'] as $field) ui_check(!str_contains($policyForm, 'name="' . $field . '"'), 'Policy form must not submit unrelated field ' . $field);

$vgpuRendered = ui_render($base + ['tab' => 'vgpu']);
ui_check(ui_actions($vgpuRendered) === ['status', 'logs'], 'vGPU tab GET must not start devices or query remote sources');
ui_check(str_contains(ui_panel($vgpuRendered, 'vgpu'), 'name="runtime_enabled"') && str_contains(ui_panel($vgpuRendered, 'vgpu'), 'id="uvm-device-form"'), 'vGPU tab must contain boot settings and the device editor');
ui_check(!str_contains(ui_panel($vgpuRendered, 'vgpu'), 'name="source_url"') && !str_contains(ui_panel($vgpuRendered, 'vgpu'), 'name="credential_token"'), 'vGPU tab must not render source credentials or download settings');
ui_check(!str_contains(ui_panel($vgpuRendered, 'vgpu'), 'name="driver_enabled"') && !str_contains(ui_panel($vgpuRendered, 'vgpu'), 'name="upgrade_enabled"'), 'vGPU initialization must not own either driver lifecycle switch');
$vgpuPanel = ui_panel($vgpuRendered, 'vgpu');
ui_check(preg_match('/<section[^>]*id="uvm-device-create"[^>]*>\s*<h2>添加设备<\/h2>/', $vgpuPanel) === 1, 'Add device must be a permanent independent section');
foreach (['uvm-device-editor', 'uvm-edit-device', 'uvm-cancel-edit', 'setEditorMode', 'resetEditor'] as $removed) {
    ui_check(!str_contains($vgpuPanel, $removed), 'Read-only details must not retain the shared editing implementation: ' . $removed);
}
ui_check(str_contains($vgpuPanel, 'id="uvm-save-device">保存新设备</button>'), 'The initial save button must clearly identify a new device');
$createForm = ui_form($vgpuRendered, 'uvm-device-form');
ui_check(str_contains($createForm, 'name="uvm_action" value="device-create"'), 'New-device submission must use the strictly create-only backend action');
ui_check(!str_contains($createForm, 'class="uvm-submit-form"'), 'Create AJAX must own its busy state without the ordinary POST handler');
ui_check(str_contains($vgpuPanel, '不会启动设备或自动接管现有运行时'), 'New configuration must not imply runtime adoption or activation');
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
ui_check(ui_form($localRendered, 'uvm-driver-policy-form') === $policyForm, 'Local-only users must get the same boot controls without a repository or credential');
foreach (['uvm-online-panel', 'uvm-local-panel'] as $panelId) {
    preg_match('~<section\b(?=[^>]*\bid="' . $panelId . '")[^>]*>.*?</section>~s', $localRendered['html'], $modePanel);
    ui_check(!str_contains($modePanel[0], 'uvm-driver-policy-form'), 'Policy controls must remain outside mode-specific visibility panels');
}
ui_check(preg_match('/<section[^>]*id="uvm-driver-policy-panel"[^>]*\bhidden\b/', $localRendered['html']) !== 1 && !str_contains(ui_form($localRendered, 'uvm-driver-policy-form'), '<fieldset disabled'), 'Local mode must leave policy controls visible and enabled');

foreach ([false, true] as $driverEnabled) foreach ([false, true] as $upgradeEnabled) foreach ([false, true] as $runtimeEnabled) {
    $flagsFixture = $base;
    $flagsFixture['status']['settings']['driver']['enabled'] = $driverEnabled;
    $flagsFixture['status']['settings']['upgrade']['enabled'] = $upgradeEnabled;
    $flagsFixture['status']['settings']['runtime']['enabled'] = $runtimeEnabled;
    $flagsRendered = ui_render($flagsFixture);
    foreach (['driver' => $driverEnabled, 'upgrade' => $upgradeEnabled, 'runtime' => $runtimeEnabled] as $section => $enabled) {
        preg_match('~<select name="' . $section . '_enabled">.*?</select>~s', $flagsRendered['html'], $select);
        ui_check(str_contains($select[0] ?? '', '<option value="' . (int)$enabled . '" selected>'), 'The ' . $section . ' select must reflect only its own setting');
    }
    foreach (['开机恢复驱动' => $driverEnabled, '系统升级预下载' => $upgradeEnabled, 'vGPU 初始化' => $runtimeEnabled] as $label => $enabled) {
        ui_check(str_contains(ui_panel($flagsRendered, 'status'), '<tr><td>' . $label . '</td><td>' . ($enabled ? '开启' : '关闭')), 'Status must independently report ' . $label);
    }
}
$legacyFlags = $base;
unset($legacyFlags['status']['settings']['driver']);
$legacyFlags['status']['settings']['upgrade']['enabled'] = true;
$legacyRendered = ui_render($legacyFlags);
ui_check(str_contains(ui_panel($legacyRendered, 'status'), '<tr><td>开机恢复驱动</td><td>关闭'), 'An incomplete status snapshot must not infer a driver setting from prefetch; persisted migration belongs to Store');
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
ui_check(!isset($rendered['calls'][0]['payload']['driver']) && !isset($rendered['calls'][0]['payload']['upgrade']), 'A current source-only form must preserve both driver lifecycle switches');
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
    'upgrade_enabled' => '0', 'driver_enabled' => '0', 'source_connect' => '1',
]);
$rendered = ui_render($base + ['tab' => 'vgpu', 'server' => ['REQUEST_METHOD' => 'POST'], 'post' => $runtimePost]);
ui_check(ui_actions($rendered) === ['config-save', 'status', 'logs'], 'Runtime save must stay on its tab without a source lookup');
ui_check($rendered['calls'][0]['payload']['runtime']['enabled'] === true, 'Runtime save must carry its own booleans');
ui_check($rendered['calls'][0]['payload']['override_content'] === "[fixture]\nvalue = true\n", 'Runtime override replacement must normalize CR');
foreach (['source', 'credential', 'driver', 'upgrade'] as $otherSection) ui_check(!isset($rendered['calls'][0]['payload'][$otherSection]), 'Runtime save must not alter ' . $otherSection);
ui_check(str_contains($rendered['html'], 'const preferredTab = "vgpu";'), 'Runtime save must select the vGPU native tab after the full page renders');
ui_check(str_contains(ui_panel($rendered, 'vgpu'), 'vGPU 设置已保存。') && !str_contains(ui_panel($rendered, 'driver'), 'vGPU 设置已保存。'), 'Success notices must stay inside the tab that submitted the request');
$withoutOverride = array_replace($runtimePost, ['override_replace' => '0']);
$rendered = ui_render($base + ['tab' => 'vgpu', 'server' => ['REQUEST_METHOD' => 'POST'], 'post' => $withoutOverride]);
ui_check(!isset($rendered['calls'][0]['payload']['override_content']), 'Unchecked runtime override editor must preserve the stored file');

$policyPost = [
    'csrf_token' => 'fixture-ui-csrf', 'uvm_csrf_token' => 'fixture-ui-csrf', 'uvm_action' => 'config-save',
    'uvm_tab' => 'driver', 'config_section' => 'driver',
];
foreach (['0', '1'] as $driverEnabled) foreach (['0', '1'] as $upgradeEnabled) {
    $post = $policyPost + [
        'driver_enabled' => $driverEnabled, 'upgrade_enabled' => $upgradeEnabled,
        'runtime_enabled' => '1', 'source_url' => 'https://stale.example.test/driver', 'source_connect' => '1',
        'credential_change' => 'replace', 'credential_token' => 'irrelevant-stale-token', 'driver_enabled_boot_id' => 'forged',
    ];
    $rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $post]);
    ui_check(ui_actions($rendered) === ['config-save', 'status', 'logs'], 'Policy save must run once across native tabs without source lookup, driver install or runtime execution');
    ui_check($rendered['calls'][0]['payload'] === ['driver' => ['enabled' => $driverEnabled === '1'], 'upgrade' => ['enabled' => $upgradeEnabled === '1']], 'Policy save must forward only its two explicit switches and never browser boot IDs');
    ui_check(str_contains(ui_panel($rendered, 'driver'), '驱动启动与升级设置已保存。') && !str_contains(ui_panel($rendered, 'vgpu'), '驱动启动与升级设置已保存。'), 'Policy notices must belong to the driver tab');
}
foreach (['driver', 'upgrade'] as $section) {
    $rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $policyPost + [$section . '_enabled' => '1']]);
    ui_check($rendered['calls'][0]['payload'] === [$section => ['enabled' => true]], 'Missing policy fields must preserve the other switch instead of turning it off');
}
foreach ([$policyPost, $policyPost + ['driver_enabled' => 'invalid'], $policyPost + ['driver_enabled' => ['1']], $policyPost + ['upgrade_enabled' => 'true'], $policyPost + ['upgrade_enabled' => ['0']]] as $post) {
    $rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $post]);
    ui_check(ui_actions($rendered) === ['status', 'logs'], 'Empty or malformed policy forms must not write configuration');
}
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $config + ['driver_enabled' => '0']]);
ui_check(!isset($rendered['calls'][0]['payload']['driver']), 'An old source form or stale browser field must not reset driver restoration');
foreach ([
    array_replace($config, ['uvm_tab' => 'vgpu']),
    array_replace($runtimePost, ['uvm_tab' => 'driver']),
    array_replace($config, ['uvm_tab' => 'status']),
    array_replace($policyPost, ['uvm_tab' => 'vgpu', 'driver_enabled' => '1']),
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
$externalActive = $deviceInput + [
    'framebuffer' => '1024M', 'active' => true, 'defined' => true, 'managed' => false, 'configuration' => null,
    'active_type' => 'nvidia-762', 'defined_type' => 'nvidia-762', 'configured_type' => '',
    'definition_start' => 'manual', 'iommu_group' => '27',
];
$inventory = ui_inventory_fixture($base, [$externalActive]);
$rendered = ui_render($inventory);
$table = ui_inventory_table($rendered);
$row = ui_inventory_row($table, $deviceInput['uuid'], $deviceInput['pci']);
ui_check(ui_actions($rendered) === ['status', 'logs'], 'Viewing external inventory must only read local status and logs, never save, import or start devices');
ui_check($inventory['status']['devices'] === [], 'The external-device fixture must have no plugin-saved devices');
foreach (['UUID', 'type', 'PCI'] as $label) ui_check(str_contains($table, $label), 'Inventory must label ' . $label . ' directly');
foreach ([$deviceInput['uuid'], $deviceInput['pci'], 'nvidia-762', 'NVIDIA RTXA5500-1Q', '1024M', '已创建'] as $value) ui_check(str_contains($row, $value), 'External active inventory must show ' . $value);
ui_check(str_contains($row, 'data-device-source="system"') && str_contains($row, '系统设备'), 'External devices must be identified as system devices rather than plugin-owned');
ui_check(str_contains($row, '手动启动') && str_contains($row, 'IOMMU 27'), 'Device details must expose the retained definition mode and IOMMU group');
ui_check(!str_contains($row, '<form') && !str_contains($row, 'uvm-edit-device'), 'External devices must not get edit/start/stop/remove mutation controls');
$externalDetail = ui_readonly_panel($rendered, $row, 'details', $deviceInput['uuid'], $deviceInput['pci']);
$externalXml = ui_readonly_panel($rendered, $row, 'xml', $deviceInput['uuid'], $deviceInput['pci']);
ui_check($externalDetail['id'] !== $externalXml['id'], 'Details and VM XML for one device must use separate disclosure targets');
ui_check(str_contains($externalXml['html'], '复制 XML') && str_contains($externalXml['html'], '&lt;hostdev') && str_contains($externalXml['html'], $deviceInput['uuid']), 'An external valid UUID must retain its escaped VM XML and copy control outside the table');
ui_check(!str_contains($table, '<pre'), 'Inventory cells must not hold the expandable XML body');
$inventoryPanel = ui_panel($rendered, 'vgpu');
ui_check(strpos($inventoryPanel, '已有 vGPU 设备') < strpos($inventoryPanel, '开机管理'), 'Existing devices must be visible before boot configuration');

$mixedSnapshot = $inventory;
$mixedSnapshot['status']['runtime']['runtime']['active_mdevs']['5cf14a12-a437-4c82-a13f-70e945782d7b'] = [
    'pci' => '0000:00:02.0', 'type' => 'i915-GVTg_V4_4', 'group' => '28',
];
$rendered = ui_render($mixedSnapshot);
foreach (['driver', 'vgpu', 'status'] as $tab) {
    ui_check(preg_match('~<div\b[^>]*aria-label="GPU 概览"[^>]*>.*?</div>~s', ui_panel($rendered, $tab), $overview) === 1, 'Every native tab must retain its overview strip');
    ui_check(str_contains($overview[0], 'vGPU：1 个已创建') && !str_contains($overview[0], 'vGPU：2 个已创建'), 'Overview must count the NVIDIA inventory rather than unrelated Intel mdevs in the raw snapshot');
}
$mixedSnapshot['status']['device_inventory']['checks']['active_known'] = false;
$rendered = ui_render($mixedSnapshot);
foreach (['driver', 'vgpu', 'status'] as $tab) {
    ui_check(preg_match('~<div\b[^>]*aria-label="GPU 概览"[^>]*>.*?</div>~s', ui_panel($rendered, $tab), $overview) === 1 && str_contains($overview[0], 'vGPU：状态未知'), 'An unknown inventory read must keep every overview unknown despite a readable raw snapshot');
    ui_check(!str_contains($overview[0], 'vGPU：0 个已创建'), 'Unknown inventory must not be presented as a known zero-device count');
}

$externalDefined = array_replace($externalActive, [
    'uuid' => '868bfe06-47c1-40a3-82df-ffd518be72d3', 'type' => 'nvidia-763',
    'profile' => 'NVIDIA RTXA5500-2Q', 'framebuffer' => '2048M', 'active' => false,
    'active_type' => '', 'defined_type' => 'nvidia-763', 'definition_start' => 'auto', 'iommu_group' => null,
]);
$rendered = ui_render(ui_inventory_fixture($base, [$externalDefined]));
$row = ui_inventory_row(ui_inventory_table($rendered), $externalDefined['uuid'], $externalDefined['pci']);
ui_check(str_contains($row, '已定义，未创建') && str_contains($row, 'nvidia-763') && str_contains($row, 'NVIDIA RTXA5500-2Q'), 'Inactive persistent definitions must remain visible with their actual type and profile');
ui_check(str_contains($row, '自动启动'), 'Persistent definition startup policy must be visible even while inactive');
ui_check(!str_contains($row, '<form') && !str_contains($row, 'uvm-edit-device'), 'An inactive external definition must not become managed just by being displayed');

$otherParent = array_replace($externalDefined, ['uuid' => $deviceInput['uuid'], 'pci' => '0000:02:00.0']);
$rendered = ui_render(ui_inventory_fixture($base, [$externalActive, $otherParent]));
$table = ui_inventory_table($rendered);
$firstParentRow = ui_inventory_row($table, $deviceInput['uuid'], $deviceInput['pci']);
$secondParentRow = ui_inventory_row($table, $deviceInput['uuid'], $otherParent['pci']);
ui_check(substr_count($table, 'data-uuid="' . $deviceInput['uuid'] . '"') === 2, 'The same UUID under two parents must not be silently collapsed into one inventory row');
$parentTargets = [];
foreach ([$deviceInput['pci'] => $firstParentRow, $otherParent['pci'] => $secondParentRow] as $parentPci => $parentRow) {
    foreach (['details', 'xml'] as $kind) {
        $parentPanel = ui_readonly_panel($rendered, $parentRow, $kind, $deviceInput['uuid'], $parentPci);
        $parentTargets[] = $parentPanel['id'];
        ui_check(str_contains($parentPanel['html'], $parentPci) && str_contains($parentPanel['html'], $deviceInput['uuid']), 'Read-only panels must visibly identify their own PCI and UUID');
    }
}
ui_check(count(array_unique($parentTargets)) === 4, 'Same-UUID devices under different parents and disclosure kinds must not share targets');
preg_match_all('/\bid="([^"]+)"/', $rendered['html'], $pairIds);
ui_check(count($pairIds[1]) === count(array_unique($pairIds[1])), 'All disclosure controls, headings and panels must have unique IDs across native tabs');

$managedRow = array_replace($externalActive, [
    'type' => 'nvidia-999', 'active_type' => 'nvidia-999', 'defined_type' => 'nvidia-999',
    'managed' => true, 'configuration' => $deviceInput, 'configured_type' => $deviceInput['type'],
]);
$managedFixture = ui_inventory_fixture($base, [$managedRow]);
$managedFixture['status']['settings']['runtime']['enabled'] = true;
$rendered = ui_render($managedFixture);
$row = ui_inventory_row(ui_inventory_table($rendered), $managedRow['uuid'], $managedRow['pci']);
ui_check(str_contains($row, 'nvidia-999') && str_contains($row, 'nvidia-762'), 'Inventory must expose actual and configured identities when their types differ');
ui_check(str_contains($row, 'data-device-source="plugin"') && str_contains($row, '插件配置'), 'Plugin-saved rows must retain their managed source label');
$readOnlyDetail = ui_readonly_panel($rendered, $row, 'details', $managedRow['uuid'], $managedRow['pci']);
foreach (['插件保存', '系统定义', '当前运行', $deviceInput['profile'], 'nvidia-762', 'nvidia-999'] as $value) {
    ui_check(str_contains($readOnlyDetail['html'], $value), 'Details must distinguish stored, defined and actual identity: ' . $value);
}
ui_check(!str_contains($row, 'uvm-device-detail-content'), 'Action columns must not contain the expanded detail body');
ui_check(str_contains($row, 'data-device-key="' . $deviceInput['pci'] . '|' . $deviceInput['uuid'] . '"'), 'Details must identify the exact PCI/UUID pair');
ui_check(!str_contains($row, 'data-device=') && !str_contains($row, 'uvm-edit-device'), 'Details must not export an editable draft or switch the create form');
foreach (['device-start', 'device-stop', 'device-remove'] as $action) ui_check(str_contains($row, 'value="' . $action . '"'), 'Managed rows must retain the existing ' . $action . ' action');
ui_check(ui_actions($rendered) === ['status', 'logs'], 'Rendering a managed inventory discrepancy must not repair or rewrite it');
foreach ([true, false, null] as $active) foreach ([true, false] as $runtimeEnabled) {
    $actionFixture = ui_inventory_fixture($base, [array_replace($managedRow, ['active' => $active])]);
    $actionFixture['status']['settings']['runtime']['enabled'] = $runtimeEnabled;
    $actionRow = ui_inventory_row(ui_inventory_table(ui_render($actionFixture)), $managedRow['uuid'], $managedRow['pci']);
    ui_check(substr_count($actionRow, '<form ') === 3, 'Device actions must retain three independent POST forms');
    foreach (['device-start', 'device-stop', 'device-remove'] as $action) {
        ui_check(preg_match('~<form method="post"[^>]*>(?:(?!</form>).)*name="uvm_action" value="' . $action . '"(?:(?!</form>).)*<button type="submit"([^>]*)>~s', $actionRow, $actionButton) === 1, 'Each action must retain its own submit button: ' . $action);
        $disabled = $active === null || ($action === 'device-start' && ($active === true || !$runtimeEnabled)) || ($action === 'device-stop' && $active !== true);
        ui_check((preg_match('/\bdisabled\b/', $actionButton[1]) === 1) === $disabled, 'Button alignment must preserve the device state and runtime safety gate: ' . $action);
        ui_check(str_contains($actionButton[0], 'name="csrf_token"') && str_contains($actionButton[0], 'name="uvm_csrf_token"'), 'Aligned action forms must retain both CSRF fields');
        ui_check(str_contains($actionButton[0], 'data-confirm=') === ($action !== 'device-start'), 'Stop/remove must retain their existing VM shutdown confirmation');
    }
}

$unknownRow = array_replace($externalActive, ['active' => null, 'defined' => null, 'active_type' => '', 'defined_type' => '']);
$rendered = ui_render(ui_inventory_fixture($base, [$unknownRow], ['active_known' => false, 'defined_known' => false], ['设备定义读取失败。']));
$row = ui_inventory_row(ui_inventory_table($rendered), $unknownRow['uuid'], $unknownRow['pci']);
ui_check(str_contains($row, '状态未知') && str_contains($row, '系统定义未知'), 'Unknown reads must not be presented as stopped or undefined');
ui_check(str_contains(ui_panel($rendered, 'vgpu'), '设备定义读取失败。'), 'Partial inventory failures must be shown without discarding known rows');
$rendered = ui_render(ui_inventory_fixture($base, [], ['active_known' => false, 'defined_known' => false], ['设备列表读取失败。']));
ui_check(str_contains(ui_panel($rendered, 'vgpu'), '设备列表暂不完整') && !str_contains(ui_panel($rendered, 'vgpu'), '未发现 vGPU 设备'), 'An unreadable empty inventory must not claim that the host has no vGPU devices');
$rendered = ui_render(ui_inventory_fixture($base, []));
ui_check(str_contains(ui_panel($rendered, 'vgpu'), '未发现 vGPU 设备'), 'An empty inventory may report no devices only after both sources were read successfully');

$invalidIdentity = array_replace($externalActive, ['uuid' => 'invalid<uuid>']);
$rendered = ui_render(ui_inventory_fixture($base, [$invalidIdentity]));
$invalidIdentityRow = ui_inventory_row(ui_inventory_table($rendered), htmlspecialchars($invalidIdentity['uuid'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $invalidIdentity['pci']);
$invalidDetail = ui_readonly_panel($rendered, $invalidIdentityRow, 'details', $invalidIdentity['uuid'], $invalidIdentity['pci']);
ui_check(!str_contains($invalidIdentityRow, 'uvm-toggle-xml') && !str_contains($invalidDetail['html'], '&lt;hostdev'), 'An invalid UUID must not expose a usable VM XML toggle or snippet');
ui_check(!str_contains($rendered['html'], $invalidIdentity['uuid']) && str_contains($invalidDetail['html'], 'invalid&lt;uuid&gt;'), 'Invalid inventory identity must remain escaped in read-only content');

$inventoryAttack = '<img src=x onerror="alert(7)"></script><script>alert(8)</script>';
$unsafeConfiguration = array_replace($deviceInput, ['profile' => $inventoryAttack]);
$unsafeRow = array_replace($managedRow, ['profile' => $inventoryAttack, 'framebuffer' => $inventoryAttack, 'definition_start' => $inventoryAttack, 'configured_type' => $inventoryAttack, 'configuration' => $unsafeConfiguration]);
$rendered = ui_render(ui_inventory_fixture($base, [$unsafeRow], ['active_known' => true, 'defined_known' => true], [$inventoryAttack]));
ui_check(!str_contains($rendered['html'], $inventoryAttack) && str_contains(ui_panel($rendered, 'vgpu'), '&lt;img'), 'Inventory metadata, messages and identity details must be escaped');
preg_match_all('/\bid="([^"]+)"/', $rendered['html'], $inventoryIds);
ui_check(count($inventoryIds[1]) === count(array_unique($inventoryIds[1])), 'Merged device inventory must not introduce duplicate IDs across the three native tab bodies');

foreach (['device-save', 'device-start', 'device-stop', 'device-remove'] as $action) {
    $post = array_replace($config, $deviceInput, ['uvm_tab' => 'vgpu', 'uvm_action' => $action]);
    $rendered = ui_render($base + ['tab' => 'vgpu', 'server' => ['REQUEST_METHOD' => 'POST'], 'post' => $post]);
    ui_check(ui_actions($rendered) === [$action, 'status', 'logs'], 'Each device operation must invoke only its explicit backend action and then read status');
    ui_check($rendered['calls'][0]['payload'] === ($action === 'device-save' ? $deviceInput : ['uuid' => $deviceInput['uuid']]), 'Device operations must not forward unrelated source/runtime fields');
    ui_check(str_contains($rendered['html'], 'data-tab="vgpu"'), 'Device result must remain on the vGPU tab');
}

$createPost = array_replace($config, $deviceInput, ['uvm_tab' => 'vgpu', 'uvm_action' => 'device-create']);
foreach ([false, true] as $gatewayStrip) {
    $created = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $createPost,
        'gateway_strip_csrf' => $gatewayStrip, 'responses' => ['device-create' => ['changed' => true]]]);
    ui_check(ui_actions($created) === ['device-create'], 'Native multi-tab creation must dispatch exactly once without status/remote/GPU work');
    ui_check($created['calls'][0]['payload'] === $deviceInput && $created['profile_scans'] === 0, 'Creation must pass only device fields and avoid a new form/UUID render');
    ui_check(substr_count($created['html'], 'id="uvm-device-create-result"') === 1, 'Native tabs must emit one tagged creation response');
    ui_check(preg_match('~id="uvm-device-create-result">(.*?)</script>~s', $created['html'], $tagged) === 1, 'Creation result must survive the Unraid HTML wrapper');
    $result = json_decode($tagged[1], true, 32, JSON_THROW_ON_ERROR);
    ui_check($result['ok'] && $result['data'] === ['uuid' => $deviceInput['uuid'], 'changed' => true], 'Only confirmed creation may be reported as success');
    ui_check(str_contains($result['message'], '未启动') && !str_contains($created['html'], 'id="uvm-device-form"'), 'Create success must not claim activation or render another POST form');
}
foreach ([['changed' => false], ['ok' => false, 'message' => 'UUID 已存在']] as $unconfirmed) {
    $created = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $createPost,
        'responses' => ['device-create' => $unconfirmed]]);
    ui_check(ui_actions($created) === ['device-create'] && str_contains($created['html'], '"ok":false'), 'Duplicate or unconfirmed creation must not become a success response');
}
foreach ([['csrf_token' => 'bad'], ['uvm_csrf_token' => 'bad'], ['uvm_tab' => 'driver'], ['uuid' => ['bad']]] as $invalid) {
    $created = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => array_replace($createPost, $invalid)]);
    ui_check(ui_actions($created) === [] && str_contains($created['html'], '"ok":false'), 'Rejected create requests must return one failure before backend dispatch');
    ui_check(substr_count($created['html'], 'id="uvm-device-create-result"') === 1, 'Rejected native multi-tab creation must not repeat its response');
}
$created = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $createPost,
    'errors' => ['device-create' => '</script><script>alert(9)</script>']]);
ui_check(!str_contains($created['html'], '<script>alert(9)') && str_contains($created['html'], '"ok":false'), 'Create failure text must stay escaped inside tagged JSON');

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

$downloadPost = ['csrf_token' => 'fixture-ui-csrf', 'uvm_csrf_token' => 'fixture-ui-csrf',
    'uvm_tab' => 'driver', 'uvm_action' => 'source-download', 'uvm_download_async' => '1',
    'download_package' => 'nvidia-595.58.03-6.18.58-Unraid-3.txz'];
$downloadPackage = ['driver' => '595.58.03', 'kernel' => '6.18.58-Unraid', 'build' => 3,
    'filename' => $downloadPost['download_package'], 'md5' => str_repeat('a', 32), 'checksum_verified' => true];
$downloadResponse = static function (array $rendered): array {
    ui_check(substr_count($rendered['html'], 'id="uvm-download-result"') === 1, 'All native tabs must emit exactly one download response');
    ui_check(!str_contains($rendered['html'], 'id="uvm-manager-'), 'Async download must not render replacement page content');
    ui_check($rendered['profile_scans'] === 0, 'Download responses must not perform extra GPU/profile scans');
    ui_check(preg_match('~<script type="application/json" id="uvm-download-result">(.*?)</script>~s', $rendered['html'], $match) === 1, 'Download result must use an inert tagged JSON response');
    return json_decode($match[1], true, 32, JSON_THROW_ON_ERROR);
};
foreach ([false, true] as $gatewayStrip) {
    $rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'gateway_strip_csrf' => $gatewayStrip,
        'post' => $downloadPost + ['source_url' => 'https://unsaved.example.test/not-used', 'credential_token' => 'unsaved-test-secret'],
        'responses' => ['source-download' => ['package' => $downloadPackage + ['authorization' => 'do-not-return']]]]);
    $result = $downloadResponse($rendered);
    ui_check(ui_actions($rendered) === ['source-download'] && $rendered['calls'][0]['payload'] === ['filename' => $downloadPackage['filename']], 'Async download dispatches once with only the exact package selection, never unsaved configuration');
    ui_check($result['ok'] && $result['outcome'] === 'cached' && $result['data']['package']['build'] === 3, 'Only completed downloads may report a cached build');
    ui_check(!str_contains($rendered['html'], 'unsaved-test-secret') && !str_contains($rendered['html'], 'do-not-return'), 'Download responses must not disclose source credentials');
}
foreach ([['csrf_token' => 'bad'], ['uvm_csrf_token' => 'bad'], ['uvm_csrf_token' => ['bad']],
    ['uvm_tab' => 'vgpu'], ['uvm_download_async' => '0'], ['uvm_download_async' => ['1']],
    ['download_package' => ['bad']]] as $changes) {
    $rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => array_replace($downloadPost, $changes)]);
    $result = $downloadResponse($rendered);
    ui_check(ui_actions($rendered) === [] && !$result['ok'] && $result['outcome'] === 'failed', 'Invalid async download fields/tab/CSRF must fail before backend dispatch');
}
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://external.example.test'], 'post' => $downloadPost]);
ui_check($downloadResponse($rendered)['outcome'] === 'failed' && ui_actions($rendered) === [], 'Cross-origin async download is refused without backend work');
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $downloadPost,
    'errors' => ['source-download' => '</script><script>not-safe()</script> glpat-UIFIXTURETOKEN']]);
$result = $downloadResponse($rendered);
ui_check(!$result['ok'] && $result['outcome'] === 'failed' && !isset($result['data']), 'A confirmed backend failure must not claim a cached package');
ui_check(!str_contains($rendered['html'], 'glpat-UIFIXTURETOKEN') && !str_contains($rendered['html'], '</script><script>'), 'Async errors must redact secrets and escape JSON script boundaries');
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $downloadPost,
    'unknown_errors' => ['source-download' => 'Completion is unknown; check status before retrying.']]);
ui_check($downloadResponse($rendered)['outcome'] === 'unknown', 'Backend timeouts or invalid terminal results must remain unknown, not a confirmed failure');
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $downloadPost]);
ui_check($downloadResponse($rendered)['outcome'] === 'unknown', 'A backend success without a package receipt is not a confirmed download');
$rendered = ui_render($base);
$downloadForm = ui_form($rendered, 'uvm-download-form');
ui_check(str_contains($downloadForm, 'method="post"') && str_contains($downloadForm, 'name="uvm_csrf_token"') && !str_contains($downloadForm, 'credential_token'), 'Download keeps a standalone dual-CSRF POST fallback without source/token fields');
ui_check(preg_match('~<progress\b[^>]*id="uvm-download-meter"[^>]*>~', $rendered['html'], $meter) === 1 && !str_contains($meter[0], 'value='), 'Download progress must be indeterminate until confirmation, not a fabricated percentage');
ui_check(str_contains($rendered['html'], 'id="uvm-driver-cache-body"') && str_contains($rendered['html'], 'id="uvm-download-check"'), 'Async UI must update only the cache and provide explicit GET recovery for an unknown result');
ui_check(str_contains($rendered['html'], 'new URLSearchParams(new FormData(downloadForm))'), 'Text-only downloads must avoid multipart before the Unraid PHP authentication gateway');
ui_check(str_contains($rendered['html'], 'new URLSearchParams(new FormData(form))'), 'Text-only cache deletion must use ordinary URL-encoded form data');
ui_check(str_contains(ui_panel($rendered, 'vgpu'), 'new URLSearchParams(new FormData(form))'), 'Text-only device creation must use the same gateway-compatible encoding');

$deletePost = ['csrf_token' => 'fixture-ui-csrf', 'uvm_csrf_token' => 'fixture-ui-csrf', 'uvm_tab' => 'driver',
    'uvm_action' => 'cache-delete', 'uvm_cache_delete_async' => '1', 'cache_filename' => $downloadPackage['filename']];
$deleteResult = ['changed' => true, 'filename' => $downloadPackage['filename'], 'removed' => [$downloadPackage['filename'], $downloadPackage['filename'] . '.md5'],
    'package_cache' => ['available' => true, 'packages' => [], 'message' => '']];
$deleteResponse = static function (array $rendered): array {
    ui_check(substr_count($rendered['html'], 'id="uvm-cache-delete-result"') === 1 && !str_contains($rendered['html'], 'id="uvm-manager-'), 'Deletion emits one inert response, never a replacement native page');
    ui_check($rendered['profile_scans'] === 0, 'Async deletion must not scan GPU profiles');
    preg_match('~<script type="application/json" id="uvm-cache-delete-result">(.*?)</script>~s', $rendered['html'], $match);
    return json_decode($match[1], true, 32, JSON_THROW_ON_ERROR);
};
foreach ([false, true] as $strip) {
    $rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'gateway_strip_csrf' => $strip,
        'post' => $deletePost + ['path' => '/must-not-use', 'credential_token' => 'unsaved-secret'], 'responses' => ['cache-delete' => $deleteResult]]);
    $result = $deleteResponse($rendered);
    ui_check($result['ok'] && $result['data']['changed'] && $result['data']['package_cache']['packages'] === [], 'Confirmed deletion returns exact identity and refreshed inventory');
    ui_check(ui_actions($rendered) === ['cache-delete'] && $rendered['calls'][0]['payload'] === ['filename' => $downloadPackage['filename']], 'Deletion only dispatches one exact filename, never arbitrary paths or unsaved credentials');
}
foreach ([['csrf_token' => 'bad'], ['uvm_csrf_token' => 'bad'], ['uvm_tab' => 'vgpu'], ['cache_filename' => ['bad']], ['uvm_cache_delete_async' => '0']] as $changes) {
    $rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => array_replace($deletePost, $changes)]);
    ui_check(!$deleteResponse($rendered)['ok'] && ui_actions($rendered) === [], 'Invalid deletion form fields/tab/CSRF fail before dispatch');
}
foreach ([[], ['changed' => false], ['removed' => []], ['removed' => 'not-an-array'], ['filename' => 'different.txz']] as $changes) {
    $response = $changes ? array_replace($deleteResult, $changes) : [];
    $rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $deletePost, 'responses' => ['cache-delete' => $response]]);
    ui_check($deleteResponse($rendered)['outcome'] === 'unknown', 'Incomplete or mismatching deletion receipts never claim success');
}
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $deletePost, 'errors' => ['cache-delete' => 'Protected </script><script>unsafe()</script> glpat-FIXTUREDELETE']]);
ui_check($deleteResponse($rendered)['outcome'] === 'failed' && !str_contains($rendered['html'], 'glpat-FIXTUREDELETE') && !str_contains($rendered['html'], '</script><script>'), 'Deletion failures are explicit, escaped and redacted');
$cachedList = $base;
$cachedList['status']['package_cache'] = ['available' => true, 'packages' => [$downloadPackage + ['size' => 500, 'references' => ['当前选中 <script>unsafe()</script>'], 'deletable' => false]]];
$rendered = ui_render($cachedList);
ui_check(str_contains($rendered['html'], 'id="uvm-downloaded-panel"') && str_contains($rendered['html'], 'name="cache_filename"'), 'Driver management renders a separate downloaded-file inventory and exact identity forms');
ui_check(str_contains($rendered['html'], '当前选中 &lt;script&gt;unsafe()&lt;/script&gt;') && preg_match('~class="uvm-cache-delete-form".*?<button type="submit" disabled~s', $rendered['html']) === 1, 'Protected package rows escape long reasons and disable deletion');

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
ui_check(!isset($rendered['calls'][0]['payload']['driver']), 'Legacy source forms may retain their prefetch setting but must never toggle cached boot installation');
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

$revisions = [];
foreach ([1, 2, 10] as $build) {
    $revisions[] = ['driver' => '595.58.03', 'kernel' => '6.18.58-Unraid', 'build' => $build,
        'filename' => 'nvidia-595.58.03-6.18.58-Unraid-' . $build . '.txz'];
}
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => [
    'csrf_token' => 'fixture-ui-csrf', 'uvm_csrf_token' => 'fixture-ui-csrf', 'uvm_tab' => 'driver', 'uvm_action' => 'source-versions',
], 'responses' => ['source-versions' => ['versions' => $revisions]]]);
foreach ($revisions as $revision) {
    ui_check(str_contains(ui_panel($rendered, 'driver'), 'value="' . $revision['filename'] . '">595.58.03 · 构建 ' . $revision['build']), 'Every build must have a distinct filename-valued option and a visible revision label');
}
ui_check(str_contains(ui_panel($rendered, 'driver'), '<th>构建号</th>'), 'The package table must distinguish builds of the same driver');
foreach ($revisions as $revision) {
    $rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => [
        'csrf_token' => 'fixture-ui-csrf', 'uvm_csrf_token' => 'fixture-ui-csrf', 'uvm_tab' => 'driver', 'uvm_action' => 'source-download',
        'download_package' => $revision['filename'], 'download_driver' => '580.159.03',
    ]]);
    ui_check(ui_actions($rendered) === ['source-download', 'status', 'logs'], 'Choosing a build must only download that package then read status');
    ui_check($rendered['calls'][0]['payload'] === ['filename' => $revision['filename']], 'Canonical package selection must take precedence over a stale legacy driver field');
}
$pinPost = array_replace($config, ['source_driver' => '595.58.03', 'source_build' => '2']);
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $pinPost]);
ui_check($rendered['calls'][0]['payload']['source']['build'] === '2', 'The advanced build pin must reach configuration validation');
$legacyPost = $pinPost;
unset($legacyPost['source_build']);
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $legacyPost]);
ui_check($rendered['calls'][0]['payload']['source']['build'] === '', 'Old forms without a build field must keep automatic build selection');
$cachedBuild = $base;
$cachedBuild['status']['settings']['selected_package'] = $revisions[1] + ['source' => 'local'];
$cachedBuild['status']['driver_installation'] = ['status' => 'pending_reboot', 'kernel' => '6.18.58-Unraid', 'message' => 'Selected build awaits reboot'];
$rendered = ui_render($cachedBuild);
ui_check(str_contains(strip_tags(ui_panel($rendered, 'driver')), 'NVIDIA 595.58.03 · 构建 2'), 'Cached build2 must be visible separately from the loaded driver version');
ui_check(str_contains(ui_panel($rendered, 'driver'), '等待重启安装所选构建'), 'A selected but not installed build must show pending reboot');
ui_check(str_contains(ui_panel($rendered, 'status'), '<tr><td>开机安装</td><td>等待重启</td>'), 'Status tab must translate pending reboot without claiming the selected build is active');
$installationFixture = $cachedBuild;
$installationFixture['status']['driver_installation']['last_success'] = ['package' => $revisions[0]];
$installationFixture['status']['driver_installation']['attempted_package'] = $revisions[1];
foreach (['pending_reboot' => 2, 'failed' => 2, 'installed' => 1, 'already_active' => 1] as $state => $expectedBuild) {
    $installationFixture['status']['driver_installation']['status'] = $state;
    $rendered = ui_render($installationFixture);
    preg_match('~<tr><td>开机安装</td>.*?</tr>~s', ui_panel($rendered, 'status'), $installationRow);
    ui_check(str_contains($installationRow[0], '595.58.03 · 构建 ' . $expectedBuild . ' · 6.18.58-Unraid'), 'Installation task must use attempted package for pending/failure and the last successful receipt for installed/active states');
}
$disabledFixture = $base;
$disabledFixture['status']['upgrade'] = ['status' => 'disabled'];
$disabledRendered = ui_render($disabledFixture);
ui_check(str_contains(ui_panel($disabledRendered, 'status'), '升级预下载已关闭') && !str_contains(ui_panel($disabledRendered, 'status'), '自动更新已关闭'), 'Disabled upgrade status must describe prefetch only');
unset($cachedBuild['status']['settings']['selected_package']['build']);
$rendered = ui_render($cachedBuild);
ui_check(str_contains(strip_tags(ui_panel($rendered, 'driver')), 'NVIDIA 595.58.03 · 构建 未记录'), 'The UI must not guess build1 from a driver version or incomplete build2 receipt');

$upload = ['csrf_token' => 'fixture-ui-csrf', 'uvm_csrf_token' => 'fixture-ui-csrf', 'uvm_tab' => 'driver', 'uvm_action' => 'source-upload-begin',
    'filename' => 'nvidia-595.58.03-6.18.58-Unraid-1.txz', 'total' => '200', 'checksum' => str_repeat('a', 32)];
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $upload,
    'responses' => ['source-upload-begin' => ['upload_id' => str_repeat('a', 32), 'offset' => 0, 'max_chunk_size' => 1048576]]]);
ui_check(ui_actions($rendered) === ['source-upload-begin'], 'Upload AJAX must avoid repeatedly rendering status and profiles');
ui_check($rendered['profile_scans'] === 0 && substr_count($rendered['html'], 'id="uvm-upload-result"') === 1, 'All native child evaluations must produce one upload result without any profile scan');
ui_check(str_contains($rendered['html'], 'id="uvm-upload-result"') && str_contains($rendered['html'], '"ok":true'), 'Upload result must be readable inside the Unraid HTML wrapper');
ui_check($rendered['calls'][0]['payload']['total'] === 200, 'Upload total must be validated then passed as integer');
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $upload, 'gateway_strip_csrf' => true]);
ui_check(ui_actions($rendered) === ['source-upload-begin'] && str_contains($rendered['html'], '"ok":true'), 'Uploads must also survive the native gateway consuming csrf_token');
foreach (['', ' ', str_repeat('a', 4097)] as $missingChecksum) {
    $rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => array_replace($upload, ['checksum' => $missingChecksum])]);
    ui_check(ui_actions($rendered) === [] && str_contains($rendered['html'], '"outcome":"failed"'), 'Missing or oversized MD5 must fail before staging or CLI execution');
}
$encodedChunk = ['csrf_token' => 'fixture-ui-csrf', 'uvm_csrf_token' => 'fixture-ui-csrf', 'uvm_tab' => 'driver',
    'uvm_action' => 'source-upload-chunk', 'upload_id' => str_repeat('a', 32), 'offset' => '0', 'chunk_data' => 'AP8K', 'path' => '/never-forward'];
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $encodedChunk, 'gateway_strip_csrf' => true]);
ui_check(ui_actions($rendered) === ['source-upload-chunk'] && $rendered['calls'][0]['payload'] === ['upload_id' => str_repeat('a', 32), 'offset' => 0, 'data' => 'AP8K'], 'Encoded binary must pass the consumed-token boundary without forwarding a client filesystem path');
foreach (['', str_repeat('a', 1398105)] as $invalidChunk) {
    $rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => array_replace($encodedChunk, ['chunk_data' => $invalidChunk])]);
    ui_check(ui_actions($rendered) === [] && str_contains($rendered['html'], '"outcome":"failed"'), 'Empty or oversized encoded chunks must fail before the CLI');
}
$rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $encodedChunk,
    'files' => ['chunk' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/phpForged']]]);
ui_check(ui_actions($rendered) === [], 'Mixed file and encoded-data chunks must not choose an ambiguous source');
$finish = array_replace($encodedChunk, ['uvm_action' => 'source-upload-finish']);
foreach (['errors' => 'failed', 'unknown_errors' => 'unknown'] as $errorKey => $outcome) {
    $rendered = ui_render($base + ['server' => ['REQUEST_METHOD' => 'POST'], 'post' => $finish, $errorKey => ['source-upload-finish' => 'fixture result']]);
    ui_check(substr_count($rendered['html'], 'id="uvm-upload-result"') === 1 && str_contains($rendered['html'], '"outcome":"' . $outcome . '"'), 'Failed and unknown upload outcomes must stay distinct inside the native page wrapper');
}
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
