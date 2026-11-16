<?php
declare(strict_types=1);

namespace Vgpu;

/** Unraid installs the staged TXZ. Only verify its native result here. */
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
        $boot = $this->read('/proc/sys/kernel/random/boot_id');
        $kernel = $this->read('/proc/sys/kernel/osrelease');
        $candidate = null;
        try {
            $previous = $this->state();
            if (($previous['boot_id'] ?? '') === $boot && !isset($previous['installer']) && in_array($previous['status'] ?? '', ['installing', 'failed'], true)) {
                return $this->result(false, 'already_attempted', 'A legacy plugin installation failed or its completion is unknown in this boot; no runtime was changed');
            }
            if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $boot) ||
                !preg_match('/^[0-9]+\\.[0-9]+\\.[0-9]+-Unraid$/D', $kernel)) throw new \RuntimeException('Current boot/kernel identity is unavailable');
            $native = new NativeBoot($this->store, $this->download);
            $selection = $native->selection($kernel);
            if ($selection === null) return $this->record(false, 'missing_package', 'No selected package is staged for Unraid to install for this kernel', $boot, $kernel);
            $candidate = PackageHistory::authorization($selection['authorization'] ?? []);
            PackageHistory::assertPolicy($candidate, $settings, $kernel);
            $stagedBoot = $selection['staged_boot_id'] ?? '';
            if (!is_string($stagedBoot) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $stagedBoot)) throw new \RuntimeException('Native selection has no valid preparation boot');
            if ($stagedBoot === $boot) return $this->record(false, 'pending_reboot', 'Selected package staged for the next Unraid boot; no driver was installed or reloaded by this plugin', $boot, $kernel, $candidate);
            $package = $native->verifyPublished($selection);
            $inspection = $this->download->inspectCached($package, $kernel);
            // Version-only modinfo cannot distinguish builds or identify the archive.
            PkgtoolsMigration::verify($this->store->paths->root, ['expected' => basename($package['filename'], '.txz')]);
            $record = $this->store->paths->path('/var/lib/pkgtools/packages/' . basename($package['filename'], '.txz'));
            $contents = (string)file_get_contents($record);
            if (!preg_match('/^PACKAGE LOCATION:\\s*(.+)$/m', $contents, $match) ||
                !in_array(trim($match[1]), [$package['boot_path'], substr($package['boot_path'], strlen($this->store->paths->root))], true)) {
                throw new \RuntimeException('Native package record does not identify this plugin selected boot package');
            }
            $this->verifyModules($inspection['plan']['modules'], $package, $kernel);
            $loaded = $this->read('/sys/module/nvidia/version');
            if ($loaded !== '' && $loaded !== $package['driver']) throw new \RuntimeException('Loaded NVIDIA driver differs from the selected native package; no hot reload was attempted');
            foreach ($inspection['plan']['modules'] as $member => $_) {
                $module = str_replace('-', '_', basename($member, '.ko'));
                if (!is_dir($this->store->paths->path('/sys/module/' . $module))) continue;
                $source = $this->read('/sys/module/' . $module . '/srcversion');
                $expected = trim($this->command(['modinfo', '-F', 'srcversion', $this->store->paths->path('/' . $member)]));
                if ($source === '' || $expected === '' || $source !== $expected) throw new \RuntimeException('Loaded module identity differs from the selected native package: ' . $module);
            }
            return $this->record(true, 'installed', 'Unraid installed the selected package; native record and module fingerprints verified', $boot, $kernel, $candidate);
        } catch (\Throwable $error) {
            return $this->record(false, 'failed', 'Native driver verification failed: ' . $error->getMessage(), $boot, $kernel, $candidate);
        }
    }

    public function state(): array
    {
        $file = $this->store->paths->configDir . '/driver-state.json';
        if (!file_exists($file) && !is_link($file)) return [];
        if (is_link($file) || !is_file($file) || filesize($file) > 65536) throw new \RuntimeException('Driver verification state is invalid');
        $data = json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Driver verification state is invalid');
        return $data;
    }

    private function verifyModules(array $modules, array $package, string $kernel): void
    {
        foreach ($modules as $member => $expected) {
            $file = $this->store->paths->path('/' . $member);
            for ($parent = dirname($file); $parent !== $this->store->paths->root && $parent !== '/'; $parent = dirname($parent)) {
                if (is_link($parent)) throw new \RuntimeException('Installed module parent is a symbolic link');
            }
            clearstatcache(true, $file);
            $stat = @lstat($file);
            if (!$stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] !== $expected['size'] || hash_file('sha256', $file) !== $expected['sha256']) {
                throw new \RuntimeException('Installed module is missing or differs from the selected package: ' . basename($member));
            }
            if (trim($this->command(['modinfo', '-F', 'version', $file])) !== $package['driver'] ||
                !str_starts_with(trim($this->command(['modinfo', '-F', 'vermagic', $file])), $kernel . ' ')) throw new \RuntimeException('Installed module version/kernel differs from the selection');
        }
    }

    private function record(bool $ok, string $status, string $message, string $boot, string $kernel, ?array $candidate = null): array
    {
        $previous = $this->state();
        $state = ['status' => $status, 'boot_id' => $boot, 'kernel' => $kernel, 'message' => $message, 'installer' => 'Unraid rc.modules', 'updated_at' => time()];
        if (isset($previous['last_success'])) $state['last_success'] = $previous['last_success'];
        if ($candidate !== null) $state['attempted_package'] = $candidate['package'];
        if ($ok && $candidate !== null) {
            $state['last_success'] = array_replace($candidate, ['installed_boot_id' => $boot]);
            (new PackageHistory($this->store))->remember($state['last_success']);
        }
        $this->store->write($this->store->paths->configDir . '/driver-state.json', $state);
        return $this->result($ok, $status, $message, $candidate === null ? [] : ['package' => $candidate['package']]);
    }

    private function command(array $argv): string
    {
        $result = ($this->runner)($argv, 30, null);
        if (($result['code'] ?? -1) !== 0) throw new \RuntimeException('Cannot verify installed module metadata');
        return (string)($result['stdout'] ?? '');
    }

    private function read(string $relative): string
    {
        return trim((string)@file_get_contents($this->store->paths->path($relative)));
    }

    private function result(bool $ok, string $status, string $message, array $extra = []): array
    {
        return ['ok' => $ok, 'code' => $status, 'status' => $status, 'message' => $message, 'changed' => false] + $extra;
    }
}
