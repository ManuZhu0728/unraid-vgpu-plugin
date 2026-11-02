<?php
declare(strict_types=1);

namespace Vgpu;

final class Store
{
    public function __construct(public Paths $paths) {}

    public static function defaults(): array
    {
        return [
            'schema_version' => 1,
            'source' => [
                'provider' => 'auto', 'mode' => 'auto', 'repository' => '', 'url' => '',
                'project_id' => '', 'driver' => '', 'tag_template' => 'v{kernel}-{driver}',
                'package_name' => 'build', 'package_version_template' => 'v{kernel}-{driver}',
            ],
            'runtime' => [
                'enabled' => false, 'unlock' => true,
                'unlock_library' => '/usr/lib64/libvgpu_unlock_rs.so', 'xml_override' => false,
            ],
            'upgrade' => ['enabled' => false, 'driver_branch' => '', 'enabled_boot_id' => ''],
            'selected_package' => null,
            'pending_restart' => false,
        ];
    }

    public function settings(): array
    {
        return array_replace_recursive(self::defaults(), $this->read($this->paths->settingsFile, []));
    }

    public function devices(): array
    {
        $devices = $this->read($this->paths->devicesFile, []);
        if (!array_is_list($devices)) {
            throw new \RuntimeException('Device configuration is not a list');
        }
        return array_map([self::class, 'validateDevice'], $devices);
    }

    public function credentials(): array
    {
        return $this->read($this->paths->credentialsFile, ['type' => 'none', 'token' => '', 'username' => '', 'source_id' => '']);
    }

    public static function sourceIdentity(array $source): string
    {
        $source = self::validateSource($source);
        return hash('sha256', json_encode([
            'provider' => $source['provider'] ?? '',
            'repository' => $source['repository'] ?? '',
            'project_id' => $source['project_id'] ?? '',
        ], JSON_THROW_ON_ERROR));
    }

    public static function validateSource(array $source): array
    {
        $source = array_replace(self::defaults()['source'], array_intersect_key($source, self::defaults()['source']));
        foreach ($source as $key => $value) {
            if (!is_string($value) || strlen($value) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new \InvalidArgumentException('Invalid source field: ' . $key);
            }
            $source[$key] = trim($value);
        }
        if (!in_array($source['provider'], ['auto', 'github', 'gitlab', 'direct'], true) || !in_array($source['mode'], ['auto', 'releases', 'generic'], true)) {
            throw new \InvalidArgumentException('Unsupported repository provider or download mode');
        }
        if ($source['provider'] === 'github' && $source['mode'] === 'generic') {
            throw new \InvalidArgumentException('GitHub only supports Releases in this version');
        }
        if ($source['url'] !== '' || $source['repository'] !== '') {
            $parsed = Source::parse($source['url'] !== '' ? $source['url'] : $source['repository'], $source['provider']);
            $source['url'] = $parsed['url'];
            $source['repository'] = $parsed['repository'];
            $source['provider'] = $parsed['provider'];
        }
        if ($source['provider'] === 'github' && $source['mode'] === 'generic') throw new \InvalidArgumentException('GitHub does not use Generic Packages');
        if ($source['driver'] !== '' && !preg_match('/^\d+\.\d+\.\d+$/D', $source['driver'])) {
            throw new \InvalidArgumentException('Driver version must be X.Y.Z');
        }
        if (($source['project_id'] !== '' && !preg_match('/^[1-9][0-9]*$/D', $source['project_id'])) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $source['package_name'])) {
            throw new \InvalidArgumentException('Invalid project ID or package name');
        }
        foreach (['tag_template', 'package_version_template'] as $field) {
            $literal = str_replace(['{kernel}', '{driver}'], '', $source[$field]);
            if (!preg_match('/^[A-Za-z0-9_.{}-]+$/D', $source[$field]) || preg_match('/[{}]/', $literal)) {
                throw new \InvalidArgumentException('Only {kernel} and {driver} placeholders are supported');
            }
        }
        return $source;
    }

    public static function validateDevice(array $device): array
    {
        $uuid = strtolower(trim((string)($device['uuid'] ?? '')));
        $pci = strtolower(trim((string)($device['pci'] ?? '')));
        $profile = trim((string)($device['profile'] ?? ''));
        $type = trim((string)($device['type'] ?? ''));
        if (!preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $uuid) || !preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/D', $pci)) {
            throw new \InvalidArgumentException('Invalid mdev UUID or PCI address');
        }
        if ($profile === '' || strlen($profile) > 128 || preg_match('/[\x00-\x1f\x7f]/', $profile) || ($type !== '' && !preg_match('/^nvidia-[0-9]+$/D', $type))) {
            throw new \InvalidArgumentException('A valid profile name is required');
        }
        return ['uuid' => $uuid, 'pci' => $pci, 'profile' => $profile, 'type' => $type];
    }

    public function saveSettings(array $settings): void
    {
        $this->write($this->paths->settingsFile, $settings);
    }

    public function saveDevices(array $devices): void
    {
        $this->write($this->paths->devicesFile, array_values($devices));
    }

    public function saveCredentials(array $credentials): void
    {
        $type = (string)($credentials['type'] ?? 'none');
        $token = (string)($credentials['token'] ?? '');
        $username = (string)($credentials['username'] ?? '');
        $sourceId = (string)($credentials['source_id'] ?? '');
        if (!in_array($type, ['none', 'auto', 'bearer', 'private-token', 'deploy-token'], true) || strlen($token) > 4096 || preg_match('/[\x00-\x20\x7f]/', $token) || strlen($username) > 256 || preg_match('/[\x00-\x1f\x7f]/', $username)) {
            throw new \InvalidArgumentException('Invalid credential');
        }
        if ($sourceId !== '' && !preg_match('/^[a-f0-9]{64}$/D', $sourceId)) throw new \InvalidArgumentException('Invalid credential source binding');
        $this->write($this->paths->credentialsFile, ['type' => $type, 'token' => $token, 'username' => $username, 'source_id' => $sourceId]);
    }

    public function locked(callable $operation): mixed
    {
        $directory = dirname($this->paths->lockFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create lock directory');
        }
        // e sets FD_CLOEXEC: background daemons must not inherit the lock.
        $handle = fopen($this->paths->lockFile, 'ce');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) fclose($handle);
            throw new \RuntimeException('Another plugin operation is in progress');
        }
        @chmod($this->paths->lockFile, 0600);
        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function read(string $file, array $default): array
    {
        if (!is_file($file)) return $default;
        $data = json_decode((string)file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Invalid configuration file');
        return $data;
    }

    public function write(string $file, array $data): void
    {
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create configuration directory');
        }
        $temporary = tempnam($directory, '.pending-');
        if ($temporary === false) throw new \RuntimeException('Unable to create temporary configuration');
        try {
            @chmod($temporary, 0600);
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $file)) {
                throw new \RuntimeException('Unable to atomically save configuration');
            }
            @chmod($file, 0600);
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }
}
