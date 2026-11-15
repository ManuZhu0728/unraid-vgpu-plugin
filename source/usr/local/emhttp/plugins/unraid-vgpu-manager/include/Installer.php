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
        if (($settings['driver']['enabled'] ?? false) !== true) return $this->result(true, 'disabled', 'Driver boot restoration is off');
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
            // A missing or changed selection must not overwrite the only
            // evidence of an interrupted installation and reopen this boot.
            if (($previous['boot_id'] ?? '') === $boot && in_array($previous['status'] ?? '', ['installing', 'failed'], true)) {
                return $this->result(false, 'already_attempted', 'Driver installation already failed or was interrupted during this boot',
                    isset($previous['attempted_package']) ? ['package' => $previous['attempted_package']] : []);
            }
            $candidate = $this->candidate($settings, $prepared, $previous, $kernel);
            if ($candidate === null) return $this->record(false, 'missing_package', 'No prepared driver package matches this kernel', $boot, $kernel, null, $previous);
            $this->identity($candidate, $settings, $kernel);
            $candidate['package'] = PackageIdentity::receipt($candidate['package']);
            $preflight = $this->runtime->installationPreflight();
            $state = $preflight['runtime'] ?? [];
            if (($preflight['code'] ?? '') === 'driver_loaded') {
                if (($state['loaded_driver'] ?? '') === $candidate['package']['driver'] && $this->installedMatches($candidate['package'], $kernel)) {
                    if ($this->installedReceiptMatches($candidate, $previous, $boot)) {
                        return $this->record(true, 'already_active', 'The selected NVIDIA driver package is already active', $boot, $kernel, $candidate, $previous, false);
                    }
                    return $this->record(false, 'pending_reboot', 'NVIDIA ' . $candidate['package']['driver'] . ' is active; the selected package build is not confirmed for this boot. Restart to install it', $boot, $kernel, $candidate, $previous);
                }
                return $this->record(false, 'blocked', 'A different NVIDIA driver is active; restart is required', $boot, $kernel, $candidate, $previous);
            }
            if (!($preflight['ok'] ?? false)) {
                return $this->record(false, 'blocked', (string)($preflight['message'] ?? 'GPU stack is in use'), $boot, $kernel, $candidate, $previous);
            }
            if ($this->installedDuringBoot($previous, $boot)) {
                $same = $this->installedReceiptMatches($candidate, $previous, $boot);
                if ($same && $this->installedMatches($candidate['package'], $kernel)) {
                    $inspection = $this->download->inspectCached($candidate['package'], $kernel);
                    $this->verifyInstalledPlan($inspection['plan'], $inspection['package'], $kernel);
                    PkgtoolsMigration::verify($this->store->paths->root, ['expected' => basename($inspection['package']['path'], '.txz')]);
                    $this->activateNative($settings, $candidate['package']);
                    return $this->result(true, 'already_installed', 'The matching driver is installed for this boot', ['package' => $candidate['package']]);
                }
                return $this->result(false, 'pending_reboot', 'The selected driver will be installed after restart', ['package' => $candidate['package']]);
            }
            if (!self::bootId($candidate['saved_boot_id'] ?? '') || $candidate['saved_boot_id'] === $boot ||
                !self::bootId($settings['driver']['enabled_boot_id'] ?? '') || $settings['driver']['enabled_boot_id'] === $boot) {
                return $this->result(false, 'pending_reboot', 'The prepared driver will be installed after restart', ['package' => $candidate['package']]);
            }
            $inspection = $this->download->inspectCached($candidate['package'], $kernel);
            $verified = $inspection['package'];
            $stat = @lstat($verified['path']);
            if (!$stat || $stat['uid'] !== $this->uid() || $stat['nlink'] !== 1) throw new \RuntimeException('Cached package has an unexpected owner or hard link');
            $candidate['package'] = $verified;
            $again = $this->runtime->installationPreflight();
            if (!($again['ok'] ?? false)) {
                return $this->record(false, 'blocked', (string)($again['message'] ?? 'GPU usage changed during verification'), $boot, $kernel, $candidate, $previous);
            }
            if (hash_file('md5', $verified['path']) !== $verified['md5']) throw new \RuntimeException('Cached package changed after verification');
            $migration = PkgtoolsMigration::select($this->store->paths->root, $verified['path']);
            $configuration = new InstallConfiguration($this->store->paths, $this->runner);
            $configuration->begin($verified);
            $completionUnknown = false;
            $configurationComplete = false;
            try {
                if (PkgtoolsMigration::select($this->store->paths->root, $verified['path']) !== $migration || hash_file('md5', $verified['path']) !== $verified['md5']) {
                    throw new \RuntimeException('Installed package ownership or cache changed during configuration backup');
                }
                $this->record(false, 'installing', 'Installing the prepared driver', $boot, $kernel, $candidate, $previous, false);
                $installed = $this->installationCommand(['upgradepkg', '--install-new', '--reinstall', $migration['argument']], 300);
                if ($installed['code'] !== 0) throw new \RuntimeException('Driver package installation failed');
                PkgtoolsMigration::verify($this->store->paths->root, $migration);
                $configuration->restore();
                $configuration->prepareTemplates($inspection['plan']['templates']);
                $configuration->registerDocker();
                if ($this->installationCommand(['depmod', '-a', $kernel], 60)['code'] !== 0) throw new \RuntimeException('Driver module dependency update failed');
                if ($this->installationCommand(['ldconfig', '-X'], 60)['code'] !== 0) throw new \RuntimeException('Driver library cache update failed');
                $this->verifyInstalledPlan($inspection['plan'], $verified, $kernel);
                // All fallible configuration checks and the journal commit
                // precede the first module load. Activation is a separate phase.
                $configuration->complete();
                $configurationComplete = true;
                $this->activateNative($settings, $verified);
            } catch (\Throwable $error) {
                if ($configurationComplete) {
                    throw new \RuntimeException('Driver package installed and verified; native activation failed: ' . $error->getMessage() .
                        '. Installed configurations retained; transaction: ' . $configuration->path(), 0, $error);
                }
                $completionUnknown = $error instanceof InstallTimeout;
                $recovery = $configuration->fail($completionUnknown);
                throw new \RuntimeException($error->getMessage() . '. ' . $recovery, 0, $error);
            }
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
            $candidates[] = PackageHistory::fromPackage($selected);
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
        // A damaged low-priority history must not interfere with an explicit
        // valid selection. Only the requested kernel's receipt is ever read.
        $historical = (new PackageHistory($this->store))->read($kernel);
        if ($historical !== null) {
            $candidates[] = $historical;
            try {
                $this->identity($historical, $settings, $kernel);
                return $historical;
            } catch (\Throwable) { /* Preserve the primary policy error below. */ }
        }
        // boot() repeats identity validation so the primary receipt's error is
        // retained when none are usable. Cache verification never falls back.
        return $candidates[0] ?? null;
    }

    private function identity(array $candidate, array $settings, string $kernel): void
    {
        PackageHistory::assertPolicy($candidate, $settings, $kernel);
    }

    private function installedMatches(array $package, string $kernel): bool
    {
        $version = $this->command(['modinfo', '-F', 'version', 'nvidia']);
        $magic = $this->command(['modinfo', '-F', 'vermagic', 'nvidia']);
        return $version['code'] === 0 && trim($version['stdout']) === $package['driver'] && $magic['code'] === 0 &&
            str_starts_with(trim($magic['stdout']), $kernel . ' ');
    }

    private function verifyInstalledPlan(array $plan, array $package, string $kernel): void
    {
        foreach ($plan['modules'] as $member => $expected) {
            $path = $this->store->paths->path('/' . $member);
            // Verify the exact archived path, not a possibly stale depmod alias.
            for ($parent = dirname($path); $parent !== $this->store->paths->root && $parent !== '/'; $parent = dirname($parent)) {
                if (is_link($parent)) throw new \RuntimeException('Installed module parent is a symbolic link');
            }
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if (!$stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] !== $expected['size'] || hash_file('sha256', $path) !== $expected['sha256']) {
                throw new \RuntimeException('Installed module is missing or differs from the prepared package: ' . basename($member));
            }
            foreach (['version' => $package['driver'], 'vermagic' => $kernel] as $field => $value) {
                $read = $this->installationCommand(['modinfo', '-F', $field, $path]);
                $actual = trim($read['stdout']);
                if ($field === 'vermagic') $actual = preg_split('/\s+/', $actual)[0];
                if ($read['code'] !== 0 || $actual !== $value) throw new \RuntimeException('Installed module version or kernel does not match the prepared package: ' . basename($member));
            }
        }
    }

    private function installedReceiptMatches(array $candidate, array $state, string $boot): bool
    {
        $last = $state['last_success'] ?? null;
        if (!$this->installedDuringBoot($state, $boot)) return false;
        try {
            $selected = PackageIdentity::receipt($candidate['package']);
            $installed = PackageIdentity::receipt($last['package'] ?? []);
        } catch (\InvalidArgumentException) { return false; }
        foreach (['filename', 'driver', 'kernel', 'build', 'md5', 'source'] as $field) {
            if (($selected[$field] ?? '') !== ($installed[$field] ?? '')) return false;
        }
        if (($candidate['local'] ?? false) !== ($last['local'] ?? false)) return false;
        return ($candidate['local'] ?? false) || ($candidate['source_id'] ?? '') === ($last['source_id'] ?? '');
    }

    private function installedDuringBoot(array $state, string $boot): bool
    {
        $last = $state['last_success'] ?? null;
        if (!is_array($last)) return false;
        $installedBoot = $last['installed_boot_id'] ?? '';
        // Legacy "installed" records prove an installation; "already_active"
        // used to be inferred from modinfo alone and cannot prove its revision.
        if ($installedBoot === '' && ($state['status'] ?? '') === 'installed') $installedBoot = $state['boot_id'] ?? '';
        return $installedBoot === $boot;
    }

    private function activateNative(array $settings, array $package): void
    {
        // vGPU initialization owns the first module load when enabled. Native
        // host/Docker-only operation still needs its device nodes and UVM.
        if (($settings['runtime']['enabled'] ?? false) === true) return;
        foreach ([['nvidia-modprobe'], ['nvidia-modprobe', '-u']] as $argv) {
            if ($this->installationCommand($argv, 30)['code'] !== 0) throw new \RuntimeException('NVIDIA device initialization failed');
        }
        if ($this->read('/sys/module/nvidia/version') !== $package['driver']) throw new \RuntimeException('Loaded NVIDIA driver differs from the installed package');
    }

    private function record(bool $ok, string $status, string $message, string $boot, string $kernel, ?array $candidate, array $previous, bool $notify = true, bool $changed = false): array
    {
        $state = ['status' => $status, 'boot_id' => $boot, 'kernel' => $kernel, 'message' => $message, 'updated_at' => time()];
        if (is_array($previous['last_success'] ?? null)) {
            $state['last_success'] = $previous['last_success'];
            if (!isset($state['last_success']['installed_boot_id']) && ($previous['status'] ?? '') === 'installed' && self::bootId($previous['boot_id'] ?? '')) {
                $state['last_success']['installed_boot_id'] = $previous['boot_id'];
            }
        }
        $history = new PackageHistory($this->store);
        $old = null;
        if (isset($state['last_success'])) {
            // Legacy records may be incomplete. Never turn missing provenance
            // or an invented boot ID into new authorization while migrating.
            try { $old = PackageHistory::authorization($state['last_success']); }
            catch (\Throwable) { /* Retain the original state without authorizing it. */ }
            if ($old !== null && PackageHistory::cached($this->store->paths, $old['package'], $old['package']['kernel'])) $history->remember($old);
        }
        if ($candidate !== null) $state['attempted_package'] = $candidate['package'];
        if ($status === 'installed' && $candidate !== null) {
            $state['last_success'] = array_replace($candidate, ['installed_boot_id' => $boot]);
            $history->remember($state['last_success']);
        }
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

    private function installationCommand(array $argv, int $timeout = 30): array
    {
        $result = $this->command($argv, $timeout);
        if ($result['code'] === 124) throw new InstallTimeout(basename($argv[0]) . ' timed out; completion is unknown');
        return $result;
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
