#!/usr/bin/php
<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Application.php';

use Vgpu\Application;
use Vgpu\Download;
use Vgpu\Paths;
use Vgpu\Runtime;
use Vgpu\Store;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$action = $argv[1] ?? 'status';
try {
    if (!is_file('/etc/unraid-version')) throw new RuntimeException('This entry point only runs on Unraid; use the hardware-free tests on a build machine');
    $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    if ($effectiveUid === null && preg_match('/^Uid:\s+\d+\s+(\d+)/m', (string)file_get_contents('/proc/self/status'), $uid)) $effectiveUid = (int)$uid[1];
    if ($effectiveUid !== 0) throw new RuntimeException('Run the backend as root through the Unraid WebGUI or CLI');
    $payloadActions = ['config-save', 'device-save', 'device-start', 'device-stop', 'device-remove'];
    if (in_array($action, $payloadActions, true) && function_exists('stream_isatty') && stream_isatty(STDIN)) {
        throw new InvalidArgumentException('This operation requires a JSON request on stdin');
    }
    $raw = in_array($action, $payloadActions, true) ? stream_get_contents(STDIN, 131073) : '';
    if (strlen($raw) > 131072) throw new InvalidArgumentException('Request is too large');
    $input = trim($raw) === '' ? [] : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('Request must be a JSON object');
    $paths = new Paths();
    $application = new Application(new Store($paths), new Runtime($paths), new Download($paths));
    $result = $application->dispatch($action, $input);
    $ok = ($result['ok'] ?? true) === true;
    $response = ['ok' => $ok, 'data' => $result];
    if (!$ok) $response['error'] = $result['message'] ?? $result['error'] ?? 'Operation was refused';
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
    exit($ok ? 0 : 1);
} catch (Throwable $error) {
    // Do not print stack traces, request bodies, headers or credential contents.
    $message = $error instanceof JsonException ? 'Invalid JSON request or response' : $error->getMessage();
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    exit(1);
}
