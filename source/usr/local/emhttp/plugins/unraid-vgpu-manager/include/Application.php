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
        if (!in_array($action, ['cache-select', 'cache-delete', 'config-save', 'source-test', 'source-versions', 'source-download', 'source-import', 'source-upload-begin', 'source-upload-chunk', 'source-upload-finish', 'source-upload-cancel', 'upgrade-check', 'device-create', 'device-save', 'device-start', 'device-stop', 'device-remove', 'ensure', 'boot', 'plan'], true)) {
            throw new \InvalidArgumentException('Unknown operation');
        }
        if ($action === 'plan') {
            return ['message' => 'Driver installation runs at boot. System upgrades can prepare the target-kernel package in advance.', 'pending_restart' => $this->store->settings()['pending_restart']];
        }
        return $this->store->locked(function () use ($action, $input): array {
            $native = new NativeBoot($this->store, $this->download);
            $native->migrate();
            if ($action === 'boot') $native->sync($this->store->settings(), (new Upgrade($this->store, $this->download, $this->runner))->status());
            $result = $this->mutate($action, $input);
            if (in_array($action, ['config-save', 'cache-select'], true) ||
                ($action === 'upgrade-check' && !array_key_exists('boot_ready', $result))) {
                $native->sync($this->store->settings(), (new Upgrade($this->store, $this->download, $this->runner))->status());
            }
            return $result;
        });
    }

    private function status(): array
    {
        $settings = $this->store->settings();
        $credentials = $this->store->credentials();
        $devices = $this->store->devices();
        $override = $this->store->paths->configDir . '/profile_override.toml';
        $runtime = $this->runtime->status($settings, $devices);
        return [
            'settings' => $settings, 'devices' => $devices,
            'credential_configured' => ($credentials['token'] ?? '') !== '' && ($credentials['source_id'] ?? '') === Store::sourceIdentity($settings['source']),
            'credential_type' => $credentials['type'] ?? 'none',
            'credential_username' => $credentials['username'] ?? '',
            'pending_restart' => $settings['pending_restart'],
            'override_content' => is_file($override) ? (string)file_get_contents($override) : '',
            'runtime' => $runtime,
            'device_inventory' => (new Inventory($this->store->paths))->read($devices, $runtime['runtime'] ?? []),
            'upgrade' => (new Upgrade($this->store, $this->download, $this->runner))->status(),
            'driver_installation' => (new Installer($this->store, $this->download, $this->runtime, $this->runner))->state(),
            'package_cache' => (new PackageCache($this->store))->listing(),
            'warning' => 'Driver caches contain privileged installation scripts. Only use repositories you trust. Flash-drive backups may contain download credentials.',
        ];
    }

    private function mutate(string $action, array $input): array
    {
        $settings = $this->store->settings();
        if ($action === 'cache-select') {
            if (array_keys($input) !== ['filename'] || !is_string($input['filename'])) throw new \InvalidArgumentException('选择请求只接受完整的驱动包文件名。');
            $native = new NativeBoot($this->store, $this->download);
            $package = $native->cachedReceipt($input['filename']);
            $this->assertPackagePolicy($package, $settings['source']);
            if (($package['source'] ?? '') !== 'local') PackageHistory::assertPolicy(PackageHistory::fromPackage($package), $settings, $package['kernel']);
            $this->download->verifyCached($package, $package['kernel']);
            $settings['selected_package'] = $this->packageReceipt($package, $settings['source']);
            $this->store->saveSettings($settings);
            return ['ok' => true, 'package' => $settings['selected_package'], 'message' => $settings['driver']['enabled']
                ? '已选择下次启动构建；由 Unraid 在下次开机安装。当前驱动和服务未改变。'
                : '已保存构建选择；开机恢复关闭，尚未加入 Unraid 启动安装目录。当前驱动和服务未改变。'];
        }
        if ($action === 'cache-delete') {
            if (array_keys($input) !== ['filename'] || !is_string($input['filename'])) throw new \InvalidArgumentException('删除请求只接受完整的驱动包文件名。');
            $cache = new PackageCache($this->store);
            return $cache->delete($input['filename']) + ['package_cache' => $cache->listing()];
        }
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
            if ($action === 'source-download') {
                $selection = [];
                if (array_key_exists('filename', $input)) {
                    if (!is_string($input['filename'])) throw new \InvalidArgumentException('Invalid driver package filename');
                    if (array_key_exists('driver', $input) || array_key_exists('build', $input)) throw new \InvalidArgumentException('Choose a package filename or a driver/build pair');
                    $identity = PackageIdentity::parse($input['filename'], $this->kernel());
                    $selection = ['driver' => $identity['driver'], 'build' => (string)$identity['build']];
                } else {
                    if (array_key_exists('driver', $input)) {
                        if (!is_string($input['driver'])) throw new \InvalidArgumentException('Driver version must be X.Y.Z');
                        $selection = ['driver' => $input['driver']];
                    }
                    if (array_key_exists('build', $input)) $selection['build'] = (string)PackageIdentity::build($input['build']);
                }
                $effectiveSource = Store::validateSource(array_replace($source, $selection));
                $this->assertPackagePolicy($effectiveSource, $source);
                $source = $effectiveSource;
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
                (new NativeBoot($this->store, $this->download))->remember($result['package']);
                $result['package_cache'] = (new PackageCache($this->store))->listing();
                $result['message'] = 'Driver package verified and cached only. Select it explicitly for the next Unraid boot. No running driver was changed.';
            }
            return $result;
        }
        if ($action === 'ensure' || $action === 'boot') {
            $installation = null;
            if ($action === 'boot' && ($settings['driver']['enabled'] ?? false) === true) {
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
        if ($action === 'device-create' || $action === 'device-save') {
            if ($action === 'device-create') {
                // Creation never doubles as an edit or an idempotent legacy save.
                $uuid = strtolower(trim((string)($input['uuid'] ?? '')));
                foreach ($devices as $existing) {
                    if ($existing['uuid'] === $uuid) {
                        return ['ok' => false, 'code' => 'device_exists',
                            'message' => '该 UUID 已存在，请查看已有设备详情。', 'changed' => false];
                    }
                }
            }
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
        $previousDriver = $settings['driver'];
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
        if (array_key_exists('driver', $input)) {
            if (!is_array($input['driver']) || !is_bool($input['driver']['enabled'] ?? null)) {
                throw new \InvalidArgumentException('Driver enabled must be a boolean');
            }
            $settings['driver']['enabled'] = $input['driver']['enabled'];
            if ($input['driver']['enabled'] && !$previousDriver['enabled']) {
                $bootId = $this->bootId();
                if ($bootId === '') throw new \RuntimeException('Cannot identify this boot before enabling driver restoration');
                $settings['driver']['enabled_boot_id'] = $bootId;
            }
        }
        if (array_key_exists('upgrade', $input)) {
            if (!is_array($input['upgrade']) || !is_bool($input['upgrade']['enabled'] ?? null)) {
                throw new \InvalidArgumentException('Upgrade enabled must be a boolean');
            }
            $settings['upgrade']['enabled'] = $input['upgrade']['enabled'];
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
        if ($settings['driver']['enabled'] === true || $settings['upgrade']['enabled'] === true) {
            $driver = $settings['source']['driver'];
            $newDriver = $settings['driver']['enabled'] && !$previousDriver['enabled'];
            $newPrefetch = $settings['upgrade']['enabled'] && !$previousUpgrade['enabled'];
            $selected = $this->selectedPackageForPolicy($settings, $newDriver);
            if ($driver === '' && ($settings['upgrade']['driver_branch'] === '' || $newDriver || $newPrefetch)) {
                $loaded = $this->store->paths->path('/sys/module/nvidia/version');
                $driver = $selected['driver'] ?? (is_file($loaded) ? trim((string)file_get_contents($loaded)) : '');
                $settings['upgrade']['driver_branch'] = '';
            }
            if (preg_match('/^([0-9]+)\.[0-9]+\.[0-9]+$/D', $driver, $match)) $settings['upgrade']['driver_branch'] = $match[1];
            // Explicitly enabling installation authorizes the previous cache
            // selection, but boot still revalidates every byte and module.
            if ($newDriver && $selected !== null) {
                $settings['selected_package'] = $this->packageReceipt($selected, $settings['source']);
            }
        }
        if ($settings['runtime'] !== $oldRuntime) $settings['pending_restart'] = true;
        $this->store->saveCredentials($credentials);
        if ($override !== null) $this->saveOverride($override);
        $this->store->saveSettings($settings);
        $result = ['message' => 'Configuration saved. No running GPU service was changed.', 'pending_restart' => $settings['pending_restart'], 'credential_cleared' => $changedIdentity];
        if ($settings['upgrade']['enabled'] !== $previousUpgrade['enabled']) $result['upgrade_helper'] = $this->syncUpgradeHelper($settings['upgrade']['enabled']);
        return $result;
    }

    private function selectedPackageForPolicy(array $settings, bool $authorizing): ?array
    {
        if (!is_array($settings['selected_package'])) return null;
        try {
            $selected = PackageIdentity::receipt($settings['selected_package']);
        } catch (\InvalidArgumentException $error) {
            if ($authorizing) throw $error;
            return null;
        }
        try { $this->assertPackagePolicy($selected, $settings['source']); }
        catch (\InvalidArgumentException) { return null; }
        if ($selected['kernel'] !== $this->kernel() . '-Unraid') return null;
        if (($selected['source'] ?? '') === 'local') return $selected;
        if ($settings['source']['repository'] === '' || ($selected['source'] ?? '') !== $settings['source']['repository']) return null;
        foreach (['source_id' => Store::sourceIdentity($settings['source']), 'source_fingerprint' => Upgrade::sourceFingerprint($settings['source'])] as $field => $value) {
            // Old selections lack provenance; explicit driver enablement can
            // authorize those, but must not revive a known-obsolete receipt.
            if (array_key_exists($field, $selected) && $selected[$field] !== $value) return null;
        }
        return $selected;
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
        if ($action === 'source-upload-begin') {
            $filename = $upload->filename($input['filename'] ?? null, $kernel);
            $this->assertPackagePolicy(PackageIdentity::parse($filename, $kernel), $settings['source']);
            return $upload->begin($input, $kernel);
        }
        if ($action === 'source-upload-chunk') return $upload->chunk($input, $kernel);
        $id = null;
        if ($action === 'source-upload-finish') {
            $id = $input['upload_id'] ?? null;
            $candidate = $upload->completed($id, $kernel);
        } else {
            $filename = $upload->filename($input['filename'] ?? null, $kernel);
            $this->assertPackagePolicy(PackageIdentity::parse($filename, $kernel), $settings['source']);
            if (!is_string($input['checksum_path'] ?? null) || $input['checksum_path'] === '') throw new \InvalidArgumentException('本地导入必须同时提供 MD5 文件。');
            $candidate = [
                'filename' => $filename,
                'path' => $upload->uploadedFile($input['path'] ?? null, Upload::MAX_PACKAGE),
                'checksum_path' => $upload->uploadedFile($input['checksum_path'], 4096),
            ];
        }
        try {
            $this->assertPackagePolicy(PackageIdentity::parse($candidate['filename'], $kernel), $settings['source']);
            $result = $this->download->importLocal($candidate['path'], $candidate['filename'], $candidate['checksum_path'], $kernel);
            if (($result['ok'] ?? false) && isset($result['package'])) {
                $result['package']['source'] = 'local';
                $result['package']['provider'] = 'local';
                $result['package'] = $this->packageReceipt($result['package'], $settings['source']);
                (new NativeBoot($this->store, $this->download))->remember($result['package']);
                $result['message'] = 'Local driver package imported; select it explicitly for the next Unraid boot';
            }
            return $result;
        } finally {
            if ($id !== null) $upload->cancel($id);
        }
    }

    private function assertPackagePolicy(array $candidate, array $source): void
    {
        if ((($source['driver'] ?? '') !== '' && ($candidate['driver'] ?? '') !== $source['driver']) ||
            (($source['build'] ?? '') !== '' && (string)($candidate['build'] ?? '') !== $source['build'])) {
            throw new \InvalidArgumentException('所选包与固定驱动版本/构建号不一致，请先修改高级设置。');
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
        $package = PackageIdentity::receipt($package);
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
