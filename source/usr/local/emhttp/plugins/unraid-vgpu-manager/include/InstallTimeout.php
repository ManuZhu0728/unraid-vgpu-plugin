<?php
declare(strict_types=1);

namespace Vgpu;

/** A mutating child may still be running; configuration recovery must not race it. */
final class InstallTimeout extends \RuntimeException {}
