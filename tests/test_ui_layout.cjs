// Optional, hardware-free browser regression. Dependencies are deliberately not
// installed by run-tests.sh. Supply an existing Playwright module and an official
// unraid/webgui emhttp/plugins/dynamix/styles directory; no NAS or network is used.
// Verified with WebGUI 7.3.4 (31c22f8327cca774dcbddd573dc76f70e87b2f10).
// PHP_BIN=... WEBGUI_STYLES=... PLAYWRIGHT_MODULE=... \
// PLAYWRIGHT_CHROMIUM_EXECUTABLE=... node tests/test_ui_layout.cjs
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const root = path.resolve(__dirname, '..');
const styles = process.env.WEBGUI_STYLES;
assert.ok(styles, 'WEBGUI_STYLES must point to the official WebGUI styles directory');
const php = process.env.PHP_BIN || path.join(root, '.tools/php/root/usr/bin/php8.4');
const bootstrap = path.join(root, 'source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php');
const defaults = JSON.parse(execFileSync(php, ['-n', '-r', 'require $argv[1]; echo json_encode(Vgpu\\Store::defaults());', bootstrap], { encoding: 'utf8' }));
defaults.runtime.enabled = true;
const device = { uuid: 'ae9572f6-1259-4136-9aa4-497dda98d2db', pci: '0000:01:00.0', profile: 'NVIDIA RTXA5500-1Q', type: 'nvidia-762' };
const commonCss = ['default-color-palette.css', 'default-base.css', 'default-dynamix.css'].map(file => readFileSync(path.join(styles, file), 'utf8')).join('\n');
const fonts = [['clear-sans.woff', 'normal'], ['clear-sans-bold.woff', 'bold']].map(([file, weight]) =>
    `@font-face { font-family: clear-sans; font-weight: ${weight}; src: url(data:font/woff;base64,${readFileSync(path.join(styles, file)).toString('base64')}); }`
).join('\n');

function render(active) {
    const row = { ...device, framebuffer: '1024M', active, defined: true, managed: true, configuration: device, active_type: active ? device.type : '', defined_type: device.type, configured_type: device.type, definition_start: 'manual', iommu_group: '27' };
    const fixture = { single_tab: 'vgpu', status: { settings: defaults, devices: [device], credential_configured: false, runtime: { ok: true, runtime: { kernel: '6.18.58-Unraid', loaded_driver: '595.58.03' } }, override_content: '', pending_restart: false, device_inventory: { devices: [row], checks: { active_known: active !== null, defined_known: true }, messages: [] } } };
    const rendered = JSON.parse(execFileSync(php, ['-n', path.join(__dirname, 'test_ui.php'), '--render-fixture'], { input: JSON.stringify(fixture), encoding: 'utf8' }));
    assert.deepEqual(rendered.calls.map(call => call.action), ['status'], 'Rendering must only read fixture status');
    return rendered.html;
}

async function geometry(page) {
    return page.locator('#uvm-inventory-table .uvm-actions').evaluate(actions => [...actions.querySelectorAll('button')].map(button => {
        const rect = button.getBoundingClientRect();
        const item = button.closest('form') || button;
        const itemRect = item.getBoundingClientRect();
        const style = getComputedStyle(item);
        return { text: button.textContent, disabled: button.disabled, form: item.tagName === 'FORM', top: rect.top, bottom: rect.bottom, height: rect.height,
            itemTop: itemRect.top, itemLeft: itemRect.left, itemRight: itemRect.right, itemHeight: itemRect.height,
            paddingTop: parseFloat(style.paddingTop), paddingBottom: parseFloat(style.paddingBottom) };
    }));
}

function assertAligned(buttons, label, singleLine = false) {
    let line = [];
    let comparisons = 0;
    const checkLine = () => {
        // Do not use equal itemTop as a precondition: an offset entire form
        // would then be silently omitted from comparisons with its neighbors.
        for (const edge of ['top', 'bottom']) {
            const values = line.map(button => button[edge]);
            assert.ok(Math.max(...values) - Math.min(...values) <= 0.5, `${label}: same-line button ${edge} edges must align`);
        }
        comparisons += Math.max(0, line.length - 1);
    };
    for (const button of buttons) {
        // Flex wrapping restarts horizontally. Use horizontal placement, not
        // the vertical coordinate we are testing, to determine each line.
        if (line.length && button.itemLeft < line.at(-1).itemRight - 0.5) {
            assert.ok(!singleLine, `${label}: wide viewport must keep all four buttons on one line`);
            checkLine();
            line = [];
        }
        line.push(button);
    }
    checkLine();
    return comparisons;
}

(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } : {}) });
    let cases = 0;
    let comparisons = 0;
    try {
        const page = await browser.newPage();
        await page.route('**/*', route => route.abort());
        for (const theme of ['black', 'white']) for (const fontSize of ['62.5%', '100%']) for (const width of [3840, 1920, 1280, 768, 390]) for (const active of [true, false, null]) {
            await page.setViewportSize({ width, height: 1080 });
            const css = commonCss + '\n' + readFileSync(path.join(styles, 'themes', theme + '.css'), 'utf8') + '\n' + fonts;
            // The native tab script shows the selected panel. Retain the real
            // tab classes and host CSS, while showing this fixture's only tab.
            const shellCss = '#fixture-vgpu-panel { display: block; }';
            await page.setContent('<!doctype html><html style="--custom-font-size:' + fontSize + '"><head><meta charset="utf-8"><style>' + css + '\n' + shellCss + '</style></head><body>' + render(active) + '</body></html>');
            await page.evaluate(() => document.fonts.ready);
            const buttons = await geometry(page);
            const label = `${theme}, font ${fontSize}, ${width}px, active ${active}`;
            assert.deepEqual(buttons.map(button => button.text), ['编辑', '启动', '停止', '移除'], label);
            assert.deepEqual(buttons.map(button => button.disabled), [false, active !== false, active !== true, active === null], label);
            for (const button of buttons) {
                if (button.form) {
                    assert.equal(button.paddingTop, 0, `${label}: native first-form padding must not affect device actions`);
                    assert.equal(button.paddingBottom, 0, `${label}: action forms must not add vertical padding`);
                }
                assert.ok(Math.abs(button.top - button.itemTop) < 0.01, `${label}: ${button.text} must align with its flex item`);
                assert.ok(Math.abs(button.height - button.itemHeight) < 0.01, `${label}: ${button.text} must not inherit extra table line-box height`);
                assert.equal(button.height, buttons[0].height, `${label}: enabled and disabled buttons must remain equal-height`);
            }
            comparisons += assertAligned(buttons, label, width === 3840 && fontSize === '62.5%');
            ++cases;
        }

        // Negative controls must fail the same alignment assertion. Alpha 9
        // left the native first-form padding intact; this reproduces the exact
        // 5 CSS px start-button offset at the default 10px root font size.
        await page.setViewportSize({ width: 3840, height: 1080 });
        const css = commonCss + '\n' + readFileSync(path.join(styles, 'themes/black.css'), 'utf8') + '\n' + fonts;
        const html = render(true);
        const fixedRule = /\.uvm-wrap \.uvm-actions > form\s*\{[^}]*\}/;
        assert.match(html, fixedRule, 'Negative control must replace the production action-form rule');
        const alpha9Html = html.replace(fixedRule, '.uvm-actions form { display: flex; align-items: center; margin: 0; }');
        const documentHtml = content => '<!doctype html><html style="--custom-font-size:62.5%"><head><meta charset="utf-8"><style>' + css + '\n#fixture-vgpu-panel { display: block; }</style></head><body>' + content + '</body></html>';
        await page.setContent(documentHtml(alpha9Html));
        await page.evaluate(() => document.fonts.ready);
        const before = await geometry(page);
        assert.equal(before[1].paddingTop, 10, 'Native tabs must apply 1rem padding to the first action form');
        assert.ok(Math.abs(before[1].top - before[0].top - 5) <= 0.5, 'Alpha 9 must reproduce the reported 5px start-button offset');
        assert.throws(() => assertAligned(before, 'Alpha 9 negative control', true), /edges must align/);
        if (process.env.UI_LAYOUT_ARTIFACTS) await page.locator('#uvm-inventory-table').screenshot({ path: path.join(process.env.UI_LAYOUT_ARTIFACTS, 'alpha9-native-tab-before.png') });
        await page.locator('#uvm-inventory-table .uvm-actions button').nth(1).evaluate(button => { button.disabled = false; });
        const enabledBefore = await geometry(page);
        assert.equal(enabledBefore[1].top, before[1].top, 'The first-form offset must persist when the button is enabled');
        assert.throws(() => assertAligned(enabledBefore, 'Enabled first-form negative control', true), /edges must align/);
        await page.setContent(documentHtml(html));
        await page.evaluate(() => document.fonts.ready);
        assertAligned(await geometry(page), 'Fixed native tab', true);
        if (process.env.UI_LAYOUT_ARTIFACTS) await page.locator('#uvm-inventory-table').screenshot({ path: path.join(process.env.UI_LAYOUT_ARTIFACTS, 'native-tab-padding-fixed.png') });
        await page.locator('#uvm-inventory-table .uvm-actions form').first().evaluate(form => { form.style.transform = 'translateY(6px)'; });
        const translated = await geometry(page);
        assert.throws(() => assertAligned(translated, 'Whole-form offset negative control', true), /edges must align/);
        assert.ok(comparisons > cases, 'Matrix must directly compare neighboring buttons, not only single-button lines');
        console.log('Negative controls: Alpha 9 first-form padding (+5px), enabled first-form padding, and whole-form displacement all detected.');
        console.log(`UI layout: ${cases} cases / ${comparisons} same-line comparisons passed with official WebGUI CSS/fonts and native tab classes; no NAS or network was used.`);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
