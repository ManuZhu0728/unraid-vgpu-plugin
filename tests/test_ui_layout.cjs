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
const fonts = ['clear-sans', 'bitstream'].flatMap(family => [['', 'normal'], ['-bold', 'bold']].map(([suffix, weight]) =>
    `@font-face { font-family: ${family}; font-weight: ${weight}; src: url(data:font/woff;base64,${readFileSync(path.join(styles, family + suffix + '.woff')).toString('base64')}); }`
)).join('\n');

function render(active, expandedFixture = false) {
    const row = { ...device, framebuffer: '1024M', active, defined: true, managed: true, configuration: device, active_type: active ? device.type : '', defined_type: device.type, configured_type: device.type, definition_start: 'manual', iommu_group: '27' };
    const second = { uuid: '868bfe06-47c1-40a3-82df-ffd518be72d3', pci: '0000:02:00.0', profile: 'NVIDIA PROFILE ' + 'X'.repeat(110), type: 'nvidia-' + '9'.repeat(20) };
    const rows = expandedFixture ? [row, { ...row, ...second, configuration: second }, { ...row, pci: '0000:09:00.0', managed: false, configuration: null, active_type: 'unexpected-' + 'Y'.repeat(180) }] : [row];
    const gpus = expandedFixture ? { [second.pci]: { device: '0x1f82', profiles: [{ ...second, name: second.profile, available: '1', framebuffer: '1024M', heads: '4', resolution: '5120x2880', frl: '60', maximum: '24' }] } } : {};
    const fixture = { single_tab: 'vgpu', gpus, status: { settings: defaults, devices: expandedFixture ? [device, second] : [device], credential_configured: false, runtime: { ok: true, runtime: { kernel: '6.18.58-Unraid', loaded_driver: '595.58.03' } }, override_content: '', pending_restart: false, device_inventory: { devices: rows, checks: { active_known: active !== null, defined_known: true }, messages: [] } } };
    const rendered = JSON.parse(execFileSync(php, ['-n', path.join(__dirname, 'test_ui.php'), '--render-fixture'], { input: JSON.stringify(fixture), encoding: 'utf8' }));
    assert.deepEqual(rendered.calls.map(call => call.action), ['status'], 'Rendering must only read fixture status');
    return rendered.html;
}

async function geometry(page) {
    return page.locator('#uvm-inventory-table .uvm-actions').first().evaluate(actions => [...actions.children].map(item => item.tagName === 'BUTTON' ? item : item.tagName === 'DETAILS' ? item.querySelector(':scope > summary') : item.querySelector('button')).filter(Boolean).map(button => {
        const rect = button.getBoundingClientRect();
        const item = button.closest('form') || button.closest('details') || button;
        const itemRect = item.getBoundingClientRect();
        const style = getComputedStyle(item);
        return { text: button.textContent, disabled: Boolean(button.disabled), form: item.tagName === 'FORM', top: rect.top, bottom: rect.bottom, height: rect.height,
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

async function inventoryLayout(page) {
    return page.locator('#uvm-inventory-table').evaluate(table => {
        const base = table.getBoundingClientRect();
        const box = node => { const r = node.getBoundingClientRect(); return [r.left - base.left, r.top - base.top, r.width, r.height]; };
        return { table: [base.width, base.height, table.scrollWidth, table.parentElement.scrollWidth],
            columns: [...table.tHead.rows[0].cells].map(box),
            rows: [...table.tBodies[0].rows].map(row => ({ cells: [...row.cells].map(box), controls: [...row.querySelectorAll('.uvm-actions > button, .uvm-actions > form > button')].map(box) })) };
    });
}

function assertStable(before, after, label) {
    const close = (a, b) => {
        assert.equal(a.length, b.length, label + ': geometry shape');
        a.forEach((value, index) => assert.ok(Math.abs(value - b[index]) <= 0.5, label + ': column/control/table geometry changed'));
    };
    close(before.table, after.table);
    before.columns.forEach((column, index) => close(column, after.columns[index]));
    before.rows.forEach((row, index) => {
        row.cells.forEach((cell, c) => close(cell, after.rows[index].cells[c]));
        row.controls.forEach((control, c) => close(control, after.rows[index].controls[c]));
    });
}

async function assertContained(page, label) {
    const result = await page.evaluate(() => ({ page: document.documentElement.scrollWidth, viewport: innerWidth,
        panels: [...document.querySelectorAll('.uvm-readonly-panel:not([hidden])')].map(panel => ({ width: panel.getBoundingClientRect().width, parent: panel.parentElement.clientWidth, client: panel.clientWidth, scroll: panel.scrollWidth })) }));
    assert.ok(result.page <= result.viewport + 1, label + ': horizontal overflow must stay inside local scroll containers');
    for (const panel of result.panels) {
        assert.ok(panel.width <= panel.parent + 1, label + ': panel must not inherit the table minimum width');
        assert.ok(panel.scroll <= panel.client + 1, label + ': long panel text must wrap or scroll internally');
    }
}

(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } : {}) });
    let cases = 0;
    let comparisons = 0;
    let expandedCases = 0;
    let expansionChecks = 0;
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
            assert.deepEqual(buttons.map(button => button.text), ['详情', '启动', '停止', '移除'], label);
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
            comparisons += assertAligned(buttons, label, true);
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
        await page.locator('#uvm-inventory-table .uvm-actions form').first().locator('button').evaluate(button => { button.disabled = false; });
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

        for (const theme of ['black', 'white']) for (const fontSize of ['62.5%', '100%']) for (const width of [1920, 768, 390]) {
            await page.setViewportSize({ width, height: 1080 });
            const css = commonCss + '\n' + readFileSync(path.join(styles, 'themes', theme + '.css'), 'utf8') + '\n' + fonts;
            await page.setContent('<!doctype html><html style="--custom-font-size:' + fontSize + '"><head><meta charset="utf-8"><style>' + css + '\n#fixture-vgpu-panel {display:block}</style></head><body>' + render(true, true) + '</body></html>');
            await page.evaluate(() => document.fonts.ready);
            // Stress the read-only code containers without changing any real
            // device or the PHP-generated UUID/XML identity.
            await page.locator('.uvm-readonly-panel pre').evaluateAll(nodes => nodes.forEach(node => { node.textContent += '\n<!-- ' + 'Z'.repeat(1600) + ' -->'; }));
            await page.locator('#uvm-device-pci').selectOption('0000:02:00.0');
            await page.locator('#uvm-profile-body input').check();
            const baseline = await inventoryLayout(page);
            const saved = await page.locator('#uvm-device-form').evaluate(form => [...new FormData(form)]);
            const forms = await page.locator('#uvm-inventory-table form').evaluateAll(nodes => nodes.map(form => ({ data: [...new FormData(form)], disabled: form.querySelector('button').disabled, confirm: form.dataset.confirm })));
            const label = `${theme}/${fontSize}/${width}px expanded`;
            const controls = await page.locator('.uvm-readonly-toggle').all();
            assert.equal(controls.length, 6);
            const targets = [];
            for (const control of controls) {
                const id = await control.getAttribute('aria-controls');
                targets.push(id);
                const panel = page.locator('#' + id);
                assert.equal(await panel.isHidden(), true);
                assert.equal(await panel.locator('button:visible').count(), 0, 'Hidden panel controls must not remain in the tab sequence');
                assert.equal(await panel.getAttribute('data-device-key'), await control.getAttribute('data-device-key'));
                assert.equal(await panel.evaluate(node => Boolean(node.closest('table'))), false, 'Panel content must not contribute to table layout');
                await control.click();
                assert.equal(await control.getAttribute('aria-expanded'), 'true');
                assert.equal(await panel.evaluate(node => document.activeElement === node), true);
                assert.ok((await panel.locator('h3').textContent()).includes((await control.getAttribute('data-device-key')).split('|')[0]));
                assertStable(baseline, await inventoryLayout(page), label);
                await assertContained(page, label);
                assert.deepEqual(await page.locator('#uvm-device-form').evaluate(form => [...new FormData(form)]), saved);
                ++expansionChecks;
            }
            assert.equal(new Set(targets).size, 6, 'Same UUID under different PCI parents and different panel kinds need unique targets');
            assert.deepEqual(await page.locator('#uvm-inventory-table form').evaluateAll(nodes => nodes.map(form => ({ data: [...new FormData(form)], disabled: form.querySelector('button').disabled, confirm: form.dataset.confirm }))), forms);
            assertAligned(await geometry(page), label, true);
            for (const control of controls) {
                const panel = page.locator('#' + await control.getAttribute('aria-controls'));
                await panel.locator('.uvm-close-readonly').click();
                assert.equal(await control.getAttribute('aria-expanded'), 'false');
                assert.equal(await control.evaluate(node => node === document.activeElement), true);
                assertStable(baseline, await inventoryLayout(page), label + ' close');
                ++expansionChecks;
            }
            const keyboardControl = controls[0];
            await keyboardControl.focus();
            await page.keyboard.press('Enter');
            assert.equal(await keyboardControl.getAttribute('aria-expanded'), 'true');
            await page.locator('#' + await keyboardControl.getAttribute('aria-controls')).locator('.uvm-close-readonly').click();
            await page.keyboard.press('Space');
            assert.equal(await keyboardControl.getAttribute('aria-expanded'), 'true');
            assertStable(baseline, await inventoryLayout(page), label + ' keyboard');
            await assertContained(page, label);
            ++expandedCases;
        }

        // Alpha 12 structural negative controls recreate the published inline
        // details/auto-table arrangement in memory, never editing source files.
        for (const kind of ['details', 'xml']) {
            await page.setViewportSize({ width: 1920, height: 1080 });
            await page.setContent(documentHtml(render(true)));
            await page.evaluate(() => document.fonts.ready);
            await page.evaluate(kind => {
                const table = document.getElementById('uvm-inventory-table');
                table.querySelector('colgroup').remove();
                const control = table.querySelector('.uvm-toggle-' + kind);
                const panel = document.getElementById(control.getAttribute('aria-controls')).cloneNode(true);
                panel.querySelectorAll('h3,button').forEach(node => node.remove());
                const details = document.createElement('details');
                details.className = 'legacy-alpha12-details';
                details.innerHTML = '<summary>' + control.textContent + '</summary><div class="legacy-alpha12-body">' + panel.innerHTML + '</div>';
                control.replaceWith(details);
                const style = document.createElement('style');
                style.textContent = '#uvm-inventory-table {table-layout:auto;min-width:0} .legacy-alpha12-details > summary {display:block;box-sizing:border-box;min-width:86px;padding:8px;font-family:clear-sans;font-size:1.1rem;font-weight:bold;line-height:normal} .legacy-alpha12-body {min-width:220px;max-width:380px} .uvm-actions:has(>.legacy-alpha12-details[open]) {align-items:flex-start}';
                document.body.appendChild(style);
            }, kind);
            const before = await inventoryLayout(page);
            await page.locator('.legacy-alpha12-details > summary').click();
            const after = await inventoryLayout(page);
            assert.ok(before.columns.some((column, index) => Math.abs(column[0] - after.columns[index][0]) > 0.5 || Math.abs(column[2] - after.columns[index][2]) > 0.5), 'Alpha 12 ' + kind + ' must reproduce table-column reflow');
            assert.throws(() => assertStable(before, after, 'Alpha 12 ' + kind), /geometry changed/);
        }
        console.log('Negative controls: Alpha 9 first-form padding (+5px), enabled first-form padding, and whole-form displacement all detected.');
        console.log(`UI layout: ${cases} cases / ${comparisons} same-line comparisons passed with official WebGUI CSS/fonts and native tab classes; no NAS or network was used.`);
        console.log(`Expanded layout: ${expandedCases} cases / ${expansionChecks} open/close stability checks, long values, same UUID/different PCI, keyboard and local overflow containment passed.`);
        console.log('Alpha 12 negative controls: inline details and inline VM XML both reproduce column reflow and fail the same stability assertion.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
