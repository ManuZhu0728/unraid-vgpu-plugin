// Offline regression: real plugin templates, pinned official WebGUI 7.3.4 CSS.
// No browser profiles, fetch mocking, NAS access, added fonts or external network.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { readFileSync, writeFileSync } = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..');
const styles = process.env.WEBGUI_STYLES;
assert.ok(styles, 'WEBGUI_STYLES must contain official CSS and Clear Sans/Bitstream WOFF files');
const php = process.env.PHP_BIN || path.join(root, '.tools/php/root/usr/bin/php8.4');
const bootstrap = path.join(root, 'source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php');
const settings = JSON.parse(execFileSync(php, ['-n', '-r', 'require $argv[1]; echo json_encode(Vgpu\\Store::defaults());', bootstrap], { encoding: 'utf8' }));
const kernel = '6.18.58-Unraid';
const packageInfo = { driver: '595.58.03', kernel, build: 3, filename: 'nvidia-595.58.03-' + kernel + '-3.txz', source: 'local', md5: 'a'.repeat(32), checksum_verified: true };
settings.selected_package = packageInfo;
const device = { uuid: 'ae9572f6-1259-4136-9aa4-497dda98d2db', pci: '0000:01:00.0', profile: 'NVIDIA RTXA5500-1Q', type: 'nvidia-762' };
const row = { ...device, active: true, defined: true, managed: true, framebuffer: '1024M', configuration: device, active_type: device.type, defined_type: device.type, configured_type: device.type };
const fixture = { server: { REQUEST_METHOD: 'POST' }, post: { uvm_action: 'source-versions', uvm_tab: 'driver', csrf_token: 'fixture-ui-csrf', uvm_csrf_token: 'fixture-ui-csrf' },
    gpus: { [device.pci]: { device: '0x1f82', profiles: [{ name: device.profile, type: device.type, available: '23', maximum: '24', framebuffer: '1024M', heads: '4', resolution: '5120x2880', frl: '60' }] } },
    status: { settings, devices: [device], credential_configured: false, override_content: '# English 中文配置\n' + 'x'.repeat(1200),
        upgrade: { status: 'ready', package: packageInfo, target_kernel: kernel },
        installation: { status: 'installed', last_success: { package: packageInfo } },
        package_cache: { available: true, packages: [{ ...packageInfo, size: 500000, references: ['当前选中'], deletable: false }] },
        runtime: { ok: true, runtime: { kernel, loaded_driver: '595.58.03', gpus: [{ name: 'NVIDIA GeForce GTX 1650' }] } },
        device_inventory: { devices: [row, { ...row, pci: '0000:02:00.0', profile: 'LONG PROFILE ' + 'X'.repeat(180) }, { ...row, uuid: '868bfe06-47c1-40a3-82df-ffd518be72d3', type: 'nvidia-' + '9'.repeat(90) }], checks: { active_known: true, defined_known: true }, messages: [] } },
    responses: { 'source-versions': { versions: [packageInfo] }, logs: { available: true, lines: ['English 中文日志\n' + 'L'.repeat(1800)], category: 'plugin' } } };
const rendered = JSON.parse(execFileSync(php, ['-n', path.join(__dirname, 'test_ui.php'), '--render-fixture'], { input: JSON.stringify(fixture), encoding: 'utf8' }));
assert.deepEqual(rendered.calls.map(call => call.action), ['source-versions', 'status', 'logs']);
const common = ['default-color-palette.css', 'default-base.css', 'default-dynamix.css'].map(file => readFileSync(path.join(styles, file), 'utf8')).join('\n');
const fontFaces = includeMono => ['clear-sans', ...(includeMono ? ['bitstream'] : [])].flatMap(family => [['', 'normal'], ['-bold', 'bold']].map(([suffix, weight]) =>
    `@font-face { font-family: ${family}; font-weight: ${weight}; src: url(data:font/woff;base64,${readFileSync(path.join(styles, family + suffix + '.woff')).toString('base64')}); }`)).join('\n');
const mono = {
    driver: '#uvm-manager-vgpu .uvm-status > span:nth-child(2) code', kernel: '#uvm-manager-vgpu .uvm-status > span:nth-child(3) code',
    uuid: '#uvm-inventory-table tbody td:first-child code', type: '#uvm-inventory-table tbody td:nth-child(2) code', pci: '#uvm-inventory-table tbody td:nth-child(4) code',
    detailTitle: '.uvm-device-details h3 code', xmlTitle: '.uvm-device-xml h3 code', detailType: '.uvm-device-details p code',
    profileType: '#uvm-profile-body td:nth-child(3) code', cacheDriver: '#uvm-driver-cache-body > p:first-child code', cacheFilename: '#uvm-driver-cache-body .uvm-cache-filename code',
    downloadedDriver: '#uvm-cache-list td:first-child p code', downloadedKernel: '#uvm-cache-list td:nth-child(2) code',
    versionList: '#uvm-online-panel .uvm-scroll tbody code', taskVersion: '#uvm-driver-tasks td:last-child code',
    xml: '.uvm-device-xml pre', detailXml: '.uvm-device-details pre', log: '#uvm-log-output', json: '#uvm-manager-status details pre', textarea: '#uvm-override-content'
};
const native = { summary: '#uvm-manager-vgpu .uvm-status > span:first-child', heading: '#uvm-manager-vgpu h2',
    tableHeading: '#uvm-inventory-table th', profile: '#uvm-inventory-table tbody td:nth-child(3)', status: '#uvm-inventory-table tbody td:nth-child(5)',
    uuidInput: '#uvm-device-uuid', profileInput: '#uvm-device-profile', typeInput: '#uvm-device-type', select: '#uvm-device-pci',
    sourceInput: '#uvm-source-url', tokenInput: '#uvm-credential-token', driverInput: '[name="source_driver"]', downloadPicker: '[name="download_package"]',
    button: '#uvm-inventory-table .uvm-toggle-details', tab: '.tabs button[role="tab"]', help: '#uvm-device-form .uvm-field small' };
const fontProperties = ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'textTransform'];
const buttonProperties = [...fontProperties, 'color', 'backgroundColor', 'backgroundImage', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'borderTopStyle', 'borderTopWidth', 'borderRadius', 'minWidth'];
const baseline = '<aside id="native-baseline"><code id="native-code">A0</code><pre id="native-pre">A0</pre><input id="native-input" type="text"><select id="native-select"><option>GPU</option></select><button id="native-button">VM XML</button></aside>';
const shell = '<div class="tabs"><button role="tab">vGPU 管理</button></div>';
const measure = async (locator, properties = fontProperties) => locator.first().evaluate((e, properties) => {
    const s = getComputedStyle(e); return Object.fromEntries(properties.map(p => [p, s[p]]));
}, properties);
const tableGeometry = page => page.locator('#uvm-inventory-table').evaluate(table => {
    const origin = table.getBoundingClientRect();
    const box = e => { const b = e.getBoundingClientRect(); return [b.x - origin.x, b.y - origin.y, b.width, b.height]; };
    return [box(table), ...[...table.tHead.rows[0].cells].map(box), ...[...table.querySelectorAll('.uvm-actions button')].map(box)];
});
function assertStable(before, after, label) {
    assert.equal(before.length, after.length);
    before.forEach((box, i) => box.forEach((value, j) => assert.ok(Math.abs(value - after[i][j]) <= .5, label + ': opening content changed table/button geometry')));
}
async function assertContained(page, label) {
    const bounds = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth,
        panels: [...document.querySelectorAll('.uvm-readonly-panel:not([hidden]),#uvm-log-output')].map(e => ({ width: e.getBoundingClientRect().width, parent: e.parentElement.clientWidth, client: e.clientWidth, scroll: e.scrollWidth })) }));
    assert.ok(bounds.scroll <= bounds.width + 1, label + ': no document-level horizontal overflow');
    for (const panel of bounds.panels) assert.ok(panel.width <= panel.parent + 1 && panel.scroll <= panel.client + 1, label + ': long code must stay contained');
}
(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } : {}) });
    const evidence = []; let expansionChecks = 0;
    try {
        const page = await browser.newPage(); const errors = [], requests = [];
        page.on('pageerror', e => errors.push(e.message));
        await page.route('**/*', route => { requests.push(route.request().url()); return route.abort(); });
        const cdp = await page.context().newCDPSession(page); await cdp.send('DOM.enable'); await cdp.send('CSS.enable');
        for (const theme of ['white', 'black']) for (const rootSize of ['62.5%', '100%']) for (const width of [320, 360, 768, 1920]) for (const availableFont of [true, false]) {
            const label = `${theme}/${rootSize}/${width}px/font:${availableFont}`;
            await page.setViewportSize({ width, height: 1080 });
            const html = content => '<!doctype html><html style="--custom-font-size:' + rootSize + '"><head><meta charset="utf-8"><style>' + common + '\n' + readFileSync(path.join(styles, 'themes', theme + '.css'), 'utf8') + '\n' + fontFaces(availableFont) + '\n.tab-content {display:block}</style></head><body>' + baseline + shell + content + '</body></html>';
            await page.setContent(html('')); await page.mouse.move(0, 0); await page.evaluate(() => document.fonts.ready);
            const beforeNative = {};
            for (const id of ['code', 'pre', 'input', 'select', 'button']) beforeNative[id] = await measure(page.locator('#native-' + id));
            const nativeButton = await measure(page.locator('#native-button'), buttonProperties);
            await page.setContent(html(rendered.html)); await page.evaluate(() => document.fonts.ready);
            for (const id of Object.keys(beforeNative)) assert.deepEqual(await measure(page.locator('#native-' + id)), beforeNative[id], label + ': plugin rules must not leak to native ' + id);
            await page.locator('#uvm-device-pci').selectOption(device.pci);
            await page.locator('#uvm-profile-body input').check();
            await page.mouse.move(0, 0);
            await page.locator('details').evaluateAll(nodes => nodes.forEach(e => e.open = true));
            const measurements = {};
            for (const [key, selector] of Object.entries({ ...mono, ...native })) {
                const node = page.locator(selector).first(); assert.equal(await node.count(), 1, 'Audit target: ' + key);
                measurements[key] = await measure(node); await node.evaluate((e, key) => e.dataset.fontAudit = key, key);
            }
            for (const key of Object.keys(mono)) {
                assert.equal(measurements[key].fontFamily, measurements.uuid.fontFamily, label + ': ' + key + ' must use exactly the same monospace stack');
                assert.match(measurements[key].fontFamily, /^bitstream,.*monospace$/);
            }
            for (const key of Object.keys(native)) assert.match(measurements[key].fontFamily, /clear-sans/, label + ': native ' + key);
            for (const key of ['uuidInput', 'profileInput', 'typeInput', 'driverInput']) assert.deepEqual(measurements[key], beforeNative.input, label + ': forms keep their native typography');
            assert.deepEqual(measurements.button, beforeNative.button, label + ': buttons keep all native font properties');
            assert.deepEqual(await measure(page.locator(native.button), buttonProperties), nativeButton, label + ': button colors, padding and border remain native');
            for (const key of ['uuid', 'type', 'pci', 'driver', 'kernel', 'xml', 'log', 'textarea']) assert.equal(measurements[key].fontSize, measurements.uuidInput.fontSize, label + ': body-size technical text must not shrink');
            assert.equal(measurements.tab.fontWeight, '400'); assert.equal(measurements.tab.letterSpacing, '1.8px');
            const saved = await page.locator('#uvm-device-form').evaluate(form => [...new FormData(form)]);
            // Stress real code text without creating HTML or changing device identity.
            await page.locator('.uvm-readonly-panel pre').evaluateAll(nodes => nodes.forEach(e => e.textContent += '\n<!-- ' + 'Z'.repeat(1800) + ' -->'));
            const geometry = await tableGeometry(page);
            for (const control of await page.locator('.uvm-readonly-toggle').all()) {
                const panel = page.locator('#' + await control.getAttribute('aria-controls'));
                await control.click(); assertStable(geometry, await tableGeometry(page), label); await assertContained(page, label);
                assert.equal(await control.getAttribute('aria-expanded'), 'true');
                await panel.locator('.uvm-close-readonly').click(); assertStable(geometry, await tableGeometry(page), label);
                assert.equal(await control.evaluate(e => e === document.activeElement), true); expansionChecks += 2;
            }
            assert.deepEqual(await page.locator('#uvm-device-form').evaluate(form => [...new FormData(form)]), saved, 'Font/disclosure operations cannot change draft data');
            await page.locator('.uvm-readonly-toggle').first().focus(); await page.keyboard.press('Enter');
            assert.equal(await page.locator('.uvm-readonly-toggle').first().getAttribute('aria-expanded'), 'true');
            await page.locator('.uvm-device-xml:not([hidden]) pre').first().waitFor();
            const { root: document } = await cdp.send('DOM.getDocument');
            for (const key of ['uuid', 'xml', 'log']) {
                const { nodeId } = await cdp.send('DOM.querySelector', { nodeId: document.nodeId, selector: '[data-font-audit="' + key + '"]' });
                measurements[key].platformFonts = (await cdp.send('CSS.getPlatformFontsForNode', { nodeId })).fonts;
            }
            const latinFont = key => measurements[key].platformFonts.find(f => f.glyphCount > 0 && (availableFont ? f.isCustomFont : !f.isCustomFont));
            assert.ok(latinFont('uuid') && latinFont('xml') && latinFont('log'), label + ': actual font must render');
            assert.equal(latinFont('uuid').familyName, latinFont('xml').familyName, label + ': inline code and XML must render with the same actual face');
            assert.equal(latinFont('uuid').familyName, latinFont('log').familyName, label + ': inline code and logs must render with the same actual face');
            if (process.env.TYPOGRAPHY_SCREENSHOT && theme === 'white' && rootSize === '100%' && width === 360 && availableFont) await page.screenshot({ path: process.env.TYPOGRAPHY_SCREENSHOT, fullPage: true });
            evidence.push({ theme, rootSize, width, availableFont, measurements });
        }
        // Negative control: the old per-element families must fail equality.
        await page.locator(mono.uuid).first().evaluate(e => e.style.fontFamily = 'monospace');
        await page.locator(mono.xml).first().evaluate(e => e.style.fontFamily = 'bitstream');
        assert.notEqual((await measure(page.locator(mono.uuid))).fontFamily, (await measure(page.locator(mono.xml))).fontFamily);
        assert.deepEqual(errors, []); assert.deepEqual(requests, [], 'Font tests must not send network or mutation requests');
        if (process.env.TYPOGRAPHY_EVIDENCE) writeFileSync(process.env.TYPOGRAPHY_EVIDENCE, JSON.stringify({ platform: process.platform, officialWebguiCommit: '31c22f8327cca774dcbddd573dc76f70e87b2f10', expansionChecks, evidence }, null, 2) + '\n');
        console.log(`Typography regression passed: ${evidence.length} theme/size/viewport/font-fallback cases, ${Object.keys(mono).length} mono + ${Object.keys(native).length} native targets, ${expansionChecks} expansion checks, native isolation and mixed-font negative control; no NAS or network`);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
