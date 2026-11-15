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

(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } : {}) });
    let cases = 0;
    try {
        const page = await browser.newPage();
        await page.route('**/*', route => route.abort());
        for (const theme of ['black', 'white']) for (const fontSize of ['62.5%', '100%']) for (const width of [3840, 1920, 1280, 768, 390]) for (const active of [true, false, null]) {
            await page.setViewportSize({ width, height: 1080 });
            const css = commonCss + '\n' + readFileSync(path.join(styles, 'themes', theme + '.css'), 'utf8') + '\n' + fonts;
            await page.setContent('<!doctype html><html style="--custom-font-size:' + fontSize + '"><head><meta charset="utf-8"><style>' + css + '</style></head><body>' + render(active) + '</body></html>');
            await page.evaluate(() => document.fonts.ready);
            const buttons = await page.locator('#uvm-inventory-table .uvm-actions').evaluate(actions => [...actions.querySelectorAll('button')].map(button => {
                const rect = button.getBoundingClientRect();
                const item = button.closest('form') || button;
                const itemRect = item.getBoundingClientRect();
                return { text: button.textContent, disabled: button.disabled, top: rect.top, bottom: rect.bottom, height: rect.height, itemTop: itemRect.top, itemHeight: itemRect.height };
            }));
            const label = `${theme}, font ${fontSize}, ${width}px, active ${active}`;
            assert.deepEqual(buttons.map(button => button.text), ['编辑', '启动', '停止', '移除'], label);
            assert.deepEqual(buttons.map(button => button.disabled), [false, active !== false, active !== true, active === null], label);
            for (const button of buttons) {
                assert.ok(Math.abs(button.top - button.itemTop) < 0.01, `${label}: ${button.text} must align with its flex item`);
                assert.ok(Math.abs(button.height - button.itemHeight) < 0.01, `${label}: ${button.text} must not inherit extra table line-box height`);
                assert.equal(button.height, buttons[0].height, `${label}: enabled and disabled buttons must remain equal-height`);
            }
            for (const a of buttons) for (const b of buttons) if (a.itemTop === b.itemTop) assert.equal(a.bottom, b.bottom, `${label}: buttons on the same wrapped line must align`);
            ++cases;
        }
        console.log(`UI layout: ${cases} cases passed with official WebGUI CSS/fonts; no NAS or network was used.`);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
