<?php
declare(strict_types=1);

/**
 * Model the native generateContent boundary for our PHP-only entry wrappers.
 *
 * Unraid defaults to Markdown unless the page declares Markdown="false".
 * MarkdownExtra protects complete processing instructions (<\?.*?\?>), then
 * escapes an unprotected opening tag as text. The full Markdown typography is
 * irrelevant to these wrappers; only that PHP preservation boundary is modeled.
 * Sources: Unraid 7.3.4 webgui commit
 * 31c22f8327cca774dcbddd573dc76f70e87b2f10, generateContent in
 * emhttp/plugins/dynamix/include/DefaultPageLayout/MainContent.php and
 * MarkdownExtra.php::_hashHTMLBlocks_inMarkdown under the same include tree.
 */
function ui_native_page_content(string $page): string
{
    $parts = preg_split('/^---\s*$/m', $page, 2);
    if (count($parts) !== 2) throw new RuntimeException('Native child page must separate metadata and content');
    $metadata = parse_ini_string($parts[0], false, INI_SCANNER_RAW);
    if (!is_array($metadata)) throw new RuntimeException('Invalid native page metadata');
    $body = $parts[1];
    if (!empty($metadata['Markdown']) && $metadata['Markdown'] !== 'true') return $body;
    $chunks = preg_split('/(<\?.*?\?>|<%.*?%>)/s', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($chunks as $index => &$chunk) {
        if ($index % 2 === 0) $chunk = htmlspecialchars($chunk, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    unset($chunk);
    return implode('', $chunks);
}

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
    $entrypoint = "require '/usr/local/emhttp/plugins/unraid-vgpu-manager/include/ui-page.php';";
    if (substr_count($child, $entrypoint) !== 1) throw new RuntimeException('Tab must use the fixed installed UI entrypoint');
    // Native page transformation happens before any included PHP executes.
    // Do not inline the controller into a wrapper that Markdown turned into
    // literal text, which would accidentally hide the unclosed-tag regression.
    $child = ui_native_page_content($child);
    return preg_replace_callback('/<\?php\b(?:[\s\S]*?\?>|[\s\S]*\z)/',
        static fn($match) => str_replace($entrypoint, '?>' . $controller . '<?php ', $match[0]), $child);
}
