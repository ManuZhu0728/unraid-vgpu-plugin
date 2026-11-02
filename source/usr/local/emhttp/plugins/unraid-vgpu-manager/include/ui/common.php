<?php
/* Public UI only. Configuration, credentials and device mutations belong to cli.php. */

function uvm_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function uvm_json($value): string
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
}

function uvm_read(string $path, int $limit = 65536): string
{
    if (!is_readable($path) || !is_file($path)) return '';
    $value = @file_get_contents($path, false, null, 0, $limit);
    return $value === false ? '' : trim($value);
}

function uvm_post(string $key, string $default = ''): string
{
    if (!isset($_POST[$key])) return $default;
    if (!is_string($_POST[$key])) throw new InvalidArgumentException('Invalid form field: ' . $key);
    return $_POST[$key];
}

function uvm_upload_chunk_size(): int
{
    $limit = 1048576;
    foreach (['upload_max_filesize', 'post_max_size'] as $option) {
        $value = trim((string)ini_get($option));
        if (!preg_match('/^([0-9]+)\s*([KMG]?)$/i', $value, $match) || (int)$match[1] === 0) continue;
        $bytes = (int)$match[1] * (1024 ** (int)strpos(' KMGT', strtoupper($match[2] ?: ' ')));
        $limit = min($limit, max(0, $bytes - 65536));
    }
    return $limit;
}

function uvm_redact($value)
{
    global $uvm_secrets;
    if (is_array($value)) {
        $clean = [];
        foreach ($value as $key => $entry) {
            if (is_string($key) && preg_match('/token|password|secret|authorization/i', $key)) {
                $clean[$key] = '[redacted]';
            } else {
                $clean[$key] = uvm_redact($entry);
            }
        }
        return $clean;
    }
    if (!is_string($value)) return $value;
    foreach ($uvm_secrets as $secret) {
        if ($secret !== '') $value = str_replace($secret, '[redacted]', $value);
    }
    $value = preg_replace('~https?://[^\s/@]+:[^\s/@]+@~i', 'https://[redacted]@', $value);
    return preg_replace('/\b(?:glpat-|github_pat_|gh[pousr]_)[A-Za-z0-9_.-]+/', '[redacted]', $value);
}

function uvm_cli(string $action, array $payload = []): array
{
    $allowed = ['status', 'logs', 'config-save', 'source-test', 'source-versions', 'source-download', 'source-upload-begin', 'source-upload-chunk', 'source-upload-finish', 'source-upload-cancel', 'upgrade-check', 'device-save', 'device-start', 'device-stop', 'device-remove'];
    if (!in_array($action, $allowed, true)) throw new InvalidArgumentException('Unsupported action');
    // Unraid may evaluate/cache .page templates outside their installed directory.
    $entrypoint = '/usr/local/emhttp/plugins/unraid-vgpu-manager/include/cli.php';
    if (!is_file($entrypoint) || !is_executable('/usr/bin/php')) {
        throw new RuntimeException('The plugin backend is not installed completely (include/cli.php or /usr/bin/php is missing).');
    }
    $input = json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    if (strlen($input) > 131072) throw new InvalidArgumentException('The request exceeds 128 KiB.');
    $pipes = [];
    // argv array: no shell, and credentials are delivered only over stdin.
    $process = proc_open(['/usr/bin/php', $entrypoint, $action], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Unable to start the plugin backend.');
    foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
    $timeout = $action === 'upgrade-check' ? 3600 : (in_array($action, ['source-download', 'source-upload-finish'], true) ? 900 : (str_starts_with($action, 'source-') ? 90 : 30));
    $deadline = microtime(true) + $timeout;
    $offset = 0;
    $output = '';
    $tooLarge = false;
    $expired = false;
    $status = ['running' => true, 'exitcode' => -1];
    while (true) {
        if (is_resource($pipes[0])) {
            if ($offset < strlen($input)) {
                $written = @fwrite($pipes[0], substr($input, $offset, 65536));
                if ($written !== false) $offset += $written;
            }
            if ($offset >= strlen($input)) fclose($pipes[0]);
        }
        $chunk = stream_get_contents($pipes[1]);
        if ($chunk !== false) {
            if (strlen($output) + strlen($chunk) > 2097152) $tooLarge = true;
            $output .= substr($chunk, 0, max(0, 2097152 - strlen($output)));
        }
        // Drain stderr to avoid a full pipe; never return raw stderr containing secrets.
        stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) break;
        if (microtime(true) >= $deadline) {
            $expired = true;
            proc_terminate($process, 15);
            break;
        }
        usleep(20000);
    }
    $chunk = stream_get_contents($pipes[1]);
    if ($chunk !== false) {
        if (strlen($output) + strlen($chunk) > 2097152) $tooLarge = true;
        $output .= substr($chunk, 0, max(0, 2097152 - strlen($output)));
    }
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    if ($expired) {
        // Do not wait forever on a kernel-stuck child or claim that an action was cancelled.
        throw new RuntimeException('The backend did not finish within its time limit. Completion is unknown; check status before retrying.');
    }
    $closed = proc_close($process);
    $exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
    $result = json_decode($output, true);
    if ($tooLarge || !is_array($result) || !array_key_exists('ok', $result) || !is_bool($result['ok'])) {
        throw new RuntimeException('The backend returned an invalid response. Check the plugin installation and server logs.');
    }
    if (!$result['ok']) {
        $error = $result['error'] ?? $result['data']['error'] ?? $result['data']['message'] ?? 'The requested action failed.';
        throw new RuntimeException(is_string($error) ? uvm_redact($error) : 'The requested action failed.');
    }
    if ($exitCode !== 0) throw new RuntimeException('The backend exited with an error; the operation was not confirmed.');
    if (isset($result['data']) && !is_array($result['data'])) {
        throw new RuntimeException('The backend returned an invalid data object.');
    }
    return $result['data'] ?? [];
}

function uvm_csrf_token(): string
{
    global $var;
    // Unraid's native token is held in var.ini and usually exposed as $var by emhttp.
    $native = is_array($var ?? null) && is_string($var['csrf_token'] ?? null) ? $var['csrf_token'] : '';
    if ($native !== '') return $native;
    if (is_readable('/var/local/emhttp/var.ini')) {
        $variables = @parse_ini_file('/var/local/emhttp/var.ini', false, INI_SCANNER_RAW);
        if (is_array($variables) && is_string($variables['csrf_token'] ?? null)) return $variables['csrf_token'];
    }
    return '';
}

function uvm_origin_authority(string $url): string
{
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return '';
    $scheme = strtolower($parts['scheme']);
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
}

function uvm_check_post(string $expected): void
{
    // emhttp may consume csrf_token after its gateway check. Keep a separate
    // plugin field so this check works both before and after that processing.
    if ($expected === '' || !hash_equals($expected, uvm_post('uvm_csrf_token')) ||
        (array_key_exists('csrf_token', $_POST) && !hash_equals($expected, uvm_post('csrf_token')))) {
        throw new RuntimeException('页面校验失败，请重新打开插件设置页后再试。');
    }
    $fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if (in_array($fetchSite, ['cross-site', 'same-site'], true)) throw new RuntimeException('Cross-origin requests are not allowed.');
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443' ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $local = uvm_origin_authority($scheme . '://' . $host);
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $referrer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($local === '' || ($origin !== '' ? uvm_origin_authority($origin) !== $local : ($referrer === '' || uvm_origin_authority($referrer) !== $local))) {
        throw new RuntimeException('The request origin does not match this Unraid web interface. Open the page using the same server URL.');
    }
}

function uvm_form_hidden(string $action, string $csrf, array $fields = []): void
{
    $target = str_starts_with($action, 'device-') || ($action === 'config-save' && ($fields['config_section'] ?? '') === 'runtime') ? 'vgpu' : 'driver';
    echo '<input type="hidden" name="uvm_action" value="' . uvm_h($action) . '">';
    echo '<input type="hidden" name="uvm_tab" value="' . uvm_h($target) . '">';
    echo '<input type="hidden" name="csrf_token" value="' . uvm_h($csrf) . '">';
    echo '<input type="hidden" name="uvm_csrf_token" value="' . uvm_h($csrf) . '">';
    foreach ($fields as $name => $value) echo '<input type="hidden" name="' . uvm_h($name) . '" value="' . uvm_h($value) . '">';
}

function uvm_profile_scan(): array
{
    $gpus = [];
    foreach (glob('/sys/bus/pci/devices/*') ?: [] as $directory) {
        if (uvm_read($directory . '/vendor') !== '0x10de' || !str_starts_with(uvm_read($directory . '/class'), '0x03')) continue;
        $pci = basename($directory);
        if (!preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/D', $pci)) continue;
        $profiles = [];
        foreach (glob($directory . '/mdev_supported_types/*') ?: [] as $typeDirectory) {
            $type = basename($typeDirectory);
            if (!preg_match('/^nvidia-[0-9]+$/D', $type)) continue;
            $info = [];
            foreach (preg_split('/,\s*/', uvm_read($typeDirectory . '/description')) as $item) {
                $pair = explode('=', $item, 2);
                if (count($pair) === 2) $info[trim($pair[0])] = trim($pair[1]);
            }
            $profiles[] = [
                'type' => $type, 'name' => uvm_read($typeDirectory . '/name'),
                'available' => uvm_read($typeDirectory . '/available_instances'),
                'framebuffer' => $info['framebuffer'] ?? '', 'heads' => $info['num_heads'] ?? '',
                'resolution' => $info['max_resolution'] ?? '', 'frl' => $info['frl_config'] ?? '',
                'maximum' => $info['max_instance'] ?? '',
            ];
        }
        usort($profiles, static fn($left, $right) => strnatcmp($left['name'], $right['name']));
        $information = uvm_read('/proc/driver/nvidia/gpus/' . $pci . '/information', 4096);
        preg_match('/^Model:\s*(.+)$/m', $information, $model);
        $gpus[$pci] = ['name' => trim($model[1] ?? ''), 'device' => uvm_read($directory . '/device'), 'profiles' => $profiles];
    }
    return $gpus;
}
