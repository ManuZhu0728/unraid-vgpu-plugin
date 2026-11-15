<?php
declare(strict_types=1);

namespace Vgpu;

/** Plugin-owned post-install policy; package doinst scripts are never replayed. */
final class InstallContract
{
    public const REQUIRED_MODULES = ['nvidia', 'nvidia-modeset', 'nvidia-drm', 'nvidia-uvm', 'nvidia-vgpu-vfio'];
    public const OPTIONAL_MODULES = ['nvidia-peermem'];
    public const TEMPLATES = [
        'etc/nvidia/gridd.conf.new', 'etc/vgpu_unlock/config.toml.new',
        'etc/vgpu_unlock/profile_override.toml.new', 'etc/nvidia-container-runtime/config.toml.new',
        'etc/modprobe.d/nvidia.conf.new',
    ];
    public const MAX_TEMPLATE = 1048576;
}
