<?php
// Unraid renders all native tab bodies in one request. Dispatch and snapshot once.
$uvm_secrets = [];
$uvm_csrf = uvm_csrf_token();
$uvm_message = '';
$uvm_error = '';
$uvm_action_data = null;
$uvm_versions = [];
$uvm_action = '';
$uvm_request_initialized = true;
$uvm_request_tab = 'driver';
$uvm_preferred_tab = is_string($_GET['uvm_view'] ?? null) && in_array($_GET['uvm_view'], ['driver', 'vgpu', 'status'], true) ? $_GET['uvm_view'] : '';
$uvm_upload_request = is_string($_POST['uvm_action'] ?? null) && in_array($_POST['uvm_action'], ['source-upload-begin', 'source-upload-chunk', 'source-upload-finish', 'source-upload-cancel'], true);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        uvm_check_post($uvm_csrf);
        $uvm_action = uvm_post('uvm_action');
        $targetTab = uvm_post('uvm_tab');
        if (!in_array($targetTab, ['driver', 'vgpu', 'status'], true)) throw new InvalidArgumentException('页面标识无效，请刷新后再试。');
        $uvm_request_tab = $targetTab;
        $uvm_preferred_tab = $targetTab;
        $allowedActions = [
            'driver' => ['config-save', 'source-test', 'source-versions', 'source-download', 'source-upload-begin', 'source-upload-chunk', 'source-upload-finish', 'source-upload-cancel', 'upgrade-check'],
            'vgpu' => ['config-save', 'device-save', 'device-start', 'device-stop', 'device-remove'],
            'status' => [],
        ];
        if (!in_array($uvm_action, $allowedActions[$uvm_request_tab], true)) throw new InvalidArgumentException('此操作不属于当前页面。');
        $payload = [];
        switch ($uvm_action) {
            case 'config-save':
                $section = uvm_post('config_section');
                if (($uvm_request_tab === 'driver' && $section !== 'source') || ($uvm_request_tab === 'vgpu' && $section !== 'runtime')) {
                    throw new InvalidArgumentException('设置表单与当前页面不匹配。');
                }
                if ($section === 'source') {
                    $source = [];
                    foreach (['provider', 'mode', 'repository', 'driver', 'tag_template', 'project_id', 'package_name', 'package_version_template'] as $key) $source[$key] = trim(uvm_post('source_' . $key));
                    if (isset($_POST['source_url'])) $source['url'] = trim(uvm_post('source_url'));
                    if (uvm_post('source_simple') === '1' && ($source['url'] ?? '') === '') $source['repository'] = '';
                    $payload['source'] = $source;
                    if (isset($_POST['upgrade_enabled'])) $payload['upgrade'] = ['enabled' => uvm_post('upgrade_enabled') === '1'];
                    $credentialChange = uvm_post('credential_change', 'auto');
                    if ($credentialChange === 'auto') $credentialChange = uvm_post('credential_token') !== '' ? 'replace' : 'keep';
                    if ($credentialChange === 'replace') {
                        $token = uvm_post('credential_token');
                        if ($token === '') throw new InvalidArgumentException('请输入新的 Token。');
                        $uvm_secrets[] = $token;
                        $payload['credential'] = ['type' => uvm_post('credential_type', 'auto'), 'token' => $token, 'username' => trim(uvm_post('credential_username'))];
                    } elseif ($credentialChange === 'remove') {
                        $payload['credential'] = ['type' => 'none', 'token' => '', 'username' => ''];
                    } elseif ($credentialChange !== 'keep') {
                        throw new InvalidArgumentException('Invalid credential operation.');
                    }
                } else {
                    $payload['runtime'] = [
                        'enabled' => uvm_post('runtime_enabled') === '1',
                        'unlock' => uvm_post('runtime_unlock') === '1',
                        'unlock_library' => trim(uvm_post('runtime_unlock_library')),
                        'xml_override' => uvm_post('runtime_xml_override') === '1',
                    ];
                    if (uvm_post('override_replace') === '1') {
                        $overrideContent = str_replace("\r", '', uvm_post('override_content'));
                        if (strlen($overrideContent) > 65536) throw new InvalidArgumentException('profile_override.toml must not exceed 64 KiB.');
                        $payload['override_content'] = $overrideContent;
                    }
                }
                $uvm_action_data = uvm_cli($uvm_action, $payload);
                $uvm_message = $section === 'source' ? '驱动设置已保存。' : 'vGPU 设置已保存。';
                if (isset($uvm_action_data['upgrade_helper']) && !($uvm_action_data['upgrade_helper']['ok'] ?? false)) {
                    $uvm_error = '设置已保存，但升级助手未启动，请检查插件安装。';
                }
                if ($section === 'source' && uvm_post('source_connect') === '1') {
                    $uvm_action_data = uvm_cli('source-versions');
                    $uvm_versions = is_array($uvm_action_data['versions'] ?? null) ? $uvm_action_data['versions'] : [];
                    $uvm_message = count($uvm_versions) ? '已找到 ' . count($uvm_versions) . ' 个匹配当前内核的驱动版本。' : '未找到匹配当前内核的驱动。';
                }
                break;
            case 'upgrade-check':
                $uvm_action_data = uvm_cli($uvm_action, ['force' => true]);
                $upgradeCode = (string)($uvm_action_data['status'] ?? '');
                $uvm_message = $upgradeCode === 'ready' ? '目标内核的驱动已准备好。' : '升级准备检查完成，请查看系统升级状态。';
                break;
            case 'source-test':
            case 'source-versions':
            case 'source-download':
                // Only saved source + saved credentials are used. No implicit config-save.
                if ($uvm_action === 'source-download' && uvm_post('download_driver') !== '') $payload['driver'] = trim(uvm_post('download_driver'));
                $uvm_action_data = uvm_cli($uvm_action, $payload);
                if (($uvm_action_data['ok'] ?? true) === false) throw new RuntimeException((string)($uvm_action_data['error'] ?? $uvm_action_data['message'] ?? 'The source operation failed.'));
                if ($uvm_action === 'source-versions') {
                    $uvm_versions = is_array($uvm_action_data['versions'] ?? null) ? $uvm_action_data['versions'] : [];
                    $uvm_message = count($uvm_versions) ? '已找到 ' . count($uvm_versions) . ' 个匹配版本。' : '未找到匹配当前内核的驱动。';
                } elseif ($uvm_action === 'source-download') {
                    $uvm_message = '驱动已下载并校验，文件已保存到缓存。';
                } else {
                    $uvm_message = '连接成功。';
                }
                break;
            case 'source-upload-begin':
                $total = uvm_post('total');
                if (!preg_match('/^[1-9][0-9]*$/D', $total) || strlen($total) > 10) throw new InvalidArgumentException('文件大小无效。');
                $uvm_action_data = uvm_cli($uvm_action, ['filename' => uvm_post('filename'), 'total' => (int)$total, 'checksum' => uvm_post('checksum')]);
                break;
            case 'source-upload-chunk':
                $chunk = $_FILES['chunk'] ?? null;
                if (!is_array($chunk) || ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($chunk['tmp_name'] ?? null) || !is_uploaded_file($chunk['tmp_name'])) {
                    throw new InvalidArgumentException('上传分块失败，请检查服务器上传限制。');
                }
                $offset = uvm_post('offset');
                if (!preg_match('/^(0|[1-9][0-9]*)$/D', $offset) || strlen($offset) > 10) throw new InvalidArgumentException('上传位置无效。');
                $uvm_action_data = uvm_cli($uvm_action, ['upload_id' => uvm_post('upload_id'), 'offset' => (int)$offset, 'path' => $chunk['tmp_name']]);
                break;
            case 'source-upload-finish':
            case 'source-upload-cancel':
                $uvm_action_data = uvm_cli($uvm_action, ['upload_id' => uvm_post('upload_id')]);
                break;
            case 'device-save':
                foreach (['uuid', 'pci', 'profile', 'type'] as $key) $payload[$key] = trim(uvm_post($key));
                $uvm_action_data = uvm_cli($uvm_action, $payload);
                $uvm_message = '设备配置已保存。';
                break;
            case 'device-start':
            case 'device-stop':
            case 'device-remove':
                $uvm_action_data = uvm_cli($uvm_action, ['uuid' => trim(uvm_post('uuid'))]);
                $uvm_message = ['device-start' => '设备已启动。', 'device-stop' => '设备已停止。', 'device-remove' => '设备配置已移除。'][$uvm_action];
                break;
            default:
                throw new InvalidArgumentException('Unsupported page action.');
        }
    } catch (Throwable $exception) {
        $uvm_error = uvm_redact($exception->getMessage());
        $uvm_message = '';
    }
}

// Unraid wraps .page responses in its own HTML. A small tagged response works
// with that wrapper without a second public endpoint or filesystem writer.
if ($uvm_upload_request) {
    echo '<script type="application/json" id="uvm-upload-result">' . uvm_json($uvm_error === '' ? ['ok' => true, 'data' => $uvm_action_data] : ['ok' => false, 'error' => $uvm_error]) . '</script>';
    return;
}

$uvm_status = [];
$uvm_status_ok = false;
try {
    $uvm_status = uvm_cli('status');
    $uvm_status_ok = true;
} catch (Throwable $exception) {
    $uvm_error .= ($uvm_error !== '' ? "\n" : '') . '状态读取失败：' . uvm_redact($exception->getMessage());
}
$uvm_defaults = [
    'source' => ['url' => '', 'provider' => 'auto', 'mode' => 'auto', 'repository' => '', 'driver' => '', 'project_id' => '', 'tag_template' => 'v{kernel}-{driver}', 'package_name' => 'build', 'package_version_template' => 'v{kernel}-{driver}'],
    'runtime' => ['enabled' => false, 'unlock' => true, 'unlock_library' => '/usr/lib64/libvgpu_unlock_rs.so', 'xml_override' => false],
    'upgrade' => ['enabled' => false, 'driver_branch' => '', 'enabled_boot_id' => ''],
];
$uvm_settings = array_replace_recursive($uvm_defaults, is_array($uvm_status['settings'] ?? null) ? $uvm_status['settings'] : []);
$uvm_source = $uvm_settings['source'];
$uvm_runtime_settings = $uvm_settings['runtime'];
$uvm_upgrade = is_array($uvm_status['upgrade'] ?? null) ? $uvm_status['upgrade'] : [];
$uvm_installation = is_array($uvm_status['driver_installation'] ?? null) ? $uvm_status['driver_installation'] : [];
$uvm_devices = is_array($uvm_status['devices'] ?? null) ? $uvm_status['devices'] : [];
$uvm_runtime = is_array($uvm_status['runtime'] ?? null) ? $uvm_status['runtime'] : [];
$uvm_pending = !empty($uvm_status['pending_restart']) || !empty($uvm_settings['pending_restart']);
$uvm_controls = $uvm_status_ok && $uvm_csrf !== '';
$uvm_gpus = uvm_profile_scan();
$uvm_selected_package = $uvm_settings['selected_package'] ?? null;
$uvm_override = is_string($uvm_status['override_content'] ?? null) ? $uvm_status['override_content'] : '';
$uvm_uuid_bytes = random_bytes(16);
$uvm_uuid_bytes[6] = chr((ord($uvm_uuid_bytes[6]) & 15) | 64);
$uvm_uuid_bytes[8] = chr((ord($uvm_uuid_bytes[8]) & 63) | 128);
$uvm_uuid_hex = bin2hex($uvm_uuid_bytes);
$uvm_new_uuid = substr($uvm_uuid_hex, 0, 8) . '-' . substr($uvm_uuid_hex, 8, 4) . '-' . substr($uvm_uuid_hex, 12, 4) . '-' . substr($uvm_uuid_hex, 16, 4) . '-' . substr($uvm_uuid_hex, 20);
$uvm_driver_mode = ($_GET['driver_mode'] ?? '') === 'local' ? 'local' : 'online';
$uvm_snapshot = is_array($uvm_runtime['runtime'] ?? null) ? $uvm_runtime['runtime'] : [];
$uvm_gpu_labels = [];
foreach ($uvm_gpus as $pci => $gpu) $uvm_gpu_labels[] = ($gpu['name'] ?? '') ?: 'NVIDIA ' . ($gpu['device'] ?? $pci);

