<?php
declare(strict_types=1);

namespace Vgpu;

/** Restore a reviewed cached driver on a subsequent boot. Application owns the lock. */
final class Installer
{
    private $runner;

    public function __construct(private Store $store, private Download $download, private Runtime $runtime, ?callable $runner = null)
    {
        $this->runner = $runner ?? new Process();
    }

    public function boot(array $settings, ?array $prepared = null): array
    {
        if (($settings['upgrade']['enabled'] ?? false) !== true) return $this->result(true, 'disabled', 'Driver auto-update is off');
        $kernel = $this->read('/proc/sys/kernel/osrelease');
        $boot = $this->read('/proc/sys/kernel/random/boot_id');
        $previous = [];
        $candidate = null;
        $stateKnown = false;
        try {
            if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+-Unraid$/D', $kernel) || !self::bootId($boot)) {
                throw new \RuntimeException('Current kernel or boot identity is unavailable');
            }
            $previous = $this->state();
            $stateKnown = true;
            $candidate = $this->candidate($settings, $prepared, $previous, $kernel);
            if ($candidate === null) return $this->record(false, 'missing_package', 'No prepared driver package matches this kernel', $boot, $kernel, null, $previous);
            $this->identity($candidate, $settings, $kernel);
            if (($previous['boot_id'] ?? '') === $boot && in_array($previous['status'] ?? '', ['installing', 'failed'], true)) {
                return $this->result(false, 'already_attempted', 'Driver installation already failed or was interrupted during this boot', ['package' => $candidate['package']]);
            }
            $preflight = $this->runtime->installationPreflight();
            $state = $preflight['runtime'] ?? [];
            if (($preflight['code'] ?? '') === 'driver_loaded') {
                if (($state['loaded_driver'] ?? '') === $candidate['package']['driver'] && $this->installedMatches($candidate['package'], $kernel)) {
                    if (($previous['boot_id'] ?? '') === $boot && ($previous['status'] ?? '') === 'installed') {
                        return $this->result(true, 'already_active', 'The matching NVIDIA driver is already active', ['package' => $candidate['package']]);
                    }
                    return $this->record(true, 'already_active', 'The matching NVIDIA driver is already active', $boot, $kernel, $candidate, $previous, false);
                }
                return $this->record(false, 'blocked', 'A different NVIDIA driver is active; restart is required', $boot, $kernel, $candidate, $previous);
            }
            if (!($preflight['ok'] ?? false)) {
                return $this->record(false, 'blocked', (string)($preflight['message'] ?? 'GPU stack is in use'), $boot, $kernel, $candidate, $previous);
            }
            if (($previous['boot_id'] ?? '') === $boot && ($previous['status'] ?? '') === 'installed') {
                $same = ($previous['last_success']['package']['md5'] ?? '') === $candidate['package']['md5'];
                if ($same && $this->installedMatches($candidate['package'], $kernel)) {
                    $this->activateNative($settings, $candidate['package']);
                    return $this->result(true, 'already_installed', 'The matching driver is installed for this boot', ['package' => $candidate['package']]);
                }
                return $this->result(false, 'pending_reboot', 'The selected driver will be installed after restart', ['package' => $candidate['package']]);
            }
            if (!self::bootId($candidate['saved_boot_id'] ?? '') || $candidate['saved_boot_id'] === $boot ||
                !self::bootId($settings['upgrade']['enabled_boot_id'] ?? '') || $settings['upgrade']['enabled_boot_id'] === $boot) {
                return $this->result(false, 'pending_reboot', 'The prepared driver will be installed after restart', ['package' => $candidate['package']]);
            }
            $verified = $this->download->verifyCached($candidate['package'], $kernel);
            $stat = @lstat($verified['path']);
            if (!$stat || $stat['uid'] !== $this->uid() || $stat['nlink'] !== 1) throw new \RuntimeException('Cached package has an unexpected owner or hard link');
            $candidate['package'] = $verified;
            $again = $this->runtime->installationPreflight();
            if (!($again['ok'] ?? false)) {
                return $this->record(false, 'blocked', (string)($again['message'] ?? 'GPU usage changed during verification'), $boot, $kernel, $candidate, $previous);
            }
            if (hash_file('md5', $verified['path']) !== $verified['md5']) throw new \RuntimeException('Cached package changed after verification');
            $this->record(false, 'installing', 'Installing the prepared driver', $boot, $kernel, $candidate, $previous, false);
            $installed = $this->command(['upgradepkg', '--install-new', '--reinstall', $verified['path']], 300);
            if ($installed['code'] !== 0) throw new \RuntimeException('Driver package installation failed');
            if ($this->command(['depmod', '-a', $kernel], 60)['code'] !== 0) throw new \RuntimeException('Driver module dependency update failed');
            if (!$this->installedMatches($verified, $kernel)) throw new \RuntimeException('Installed module version or kernel does not match the prepared package');
            $this->activateNative($settings, $verified);
            return $this->record(true, 'installed', 'Driver installed for ' . $kernel, $boot, $kernel, $candidate, $previous, true, true);
        } catch (\Throwable $error) {
            if (!$stateKnown || !self::bootId($boot) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+-Unraid$/D', $kernel)) {
                return $this->result(false, 'failed', $error->getMessage());
            }
            try {
                return $this->record(false, 'failed', $error->getMessage(), $boot, $kernel, $candidate, $previous);
            } catch (\Throwable) {
                return $this->result(false, 'failed', 'Driver installation state could not be saved');
            }
        }
    }

    public function state(): array
    {
        $path = $this->store->paths->configDir . '/driver-state.json';
        if (!file_exists($path) && !is_link($path)) return [];
        if (is_link($path) || !is_file($path) || filesize($path) > 65536) throw new \RuntimeException('Driver installation state is invalid');
        $state = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($state)) throw new \RuntimeException('Driver installation state is invalid');
        return $state;
    }

    private function candidate(array $settings, ?array $prepared, array $state, string $kernel): ?array
    {
        $candidates = [];
        $selected = $settings['selected_package'] ?? null;
        if (is_array($selected) && ($selected['kernel'] ?? '') === $kernel) {
            $candidates[] = ['package' => $selected, 'saved_boot_id' => $selected['saved_boot_id'] ?? '',
                'source_id' => $selected['source_id'] ?? '', 'source_fingerprint' => $selected['source_fingerprint'] ?? '',
                'local' => ($selected['source'] ?? '') === 'local'];
        }
        if (($prepared['status'] ?? '') === 'ready' && ($prepared['target_kernel'] ?? '') === $kernel && is_array($prepared['package'] ?? null)) {
            $candidates[] = ['package' => $prepared['package'], 'saved_boot_id' => $prepared['prepared_boot_id'] ?? '',
                'source_id' => $prepared['source_id'] ?? '', 'source_fingerprint' => $prepared['source_fingerprint'] ?? '', 'local' => false];
        }
        $last = $state['last_success'] ?? null;
        if (is_array($last) && ($last['package']['kernel'] ?? '') === $kernel) $candidates[] = $last;
        foreach ($candidates as $candidate) {
            try {
                // An explicit current-kernel selection supersedes an older
                // preparation; receipts from an obsolete policy are skipped.
                $this->identity($candidate, $settings, $kernel);
                return $candidate;
            } catch (\Throwable) { /* Keep looking for a current-policy receipt. */ }
        }
        // boot() repeats identity validation so the primary receipt's error is
        // retained when none are usable. Cache verification never falls back.
        return $candidates[0] ?? null;
    }

    private function identity(array $candidate, array $settings, string $kernel): void
    {
        $package = $candidate['package'] ?? [];
        $driver = $package['driver'] ?? '';
        if (!is_string($driver) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $driver) ||
            ($package['kernel'] ?? '') !== $kernel || !preg_match('/^[a-f0-9]{32}$/D', (string)($package['md5'] ?? ''))) {
            throw new \RuntimeException('Prepared driver metadata is invalid');
        }
        if (($package['filename'] ?? '') !== 'nvidia-' . $driver . '-' . $kernel . '-1.txz') throw new \RuntimeException('Prepared driver filename does not match its metadata');
        $branch = (string)($settings['upgrade']['driver_branch'] ?? '');
        if ($branch !== '' && (!preg_match('/^[0-9]+$/D', $branch) || explode('.', $driver)[0] !== $branch)) throw new \RuntimeException('Prepared driver belongs to another NVIDIA branch');
        if (($settings['source']['driver'] ?? '') !== '' && $driver !== $settings['source']['driver']) throw new \RuntimeException('Prepared driver differs from the selected version');
        if (!($candidate['local'] ?? false)) {
            if (($candidate['source_id'] ?? '') !== Store::sourceIdentity($settings['source']) ||
                ($candidate['source_fingerprint'] ?? '') !== Upgrade::sourceFingerprint($settings['source'])) {
                throw new \RuntimeException('Driver source changed; prepare the package again');
            }
        }
    }

    private function installedMatches(array $package, string $kernel): bool
    {
        $version = $this->command(['modinfo', '-F', 'version', 'nvidia']);
        $magic = $this->command(['modinfo', '-F', 'vermagic', 'nvidia']);
        return $version['code'] === 0 && trim($version['stdout']) === $package['driver'] && $magic['code'] === 0 &&
            str_starts_with(trim($magic['stdout']), $kernel . ' ');
    }

    private function activateNative(array $settings, array $package): void
    {
        // vGPU initialization owns the first module load when enabled. Native
        // host/Docker-only operation still needs its device nodes and UVM.
        if (($settings['runtime']['enabled'] ?? false) === true) return;
        foreach ([['nvidia-modprobe'], ['nvidia-modprobe', '-u']] as $argv) {
            if ($this->command($argv, 30)['code'] !== 0) throw new \RuntimeException('NVIDIA device initialization failed');
        }
        if ($this->read('/sys/module/nvidia/version') !== $package['driver']) throw new \RuntimeException('Loaded NVIDIA driver differs from the installed package');
    }

    private function record(bool $ok, string $status, string $message, string $boot, string $kernel, ?array $candidate, array $previous, bool $notify = true, bool $changed = false): array
    {
        $state = ['status' => $status, 'boot_id' => $boot, 'kernel' => $kernel, 'message' => $message, 'updated_at' => time()];
        if (is_array($previous['last_success'] ?? null)) $state['last_success'] = $previous['last_success'];
        if ($candidate !== null) $state['attempted_package'] = $candidate['package'];
        if ($status === 'installed' && $candidate !== null) $state['last_success'] = $candidate;
        $key = hash('sha256', $boot . '\0' . $status . '\0' . $kernel . '\0' . $message);
        $state['notification_key'] = $notify ? $key : ($previous['notification_key'] ?? '');
        $this->store->write($this->store->paths->configDir . '/driver-state.json', $state);
        if ($notify && ($previous['notification_key'] ?? '') !== $key) {
            try {
                $this->command([$this->store->paths->path('/usr/local/emhttp/plugins/dynamix/scripts/notify'), '-e', 'NVIDIA vGPU Manager', '-d', $message,
                    '-i', $ok ? 'normal' : 'alert', '-l', '/Settings/unraid-vgpu-manager']);
            } catch (\Throwable) { /* Installation state remains available in the UI. */ }
        }
        return $this->result($ok, $status, $message, $candidate === null ? [] : ['package' => $candidate['package']], $changed);
    }

    private function command(array $argv, int $timeout = 30): array
    {
        $result = ($this->runner)($argv, $timeout, null);
        return ['code' => (int)($result['code'] ?? -1), 'stdout' => (string)($result['stdout'] ?? '')];
    }

    private function read(string $path): string
    {
        $value = @file_get_contents($this->store->paths->path($path));
        return $value === false ? '' : trim($value);
    }

    private static function bootId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $value) === 1;
    }

    private function uid(): int
    {
        if (function_exists('posix_geteuid')) return posix_geteuid();
        if (preg_match('/^Uid:\s+\d+\s+(\d+)/m', (string)file_get_contents('/proc/self/status'), $match)) return (int)$match[1];
        throw new \RuntimeException('Cannot determine cached package owner');
    }

    private function result(bool $ok, string $status, string $message, array $extra = [], bool $changed = false): array
    {
        return ['ok' => $ok, 'code' => $status, 'status' => $status, 'message' => $message, 'changed' => $changed] + $extra;
    }
}
