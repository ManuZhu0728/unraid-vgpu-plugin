// Optional hardware-free download behavior/layout regression. Uses the actual
// PHP page, official WebGUI styles and same-origin intercepted HTTP only.
// WEBGUI_STYLES=... PLAYWRIGHT_MODULE=... PLAYWRIGHT_CHROMIUM_EXECUTABLE=... \
// node tests/test_ui_download.cjs
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
settings.source.url = 'https://git.example.test/owner/drivers';
settings.source.repository = 'owner/drivers';
settings.source.provider = 'gitlab';
const kernel = '6.18.58-Unraid';
const packageInfo = build => ({ driver: '595.58.03', kernel, build, filename: `nvidia-595.58.03-${kernel}-${build}.txz`,
    path: '/boot/config/plugins/unraid-vgpu-manager/packages/cached.txz', md5: 'a'.repeat(32), checksum_verified: true });
const commonCss = ['default-color-palette.css', 'default-base.css', 'default-dynamix.css'].map(file => readFileSync(path.join(styles, file), 'utf8')).join('\n');
const fonts = ['clear-sans', 'bitstream'].flatMap(family => [['', 'normal'], ['-bold', 'bold']].map(([suffix, weight]) =>
    `@font-face {font-family:${family};font-weight:${weight};src:url(data:font/woff;base64,${readFileSync(path.join(styles, family + suffix + '.woff')).toString('base64')})}`)).join('\n');
const baseUrl = 'https://unraid.example.test/Settings/unraid-vgpu-manager?uvm_view=driver#download';
const marker = result => '<script type="application/json" id="uvm-download-result">' + JSON.stringify(result).replace(/</g, '\\u003c') + '</script>';
const success = patch => ({ ok: true, outcome: 'cached', data: { package: { ...packageInfo(3), ...patch } } });
const wrap = content => '<html><body>native wrapper<script>window.__responseExecuted=true</script>' + content + '</body></html>';
const renderedPages = new Map();

function render(theme = 'white', fontSize = '62.5%', disabled = false) {
    const key = `${theme}|${fontSize}|${disabled}`;
    if (renderedPages.has(key)) return renderedPages.get(key);
    const fixture = { single_tab: 'driver',
        status: { settings: { ...settings, selected_package: packageInfo(2) }, devices: [], credential_configured: false, override_content: '', pending_restart: false,
            runtime: { ok: true, runtime: { kernel, loaded_driver: '595.58.03' } } },
        server: { REQUEST_METHOD: 'POST' },
        post: { uvm_action: 'source-versions', uvm_tab: 'driver', csrf_token: 'fixture-ui-csrf', uvm_csrf_token: 'fixture-ui-csrf' },
        responses: { 'source-versions': { versions: [packageInfo(1), packageInfo(2), packageInfo(3)] } },
        ...(disabled ? { errors: { status: 'fixture status unavailable' } } : {}) };
    const rendered = JSON.parse(execFileSync(php, ['-n', path.join(__dirname, 'test_ui.php'), '--render-fixture'], { input: JSON.stringify(fixture), encoding: 'utf8' }));
    assert.deepEqual(rendered.calls.map(call => call.action), ['source-versions', 'status']);
    const css = commonCss + '\n' + readFileSync(path.join(styles, 'themes', theme + '.css'), 'utf8') + '\n' + fonts + '\n#fixture-driver-panel {display:block}';
    const html = '<!doctype html><html style="--custom-font-size:' + fontSize + '"><head><meta charset="utf-8"><style>' + css + '</style></head><body>' + rendered.html + '</body></html>';
    renderedPages.set(key, html);
    return html;
}

async function drafts(page) {
    return page.evaluate(() => Object.fromEntries(new FormData(document.getElementById('uvm-source-form'))));
}

async function assertContained(page, label) {
    const size = await page.evaluate(() => ({ viewport: innerWidth, page: document.documentElement.scrollWidth,
        panels: ['uvm-download-feedback', 'uvm-driver-cache-body'].map(id => { const el = document.getElementById(id); return { client: el.clientWidth, scroll: el.scrollWidth }; }) }));
    assert.ok(size.page <= size.viewport + 1, label + ': no document-level horizontal overflow');
    for (const panel of size.panels) assert.ok(panel.scroll <= panel.client + 1, label + ': long feedback/cache text must wrap or scroll locally');
}

(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } : {}) });
    const errors = [];
    const requests = [];
    let responseKind = 'success';
    let releasePending;
    let theme = 'white';
    let fontSize = '62.5%';
    let disabled = false;
    let pageNumber = 0;
    let cases = 0;
    let layoutCases = 0;
    let page;
    try {
        page = await browser.newPage({ viewport: { width: 1920, height: 1080 } });
        page.setDefaultTimeout(10000);
        page.on('pageerror', error => errors.push(error.message));
        page.on('dialog', dialog => dialog.accept());
        await page.route('**/*', async route => {
            const request = route.request();
            requests.push({ method: request.method(), url: request.url(), body: request.postData() || '', type: request.resourceType(), contentType: request.headers()['content-type'] || '' });
            assert.equal(new URL(request.url()).origin, new URL(baseUrl).origin, 'Never contact a NAS or other origin');
            if (request.method() === 'GET') return route.fulfill({ contentType: 'text/html', body: render(theme, fontSize, disabled) });
            assert.equal(request.method(), 'POST');
            assert.equal(request.resourceType(), 'fetch', 'Download must not navigate the document');
            if (responseKind === 'pending') return new Promise(resolve => { releasePending = async result => {
                await route.fulfill({ contentType: 'text/html', body: wrap(marker(result || success())) }); resolve();
            }; });
            if (responseKind === 'network') return route.abort('connectionreset');
            if (responseKind === 'timeout') return new Promise(resolve => page.once('requestfailed', resolve));
            if (responseKind === 'http') return route.fulfill({ status: 502, contentType: 'text/html', body: wrap(marker(success())) });
            const invalid = {
                'wrong-kernel': { kernel: '6.18.60-Unraid' }, 'wrong-file': { filename: packageInfo(1).filename },
                'unverified': { checksum_verified: false }, 'bad-md5': { md5: '' }, 'bad-build': { build: '3' },
            };
            let body = marker(success());
            if (Object.hasOwn(invalid, responseKind)) body = marker(success(invalid[responseKind]));
            if (responseKind === 'login') body = '<form>Login expired</form>';
            if (responseKind === 'duplicate-marker') body += marker(success());
            if (responseKind === 'malformed') body = '<script type="application/json" id="uvm-download-result">{broken</script>';
            if (responseKind === 'unknown') body = marker({ ok: false, outcome: 'unknown', error: 'timeout' });
            if (responseKind === 'missing-receipt') body = marker({ ok: true, outcome: 'cached', data: {} });
            if (responseKind === 'failure') body = marker({ ok: false, outcome: 'failed', error: 'MD5 校验失败 <img id="injected-error" src=x>' });
            if (responseKind === 'long-failure') body = marker({ ok: false, outcome: 'failed', error: '下载失败 ' + 'X'.repeat(500) });
            if (responseKind === 'long-success') body = marker(success({ path: '/boot/' + 'X'.repeat(500) + '<img id="injected-path" src=x>' }));
            return route.fulfill({ contentType: 'text/html', body: wrap(body) });
        });
        const reset = async (url = baseUrl) => {
            const target = new URL(url);
            target.searchParams.set('fixture_page', String(++pageNumber));
            await page.goto(target.href);
            await page.evaluate(() => document.fonts.ready);
        };
        const submit = () => page.locator('#uvm-download-form button[type=submit]').click();
        const waitFinished = () => page.waitForFunction(() => document.getElementById('uvm-download-form').getAttribute('aria-busy') === 'false');

        await reset();
        await page.locator('#uvm-source-url').fill('https://unsaved.example.test/do-not-send');
        await page.locator('#uvm-credential-token').fill('fixture-unsaved-secret');
        await page.locator('[name=download_package]').selectOption(packageInfo(3).filename);
        const draft = await drafts(page);
        const initialCache = await page.locator('#uvm-driver-cache-body').textContent();
        assert.match(initialCache, /构建 2/);
        const currentUrl = page.url();
        const beforePending = requests.length;
        responseKind = 'pending';
        await submit();
        await page.waitForFunction(() => document.getElementById('uvm-download-form').getAttribute('aria-busy') === 'true');
        await page.waitForFunction(() => !document.getElementById('uvm-download-elapsed').textContent.endsWith('0:00'));
        assert.equal(await page.locator('#uvm-download-meter').isVisible(), true);
        assert.equal(await page.locator('#uvm-download-meter').getAttribute('value'), null, 'Pending progress is indeterminate, never a fabricated percentage');
        assert.equal(await page.locator('#uvm-source-form').isVisible(), true);
        assert.equal(await page.locator('#uvm-download-form button').isDisabled(), true);
        assert.equal(await page.locator('[name=download_package]').isDisabled(), true);
        assert.equal(await page.locator('#uvm-driver-cache-body').textContent(), initialCache, 'Do not announce a new cache before the receipt');
        assert.equal(await page.locator('#uvm-download-feedback').evaluate(el => el === document.activeElement), true);
        assert.equal(await page.evaluate(() => { const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented; }), true);
        await page.locator('#uvm-download-form').evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
        await page.locator('#uvm-source-form button').first().click();
        await page.locator('#uvm-driver-mode').selectOption('local');
        assert.equal(await page.locator('#uvm-driver-mode').inputValue(), 'online', 'Mode must remain stable while downloading');
        assert.equal(requests.length, beforePending + 1, 'Double submit and other mutation forms cannot race a pending download');
        const downloadRequest = requests.at(-1);
        assert.match(downloadRequest.contentType, /^application\/x-www-form-urlencoded\b/i, 'Downloads must use text form encoding through the native authentication gateway');
        for (const [name, value] of Object.entries({ uvm_action: 'source-download', uvm_tab: 'driver', csrf_token: 'fixture-ui-csrf', uvm_csrf_token: 'fixture-ui-csrf', uvm_download_async: '1', download_package: packageInfo(3).filename })) {
            assert.equal(new URLSearchParams(downloadRequest.body).get(name), value, name + ' must be included in the one exact download request');
        }
        assert.ok(!downloadRequest.body.includes('unsaved.example.test') && !downloadRequest.body.includes('fixture-unsaved-secret'));
        if (process.env.DOWNLOAD_TEST_OUTPUT) await page.locator('#uvm-online-panel').screenshot({ path: path.join(process.env.DOWNLOAD_TEST_OUTPUT, 'download-pending.png') });
        await releasePending(success({ path: '/boot/<img id="injected-path" src=x>' }));
        await waitFinished();
        assert.match(await page.locator('#uvm-download-message').textContent(), /已下载并校验/);
        assert.match(await page.locator('#uvm-driver-cache-body').textContent(), /构建 3/);
        assert.deepEqual(await page.locator('#uvm-driver-cache-body > p:first-child > code').allTextContents(), ['595.58.03', '3', kernel], 'Dynamic download receipts must use the same semantic code markup as the initial page');
        assert.equal(await page.locator('#uvm-driver-cache-body .uvm-cache-filename > code').textContent(), packageInfo(3).filename);
        assert.equal(await page.locator('#uvm-download-meter').getAttribute('value'), '1');
        assert.equal(await page.evaluate(() => window.uvmUI.isBusy()), false);
        assert.equal(await page.locator('#uvm-download-form button').isDisabled(), false);
        assert.equal(await page.locator('[name=download_package]').isDisabled(), false);
        assert.deepEqual(await drafts(page), draft, 'Source/token drafts survive a successful download');
        assert.equal(page.url(), currentUrl);
        assert.equal(requests.length, beforePending + 1, 'Success updates the cache in place, without a GET or page reload');
        assert.equal(await page.evaluate(() => window.__responseExecuted), undefined);
        assert.equal(await page.locator('#injected-path').count(), 0, 'Package text must not become response HTML');
        assert.match(await page.locator('[aria-label="GPU 概览"]').textContent(), /595\.58\.03/);
        assert.equal(await page.evaluate(() => { const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented; }), false);
        ++cases;

        responseKind = 'failure';
        const beforeFailure = requests.length;
        await submit(); await waitFinished();
        assert.match(await page.locator('#uvm-download-message').textContent(), /MD5 校验失败/);
        assert.equal(await page.locator('#injected-error').count(), 0);
        assert.equal(await page.locator('#uvm-download-meter').isHidden(), true);
        assert.equal(await page.locator('#uvm-download-check').isHidden(), true);
        assert.equal(await page.evaluate(() => window.uvmUI.isBusy()), false);
        assert.equal(await page.locator('#uvm-download-form button').isDisabled(), false);
        assert.deepEqual(await drafts(page), draft);
        assert.match(await page.locator('#uvm-driver-cache-body').textContent(), /构建 3/);
        assert.equal(requests.length, beforeFailure + 1);
        responseKind = 'success';
        await submit(); await waitFinished();
        assert.match(await page.locator('#uvm-download-message').textContent(), /已下载并校验/);
        assert.equal(requests.length, beforeFailure + 2, 'Confirmed failure permits one explicit retry, never an automatic retry');
        ++cases;

        await page.locator('[name=download_package]').selectOption('');
        const beforeAutomatic = requests.length;
        await submit(); await waitFinished();
        assert.match(await page.locator('#uvm-download-message').textContent(), /已下载并校验/);
        assert.equal(requests.length, beforeAutomatic + 1, 'Automatic package selection also stays on the page and dispatches once');
        assert.equal(new URLSearchParams(requests.at(-1).body).get('download_package'), '');
        ++cases;

        await page.locator('#uvm-driver-cache-body').evaluate(body => { body.replaceChildren = () => { throw new Error('Simulated DOM update failure'); }; });
        const beforeRenderFailure = requests.length;
        await submit(); await waitFinished();
        assert.match(await page.locator('#uvm-download-message').textContent(), /下载与校验已确认.*不要重复下载/);
        assert.equal(await page.locator('#uvm-download-check').isVisible(), true);
        assert.equal(await page.locator('#uvm-download-form button').isDisabled(), true);
        assert.equal(requests.length, beforeRenderFailure + 1);
        ++cases;

        for (const kind of ['login', 'duplicate-marker', 'malformed', 'unknown', 'missing-receipt', 'wrong-kernel', 'wrong-file', 'unverified', 'bad-md5', 'bad-build', 'http', 'network', 'timeout']) {
            await reset();
            await page.locator('[name=download_package]').selectOption(packageInfo(3).filename);
            responseKind = kind;
            if (kind === 'timeout') await page.evaluate(() => {
                const nativeTimeout = window.setTimeout;
                window.setTimeout = (callback, delay, ...args) => nativeTimeout(callback, delay === 930000 ? 25 : delay, ...args);
            });
            const before = requests.length;
            await submit(); await waitFinished();
            assert.match(await page.locator('#uvm-download-message').textContent(), /未能确认下载结果/, kind);
            assert.match(await page.locator('#uvm-driver-cache-body').textContent(), /构建 2/, kind + ': keep the previous cache');
            assert.equal(await page.locator('#uvm-download-check').isVisible(), true, kind);
            assert.equal(await page.locator('#uvm-download-meter').isHidden(), true, kind);
            assert.equal(await page.locator('#uvm-download-form button').isDisabled(), true, kind);
            assert.equal(await page.evaluate(() => window.uvmUI.isBusy()), true, kind + ': do not allow mutations until cache is checked');
            await page.locator('#uvm-download-form').evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
            await page.locator('#uvm-driver-policy-form button').click();
            assert.equal(requests.length, before + 1, kind + ': no automatic or forced duplicate request');
            assert.equal(await page.evaluate(() => window.__responseExecuted), undefined, kind);
            ++cases;
        }
        const beforeCheck = requests.length;
        await page.locator('#uvm-download-check').click();
        await page.waitForLoadState('domcontentloaded');
        assert.deepEqual(requests.slice(beforeCheck).map(request => request.method), ['GET'], 'Explicit cache check must not replay the POST');
        assert.equal(new URL(page.url()).searchParams.get('uvm_view'), 'driver');
        assert.equal(await page.evaluate(() => window.uvmUI.isBusy()), false);
        ++cases;

        await reset(baseUrl.replace('#download', '&csrf_token=fixture-query-token&uvm_csrf_token=fixture-query-mirror#download'));
        responseKind = 'unknown';
        await submit(); await waitFinished();
        const recovery = new URL(await page.locator('#uvm-download-check').getAttribute('href'));
        assert.equal(recovery.origin, new URL(baseUrl).origin);
        assert.equal(recovery.searchParams.has('csrf_token'), false);
        assert.equal(recovery.searchParams.has('uvm_csrf_token'), false);
        assert.equal(recovery.hash, '');
        ++cases;

        for (theme of ['white', 'black']) for (fontSize of ['62.5%', '100%']) for (const width of [360, 768, 1920, 3840]) {
            await page.setViewportSize({ width, height: 1080 });
            await reset();
            responseKind = 'long-failure';
            await submit(); await waitFinished();
            await assertContained(page, `${theme}, ${fontSize}, ${width}px failure`);
            responseKind = 'long-success';
            await submit(); await waitFinished();
            await page.locator('#uvm-driver-cache-body summary').click();
            await assertContained(page, `${theme}, ${fontSize}, ${width}px cache`);
            assert.equal(await page.locator('#injected-path').count(), 0);
            ++layoutCases;
        }
        theme = 'white'; fontSize = '62.5%'; disabled = true;
        await reset();
        const beforeDisabled = requests.length;
        assert.equal(await page.locator('#uvm-download-form button').isDisabled(), true);
        await page.locator('#uvm-download-form').evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
        assert.equal(requests.length, beforeDisabled, 'An unavailable status must not permit even a forced download');
        ++cases;
        assert.deepEqual(errors, [], 'No browser page errors');
        console.log(`Driver download browser regression passed: ${cases} behavior cases and ${layoutCases} theme/font/viewport cases; no NAS or external network used.`);
    } catch (error) {
        if (page && process.env.DOWNLOAD_TEST_OUTPUT) await page.screenshot({ path: path.join(process.env.DOWNLOAD_TEST_OUTPUT, 'download-failure.png'), fullPage: true });
        throw error;
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exit(1); });
