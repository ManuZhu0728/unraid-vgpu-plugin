<?php
declare(strict_types=1);

namespace Vgpu;

/** All writes, whether from the WebGUI or CLI, pass through this transaction boundary. */
final class Application
{
    public function __construct(private Store $store, private Runtime $runtime, private Download $download) {}

    public function dispatch(string $action, array $input = []): array
    {
        if ($action === 'status') return $this->status();
        if (!in_array($action, ['config-save', 'source-test', 'source-versions', 'source-download', 'device-save', 'device-start', 'device-stop', 'device-remove', 'ensure', 'boot', 'plan'], true)) {
            throw new \InvalidArgumentException('Unknown operation');
        }
        if ($action === 'plan') {
            return ['message' => 'No hot driver install/reload is available. Downloads only populate the cache. Runtime enablement requires a reviewed migration.', 'pending_restart' => $this->store->settings()['pending_restart']];
        }
        return $this->store->locked(fn() => $this->mutate($action, $input));
    }

    private function status(): array
    {
        $settings = $this->store->settings();
        $credentials = $this->store->credentials();
        $devices = $this->store->devices();
        $override = $this->store->paths->configDir . '/profile_override.toml';
        return [
            'settings' => $settings, 'devices' => $devices,
            'credential_configured' => ($credentials['token'] ?? '') !== '' && ($credentials['source_id'] ?? '') === Store::sourceIdentity($settings['source']),
            'credential_type' => $credentials['type'] ?? 'none',
            'credential_username' => $credentials['username'] ?? '',
            'pending_restart' => $settings['pending_restart'],
            'override_content' => is_file($override) ? (string)file_get_contents($override) : '',
            'runtime' => $this->runtime->status($settings, $devices),
            'warning' => 'Driver caches contain privileged installation scripts. Only use repositories you trust. Flash-drive backups may contain download credentials.',
        ];
    }

    private function mutate(string $action, array $input): array
    {
        $settings = $this->store->settings();
        $devices = $this->store->devices();
        if ($action === 'config-save') return $this->saveConfig($settings, $input);
        if (in_array($action, ['source-test', 'source-versions', 'source-download'], true)) {
            $source = Store::validateSource($settings['source']);
            if ($source['repository'] === '') throw new \RuntimeException('Save a driver repository before connecting');
            $credentials = $this->store->credentials();
            if (($credentials['token'] ?? '') !== '' && ($credentials['source_id'] ?? '') !== Store::sourceIdentity($source)) {
                throw new \RuntimeException('Credential source binding mismatch; explicitly save a new token for this repository');
            }
            $method = ['source-test' => 'test', 'source-versions' => 'versions', 'source-download' => 'fetch'][$action];
            $result = $this->download->$method($source, $credentials, $this->kernel());
            if ($action === 'source-download' && ($result['ok'] ?? true) && isset($result['package'])) {
                $result['package']['source'] = $source['repository'];
                $result['package']['provider'] = $source['provider'];
                $settings['selected_package'] = $result['package'];
                $this->store->saveSettings($settings);
                $result['message'] = 'Driver package verified and cached only. No driver was installed or reloaded.';
            }
            return $result;
        }
        if ($action === 'ensure' || $action === 'boot') {
            $result = $this->runtime->ensure($settings, $devices);
            if (($result['ok'] ?? false) && ($settings['runtime']['enabled'] ?? false)) {
                $settings['pending_restart'] = false;
                $this->store->saveSettings($settings);
            }
            return $result;
        }
        if ($action === 'device-save') {
            $device = Store::validateDevice($input);
            foreach ($devices as $existing) {
                if ($existing['uuid'] === $device['uuid']) {
                    if ($existing !== $device) throw new \RuntimeException('UUID already configured; stop and remove its unused device before changing its identity');
                    return ['message' => 'Device already configured; no changes', 'changed' => false];
                }
            }
            // Saving is intentionally separate from starting a GPU device.
            $devices[] = $device;
            $this->store->saveDevices($devices);
            return ['message' => 'Device saved; it has not been started', 'changed' => true];
        }
        $uuid = strtolower((string)($input['uuid'] ?? ''));
        $matches = array_values(array_filter($devices, fn($device) => $device['uuid'] === $uuid));
        if (count($matches) !== 1) throw new \RuntimeException('Device is not configured in this plugin');
        $device = $matches[0];
        if ($action === 'device-start') {
            if (!($settings['runtime']['enabled'] ?? false)) throw new \RuntimeException('Runtime management is disabled; complete migration before starting devices');
            return $this->runtime->startDevice($device, $settings);
        }
        if ($action === 'device-stop') return $this->runtime->stopDevice($device);
        if ($action === 'device-remove') {
            $result = $this->runtime->removeDevice($device);
            if ($result['ok'] ?? false) {
                $this->store->saveDevices(array_values(array_filter($devices, fn($entry) => $entry['uuid'] !== $uuid)));
            }
            return $result;
        }
        throw new \InvalidArgumentException('Unsupported operation');
    }

    private function saveConfig(array $settings, array $input): array
    {
        $previousSource = $settings['source'];
        if (isset($input['source'])) {
            if (!is_array($input['source'])) throw new \InvalidArgumentException('Source must be an object');
            $settings['source'] = Store::validateSource($input['source']);
        }
        $oldRuntime = $settings['runtime'];
        if (isset($input['runtime'])) {
            if (!is_array($input['runtime'])) throw new \InvalidArgumentException('Runtime must be an object');
            foreach (['enabled', 'unlock', 'xml_override'] as $field) {
                if (array_key_exists($field, $input['runtime'])) {
                    if (!is_bool($input['runtime'][$field])) throw new \InvalidArgumentException('Runtime flags must be booleans');
                    $settings['runtime'][$field] = $input['runtime'][$field];
                }
            }
            if (isset($input['runtime']['unlock_library'])) {
                if (!in_array($input['runtime']['unlock_library'], ['/usr/lib64/libvgpu_unlock_rs.so', '/usr/lib/libvgpu_unlock_rs.so'], true)) {
                    throw new \InvalidArgumentException('Only the installed driver package UnlockRS paths are supported');
                }
                $settings['runtime']['unlock_library'] = $input['runtime']['unlock_library'];
            }
            if ($settings['runtime']['xml_override']) throw new \InvalidArgumentException('XML overrides are not enabled in this preview; keep the XML paired with the installed driver');
        }
        $override = null;
        if (array_key_exists('override_content', $input)) {
            if (!is_string($input['override_content']) || strlen($input['override_content']) > 65536 || str_contains($input['override_content'], "\0")) {
                throw new \InvalidArgumentException('Invalid or oversized profile override');
            }
            $override = str_replace("\r", '', $input['override_content']);
            // Saving an override does not relink or overwrite the active /etc file.
            $settings['pending_restart'] = true;
        }
        $credentials = $this->store->credentials();
        if (($credentials['token'] ?? '') !== '' && ($credentials['source_id'] ?? '') !== Store::sourceIdentity($previousSource)) {
            // A partial filesystem write or manual edit must never pair a new
            // credential with an old repository, including after a "keep" save.
            $credentials = ['type' => 'none', 'token' => '', 'username' => ''];
        }
        $changedIdentity = ($settings['source']['repository'] !== $previousSource['repository']) || ($settings['source']['provider'] !== $previousSource['provider']) || ($settings['source']['project_id'] !== $previousSource['project_id']);
        if ($changedIdentity) {
            $credentials = ['type' => 'none', 'token' => '', 'username' => ''];
            $settings['selected_package'] = null;
        }
        if (isset($input['credential'])) {
            if (!is_array($input['credential'])) throw new \InvalidArgumentException('Credential must be an object');
            $candidate = $input['credential'];
            if (($candidate['type'] ?? 'none') === 'none') {
                $credentials = ['type' => 'none', 'token' => '', 'username' => ''];
            } elseif (($candidate['token'] ?? '') !== '') {
                $credentials = $candidate;
            } elseif (($candidate['type'] ?? '') !== ($credentials['type'] ?? 'none') || ($credentials['token'] ?? '') === '') {
                throw new \InvalidArgumentException('Supply a token when changing authentication or repository');
            }
        }
        // Validate the complete transaction before any persistent write.
        self::validateCredential($credentials, $settings['source']['provider']);
        $credentials['source_id'] = Store::sourceIdentity($settings['source']);
        if ($settings['runtime'] !== $oldRuntime) $settings['pending_restart'] = true;
        $this->store->saveCredentials($credentials);
        if ($override !== null) $this->saveOverride($override);
        $this->store->saveSettings($settings);
        return ['message' => 'Configuration saved. No running GPU service was changed.', 'pending_restart' => $settings['pending_restart'], 'credential_cleared' => $changedIdentity];
    }

    private static function validateCredential(array $credential, string $provider): void
    {
        $type = $credential['type'] ?? 'none';
        if (!in_array($type, ['none', 'bearer', 'private-token', 'deploy-token'], true)) throw new \InvalidArgumentException('Unsupported credential type');
        if (($provider === 'github' && !in_array($type, ['none', 'bearer'], true)) || ($provider === 'gitlab' && !in_array($type, ['none', 'private-token', 'deploy-token'], true))) {
            throw new \InvalidArgumentException('Authentication type does not match the repository provider');
        }
        if (!is_string($credential['token'] ?? '') || strlen($credential['token'] ?? '') > 4096 || preg_match('/[\x00-\x20\x7f]/', $credential['token'] ?? '')) throw new \InvalidArgumentException('Invalid token');
        if (!is_string($credential['username'] ?? '') || strlen($credential['username'] ?? '') > 256 || preg_match('/[\x00-\x1f\x7f]/', $credential['username'] ?? '')) throw new \InvalidArgumentException('Invalid credential username');
    }

    private function saveOverride(string $contents): void
    {
        $directory = $this->store->paths->configDir;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Cannot create configuration directory');
        $temporary = tempnam($directory, '.override-');
        if ($temporary === false) throw new \RuntimeException('Cannot save profile override');
        try {
            @chmod($temporary, 0600);
            if (file_put_contents($temporary, $contents) !== strlen($contents) || !rename($temporary, $directory . '/profile_override.toml')) throw new \RuntimeException('Cannot atomically save profile override');
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }

    private function kernel(): string
    {
        $file = $this->store->paths->path('/proc/sys/kernel/osrelease');
        $release = is_file($file) ? trim((string)file_get_contents($file)) : php_uname('r');
        if (!preg_match('/^(\d+\.\d+\.\d+)-Unraid$/D', $release, $match)) throw new \RuntimeException('Downloads require an Unraid X.Y.Z-Unraid kernel');
        return $match[1];
    }
}
