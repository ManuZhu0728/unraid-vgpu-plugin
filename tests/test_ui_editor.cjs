// Optional, hardware-free editor behavior regression. Reuses the real PHP page
// renderer and the same external WebGUI/Playwright dependencies as test_ui_layout.cjs.
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
const bootstrap = path.join(root, 'source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php');
const settings = JSON.parse(execFileSync(php, ['-n', '-r', 'require $argv[1]; echo json_encode(Vgpu\\Store::defaults());', bootstrap], { encoding: 'utf8' }));
settings.runtime.enabled = true;
const devices = [
    { uuid: 'ae9572f6-1259-4136-9aa4-497dda98d2db', pci: '0000:01:00.0', profile: 'NVIDIA RTXA5500-1Q', type: 'nvidia-762' },
    { uuid: '868bfe06-47c1-40a3-82df-ffd518be72d3', pci: '0000:02:00.0', profile: 'NVIDIA RTXA5500-2Q', type: 'nvidia-763' },
    { uuid: '5cf14a12-a437-4c82-a13f-70e945782d7b', pci: '0000:09:00.0', profile: 'NVIDIA RTXA5500-4Q', type: 'nvidia-765' },
];
const entry = (type, name, available) => ({ type, name, available, framebuffer: '1024M', heads: '4', resolution: '5120x2880', frl: '60', maximum: '24' });
const gpus = {
    [devices[0].pci]: { device: '0x1f82', profiles: [entry(devices[0].type, devices[0].profile, '0'), entry('nvidia-760', 'NVIDIA RTXA5500-1B', '2')] },
    [devices[1].pci]: { device: '0x1f82', profiles: [entry(devices[1].type, devices[1].profile, '1')] },
};
const css = ['default-color-palette.css', 'default-base.css', 'default-dynamix.css', 'themes/white.css'].map(file => readFileSync(path.join(styles, file), 'utf8')).join('\n')
    + '\n' + [['clear-sans.woff', 'normal'], ['clear-sans-bold.woff', 'bold']].map(([file, weight]) =>
        `@font-face { font-family: clear-sans; font-weight: ${weight}; src: url(data:font/woff;base64,${readFileSync(path.join(styles, file)).toString('base64')}); }`
    ).join('\n') + '\n#fixture-vgpu-panel { display: block; }';
const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

function render(disabled = false) {
    const rows = devices.map((device, index) => ({ ...device, active: index === 0, defined: true, managed: true, configuration: device,
        active_type: index === 0 ? device.type : '', defined_type: device.type, configured_type: device.type, framebuffer: '1024M', iommu_group: '27' }));
    const fixture = { single_tab: 'vgpu', gpus, status: { settings, devices, credential_configured: false, override_content: '', pending_restart: false,
        runtime: { ok: true, runtime: { kernel: '6.18.58-Unraid', loaded_driver: '595.58.03' } },
        device_inventory: { devices: rows, checks: { active_known: true, defined_known: true }, messages: [] } },
        ...(disabled ? { errors: { status: 'fixture status unavailable' } } : {}) };
    const rendered = JSON.parse(execFileSync(php, ['-n', path.join(__dirname, 'test_ui.php'), '--render-fixture'], { input: JSON.stringify(fixture), encoding: 'utf8' }));
    assert.deepEqual(rendered.calls.map(call => call.action), ['status'], 'Rendering must only read fixture status');
    assert.equal(rendered.profile_scans, 1);
    assert.match(rendered.html, /class="tab-content content" role="tabpanel"/, 'Use the real native tab shell');
    return '<!doctype html><html class="Theme--white"><head><meta charset="utf-8"><style>' + css + '</style></head><body>' + rendered.html + '</body></html>';
}

async function state(page) {
    return page.evaluate(() => {
        const el = id => document.getElementById(id);
        const uuid = el('uvm-device-uuid');
        return { mode: el('uvm-device-editor').dataset.mode, open: el('uvm-device-editor').open,
            title: el('uvm-device-editor-title').textContent, save: el('uvm-save-device').textContent,
            uuid: uuid.value, readOnly: uuid.readOnly, disabled: uuid.matches(':disabled'), pci: el('uvm-device-pci').value,
            profile: el('uvm-device-profile').value, type: el('uvm-device-type').value,
            checked: [...document.querySelectorAll('#uvm-profile-body input:checked')].map(input => input.value),
            current: [...document.querySelectorAll('#uvm-profile-body .uvm-profile-current')].map(row => row.querySelector('input').value),
            temporary: [...el('uvm-device-pci').querySelectorAll('[data-uvm-editor-temporary]')].map(option => option.value),
            generateHidden: el('uvm-new-device').hidden, cancelHidden: el('uvm-cancel-edit').hidden, noteHidden: el('uvm-edit-device-note').hidden,
            fields: Object.fromEntries(new FormData(el('uvm-device-form'))) };
    });
}

function addState(actual, open, label) {
    assert.equal(actual.mode, 'add', label);
    assert.equal(actual.open, open, label);
    assert.equal(actual.title, '添加设备', label);
    assert.equal(actual.save, '保存新设备', label);
    assert.match(actual.uuid, uuidPattern, label);
    assert.ok(!devices.some(device => device.uuid === actual.uuid), label + ': never reuse an existing device UUID');
    assert.equal(actual.readOnly, false, label);
    for (const name of ['pci', 'profile', 'type']) assert.equal(actual[name], '', label + ': clear ' + name);
    for (const name of ['checked', 'current', 'temporary']) assert.deepEqual(actual[name], [], label + ': clear ' + name);
    assert.equal(actual.generateHidden, false, label);
    assert.equal(actual.cancelHidden, true, label);
    assert.equal(actual.noteHidden, true, label);
}

function editState(actual, device, label) {
    assert.equal(actual.mode, 'edit', label);
    assert.equal(actual.open, true, label);
    assert.equal(actual.title, '编辑设备', label);
    assert.equal(actual.save, '保存当前配置', label);
    for (const name of ['uuid', 'pci', 'profile', 'type']) assert.equal(actual[name], device[name], label + ': populate ' + name);
    assert.equal(actual.readOnly, true, label);
    assert.equal(actual.disabled, false, label + ': readonly UUID must still be submitted');
    assert.equal(actual.fields.uuid, device.uuid, label);
    assert.equal(actual.generateHidden, true, label);
    assert.equal(actual.cancelHidden, false, label);
    assert.equal(actual.noteHidden, false, label);
    const selected = gpus[device.pci] ? [device.type] : [];
    assert.deepEqual(actual.checked, selected, label);
    assert.deepEqual(actual.current, selected, label);
}

async function actionForms(page) {
    return page.locator('#uvm-inventory-table tbody > tr').evaluateAll(rows => rows.map(row => ({ uuid: row.dataset.uuid,
        forms: [...row.querySelectorAll('.uvm-actions > form')].map(form => ({ method: form.method, confirm: form.dataset.confirm || '',
            fields: Object.fromEntries(new FormData(form)), disabled: form.querySelector('button').disabled })) })));
}

(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } : {}) });
    const errors = [];
    const requests = [];
    let scenarios = 0;
    try {
        const page = await browser.newPage({ viewport: { width: 1920, height: 1080 } });
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => { requests.push(route.request().method() + ' ' + route.request().url()); return route.abort(); });
        async function load(disabled = false) {
            await page.setContent(render(disabled));
            await page.evaluate(() => {
                window.__editorTestSubmits = 0;
                document.addEventListener('submit', event => { ++window.__editorTestSubmits; event.preventDefault(); }, true);
                return document.fonts.ready;
            });
        }
        const edit = index => page.locator('#uvm-inventory-table tr[data-uuid="' + devices[index].uuid + '"] .uvm-edit-device').click();
        await load();
        const original = await state(page);
        addState(original, false, 'initial mode');
        assert.equal(await page.locator('#uvm-add-device').isVisible(), true, 'Add entry must remain outside the collapsed editor');
        const forms = await actionForms(page);
        assert.equal(forms.length, devices.length);
        for (const [index, row] of forms.entries()) {
            assert.equal(row.forms.length, 3, 'Each device retains three independent action forms');
            assert.deepEqual(row.forms.map(form => form.fields.uvm_action), ['device-start', 'device-stop', 'device-remove']);
            assert.deepEqual(row.forms.map(form => form.disabled), index === 0 ? [true, false, false] : [false, true, false]);
            for (const [action, form] of row.forms.entries()) {
                assert.equal(form.method, 'post');
                assert.equal(form.fields.uuid, row.uuid);
                assert.equal(form.fields.uvm_tab, 'vgpu');
                assert.equal(form.fields.csrf_token, 'fixture-ui-csrf');
                assert.equal(form.fields.uvm_csrf_token, 'fixture-ui-csrf');
                assert.equal(Boolean(form.confirm), action !== 0);
            }
        }
        async function unchangedActions() {
            assert.deepEqual(await actionForms(page), forms, 'Editor actions must not alter device operation forms or safety gates');
            const current = await state(page);
            assert.equal(current.fields.uvm_action, 'device-save');
            assert.equal(current.fields.uvm_tab, 'vgpu');
            assert.equal(current.fields.csrf_token, 'fixture-ui-csrf');
            assert.equal(current.fields.uvm_csrf_token, 'fixture-ui-csrf');
            assert.equal(await page.locator('#uvm-device-form').count(), 1);
            assert.equal(await page.evaluate(() => window.__editorTestSubmits), 0, 'Mode switches must never submit a form');
            ++scenarios;
        }
        await edit(0);
        editState(await state(page), devices[0], 'edit existing');
        assert.equal(await page.locator('#uvm-add-device').isVisible(), true);
        assert.equal(await page.locator('#uvm-profile-body input[value="nvidia-762"]').isDisabled(), false, 'Keep the existing zero-availability profile selectable while editing');
        await unchangedActions();
        await page.locator('#uvm-cancel-edit').click();
        addState(await state(page), false, 'cancel edit');
        assert.equal(await page.locator('#uvm-add-device').evaluate(button => button === document.activeElement), true);
        await unchangedActions();
        await edit(0);
        await page.locator('#uvm-add-device').click();
        addState(await state(page), true, 'add after editing');
        await page.locator('#uvm-device-pci').selectOption(devices[0].pci);
        assert.equal(await page.locator('#uvm-profile-body input:checked').count(), 0);
        assert.equal(await page.locator('#uvm-profile-body .uvm-profile-current').count(), 0);
        assert.equal(await page.locator('#uvm-profile-body input[value="nvidia-762"]').isDisabled(), true, 'Add mode must not inherit the edited current-profile exception');
        await page.locator('#uvm-profile-body input[value="nvidia-760"]').check();
        const selected = await state(page);
        await page.locator('#uvm-new-device').click();
        const generated = await state(page);
        assert.match(generated.uuid, uuidPattern);
        assert.notEqual(generated.uuid, selected.uuid);
        assert.deepEqual({ ...generated, uuid: selected.uuid, fields: { ...generated.fields, uuid: selected.uuid } }, selected, 'Generate UUID must preserve GPU, profile, radio selection and mode');
        await unchangedActions();
        await edit(0);
        await edit(1);
        editState(await state(page), devices[1], 'switch edit target');
        await edit(2);
        await edit(2);
        editState(await state(page), devices[2], 'edit missing GPU twice');
        assert.deepEqual((await state(page)).temporary, [devices[2].pci], 'Missing GPU edit must create only one temporary PCI option');
        await page.locator('#uvm-new-device').evaluate(button => button.click());
        editState(await state(page), devices[2], 'hidden UUID generator cannot alter edit mode');
        await page.locator('#uvm-add-device').click();
        addState(await state(page), true, 'add clears temporary PCI');
        await unchangedActions();
        for (const editing of [false, true]) {
            if (editing) await edit(0);
            const before = await state(page);
            await page.evaluate(() => window.uvmUI.setBusy(true));
            for (const selector of ['#uvm-add-device', '#uvm-cancel-edit', '#uvm-new-device', '.uvm-edit-device']) {
                await page.locator(selector).first().evaluate(button => button.click());
                assert.deepEqual(await state(page), before, 'Busy must block editor action ' + selector);
            }
            await page.evaluate(() => window.uvmUI.setBusy(false));
            await unchangedActions();
        }
        await page.evaluate(() => { window.__editorTestCrypto = window.crypto; Object.defineProperty(window, 'crypto', { configurable: true, value: undefined }); });
        await page.locator('#uvm-add-device').click();
        addState(await state(page), true, 'no-crypto add fallback');
        assert.equal((await state(page)).uuid, original.uuid, 'Fallback must use the server-rendered add UUID, not the edited UUID');
        await edit(2);
        await page.locator('#uvm-cancel-edit').click();
        addState(await state(page), false, 'no-crypto cancel fallback');
        assert.equal((await state(page)).uuid, original.uuid);
        await page.locator('#uvm-add-device').click();
        await page.locator('#uvm-device-pci').selectOption(devices[1].pci);
        await page.locator('#uvm-profile-body input[value="nvidia-763"]').check();
        const noCrypto = await state(page);
        await page.locator('#uvm-new-device').click();
        assert.deepEqual(await state(page), noCrypto, 'No-crypto generation must preserve the selected profile and UUID');
        assert.match(await page.locator('#uvm-browser-message-vgpu').textContent(), /无法生成 UUID/);
        await page.evaluate(() => { Object.defineProperty(window, 'crypto', { configurable: true, value: window.__editorTestCrypto }); delete window.__editorTestCrypto; });
        await unchangedActions();
        assert.deepEqual(errors, [], 'The real editor scripts must execute without browser errors');
        assert.deepEqual(requests, [], 'Editor mode changes must not make network requests');

        await load(true);
        assert.equal(await page.locator('#uvm-add-device').isDisabled(), true, 'Unavailable status must keep the permanent add entry disabled');
        for (const selector of ['#uvm-device-uuid', '#uvm-device-pci', '#uvm-save-device', '#uvm-new-device', '#uvm-cancel-edit']) assert.equal(await page.locator(selector).isDisabled(), true, 'Retain the disabled fieldset gate: ' + selector);
        const disabled = await state(page);
        await page.locator('#uvm-add-device').evaluate(button => button.click());
        assert.deepEqual(await state(page), disabled, 'A disabled add button must not enter the editor');
        assert.equal(disabled.fields.uuid, undefined, 'Disabled fieldset must remain distinct from readonly edit UUID submission');
        assert.equal(disabled.fields.csrf_token, 'fixture-ui-csrf');
        assert.equal(disabled.fields.uvm_csrf_token, 'fixture-ui-csrf');
        assert.equal(await page.evaluate(() => window.__editorTestSubmits), 0);
        assert.deepEqual(errors, []);
        assert.deepEqual(requests, []);
        ++scenarios;
        console.log(`UI editor: ${scenarios} behavior scenarios passed with real PHP/native-tab rendering; no form submission, network or GPU operation occurred.`);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
