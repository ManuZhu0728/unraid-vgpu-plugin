<?php
declare(strict_types=1);

namespace Vgpu;

/**
 * Conservative runtime controller. Application/Store owns the transaction lock.
 * No driver installation, daemon replacement, SIGKILL or module unloading lives
 * here. An existing foreign runtime must be migrated during maintenance first.
 */
final class Runtime
{
    private $runner;

    public function __construct(private Paths $paths, ?callable $runner = null)
    {
        $this->runner = $runner ?? new Process();
    }

    public function status(array $settings, array $devices): array
    {
        $state = $this->snapshot();
        $state['enabled'] = ($settings['runtime']['enabled'] ?? false) === true;
        $state['devices'] = array_map(function (array $device) use ($state): array {
            return $device + ['active' => isset($state['active_mdevs'][$device['uuid'] ?? ''])];
        }, $devices);
        $state['selected_package'] = $settings['selected_package'] ?? null;
        $state['owner_record_present'] = $this->owner() !== null;
        return $this->result(true, 'status', 'Read-only runtime status', ['runtime' => $state]);
    }

    public function ensure(array $settings, array $devices): array
    {
        try {
            if (($settings['runtime']['enabled'] ?? false) !== true) {
                return $this->result(true, 'runtime_disabled', 'Runtime management is disabled; no running service was changed');
            }
            $runtime = $settings['runtime'];
            if (($runtime['xml_override'] ?? false) !== false) {
                return $this->result(false, 'unsupported_xml_override', 'Keep vgpuConfig.xml paired with the installed driver; XML overrides are not supported');
            }
            $state = $this->snapshot();
            if ($state['legacy_owners']) {
                return $this->result(false, 'migration_required', 'A legacy plugin or scheduled initializer still owns vGPU startup', ['owners' => $state['legacy_owners']]);
            }
            if (!$state['checks']['processes_known'] || !$state['checks']['mdevs_known']) {
                return $this->result(false, 'state_unknown', 'Cannot safely inspect existing processes and mdev devices');
            }
            $library = $this->library($runtime);
            $installed = $this->command(['modinfo', '-F', 'version', 'nvidia']);
            $vermagic = $this->command(['modinfo', '-F', 'vermagic', 'nvidia']);
            $version = trim($installed['stdout']);
            if ($installed['code'] !== 0 || !preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $version)) {
                return $this->result(false, 'driver_missing', 'No installed NVIDIA driver module was identified; cached packages are not installed automatically');
            }
            if ($vermagic['code'] !== 0 || explode(' ', trim($vermagic['stdout']))[0] !== $state['kernel']) {
                return $this->result(false, 'kernel_mismatch', 'Installed module vermagic does not match the running kernel');
            }
            if ($state['loaded_driver'] !== '' && $state['loaded_driver'] !== $version) {
                return $this->result(false, 'maintenance_required', 'Loaded and installed module versions differ; no hot driver reload will be attempted');
            }
            $fingerprint = $this->fingerprint($runtime, $library);
            $existing = $state['manager_pids'] || $state['vgpud_pids'] || $state['active_mdevs'];
            if (!$existing && $state['loaded_driver'] !== '' && $state['profiles_present']) {
                return $this->result(false, 'migration_required', 'The loaded RM already contains vGPU profiles without a managed daemon; cached profile state requires maintenance, not a new initializer');
            }
            if ($existing) {
                $ownership = $this->ownedRuntime($state, $library);
                if (!$ownership['ok']) return $ownership;
                if (($this->owner()['configuration'] ?? '') !== $fingerprint) {
                    return $this->result(false, 'maintenance_required', 'Pending configuration differs from the active runtime snapshot; no live configuration was overwritten');
                }
                if (!$this->configurationMatches($runtime)) {
                    return $this->result(false, 'maintenance_required', 'The active runtime configuration was changed outside this plugin; it was not overwritten');
                }
                // Do not modprobe, rewrite /etc, restart a daemon or redefine an
                // active device simply because ensure was invoked again.
                return $this->apply($devices, $settings, false);
            }
            $holders = $this->openHandles([], null);
            if (!$holders['known']) return $this->result(false, 'usage_unknown', 'Cannot inspect host GPU users before cold initialization');
            if ($holders['holders']) return $this->result(false, 'gpu_busy', 'Host GPU handles are open; cold initialization was not attempted', ['holders' => $holders['holders']]);
            $xml = $this->paths->path('/usr/share/nvidia/vgpu/vgpuConfig.xml');
            if (!is_file($xml) || filesize($xml) === 0) {
                return $this->result(false, 'driver_xml_missing', 'The installed driver has no nonempty vgpuConfig.xml');
            }
            $this->configure($runtime);
            foreach ([['nvidia-modprobe'], ['modprobe', 'mdev'], ['modprobe', 'nvidia_vgpu_vfio']] as $argv) {
                $response = $this->command($argv);
                if ($response['code'] !== 0) return $this->result(false, 'module_start_failed', 'A required module did not load; no daemon was started', ['command' => $argv, 'exit_code' => $response['code']]);
            }
            $loaded = $this->read('/sys/module/nvidia/version');
            if ($loaded !== $version) return $this->result(false, 'module_version_unknown', 'The loaded NVIDIA version could not be verified after module loading');
            // Recheck immediately before deleting only demonstrably stale PID
            // files. A second, non-cooperating launcher is never killed.
            $latest = $this->snapshot();
            if (!$latest['checks']['processes_known'] || $latest['manager_pids'] || $latest['vgpud_pids'] || $latest['active_mdevs']) {
                return $this->result(false, 'migration_required', 'Another initializer appeared during cold initialization; runtime files were retained');
            }
            $this->cleanStalePidFiles();
            foreach (['nvidia-vgpud', 'nvidia-vgpu-mgr'] as $daemon) {
                $response = $this->command(['env', 'LD_PRELOAD=' . $library['path'], $daemon], 15);
                if ($response['code'] !== 0) return $this->result(false, 'daemon_start_failed', $daemon . ' did not finish starting; no automatic kill or cleanup was attempted', ['exit_code' => $response['code']]);
            }
            $manager = null;
            for ($attempt = 0; $attempt <= 15; $attempt++) {
                $latest = $this->snapshot();
                if ($latest['checks']['processes_known'] && count($latest['manager_pids']) === 1) {
                    $pid = $latest['manager_pids'][0];
                    if ($this->mapsLibrary($pid, $library['path']) && $this->startTime($pid) !== null) {
                        $manager = $pid;
                        break;
                    }
                }
                if ($attempt < 15) $this->pause();
            }
            if ($manager === null) return $this->result(false, 'manager_not_ready', 'A unique manager with the configured UnlockRS mapping did not become ready; no process was killed');
            $this->atomic($this->paths->runtimeDir . '/owner.json', json_encode([
                'manager_pid' => $manager, 'manager_start' => $this->startTime($manager),
                'library' => $library, 'configuration' => $fingerprint,
                'xml_sha256' => hash_file('sha256', $xml),
            ], JSON_THROW_ON_ERROR) . "\n");
            return $this->apply($devices, $settings, true);
        } catch (\Throwable $error) {
            return $this->result(false, 'runtime_error', $error->getMessage());
        }
    }

    public function startDevice(array $device, array $settings): array
    {
        if (($settings['runtime']['enabled'] ?? false) !== true) return $this->result(false, 'runtime_disabled', 'Enable runtime management only after completing migration');
        $ready = $this->ensure($settings, []);
        if (!$ready['ok']) return $ready;
        try {
            return $this->startOne($device);
        } catch (\Throwable $error) {
            return $this->result(false, 'device_error', $error->getMessage());
        }
    }

    public function stopDevice(array $device): array
    {
        try {
            $this->identity($device);
            $active = $this->activeDevices();
            if ($active === null) return $this->result(false, 'state_unknown', 'Cannot inspect active mdev devices');
            if (!isset($active[$device['uuid']])) return $this->result(true, 'already_stopped', 'The target mdev is not active');
            if (!$this->sameParent($active[$device['uuid']], $device)) return $this->result(false, 'identity_conflict', 'Active UUID does not belong to the configured PCI device');
            $safe = $this->destructivePreflight($device);
            if (!$safe['ok']) return $safe;
            return $this->stopOne($device);
        } catch (\Throwable $error) {
            return $this->result(false, 'device_error', $error->getMessage());
        }
    }

    public function removeDevice(array $device): array
    {
        try {
            $this->identity($device);
            $safe = $this->destructivePreflight($device);
            if (!$safe['ok']) return $safe;
            $stopped = $this->stopDevice($device);
            if (!$stopped['ok']) return $stopped;
            $definition = $this->definition($device);
            if ($definition === null) return $this->result(false, 'definition_unknown', 'Cannot inspect the target mdev definition');
            if ($definition === false) return $this->result(true, 'removed', 'No active device or definition remains', [], $stopped['changed']);
            $expectedType = $this->profile($device);
            if ($expectedType === null || $definition['pci'] !== $device['pci'] || $definition['type'] !== $expectedType) {
                return $this->result(false, 'definition_conflict', 'The existing definition does not match the configured PCI/profile; it was not undefined');
            }
            // VM/FD ownership can have changed since the first check.
            $safe = $this->destructivePreflight($device);
            if (!$safe['ok']) return $safe;
            $response = $this->command(['mdevctl', 'undefine', '-u', $device['uuid'], '-p', $device['pci']]);
            if ($response['code'] !== 0 || $this->definition($device) !== false) {
                return $this->result(false, 'undefine_failed', 'The definition could not be removed; persistent device configuration must be retained');
            }
            return $this->result(true, 'removed', 'Target mdev stopped and undefined', [], true);
        } catch (\Throwable $error) {
            return $this->result(false, 'device_error', $error->getMessage());
        }
    }

    private function apply(array $devices, array $settings, bool $changed): array
    {
        $results = [];
        foreach ($devices as $device) {
            $result = $this->startOne($device);
            $results[] = $result;
            $changed = $changed || $result['changed'];
            if (!$result['ok']) return $this->result(false, $result['code'], $result['message'], ['devices' => $results], $changed);
        }
        return $this->result(true, 'ready', 'Managed runtime is ready; existing healthy resources were retained', ['devices' => $results], $changed);
    }

    private function startOne(array $device): array
    {
        $this->identity($device);
        $type = null;
        for ($attempt = 0; $attempt <= 15; $attempt++) {
            $type = $this->profile($device);
            if ($type !== null) break;
            if ($attempt < 15) $this->pause();
        }
        if ($type === null) return $this->result(false, 'profile_not_ready', 'The requested profile is not ready on the configured PCI device');
        $device['type'] = $type;
        $active = $this->activeDevices();
        if ($active === null) return $this->result(false, 'state_unknown', 'Cannot inspect active mdev devices');
        if (isset($active[$device['uuid']])) {
            if (!$this->sameParent($active[$device['uuid']], $device) || $active[$device['uuid']]['type'] !== $type) {
                return $this->result(false, 'identity_conflict', 'Existing UUID has a different PCI/profile; it was not redefined');
            }
            return $this->result(true, 'already_active', 'Configured mdev is already active', ['device' => $device]);
        }
        $available = $this->read('/sys/bus/pci/devices/' . $device['pci'] . '/mdev_supported_types/' . $type . '/available_instances');
        if (!preg_match('/^[0-9]+$/D', $available) || (int)$available < 1) {
            return $this->result(false, 'profile_capacity_unknown', 'The driver does not report an available instance for this profile; no definition was changed');
        }
        $definition = $this->definition($device);
        if ($definition === null) return $this->result(false, 'definition_unknown', 'Cannot inspect the target definition');
        if (is_array($definition) && ($definition['pci'] !== $device['pci'] || $definition['type'] !== $type)) {
            return $this->result(false, 'definition_conflict', 'A different persistent definition already uses this UUID; it was retained');
        }
        if ($definition === false) {
            $defined = $this->command(['mdevctl', 'define', '-u', $device['uuid'], '-p', $device['pci'], '--type', $type]);
            if ($defined['code'] !== 0) return $this->result(false, 'define_failed', 'mdev definition failed; start was not attempted');
            $verified = $this->definition($device);
            if (!is_array($verified) || $verified['pci'] !== $device['pci'] || $verified['type'] !== $type) {
                return $this->result(false, 'define_unverified', 'The new mdev definition could not be verified; start was not attempted');
            }
        }
        $started = $this->command(['mdevctl', 'start', '-u', $device['uuid'], '-p', $device['pci']]);
        $active = $this->activeDevices();
        if ($started['code'] !== 0 || !isset($active[$device['uuid']]) || !$this->sameParent($active[$device['uuid']], $device) || $active[$device['uuid']]['type'] !== $type) {
            return $this->result(false, 'start_failed', 'The configured mdev did not become active; its definition was retained');
        }
        return $this->result(true, 'started', 'Configured mdev started', ['device' => $device], true);
    }

    private function stopOne(array $device): array
    {
        $response = $this->command(['mdevctl', 'stop', '-u', $device['uuid'], '-p', $device['pci']]);
        if ($response['code'] !== 0) return $this->result(false, 'stop_failed', 'Target stop failed or timed out; no undefine, PID cleanup or forced kill was attempted', ['exit_code' => $response['code']]);
        $active = $this->activeDevices();
        if ($active === null || isset($active[$device['uuid']])) return $this->result(false, 'stop_unverified', 'Target stop could not be verified; its persistent definition must be retained');
        return $this->result(true, 'stopped', 'The unused target mdev stopped', [], true);
    }

    private function destructivePreflight(array $device): array
    {
        $state = $this->snapshot();
        if ($state['legacy_owners']) return $this->result(false, 'migration_required', 'Legacy startup ownership must be resolved before changing runtime devices', ['owners' => $state['legacy_owners']]);
        if (!$state['checks']['processes_known'] || !$state['checks']['mdevs_known']) return $this->result(false, 'usage_unknown', 'Cannot inspect all relevant processes and mdevs');
        if (isset($state['active_mdevs'][$device['uuid']]) && !$this->sameParent($state['active_mdevs'][$device['uuid']], $device)) {
            return $this->result(false, 'identity_conflict', 'Active UUID belongs to another PCI device');
        }
        $owned = [];
        if ($state['manager_pids'] || $state['vgpud_pids'] || $state['active_mdevs']) {
            $owner = $this->owner();
            if ($owner === null || !is_array($owner['library'] ?? null)) return $this->result(false, 'migration_required', 'Existing runtime is not owned by this plugin');
            $ownership = $this->ownedRuntime($state, $owner['library']);
            if (!$ownership['ok']) return $ownership;
            $owned = $state['manager_pids'];
        }
        $domains = $this->command(['virsh', '-c', 'qemu:///system', 'list', '--name']);
        if ($domains['code'] !== 0) return $this->result(false, 'usage_unknown', 'Cannot inspect active libvirt domains; target was not stopped');
        foreach (preg_split('/\R/', trim($domains['stdout'])) as $domain) {
            if ($domain === '') continue;
            $xml = $this->command(['virsh', '-c', 'qemu:///system', 'dumpxml', $domain]);
            if ($xml['code'] !== 0 || !preg_match('/<domain\b[^>]*>.*<\/domain>\s*$/s', trim($xml['stdout'])) || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml['stdout'])) {
                return $this->result(false, 'usage_unknown', 'Cannot safely inspect a live domain XML; target was not stopped');
            }
            // Conservatively match the exact UUID anywhere in valid-looking
            // live domain XML. False positives refuse an operation, never stop
            // a device. No XML extension or external entity expansion is used.
            if (stripos($xml['stdout'], $device['uuid']) !== false) return $this->result(false, 'vm_busy', 'An active libvirt domain references the target UUID');
        }
        $group = $state['active_mdevs'][$device['uuid']]['group'] ?? null;
        if (isset($state['active_mdevs'][$device['uuid']]) && ($group === null || !preg_match('/^[0-9]+$/D', $group))) {
            return $this->result(false, 'usage_unknown', 'The active target has no verifiable VFIO group; it was not stopped');
        }
        if (isset($state['active_mdevs'][$device['uuid']])) {
            $expectedType = $this->profile($device);
            if ($expectedType === null) return $this->result(false, 'usage_unknown', 'Cannot resolve the active target profile identity');
            if ($state['active_mdevs'][$device['uuid']]['type'] !== $expectedType) return $this->result(false, 'identity_conflict', 'The active target profile differs from the configured identity');
        }
        $holders = $this->openHandles($owned, $group, $device['pci']);
        if (!$holders['known']) return $this->result(false, 'usage_unknown', 'Cannot inspect VFIO/host GPU file descriptors; target was not stopped');
        if ($holders['holders']) return $this->result(false, 'gpu_busy', 'A VFIO or host GPU process still has relevant handles open', ['holders' => $holders['holders']]);
        return $this->result(true, 'unused', 'No active VM or conflicting process was found');
    }

    private function snapshot(): array
    {
        $processes = $this->processes();
        $active = $this->activeDevices();
        return [
            'kernel' => $this->read('/proc/sys/kernel/osrelease'),
            'loaded_driver' => $this->read('/sys/module/nvidia/version'),
            'host_cuda_parameter' => $this->read('/sys/module/nvidia/parameters/cudahost'),
            'manager_pids' => $processes['nvidia-vgpu-mgr'],
            'vgpud_pids' => $processes['nvidia-vgpud'],
            'active_mdevs' => $active ?? [], 'legacy_owners' => $this->legacyOwners(),
            'profiles_present' => (bool)glob($this->paths->path('/sys/bus/pci/devices/*/mdev_supported_types/nvidia-*'), GLOB_ONLYDIR),
            'checks' => ['processes_known' => $processes['known'], 'mdevs_known' => $active !== null],
        ];
    }

    private function processes(): array
    {
        $result = ['known' => is_readable($this->paths->path('/proc')), 'nvidia-vgpu-mgr' => [], 'nvidia-vgpud' => []];
        foreach (glob($this->paths->path('/proc/[0-9]*'), GLOB_ONLYDIR) ?: [] as $directory) {
            $file = $directory . '/comm';
            $comm = @file_get_contents($file);
            if ($comm === false) {
                if (is_dir($directory)) $result['known'] = false;
                continue;
            }
            $comm = trim($comm);
            if (isset($result[$comm]) && is_array($result[$comm])) $result[$comm][] = (int)basename($directory);
        }
        return $result;
    }

    private function activeDevices(): ?array
    {
        $directory = $this->paths->path('/sys/bus/mdev/devices');
        if (!is_dir($directory)) return []; // An unloaded mdev subsystem is empty.
        if (!is_readable($directory)) return null;
        $result = [];
        foreach (glob($directory . '/*') ?: [] as $entry) {
            $real = realpath($entry);
            if ($real === false) return null;
            $uuid = basename($entry);
            $type = @readlink($entry . '/mdev_type');
            $group = @readlink($entry . '/iommu_group');
            $result[$uuid] = ['pci' => basename(dirname($real)), 'type' => $type === false ? '' : basename($type), 'group' => $group === false ? null : basename($group)];
        }
        return $result;
    }

    private function legacyOwners(): array
    {
        $owners = [];
        foreach (['novidio-vgpu-driver.plg', 'nvidia-vgpu-driver.plg'] as $plugin) {
            if (is_file($this->paths->path('/boot/config/plugins/' . $plugin))) $owners[] = 'plugin:' . $plugin;
        }
        $schedule = $this->paths->path('/boot/config/plugins/user.scripts/schedule.json');
        if (is_file($schedule)) {
            $jobs = json_decode((string)@file_get_contents($schedule), true);
            if (!is_array($jobs)) return array_merge($owners, ['unknown:user-scripts-schedule']);
            foreach ($jobs as $key => $job) {
                if (is_string($job)) $job = ['frequency' => $job];
                if (!is_array($job) || in_array(strtolower((string)($job['frequency'] ?? 'disabled')), ['', 'disabled', 'off', 'none'], true)) continue;
                $script = (string)($job['script'] ?? $key);
                $looksGpuRelated = preg_match('/vgpu|nvidia/i', $script) === 1;
                $base = '/boot/config/plugins/user.scripts/scripts/';
                if (preg_match('#^[A-Za-z0-9_ .-]+(?:/script)?$#D', $script) && !str_contains($script, '..')) {
                    $script = $base . preg_replace('#/script$#', '', $script) . '/script';
                }
                if (!str_starts_with($script, '/boot/config/plugins/user.scripts/scripts/') || str_contains($script, '..')) {
                    if ($looksGpuRelated) $owners[] = 'unknown:scheduled-vgpu-initializer';
                    continue;
                }
                $contents = @file_get_contents($this->paths->path($script));
                if ($contents === false) {
                    if ($looksGpuRelated) $owners[] = 'unknown:scheduled-vgpu-script';
                } elseif (preg_match('/nvidia-vgpu-mgr|nvidia-vgpud|mdevctl\s+(?:define|start)/', $contents)) {
                    $owners[] = 'user-script:' . basename(dirname($script));
                }
            }
        }
        return array_values(array_unique($owners));
    }

    private function ownedRuntime(array $state, array $library): array
    {
        $owner = $this->owner();
        $base = (int)($owner['manager_pid'] ?? 0);
        if (!$owner || !in_array($base, $state['manager_pids'], true) || $this->startTime($base) !== ($owner['manager_start'] ?? null)) {
            return $this->result(false, 'migration_required', 'An existing manager/mdev runtime has no matching ownership record; no takeover was attempted');
        }
        if (($owner['library']['path'] ?? '') !== ($library['path'] ?? '') || ($owner['library']['sha256'] ?? '') !== ($library['sha256'] ?? '') || !is_file($library['path'] ?? '') || hash_file('sha256', $library['path']) !== ($library['sha256'] ?? '')) {
            return $this->result(false, 'migration_required', 'Installed UnlockRS identity differs from the managed runtime');
        }
        $xml = $this->paths->path('/usr/share/nvidia/vgpu/vgpuConfig.xml');
        if (!is_file($xml) || hash_file('sha256', $xml) !== ($owner['xml_sha256'] ?? '')) return $this->result(false, 'maintenance_required', 'Installed vGPU XML differs from the active managed runtime; no replacement was attempted');
        foreach ($state['manager_pids'] as $pid) {
            if (!$this->mapsLibrary($pid, $library['path']) || !$this->descendsFrom($pid, $base)) return $this->result(false, 'migration_required', 'A manager has an unknown library mapping or process ancestry');
            $stat = @file_get_contents($this->paths->path('/proc/' . $pid . '/stat'));
            if ($stat === false || !preg_match('/^\d+ \(.*\) ([RSI])\s/s', $stat)) return $this->result(false, 'manager_not_ready', 'A manager is stopped, exiting, uninterruptible or in an unknown state; no cleanup was attempted');
        }
        if ($state['vgpud_pids']) return $this->result(false, 'migration_required', 'A vgpud initialization is still running; no competing initializer was started');
        return $this->result(true, 'owned', 'Existing manager ownership and library mapping match');
    }

    private function library(array $runtime): array
    {
        $configured = $runtime['unlock_library'] ?? '/usr/lib64/libvgpu_unlock_rs.so';
        if (!in_array($configured, ['/usr/lib64/libvgpu_unlock_rs.so', '/usr/lib/libvgpu_unlock_rs.so'], true)) throw new \RuntimeException('Only the installed package UnlockRS paths are supported');
        $real = realpath($this->paths->path($configured));
        if ($real === false || !is_file($real) || !is_readable($real) || filesize($real) === 0) throw new \RuntimeException('Configured UnlockRS is missing, empty or unreadable');
        return ['path' => $real, 'sha256' => hash_file('sha256', $real)];
    }

    private function owner(): ?array
    {
        $file = $this->paths->runtimeDir . '/owner.json';
        if (!is_file($file) || is_link($file)) return null;
        $record = json_decode((string)@file_get_contents($file), true);
        return is_array($record) ? $record : null;
    }

    private function fingerprint(array $runtime, array $library): string
    {
        $file = $this->paths->configDir . '/profile_override.toml';
        $override = is_file($file) ? file_get_contents($file) : '';
        if ($override === false || strlen($override) > 65536 || str_contains($override, "\0")) throw new \RuntimeException('Pending profile override is unreadable or invalid');
        return hash('sha256', json_encode(['unlock' => ($runtime['unlock'] ?? true) === true, 'library' => $library, 'override' => $override], JSON_THROW_ON_ERROR));
    }

    private function configure(array $runtime): void
    {
        $this->atomic($this->paths->path('/etc/vgpu_unlock/config.toml'), 'unlock = ' . ((($runtime['unlock'] ?? true) === true) ? 'true' : 'false') . "\n");
        $file = $this->paths->configDir . '/profile_override.toml';
        $this->atomic($this->paths->path('/etc/vgpu_unlock/profile_override.toml'), is_file($file) ? (string)file_get_contents($file) : '');
    }

    private function configurationMatches(array $runtime): bool
    {
        $config = $this->paths->path('/etc/vgpu_unlock/config.toml');
        $override = $this->paths->path('/etc/vgpu_unlock/profile_override.toml');
        $pending = $this->paths->configDir . '/profile_override.toml';
        return !is_link($config) && !is_link($override)
            && @file_get_contents($config) === 'unlock = ' . ((($runtime['unlock'] ?? true) === true) ? 'true' : 'false') . "\n"
            && @file_get_contents($override) === (is_file($pending) ? @file_get_contents($pending) : '');
    }

    private function atomic(string $file, string $contents): void
    {
        $directory = dirname($file);
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))) throw new \RuntimeException('Cannot create a safe runtime configuration directory');
        $temporary = tempnam($directory, '.vgpu-');
        if ($temporary === false) throw new \RuntimeException('Cannot prepare runtime configuration');
        try {
            @chmod($temporary, 0600);
            if (file_put_contents($temporary, $contents) !== strlen($contents) || !rename($temporary, $file)) throw new \RuntimeException('Cannot publish runtime configuration');
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }

    private function cleanStalePidFiles(): void
    {
        foreach (['nvidia-vgpud', 'nvidia-vgpu-mgr'] as $daemon) {
            $directory = $this->paths->path('/var/run/' . $daemon);
            if (is_link($directory)) throw new \RuntimeException('A daemon runtime directory is a symlink; PID cleanup refused');
            foreach (glob($directory . '/*.pid') ?: [] as $file) {
                if (!is_file($file) || is_link($file)) throw new \RuntimeException('Unsafe PID entry; cleanup refused');
                $value = trim((string)file_get_contents($file));
                if (!preg_match('/^[1-9][0-9]*$/D', $value) || is_dir($this->paths->path('/proc/' . $value))) throw new \RuntimeException('PID file may identify a live or unknown process; cleanup refused');
                if (!unlink($file)) throw new \RuntimeException('Cannot remove a verified stale PID file');
            }
        }
    }

    private function openHandles(array $ignoredPids, ?string $group, ?string $pci = null): array
    {
        $known = is_readable($this->paths->path('/proc'));
        $holders = [];
        $drmNodes = [];
        $pciDirectories = $pci === null ? (glob($this->paths->path('/sys/bus/pci/drivers/nvidia/????:??:??.?'), GLOB_ONLYDIR) ?: []) : [$this->paths->path('/sys/bus/pci/devices/' . $pci)];
        foreach ($pciDirectories as $pciDirectory) {
            foreach (glob($pciDirectory . '/drm/*') ?: [] as $node) {
                if (preg_match('/^(?:card|renderD)[0-9]+$/D', basename($node))) $drmNodes[] = '/dev/dri/' . basename($node);
            }
        }
        foreach (glob($this->paths->path('/proc/[0-9]*'), GLOB_ONLYDIR) ?: [] as $directory) {
            $pid = (int)basename($directory);
            if (in_array($pid, $ignoredPids, true)) continue;
            if (!is_readable($directory . '/fd')) {
                if (is_dir($directory)) $known = false;
                continue;
            }
            $handles = [];
            foreach (glob($directory . '/fd/*') ?: [] as $fd) {
                $target = @readlink($fd);
                if ($target === false) {
                    if (file_exists($fd) || is_link($fd)) $known = false;
                    continue;
                }
                $gpu = preg_match('#^/dev/(?:nvidia(?:[0-9]+|ctl|-uvm(?:-tools)?|-modeset)?|nvidia-caps/nvidia-cap[0-9]+)(?: \(deleted\))?$#D', $target) || in_array($target, $drmNodes, true);
                // VFIO cdev numbering is NOT the IOMMU group number. Until a
                // cdev-to-target mapping is proven, refuse conservatively.
                $vfio = $group !== null && ($target === '/dev/vfio/' . $group || preg_match('#^/dev/vfio/devices/vfio[0-9]+$#D', $target));
                if ($gpu || $vfio) $handles[] = $target;
            }
            if ($handles) {
                $cgroup = (string)@file_get_contents($directory . '/cgroup');
                preg_match('/(?:docker[\/-]|docker-)([a-f0-9]{12,64})/', $cgroup, $container);
                $holders[] = ['pid' => $pid, 'name' => trim((string)@file_get_contents($directory . '/comm')), 'container' => isset($container[1]) ? substr($container[1], 0, 12) : null, 'handles' => array_values(array_unique($handles))];
            }
        }
        return ['known' => $known, 'holders' => $holders];
    }

    private function profile(array $device): ?string
    {
        $base = $this->paths->path('/sys/bus/pci/devices/' . $device['pci']);
        if (!is_dir($this->paths->path('/sys/bus/pci/drivers/nvidia/' . $device['pci']))) return null;
        $matches = [];
        foreach (glob($base . '/mdev_supported_types/nvidia-*', GLOB_ONLYDIR) ?: [] as $directory) {
            $name = trim((string)@file_get_contents($directory . '/name'));
            if (($device['profile'] ?? '') !== '' ? $name === $device['profile'] : basename($directory) === ($device['type'] ?? '')) $matches[] = basename($directory);
        }
        if (count($matches) > 1) throw new \RuntimeException('Profile name is ambiguous on the configured PCI device');
        if (!$matches) return null;
        // The numeric type is only a cache. NVIDIA may renumber profiles after
        // an upgrade; the name selects the desired profile. Actual active and
        // defined device identities are checked separately before mutation.
        return $matches[0];
    }

    /** null = unknown, false = absent, array = a verified unique definition. */
    private function definition(array $device): array|false|null
    {
        $response = $this->command(['mdevctl', 'list', '--defined', '-u', $device['uuid']]);
        if ($response['code'] !== 0) return null;
        $lines = array_values(array_filter(preg_split('/\R/', trim($response['stdout'])), fn($line) => trim($line) !== ''));
        if (!$lines) return false;
        if (count($lines) !== 1 || !preg_match('/^([0-9a-f-]{36})\s+([0-9a-f:.]+)\s+(nvidia-[0-9]+)\s/i', $lines[0], $match) || strtolower($match[1]) !== $device['uuid']) return null;
        return ['pci' => strtolower($match[2]), 'type' => $match[3]];
    }

    private function identity(array $device): void
    {
        if (!preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $device['uuid'] ?? '') || !preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/D', $device['pci'] ?? '')) throw new \RuntimeException('Invalid target UUID or PCI address');
    }

    private function sameParent(array $active, array $device): bool
    {
        return $active['pci'] === $device['pci'];
    }

    private function mapsLibrary(int $pid, string $path): bool
    {
        $maps = @file_get_contents($this->paths->path('/proc/' . $pid . '/maps'));
        if ($maps === false) return false;
        foreach (preg_split('/\R/', $maps) as $line) {
            $parts = preg_split('/\s+/', trim($line), 6);
            if (count($parts) === 6 && $parts[5] === $path) return true;
        }
        return false;
    }

    private function startTime(int $pid): ?string
    {
        $stat = @file_get_contents($this->paths->path('/proc/' . $pid . '/stat'));
        if ($stat === false || !preg_match('/^\d+ \(.*\) (.+)$/s', trim($stat), $match)) return null;
        $parts = preg_split('/\s+/', $match[1]);
        return isset($parts[19]) && preg_match('/^[0-9]+$/D', $parts[19]) ? $parts[19] : null;
    }

    private function descendsFrom(int $pid, int $base): bool
    {
        for ($depth = 0; $depth < 32 && $pid > 1; $depth++) {
            if ($pid === $base) return true;
            $status = @file_get_contents($this->paths->path('/proc/' . $pid . '/status'));
            if ($status === false || !preg_match('/^PPid:\s+([0-9]+)$/m', $status, $match)) return false;
            $pid = (int)$match[1];
        }
        return false;
    }

    private function read(string $path): string
    {
        return trim((string)@file_get_contents($this->paths->path($path)));
    }

    private function command(array $argv, int $timeout = 15): array
    {
        $result = ($this->runner)($argv, $timeout, null);
        if (!is_array($result) || !isset($result['code']) || !is_int($result['code'])) throw new \RuntimeException('Command completion is unknown');
        return ['code' => $result['code'], 'stdout' => (string)($result['stdout'] ?? ''), 'stderr' => (string)($result['stderr'] ?? '')];
    }

    private function pause(): void
    {
        // Isolated filesystem tests never sleep; production polls are bounded.
        if ($this->paths->root === '') sleep(1);
    }

    private function result(bool $ok, string $code, string $message, array $extra = [], bool $changed = false): array
    {
        return ['ok' => $ok, 'code' => $code, 'message' => $message, 'changed' => $changed] + $extra;
    }
}
