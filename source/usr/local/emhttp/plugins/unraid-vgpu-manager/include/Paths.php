<?php
declare(strict_types=1);

namespace Vgpu;

final class Paths
{
    public string $root;
    public string $configDir;
    public string $runtimeDir;
    public string $cacheDir;
    public string $settingsFile;
    public string $credentialsFile;
    public string $devicesFile;
    public string $lockFile;

    public function __construct(string $root = '/')
    {
        if ($root === '' || $root[0] !== '/') {
            throw new \InvalidArgumentException('A filesystem root must be absolute');
        }
        $this->root = rtrim($root, '/');
        $this->configDir = $this->path('/boot/config/plugins/unraid-vgpu-manager');
        $this->runtimeDir = $this->path('/var/run/unraid-vgpu-manager');
        $this->cacheDir = $this->configDir . '/packages';
        $this->settingsFile = $this->configDir . '/settings.json';
        $this->credentialsFile = $this->configDir . '/credentials.json';
        $this->devicesFile = $this->configDir . '/devices.json';
        $this->lockFile = $this->path('/var/run/unraid-vgpu-manager.lock');
    }

    public function path(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, '..')) {
            throw new \InvalidArgumentException('Invalid absolute path');
        }
        return $this->root . $path;
    }
}
