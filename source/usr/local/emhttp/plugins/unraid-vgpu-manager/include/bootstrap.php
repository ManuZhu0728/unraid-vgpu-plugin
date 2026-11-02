<?php
declare(strict_types=1);

require_once __DIR__ . '/Paths.php';
require_once __DIR__ . '/Process.php';
require_once __DIR__ . '/Source.php';
require_once __DIR__ . '/Store.php';
foreach (['Download.php', 'Runtime.php', 'Upload.php', 'Upgrade.php', 'Installer.php', 'Logs.php', 'Inventory.php'] as $component) {
    if (is_file(__DIR__ . '/' . $component)) require_once __DIR__ . '/' . $component;
}
