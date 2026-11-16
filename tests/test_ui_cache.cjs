// Optional browser regression: actual PHP UI, official WebGUI CSS/fonts,
// intercepted same-origin requests only. Reuses the layout test dependencies.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..');
const styles = process.env.WEBGUI_STYLES;
assert.ok(styles, 'WEBGUI_STYLES is required');
const php = process.env.PHP_BIN || path.join(root, '.tools/php/root/usr/bin/php8.4');
const settings = JSON.parse(execFileSync(php, ['-n', '-r', 'require $argv[1]; echo json_encode(Vgpu\\Store::defaults());', path.join(root, 'source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php')], { encoding: 'utf8' }));
settings.source.url = 'https://git.example.test/owner/drivers'; settings.source.repository = 'owner/drivers'; settings.source.provider = 'gitlab';
const pkg = (build, kernel = '6.18.58-Unraid') => ({ filename: `nvidia-595.58.03-${kernel}-${build}.txz`, driver: '595.58.03', kernel, build,
    path: '/boot/cached.txz', md5: 'a'.repeat(32), checksum_verified: true, size: 478906424 });
const free = pkg(2);
const selected = pkg(3);
const upgraded = pkg(2, '6.18.60-Unraid');
const inventory = (deleted = false, downloaded = false, long = false) => ({ available: true, packages: [
    ...(!deleted ? [{ ...free, deletable: true, references: [] }] : []),
    { ...selected, deletable: downloaded, references: downloaded ? [] : ['当前选中'] },
    { ...upgraded, deletable: false, references: [long ? '升级预下载 ' + 'X'.repeat(500) + '<img id="injected-reason" src=x>' : '升级预下载'] },
    ...(downloaded ? [{ ...pkg(4), deletable: false, references: ['当前选中'] }] : []),
] });
const css = ['default-color-palette.css', 'default-base.css', 'default-dynamix.css'].map(file => readFileSync(path.join(styles, file), 'utf8')).join('\n');
const fonts = [['clear-sans.woff', 'normal'], ['clear-sans-bold.woff', 'bold']].map(([file, weight]) => `@font-face {font-family:clear-sans;font-weight:${weight};src:url(data:font/woff;base64,${readFileSync(path.join(styles, file)).toString('base64')})}`).join('\n');
const base = 'https://unraid.example.test/Settings/unraid-vgpu-manager?uvm_view=driver';
const marker = (id, result) => '<html><body><script>window.__responseExecuted=true</script><script type="application/json" id="' + id + '">' + JSON.stringify(result).replace(/</g, '\\u003c') + '</script></body></html>';
const deleted = () => ({ ok: true, data: { filename: free.filename, changed: true, removed: [free.filename, free.filename + '.md5'], package_cache: inventory(true) }, message: '已删除 TXZ 和 MD5，需要时重新下载或上传。' });

function render(theme, fontSize, long, disabled) {
    const fixture = { single_tab: 'driver', status: { settings: { ...settings, selected_package: selected }, devices: [], package_cache: inventory(false, false, long), credential_configured: false,
        override_content: '', pending_restart: false, runtime: { ok: true, runtime: { kernel: '6.18.58-Unraid', loaded_driver: '595.58.03' } } },
        ...(disabled ? { errors: { status: 'fixture unavailable' } } : {}) };
    const data = JSON.parse(execFileSync(php, ['-n', path.join(__dirname, 'test_ui.php'), '--render-fixture'], { input: JSON.stringify(fixture), encoding: 'utf8' }));
    assert.deepEqual(data.calls.map(call => call.action), ['status']);
    return '<!doctype html><html style="--custom-font-size:' + fontSize + '"><head><meta charset="utf-8"><style>' + css + '\n' + fonts + '\n' + readFileSync(path.join(styles, 'themes', theme + '.css'), 'utf8') + '\n#fixture-driver-panel {display:block}</style></head><body>' + data.html + '</body></html>';
}

(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } : {}) });
    const errors = [], requests = [], confirmations = [];
    let accept = false, responseKind = 'success', release, pageNumber = 0, theme = 'white', fontSize = '62.5%', long = false, disabled = false;
    let cases = 0, layoutCases = 0;
    let page;
    try {
        page = await browser.newPage({ viewport: { width: 1920, height: 1080 } });
        page.setDefaultTimeout(10000);
        page.on('pageerror', error => errors.push(error.message));
        page.on('dialog', async dialog => { confirmations.push(dialog.message()); if (accept) await dialog.accept(); else await dialog.dismiss(); });
        await page.route('**/*', async route => {
            const request = route.request();
            assert.equal(new URL(request.url()).origin, new URL(base).origin, 'No NAS or external network');
            requests.push({ method: request.method(), body: request.postData() || '', type: request.resourceType(), contentType: request.headers()['content-type'] || '' });
            if (request.method() === 'GET') return route.fulfill({ contentType: 'text/html', body: render(theme, fontSize, long, disabled) });
            assert.equal(request.method(), 'POST'); assert.equal(request.resourceType(), 'fetch');
            assert.match(request.headers()['content-type'], /^application\/x-www-form-urlencoded\b/i, 'Text-only POST must not enter the multipart authentication deadlock');
            if (new URLSearchParams(request.postData()).get('uvm_action') === 'source-download') return route.fulfill({ contentType: 'text/html', body: marker('uvm-download-result', {
                ok: true, outcome: 'cached', data: { package: pkg(4), package_cache: inventory(false, true) },
            }) });
            if (responseKind === 'pending') return new Promise(resolve => { release = async () => { await route.fulfill({ contentType: 'text/html', body: marker('uvm-cache-delete-result', deleted()) }); resolve(); }; });
            if (responseKind === 'network') return route.abort('connectionreset');
            if (responseKind === 'timeout') return new Promise(resolve => page.once('requestfailed', resolve));
            let result = deleted();
            if (responseKind === 'failure') result = { ok: false, outcome: 'failed', error: '不能删除：已被当前选中 <img id="injected-error" src=x>' };
            if (responseKind === 'unknown') result = { ok: false, outcome: 'unknown', error: 'Completion unknown' };
            if (responseKind === 'wrong-file') result.data.filename = selected.filename;
            if (responseKind === 'missing-removed') result.data.removed = [];
            const body = responseKind === 'login' ? '<html>Login required</html>' : marker('uvm-cache-delete-result', result);
            return route.fulfill({ status: responseKind === 'http' ? 502 : 200, contentType: 'text/html', body });
        });
        const reset = async () => { await page.goto(base + '&fixture_page=' + ++pageNumber); await page.evaluate(() => document.fonts.ready); };
        const row = name => page.locator('#uvm-cache-list tr[data-cache-filename="' + name + '"]');
        const submit = () => row(free.filename).locator('button').click();
        const done = () => page.waitForFunction(() => !document.getElementById('uvm-cache-message').hidden && !document.getElementById('uvm-cache-message').textContent.includes('正在删除'));
        await reset();
        if (process.env.CACHE_TEST_OUTPUT) await page.locator('#uvm-downloaded-panel').screenshot({ path: path.join(process.env.CACHE_TEST_OUTPUT, 'cache-management.png') });
        assert.equal(await row(free.filename).locator('button').isDisabled(), false);
        assert.equal(await row(selected.filename).locator('button').isDisabled(), true);
        assert.equal(await row(upgraded.filename).locator('button').isDisabled(), true);
        const beforeProtected = requests.length;
        await row(selected.filename).locator('form').evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
        assert.equal(requests.length, beforeProtected); assert.equal(confirmations.length, 0);
        await submit();
        assert.equal(requests.length, beforeProtected, 'Cancelling confirmation must not send any POST');
        assert.ok(confirmations.at(-1).includes(free.filename) && confirmations.at(-1).includes('同名 MD5'));
        ++cases;

        await page.locator('#uvm-source-url').fill('https://unsaved.example.test'); await page.locator('#uvm-credential-token').fill('unsaved-fixture-secret');
        const draft = await page.locator('#uvm-source-form').evaluate(form => Object.fromEntries(new FormData(form)));
        const currentUrl = page.url();
        accept = true; responseKind = 'pending';
        const beforePending = requests.length;
        await submit();
        await page.waitForFunction(() => window.uvmUI.isBusy());
        assert.equal(await row(free.filename).locator('button').isDisabled(), true);
        await row(free.filename).locator('form').evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
        await page.locator('#uvm-download-form button').click();
        assert.equal(requests.length, beforePending + 1, 'Pending deletion blocks double submit and racing download');
        for (const [key, value] of Object.entries({ uvm_action: 'cache-delete', uvm_tab: 'driver', csrf_token: 'fixture-ui-csrf', uvm_csrf_token: 'fixture-ui-csrf', cache_filename: free.filename, uvm_cache_delete_async: '1' })) assert.equal(new URLSearchParams(requests.at(-1).body).get(key), value);
        assert.ok(!requests.at(-1).body.includes('unsaved.example.test') && !requests.at(-1).body.includes('unsaved-fixture-secret'));
        await release(); await done();
        assert.equal(await row(free.filename).count(), 0); assert.equal(await row(selected.filename).locator('button').isDisabled(), true);
        assert.equal(page.url(), currentUrl); assert.equal(requests.length, beforePending + 1);
        assert.deepEqual(await page.locator('#uvm-source-form').evaluate(form => Object.fromEntries(new FormData(form))), draft);
        assert.match(await page.locator('#uvm-driver-cache-body').textContent(), /构建 3/);
        assert.equal(await page.evaluate(() => window.uvmUI.isBusy()), false);
        assert.equal(await page.evaluate(() => window.__responseExecuted), undefined);
        ++cases;

        for (const kind of ['failure', 'unknown', 'login', 'http', 'network', 'timeout', 'wrong-file', 'missing-removed']) {
            await reset(); responseKind = kind;
            if (kind === 'timeout') await page.evaluate(() => { const native = window.setTimeout; window.setTimeout = (callback, delay, ...args) => native(callback, delay === 45000 ? 25 : delay, ...args); });
            const before = requests.length;
            await submit(); await done();
            assert.equal(requests.length, before + 1, kind + ': never retry a destructive action automatically');
            assert.equal(await page.locator('#uvm-cache-check').isVisible(), true, kind);
            assert.equal(await page.locator('#uvm-cache-list button').count(), 0, kind + ': stale list cannot offer another delete');
            assert.equal(await page.locator('#injected-error').count(), 0);
            assert.match(await page.locator('#uvm-driver-cache-body').textContent(), /构建 3/);
            if (kind !== 'failure') assert.match(await page.locator('#uvm-cache-message').textContent(), /未能确认删除结果/);
            assert.equal(await page.evaluate(() => window.uvmUI.isBusy()), kind !== 'failure');
            const beforeCheck = requests.length;
            await page.locator('#uvm-cache-check').click(); await page.waitForLoadState('domcontentloaded');
            assert.deepEqual(requests.slice(beforeCheck).map(request => request.method), ['GET'], 'Recovery does not replay the DELETE POST');
            ++cases;
        }
        await reset();
        await page.locator('#uvm-download-form button').click();
        await page.waitForFunction(() => document.getElementById('uvm-download-form').getAttribute('aria-busy') === 'false');
        assert.match(await page.locator('#uvm-driver-cache-body').textContent(), /构建 4/);
        assert.equal(await row(selected.filename).locator('button').isDisabled(), false, 'Download refreshes the old selection reference');
        assert.equal(await row(pkg(4).filename).locator('button').isDisabled(), true, 'Newly downloaded/selected package is immediately protected');
        assert.equal(await row(upgraded.filename).locator('button').isDisabled(), true);
        ++cases;

        long = true;
        for (theme of ['white', 'black']) for (fontSize of ['62.5%', '100%']) for (const width of [360, 768, 1920, 3840]) {
            await page.setViewportSize({ width, height: 1080 }); await reset();
            const bounds = await page.evaluate(() => ({ viewport: innerWidth, page: document.documentElement.scrollWidth, panel: document.getElementById('uvm-downloaded-panel').getBoundingClientRect().width,
                parent: document.getElementById('uvm-downloaded-panel').parentElement.clientWidth }));
            assert.ok(bounds.page <= bounds.viewport + 1 && bounds.panel <= bounds.parent + 1, `${theme}, ${fontSize}, ${width}px: table overflow must stay local`);
            assert.equal(await page.locator('#injected-reason').count(), 0);
            ++layoutCases;
        }
        theme = 'white'; fontSize = '62.5%'; long = false; disabled = true;
        await reset(); const beforeDisabled = requests.length;
        assert.equal(await page.locator('#uvm-cache-list button:enabled').count(), 0);
        assert.equal(requests.length, beforeDisabled);
        ++cases;
        assert.deepEqual(errors, []);
        console.log(`Cache deletion browser regression passed: ${cases} behavior cases, ${layoutCases} theme/font/viewport cases; no NAS/network/GPU.`);
    } catch (error) {
        if (page && process.env.CACHE_TEST_OUTPUT) await page.screenshot({ path: path.join(process.env.CACHE_TEST_OUTPUT, 'cache-browser-failure.png'), fullPage: true });
        throw error;
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
