// Optional hardware-free create/details behavior regression. Uses real PHP
// rendering, official WebGUI CSS/fonts, and intercepted same-origin HTTP only.
// WEBGUI_STYLES=... PLAYWRIGHT_MODULE=... PLAYWRIGHT_CHROMIUM_EXECUTABLE=... \
// node tests/test_ui_editor.cjs
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..');
const styles = process.env.WEBGUI_STYLES;
assert.ok(styles, 'WEBGUI_STYLES must point to the official WebGUI styles directory');
const php = process.env.PHP_BIN || path.join(root, '.tools/php/root/usr/bin/php8.4');
const settings = JSON.parse(execFileSync(php, ['-n', '-r', 'require $argv[1]; echo json_encode(Vgpu\\Store::defaults());', path.join(root, 'source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php')], { encoding: 'utf8' }));
settings.runtime.enabled = true;
const devices = [
    { uuid: 'ae9572f6-1259-4136-9aa4-497dda98d2db', pci: '0000:01:00.0', profile: 'NVIDIA RTXA5500-1Q', type: 'nvidia-762' },
    { uuid: '868bfe06-47c1-40a3-82df-ffd518be72d3', pci: '0000:02:00.0', profile: 'NVIDIA RTXA5500-2Q', type: 'nvidia-763' },
];
const profile = (type, name, available) => ({ type, name, available, framebuffer: '1024M', heads: '4', resolution: '5120x2880', frl: '60', maximum: '24' });
const gpus = {
    [devices[0].pci]: { device: '0x1f82', profiles: [profile(devices[0].type, devices[0].profile, '0'), profile('nvidia-760', 'NVIDIA RTXA5500-1B', '2')] },
    [devices[1].pci]: { device: '0x1f82', profiles: [profile(devices[1].type, devices[1].profile, '1')] },
};
const css = ['default-color-palette.css', 'default-base.css', 'default-dynamix.css', 'themes/white.css'].map(file => readFileSync(path.join(styles, file), 'utf8')).join('\n') + '\n'
    + [['clear-sans.woff', 'normal'], ['clear-sans-bold.woff', 'bold']].map(([file, weight]) => `@font-face {font-family:clear-sans;font-weight:${weight};src:url(data:font/woff;base64,${readFileSync(path.join(styles, file)).toString('base64')})}`).join('\n')
    + '\n#fixture-vgpu-panel {display:block}';
const baseUrl = 'https://unraid.example.test/Settings/unraid-vgpu-manager?driver_mode=local&uvm_view=vgpu';
const marker = data => '<html><body>native wrapper<script type="application/json" id="uvm-device-create-result">' + JSON.stringify(data) + '</script></body></html>';
const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

function render(created, disabled = false) {
    const configurations = created ? [...devices, created] : devices;
    const rows = configurations.map((device, index) => ({ ...device, active: index === 0, defined: true, managed: true, configuration: device,
        active_type: index === 0 ? 'nvidia-999' : '', defined_type: device.type, configured_type: device.type, framebuffer: '1024M', iommu_group: '27' }));
    // The same UUID under another PCI must have an independent, read-only detail.
    rows.push({ ...rows[0], pci: '0000:09:00.0', managed: false, configuration: null, configured_type: '', active_type: 'nvidia-888' });
    const fixture = { single_tab: 'vgpu', gpus, status: { settings, devices: configurations, credential_configured: false, override_content: '', pending_restart: false,
        runtime: { ok: true, runtime: { kernel: '6.18.58-Unraid', loaded_driver: '595.58.03' } },
        device_inventory: { devices: rows, checks: { active_known: true, defined_known: true }, messages: [] } },
        ...(disabled ? { errors: { status: 'fixture status unavailable' } } : {}) };
    const rendered = JSON.parse(execFileSync(php, ['-n', path.join(__dirname, 'test_ui.php'), '--render-fixture'], { input: JSON.stringify(fixture), encoding: 'utf8' }));
    assert.deepEqual(rendered.calls.map(call => call.action), ['status']);
    assert.match(rendered.html, /class="tab-content content" role="tabpanel"/);
    return '<!doctype html><html class="Theme--white"><head><meta charset="utf-8"><style>' + css + '</style></head><body>' + rendered.html + '</body></html>';
}

async function draft(page) {
    return page.evaluate(() => {
        const el = id => document.getElementById(id);
        return { uuid: el('uvm-device-uuid').value, pci: el('uvm-device-pci').value, profile: el('uvm-device-profile').value, type: el('uvm-device-type').value,
            checked: [...document.querySelectorAll('#uvm-profile-body input:checked')].map(input => input.value),
            current: [...document.querySelectorAll('#uvm-profile-body .uvm-profile-current')].map(row => row.querySelector('input').value) };
    });
}

async function actions(page) {
    return page.locator('#uvm-inventory-table .uvm-actions > form').evaluateAll(forms => forms.map(form => ({
        fields: Object.fromEntries(new FormData(form)), method: form.method, confirm: form.dataset.confirm || '', disabled: form.querySelector('button').disabled,
    })));
}

(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } : {}) });
    const errors = [];
    const requests = [];
    let responseKind = 'validation';
    let releasePending;
    let created;
    let disabled = false;
    let scenarios = 0;
    try {
        const page = await browser.newPage({ viewport: { width: 1920, height: 1080 } });
        page.on('pageerror', error => errors.push(error.message));
        page.on('dialog', dialog => dialog.dismiss());
        await page.route('**/*', async route => {
            const request = route.request();
            requests.push({ method: request.method(), url: request.url(), body: request.postData() || '', contentType: request.headers()['content-type'] || '' });
            assert.equal(new URL(request.url()).origin, new URL(baseUrl).origin, 'Never contact a NAS or other origin');
            if (request.method() === 'GET') {
                return route.fulfill({ contentType: 'text/html', body: render(created, disabled) });
            }
            assert.equal(request.method(), 'POST');
            assert.match(request.headers()['content-type'], /^application\/x-www-form-urlencoded\b/i);
            const uuid = new URLSearchParams(request.postData()).get('uuid');
            if (responseKind === 'pending') return new Promise(resolve => { releasePending = async () => { await route.fulfill({ contentType: 'text/html', body: marker({ ok: false, error: '稍后重试' }) }); resolve(); }; });
            if (responseKind === 'network') return route.abort('connectionreset');
            if (responseKind === 'timeout') return new Promise(resolve => { page.once('requestfailed', resolve); });
            let body;
            if (responseKind === 'validation') body = marker({ ok: false, error: '配置验证失败' });
            if (responseKind === 'duplicate') body = marker({ ok: false, error: 'UUID 已保存，不会覆盖' });
            if (responseKind === 'unknown') body = '<html>session expired<script>window.__responseExecuted=true</script></html>';
            if (responseKind === 'malformed') body = marker({ ok: true, data: { uuid, changed: false } });
            if (responseKind === 'success') {
                created = { uuid, pci: devices[1].pci, profile: devices[1].profile, type: devices[1].type };
                body = marker({ ok: true, data: { uuid, changed: true }, message: '设备配置已保存，本次保存未启动设备。' });
            }
            return route.fulfill({ contentType: 'text/html', body });
        });
        await page.goto(baseUrl);
        await page.evaluate(() => document.fonts.ready);
        assert.equal(await page.locator('#uvm-device-create').isVisible(), true);
        assert.equal(await page.locator('#uvm-device-create h2').textContent(), '添加设备');
        assert.equal(await page.locator('#uvm-device-editor, .uvm-edit-device, #uvm-cancel-edit').count(), 0);
        assert.equal(await page.locator('#uvm-device-form.uvm-submit-form').count(), 0, 'The generic submit handler must not intercept create');
        assert.equal(await page.locator('.uvm-device-details form, .uvm-device-details input, .uvm-device-details select, .uvm-device-details textarea').count(), 0);
        const formData = await page.locator('#uvm-device-form').evaluate(form => Object.fromEntries(new FormData(form)));
        assert.equal(formData.uvm_action, 'device-create');
        assert.equal(formData.csrf_token, 'fixture-ui-csrf');
        assert.equal(formData.uvm_csrf_token, 'fixture-ui-csrf');
        const baselineActions = await actions(page);
        assert.equal(baselineActions.length, 6);
        assert.deepEqual(baselineActions.map(form => form.disabled), [true, false, false, false, true, false]);
        for (const form of baselineActions) {
            assert.equal(form.method, 'post');
            assert.equal(form.fields.csrf_token, 'fixture-ui-csrf');
            assert.equal(form.fields.uvm_csrf_token, 'fixture-ui-csrf');
        }
        await page.locator('#uvm-device-pci').selectOption(devices[0].pci);
        assert.equal(await page.locator('#uvm-profile-body input[value="nvidia-762"]').isDisabled(), true);
        await page.locator('#uvm-profile-body input[value="nvidia-760"]').check();
        const selected = await draft(page);
        for (const control of await page.locator('.uvm-readonly-toggle').all()) {
            const panelId = await control.getAttribute('aria-controls');
            const panel = page.locator('#' + panelId);
            await control.click();
            assert.equal(await control.getAttribute('aria-expanded'), 'true');
            assert.equal(await panel.isVisible(), true);
            assert.equal(await panel.evaluate(node => node === document.activeElement), true);
            assert.deepEqual(await draft(page), selected, 'Opening details must not touch the independent create draft');
            await panel.locator('.uvm-close-readonly').click();
            assert.equal(await panel.isHidden(), true);
            assert.equal(await control.getAttribute('aria-expanded'), 'false');
            assert.equal(await control.evaluate(node => node === document.activeElement), true);
        }
        assert.equal(new Set(await page.locator('.uvm-device-details').evaluateAll(nodes => nodes.map(node => node.dataset.deviceKey))).size, 3);
        const detailText = await page.locator('.uvm-device-details').first().textContent();
        for (const text of ['插件保存', '系统定义', '当前运行', 'nvidia-999', 'nvidia-762', 'VM XML', '身份存在差异']) assert.ok(detailText.includes(text), text);
        await page.locator('#uvm-new-device').click();
        const generated = await draft(page);
        assert.match(generated.uuid, uuidPattern);
        assert.notEqual(generated.uuid, selected.uuid);
        assert.deepEqual({ ...generated, uuid: selected.uuid }, selected, 'Generate only changes UUID');
        await page.evaluate(() => { window.__nativeCrypto = window.crypto; Object.defineProperty(window, 'crypto', { configurable: true, value: undefined }); });
        await page.locator('#uvm-new-device').click();
        assert.deepEqual(await draft(page), generated);
        assert.match(await page.locator('#uvm-device-create-message').textContent(), /当前 UUID 已保留/);
        await page.evaluate(() => { Object.defineProperty(window, 'crypto', { configurable: true, value: window.__nativeCrypto }); delete window.__nativeCrypto; });
        assert.equal(requests.filter(request => request.method === 'POST').length, 0);
        ++scenarios;

        const xmlControl = page.locator('.uvm-toggle-xml').first();
        await xmlControl.click();
        const xmlPanel = page.locator('#' + await xmlControl.getAttribute('aria-controls'));
        const xmlText = await xmlPanel.locator('.uvm-vm-xml').textContent();
        await page.evaluate(() => {
            Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async text => { window.__copiedXml = text; } } });
        });
        await xmlPanel.locator('.uvm-copy-xml').click();
        assert.equal(await page.evaluate(() => window.__copiedXml), xmlText, 'Copy XML must preserve the complete read-only snippet');
        assert.ok(xmlText.includes(devices[0].uuid) && xmlText.includes('<hostdev') && xmlText.includes('</hostdev>'));
        assert.deepEqual(await draft(page), generated);
        assert.equal(requests.filter(request => request.method === 'POST').length, 0, 'Copy XML must not submit a device action');
        await xmlPanel.locator('.uvm-close-readonly').click();
        await page.evaluate(() => { delete navigator.clipboard; delete window.__copiedXml; });
        ++scenarios;

        await page.locator('#uvm-device-pci').selectOption(devices[1].pci);
        await page.locator('#uvm-profile-body input[value="nvidia-763"]').check();
        const preserved = await draft(page);
        const submit = () => page.locator('#uvm-save-device').click();
        async function expectFailure(kind, message) {
            responseKind = kind;
            const previous = requests.length;
            await submit();
            await page.waitForFunction(() => !window.uvmUI.isBusy());
            assert.equal(requests.length, previous + 1, 'One explicit submit must produce exactly one POST, never a GET or automatic retry');
            assert.match(await page.locator('#uvm-device-create-message').textContent(), message);
            assert.deepEqual(await draft(page), preserved, 'Failure must preserve every draft field and radio');
            assert.equal(await page.locator('#uvm-device-fields').isDisabled(), false);
            assert.deepEqual(await actions(page), baselineActions);
            ++scenarios;
        }
        await expectFailure('validation', /配置验证失败/);
        await expectFailure('duplicate', /UUID 已保存/);
        await expectFailure('unknown', /未能确认保存结果/);
        assert.equal(await page.evaluate(() => window.__responseExecuted), undefined, 'Fetched HTML must never execute scripts');
        await expectFailure('malformed', /未能确认保存结果/);
        await expectFailure('network', /未能确认保存结果/);
        await page.evaluate(() => { window.__nativeTimeout = window.setTimeout; window.setTimeout = (callback, delay, ...args) => window.__nativeTimeout(callback, delay === 30000 ? 30 : delay, ...args); });
        await expectFailure('timeout', /请求可能已完成/);
        await page.evaluate(() => { window.setTimeout = window.__nativeTimeout; delete window.__nativeTimeout; });

        responseKind = 'pending';
        const beforePending = requests.length;
        await submit();
        await page.waitForFunction(() => window.uvmUI.isBusy());
        assert.equal(await page.locator('#uvm-device-fields').evaluate(fields => fields.disabled), true);
        await page.locator('#uvm-device-form').evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
        await page.locator('#uvm-new-device').evaluate(button => button.click());
        const busyControl = page.locator('.uvm-toggle-details').first();
        await busyControl.click();
        assert.deepEqual(await draft(page), preserved);
        assert.equal(requests.length, beforePending + 1, 'Busy double-submit must not produce a second request');
        await releasePending();
        await page.waitForFunction(() => !window.uvmUI.isBusy());
        await page.locator('#' + await busyControl.getAttribute('aria-controls')).locator('.uvm-close-readonly').click();
        assert.deepEqual(await actions(page), baselineActions);
        ++scenarios;

        responseKind = 'success';
        const beforeSuccess = requests.length;
        await page.evaluate(() => {
            const fields = document.getElementById('uvm-device-fields');
            const native = Object.getOwnPropertyDescriptor(HTMLFieldSetElement.prototype, 'disabled');
            const transitions = [];
            Object.defineProperty(fields, 'disabled', { get() { return native.get.call(this); }, set(value) {
                transitions.push(Boolean(value));
                sessionStorage.setItem('__createFieldTransitions', JSON.stringify(transitions));
                native.set.call(this, value);
            } });
            window.addEventListener('beforeunload', () => {
                sessionStorage.setItem('__createBeforeUnload', JSON.stringify({ disabled: fields.disabled, busy: window.uvmUI.isBusy() }));
                document.getElementById('uvm-device-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                document.getElementById('uvm-new-device').click();
            }, { once: true });
        });
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), submit()]);
        await page.waitForFunction(() => document.querySelector('#uvm-device-create-message').textContent.includes('本次保存未启动设备'));
        assert.deepEqual(requests.slice(beforeSuccess).map(request => request.method), ['POST', 'GET']);
        assert.deepEqual(await page.evaluate(() => JSON.parse(sessionStorage.getItem('__createFieldTransitions'))), [true], 'Confirmed success must never unlock the old form in finally');
        assert.deepEqual(await page.evaluate(() => JSON.parse(sessionStorage.getItem('__createBeforeUnload'))), { disabled: true, busy: true }, 'A second submit during confirmed-save navigation must stay blocked');
        assert.equal(new URL(page.url()).searchParams.get('driver_mode'), 'local');
        assert.equal(await page.locator('#uvm-inventory-table tr[data-uuid="' + preserved.uuid + '"]').count(), 1, 'Refresh must show the authoritative created row');
        const refreshed = await draft(page);
        assert.notEqual(refreshed.uuid, preserved.uuid);
        for (const key of ['pci', 'profile', 'type']) assert.equal(refreshed[key], '');
        assert.deepEqual(refreshed.checked, []);
        assert.deepEqual(refreshed.current, []);
        await page.reload();
        assert.equal(await page.locator('#uvm-device-create-message').isHidden(), true, 'Success notice is consumed once');
        assert.equal(requests.at(-1).method, 'GET', 'Refresh must not replay POST');
        ++scenarios;

        disabled = true;
        await page.locator('#uvm-device-pci').selectOption(devices[1].pci);
        await page.locator('#uvm-profile-body input[value="nvidia-763"]').check();
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), submit()]);
        assert.match(await page.locator('#uvm-device-create-message').textContent(), /当前设备列表不可核验/);
        assert.notEqual(await page.locator('#uvm-device-create-message').textContent(), '设备配置已保存，本次保存未启动设备。', 'Missing authoritative status must not display an unverified success notice');
        for (const selector of ['#uvm-device-uuid', '#uvm-device-pci', '#uvm-save-device', '#uvm-new-device']) assert.equal(await page.locator(selector).isDisabled(), true);
        const beforeDisabled = requests.length;
        await page.locator('#uvm-device-form').evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
        assert.equal(requests.length, beforeDisabled);
        assert.deepEqual(errors, []);
        ++scenarios;
        for (const request of requests.filter(request => request.method === 'POST')) {
            for (const [name, value] of [['uvm_action', 'device-create'], ['csrf_token', 'fixture-ui-csrf'], ['uvm_csrf_token', 'fixture-ui-csrf']]) assert.equal(new URLSearchParams(request.body).get(name), value, 'Retain ' + name);
        }
        console.log(`UI create/details: ${scenarios} scenarios passed; wrapped fetch responses, failures/unknown outcomes, busy, independent drafts, CSRF, authoritative GET refresh; no NAS or GPU operation.`);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
