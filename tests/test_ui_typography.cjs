// Offline audit of actual plugin markup plus pinned official WebGUI 7.3.4 CSS.
// No browser profiles or NAS access. Output is evidence, not a typography redesign.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { readFileSync, writeFileSync } = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..');
const styles = process.env.WEBGUI_STYLES;
assert.ok(styles, 'WEBGUI_STYLES must contain official styles and Clear Sans/Bitstream WOFF files');
const php = process.env.PHP_BIN || path.join(root, '.tools/php/root/usr/bin/php8.4');
const bootstrap = path.join(root, 'source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php');
const settings = JSON.parse(execFileSync(php, ['-n', '-r', 'require $argv[1]; echo json_encode(Vgpu\\Store::defaults());', bootstrap], { encoding: 'utf8' }));
const device = { uuid: 'ae9572f6-1259-4136-9aa4-497dda98d2db', pci: '0000:01:00.0', profile: 'NVIDIA RTXA5500-1Q', type: 'nvidia-762' };
const fixture = { status: { settings, devices: [device], credential_configured: false, override_content: '# English 中文配置',
    runtime: { ok: true, runtime: { kernel: '6.18.58-Unraid', loaded_driver: '595.58.03', gpus: [{ name: 'NVIDIA GeForce GTX 1650' }] } },
    device_inventory: { devices: [{ ...device, active: true, defined: true, managed: true, framebuffer: '1024M', configuration: device, active_type: device.type, defined_type: device.type, configured_type: device.type }], checks: { active_known: true, defined_known: true }, messages: [] } },
    responses: { logs: { available: true, lines: ['English 中文日志'], category: 'plugin' } } };
const rendered = JSON.parse(execFileSync(php, ['-n', path.join(__dirname, 'test_ui.php'), '--render-fixture'], { input: JSON.stringify(fixture), encoding: 'utf8' }));
assert.deepEqual(rendered.calls.map(call => call.action), ['status', 'logs']);
const common = ['default-color-palette.css', 'default-base.css', 'default-dynamix.css'].map(file => readFileSync(path.join(styles, file), 'utf8')).join('\n');
const fonts = ['clear-sans', 'bitstream'].flatMap(family => [['', 'normal'], ['-bold', 'bold']].map(([suffix, weight]) =>
    `@font-face { font-family: ${family}; font-weight: ${weight}; src: url(data:font/woff;base64,${readFileSync(path.join(styles, family + suffix + '.woff')).toString('base64')}); }`)).join('\n');
const selectors = {
    summary: '#uvm-manager-vgpu .uvm-status > span:first-child',
    driver: '#uvm-manager-vgpu .uvm-status > span:nth-child(2) code',
    kernel: '#uvm-manager-vgpu .uvm-status > span:nth-child(3) code',
    heading: '#uvm-manager-vgpu h2',
    tableHeading: '#uvm-inventory-table th',
    uuid: '#uvm-inventory-table tbody td:first-child code',
    type: '#uvm-inventory-table tbody td:nth-child(2) code',
    profile: '#uvm-inventory-table tbody td:nth-child(3)',
    pci: '#uvm-inventory-table tbody td:nth-child(4) code',
    status: '#uvm-inventory-table tbody td:nth-child(5)',
    muted: '#uvm-inventory-table .uvm-muted',
    button: '#uvm-inventory-table .uvm-toggle-details',
    tab: '.tabs button[role="tab"]',
    uuidInput: '#uvm-device-uuid',
    profileInput: '#uvm-device-profile',
    typeInput: '#uvm-device-type',
    select: '#uvm-device-pci',
    help: '#uvm-device-form .uvm-field small',
    xml: '.uvm-device-xml pre',
    textarea: '#uvm-override-content',
    log: '#uvm-manager-status pre'
};
(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } : {}) });
    const evidence = [];
    try {
        const page = await browser.newPage({ viewport: { width: 1920, height: 1080 } });
        await page.route('**/*', route => route.abort());
        const cdp = await page.context().newCDPSession(page);
        await cdp.send('DOM.enable'); await cdp.send('CSS.enable');
        for (const theme of ['white', 'black']) for (const fontSize of ['62.5%', '100%']) {
            await page.setContent('<!doctype html><html style="--custom-font-size:' + fontSize + '"><head><meta charset="utf-8"><style>' + common + '\n' + readFileSync(path.join(styles, 'themes', theme + '.css'), 'utf8') + '\n' + fonts + '\n.tab-content { display: block; }</style></head><body><div class="tabs"><button role="tab">vGPU 管理</button></div>' + rendered.html + '</body></html>');
            await page.evaluate(() => {
                document.querySelectorAll('details').forEach(e => e.open = true);
                document.querySelectorAll('.uvm-readonly-panel').forEach(e => e.hidden = false);
                return document.fonts.ready;
            });
            const measurements = {};
            for (const [key, selector] of Object.entries(selectors)) {
                const node = page.locator(selector).first();
                assert.equal(await node.count(), 1, 'Audit target exists: ' + key);
                measurements[key] = await node.evaluate((e, key) => {
                    e.dataset.fontAudit = key;
                    const s = getComputedStyle(e);
                    return Object.fromEntries(['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'textTransform'].map(p => [p, s[p]]));
                }, key);
            }
            const { root: document } = await cdp.send('DOM.getDocument');
            for (const [key, measurement] of Object.entries(measurements)) {
                const { nodeId } = await cdp.send('DOM.querySelector', { nodeId: document.nodeId, selector: '[data-font-audit="' + key + '"]' });
                measurement.platformFonts = (await cdp.send('CSS.getPlatformFontsForNode', { nodeId })).fonts;
            }
            for (const key of ['summary', 'profile', 'status', 'uuidInput', 'profileInput', 'typeInput', 'select', 'button', 'tab']) assert.match(measurements[key].fontFamily, /clear-sans/);
            for (const key of ['uuid', 'type', 'pci', 'driver', 'kernel', 'textarea']) assert.match(measurements[key].fontFamily, /monospace/);
            for (const key of ['xml', 'log']) assert.equal(measurements[key].fontFamily, 'bitstream');
            assert.equal(measurements.button.letterSpacing, '1.8px');
            assert.equal(measurements.button.fontWeight, '700');
            assert.equal(measurements.tab.fontWeight, '400');
            evidence.push({ theme, rootFontSize: fontSize, measurements });
        }
        if (process.env.TYPOGRAPHY_EVIDENCE) writeFileSync(process.env.TYPOGRAPHY_EVIDENCE, JSON.stringify({ platform: process.platform, officialWebguiCommit: '31c22f8327cca774dcbddd573dc76f70e87b2f10', evidence }, null, 2) + '\n');
        console.log('Typography audit passed: ' + evidence.length + ' theme/root-size combinations, ' + Object.keys(selectors).length + ' actual markup targets; Linux rendering is not Windows font proof');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
