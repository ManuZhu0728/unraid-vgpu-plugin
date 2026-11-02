<?php
declare(strict_types=1);

/** Expand the installed tab templates without creating files under /usr/local. */
function ui_template(string $tab): string
{
    if (!in_array($tab, ['driver', 'vgpu', 'status'], true)) {
        throw new InvalidArgumentException('Invalid fixture tab');
    }
    $prefix = __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager';
    $load = static function (string $path): string {
        $contents = file_get_contents($path);
        if ($contents === false) throw new RuntimeException('Missing UI fixture component: ' . $path);
        return $contents;
    };
    $controller = $load($prefix . '/include/ui-page.php');
    $commonRequire = "require_once __DIR__ . '/ui/common.php';";
    $stateRequire = "require __DIR__ . '/ui/state.php';";
    $viewRequire = "require __DIR__ . '/ui/' . \$uvm_tab . '.php';";
    if (substr_count($controller, $commonRequire) !== 1 || substr_count($controller, $stateRequire) !== 1 || substr_count($controller, $viewRequire) !== 1) {
        throw new RuntimeException('Update the fixture assembler for the actual shared UI includes');
    }
    $common = $load($prefix . '/include/ui/common.php');
    if (!str_starts_with($common, '<?php') || str_contains($common, '?>')) throw new RuntimeException('Common UI helpers must be PHP-only');
    // Native Unraid renders every child in one PHP scope. Model require_once,
    // rather than accidentally redeclaring the shared functions per child.
    $common = "if (!isset(\$GLOBALS['ui_fixture_common_included'])) {\n\$GLOBALS['ui_fixture_common_included'] = true;\n" . substr($common, 5) . "\n}";
    $controller = str_replace($commonRequire, $common, $controller);
    $state = $load($prefix . '/include/ui/state.php');
    if (!str_starts_with($state, '<?php') || str_contains($state, '?>')) throw new RuntimeException('UI request state must be PHP-only');
    $controller = str_replace($stateRequire, "{\n" . substr($state, 5) . "\n}", $controller);
    $controller = str_replace($viewRequire, '?>' . $load($prefix . '/include/ui/' . $tab . '.php') . '<?php ', $controller);
    $child = $load($prefix . '/unraid-vgpu-manager-' . $tab . '.page');
    $parts = preg_split('/^---\s*$/m', $child, 2);
    if (count($parts) !== 2) throw new RuntimeException('Native child page must separate metadata and content');
    $child = $parts[1];
    $entrypoint = "require '/usr/local/emhttp/plugins/unraid-vgpu-manager/include/ui-page.php';";
    if (substr_count($child, $entrypoint) !== 1) throw new RuntimeException('Tab must use the fixed installed UI entrypoint');
    return str_replace($entrypoint, '?>' . $controller . '<?php ', $child);
}
