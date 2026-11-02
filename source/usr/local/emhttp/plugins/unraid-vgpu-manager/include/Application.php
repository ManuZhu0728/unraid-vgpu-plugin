<?php
declare(strict_types=1);

namespace Vgpu;

/** All writes, whether from the WebGUI or CLI, pass through this transaction boundary. */
final class Application
{
    private $runner;

    public function __construct(private Store $store, private Runtime $runtime, private Download $download, ?callable $runner = null)
    {
        $this->runner = $runner ?? new Process();
    }

    public function dispatch(string $action, array $input = []): array
    {
        if ($action === 'status') return $this->status();
        if ($action === 'logs') {
            $category = $input['category'] ?? 'plugin';
            if (!is_string($category)) throw new \InvalidArgumentException('Invalid log category');
            return (new Logs($this->store->paths, (string)($this->store->credentials()['token'] ?? '')))->read($category);
        }
        if (!in_array($action, ['config-save', 'source-test', 'source-versions', 'source-download', 'source-import', 'source-upload-begin', 'source-upload-chunk', 'source-upload-finish', 'source-upload-cancel', 'upgrade-check', 'device-save', 'device-start', 'device-stop', 'device-remove', 'ensure', 'boot', 'plan'], true)) {
            throw new \InvalidArgumentException('Unknown operation');
        }
        if ($action === 'plan') {
            return ['message' => 'Driver installation runs at boot. System upgrades can prepare the target-kernel package in advance.', 'pending_restart' => $this->store->settings()['pending_restart']];
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
            'upgrade' => (new Upgrade($this->store, $this->download, $this->runner))->status(),
            'driver_installation' => (new Installer($this->store, $this->download, $this->runtime, $this->runner))->state(),
            'warning' => 'Driver caches contain privileged installation scripts. Only use repositories you trust. Flash-drive backups may contain download credentials.',
        ];
    }

    private function mutate(string $action, array $input): array
    {
        $settings = $this->store->settings();
        $devices = $this->store->devices();
        if ($action === 'config-save') return $this->saveConfig($settings, $input);
        if ($action === 'upgrade-check') {
            $target = $input['target_kernel'] ?? null;
            if ($target !== null && !is_string($target)) throw new \InvalidArgumentException('Invalid target kernel');
            $force = $input['force'] ?? false;
            if (!is_bool($force)) throw new \InvalidArgumentException('Retry flag must be a boolean');
            return (new Upgrade($this->store, $this->download, $this->runner))->check($settings, $this->store->credentials(), $target, $force);
        }
        if ($action === 'source-import' || str_starts_with($action, 'source-upload-')) {
            return $this->import($action, $settings, $input);
        }
        if (in_array($action, ['source-test', 'source-versions', 'source-download'], true)) {
            $source = Store::validateSource($settings['source']);
            if ($source['repository'] === '' && ($source['url'] ?? '') === '') throw new \RuntimeException('Save a driver link before connecting');
            if ($action === 'source-download' && array_key_exists('driver', $input)) {
                if (!is_string($input['driver'])) throw new \InvalidArgumentException('Driver version must be X.Y.Z');
                $source = Store::validateSource(array_replace($source, ['driver' => $input['driver']]));
            }
            $credentials = $this->store->credentials();
            if (($credentials['token'] ?? '') !== '' && ($credentials['source_id'] ?? '') !== Store::sourceIdentity($source)) {
                throw new \RuntimeException('Credential source binding mismatch; explicitly save a new token for this repository');
            }
            $method = ['source-test' => 'test', 'source-versions' => 'versions', 'source-download' => 'fetch'][$action];
            $result = $this->download->$method($source, $credentials, $this->kernel());
            if ($action === 'source-download' && ($result['ok'] ?? true) && isset($result['package'])) {
                $result['package']['source'] = $source['repository'];
                $result['package']['provider'] = $source['provider'];
                $result['package'] = $this->packageReceipt($result['package'], $settings['source']);
                $settings['selected_package'] = $result['package'];
                $this->store->saveSettings($settings);
                $result['message'] = 'Driver package verified and cached only. No driver was installed or reloaded.';
            }
            return $result;
        }
        if ($action === 'ensure' || $action === 'boot') {
            $installation = null;
            if ($action === 'boot' && ($settings['upgrade']['enabled'] ?? false) === true) {
                $prepared = (new Upgrade($this->store, $this->download, $this->runner))->status();
                $installation = (new Installer($this->store, $this->download, $this->runtime, $this->runner))->boot($settings, $prepared);
                if (!($installation['ok'] ?? false)) return $installation;
            }
            $result = $this->runtime->ensure($settings, $devices);
            if ($installation !== null) $result['driver_installation'] = $installation;
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
        $previousUpgrade = $settings['upgrade'];
        if (isset($input['source'])) {
            if (!is_array($input['source'])) throw new \InvalidArgumentException('Source must be an object');
            // Earlier clients edit repository on a complete settings snapshot.
            // A new URL explicitly supplied by the simple form takes priority.
            if (isset($input['source']['repository']) && $input['source']['repository'] !== $previousSource['repository'] &&
                array_key_exists('url', $input['source']) && $input['source']['url'] === ($previousSource['url'] ?? '')) {
                $input['source']['url'] = $input['source']['repository'];
            }
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
        if (isset($input['upgrade'])) {
            if (!is_array($input['upgrade']) || !is_bool($input['upgrade']['enabled'] ?? null)) {
                throw new \InvalidArgumentException('Upgrade enabled must be a boolean');
            }
            $settings['upgrade']['enabled'] = $input['upgrade']['enabled'];
            if ($input['upgrade']['enabled'] && !$previousUpgrade['enabled']) {
                $bootId = $this->bootId();
                if ($bootId === '') throw new \RuntimeException('Cannot identify this boot before enabling automatic installation');
                $settings['upgrade']['enabled_boot_id'] = $bootId;
            }
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
        if (($settings['upgrade']['enabled'] ?? false) === true) {
            $driver = $settings['source']['driver'];
            if ($driver === '' && ($settings['upgrade']['driver_branch'] === '' || !$previousUpgrade['enabled'])) {
                $loaded = $this->store->paths->path('/sys/module/nvidia/version');
                $driver = is_file($loaded) ? trim((string)file_get_contents($loaded)) : (string)($settings['selected_package']['driver'] ?? '');
                $settings['upgrade']['driver_branch'] = '';
            }
            if (preg_match('/^([0-9]+)\.[0-9]+\.[0-9]+$/D', $driver, $match)) $settings['upgrade']['driver_branch'] = $match[1];
            // Explicitly enabling installation authorizes the previous cache
            // selection, but boot still revalidates every byte and module.
            if (!$previousUpgrade['enabled'] && is_array($settings['selected_package'])) {
                $selected = $settings['selected_package'];
                if (($selected['source'] ?? '') === 'local' || ($selected['source'] ?? '') === $settings['source']['repository']) {
                    $settings['selected_package'] = $this->packageReceipt($selected, $settings['source']);
                }
            }
        }
        if ($settings['runtime'] !== $oldRuntime) $settings['pending_restart'] = true;
        $this->store->saveCredentials($credentials);
        if ($override !== null) $this->saveOverride($override);
        $this->store->saveSettings($settings);
        $result = ['message' => 'Configuration saved. No running GPU service was changed.', 'pending_restart' => $settings['pending_restart'], 'credential_cleared' => $changedIdentity];
        if (isset($input['upgrade'])) $result['upgrade_helper'] = $this->syncUpgradeHelper($settings['upgrade']['enabled']);
        return $result;
    }

    private static function validateCredential(array $credential, string $provider): void
    {
        $type = $credential['type'] ?? 'none';
        if (!in_array($type, ['none', 'auto', 'bearer', 'private-token', 'deploy-token'], true)) throw new \InvalidArgumentException('Unsupported credential type');
        if (($provider === 'github' && !in_array($type, ['none', 'auto', 'bearer'], true)) || ($provider === 'gitlab' && !in_array($type, ['none', 'auto', 'private-token', 'deploy-token'], true)) || ($provider === 'direct' && !in_array($type, ['none', 'auto', 'bearer'], true))) {
            throw new \InvalidArgumentException('Authentication type does not match the repository provider');
        }
        if (!is_string($credential['token'] ?? '') || strlen($credential['token'] ?? '') > 4096 || preg_match('/[\x00-\x20\x7f]/', $credential['token'] ?? '')) throw new \InvalidArgumentException('Invalid token');
        if (!is_string($credential['username'] ?? '') || strlen($credential['username'] ?? '') > 256 || preg_match('/[\x00-\x1f\x7f]/', $credential['username'] ?? '')) throw new \InvalidArgumentException('Invalid credential username');
    }

    private function import(string $action, array $settings, array $input): array
    {
        $upload = new Upload($this->store->paths);
        if ($action === 'source-upload-cancel') return $upload->cancel($input['upload_id'] ?? null);
        $kernel = $this->kernel();
        if ($action === 'source-upload-begin') return $upload->begin($input, $kernel);
        if ($action === 'source-upload-chunk') return $upload->chunk($input, $kernel);
        $id = null;
        if ($action === 'source-upload-finish') {
            $id = $input['upload_id'] ?? null;
            $candidate = $upload->completed($id, $kernel);
        } else {
            $candidate = [
                'filename' => $upload->filename($input['filename'] ?? null, $kernel),
                'path' => $upload->uploadedFile($input['path'] ?? null, Upload::MAX_PACKAGE),
                'checksum_path' => isset($input['checksum_path']) ? $upload->uploadedFile($input['checksum_path'], 4096) : null,
            ];
        }
        try {
            $result = $this->download->importLocal($candidate['path'], $candidate['filename'], $candidate['checksum_path'], $kernel);
            if (($result['ok'] ?? false) && isset($result['package'])) {
                $result['package']['source'] = 'local';
                $result['package']['provider'] = 'local';
                $result['package'] = $this->packageReceipt($result['package'], $settings['source']);
                $settings['selected_package'] = $result['package'];
                $this->store->saveSettings($settings);
                $result['message'] = 'Local driver package imported';
            }
            return $result;
        } finally {
            if ($id !== null) $upload->cancel($id);
        }
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

    private function bootId(): string
    {
        $path = $this->store->paths->path('/proc/sys/kernel/random/boot_id');
        $value = is_file($path) ? trim((string)file_get_contents($path)) : '';
        return preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $value) ? $value : '';
    }

    private function packageReceipt(array $package, array $source): array
    {
        $package['saved_boot_id'] = $this->bootId();
        $package['source_id'] = Store::sourceIdentity($source);
        $package['source_fingerprint'] = Upgrade::sourceFingerprint($source);
        return $package;
    }

    private function syncUpgradeHelper(bool $enabled): array
    {
        $script = $this->store->paths->path('/usr/local/emhttp/plugins/unraid-vgpu-manager/scripts/upgrade-helper');
        if (!is_file($script)) return ['ok' => false, 'message' => 'Upgrade helper is not installed'];
        $response = ($this->runner)(['/bin/bash', $script, $enabled ? 'start' : 'stop'], 10, null);
        return ['ok' => ($response['code'] ?? -1) === 0, 'message' => ($response['code'] ?? -1) === 0 ? 'Upgrade helper updated' : 'Upgrade helper could not be started'];
    }
}
