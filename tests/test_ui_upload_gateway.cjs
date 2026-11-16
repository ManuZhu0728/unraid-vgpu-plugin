// Actual browser -> localhost nginx auth_request -> PHP-FPM -> real Upload/cache.
// No fetch mocking and no external origin, credentials, GPU or NAS access.
const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const [url, packagePath, md5Path, mode] = process.argv.slice(2);
const origin = new URL(url).origin;
assert.equal(new URL(url).hostname, '127.0.0.1', 'Never contact a NAS');
const filename = path.basename(packagePath);
(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } : {}) });
    try {
        const context = await browser.newContext();
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        const errors = [], posts = [];
        page.on('pageerror', e => errors.push(e.message));
        page.on('dialog', dialog => dialog.accept());
        context.on('request', request => {
            if (request.method() === 'POST') posts.push({ fields: new URLSearchParams(request.postData()), type: request.headers()['content-type'] });
        });
        await context.route('**/*', route => {
            assert.equal(new URL(route.request().url()).origin, origin, 'Only the private fixture is allowed');
            return route.continue();
        });
        const count = action => posts.filter(p => p.fields.get('uvm_action') === action).length;
        await page.goto(url);
        await page.locator('#uvm-driver-mode').selectOption('local');
        await page.locator('#uvm-upload-package').setInputFiles(packagePath);
        if (mode !== 'missing-md5') {
            const contents = { 'bad-md5': '0'.repeat(32) + '  ' + filename + '\n', 'empty-md5': '', 'oversized-md5': 'a'.repeat(4097),
                'invalid-md5': 'not an MD5', 'wrong-name-md5': '0'.repeat(32) + '  other-package.txz\n' };
            await page.locator('#uvm-upload-checksum').setInputFiles(Object.hasOwn(contents, mode)
                ? { name: filename + '.md5', mimeType: 'text/plain', buffer: Buffer.from(contents[mode]) } : md5Path);
        }
        const partial = ['cancel', 'cancel-failure', 'cancel-unknown', 'beacon'].includes(mode);
        const firstChunk = partial ? page.waitForRequest(r => r.method() === 'POST' && new URLSearchParams(r.postData()).get('uvm_action') === 'source-upload-chunk') : null;
        await page.locator('#uvm-upload-submit').click();
        if (mode === 'missing-md5') {
            assert.equal(await page.locator('#uvm-upload-checksum').getAttribute('required'), '');
            assert.equal(await page.locator('#uvm-upload-checksum').evaluate(e => e.validity.valueMissing), true);
            assert.equal(posts.length, 0, 'Missing external MD5 must block before a session or POST is created');
        } else if (['empty-md5', 'oversized-md5', 'invalid-md5', 'wrong-name-md5'].includes(mode)) {
            await page.locator('#uvm-browser-message-driver').waitFor({ state: 'visible' });
            assert.match(await page.locator('#uvm-browser-message-driver').innerText(), /MD5/);
            assert.equal(posts.length, 0, 'Invalid independent MD5 file must be rejected before staging');
            assert.equal(await page.evaluate(() => window.uvmUI.isBusy()), false);
        } else if (partial) {
            await firstChunk;
            if (mode === 'beacon') {
                // A real navigation triggers pagehide/sendBeacon, not a synthetic fetch.
                await page.goto(url + '?left=1');
                await page.waitForTimeout(700);
            } else {
                await page.locator('#uvm-upload-cancel').click();
                if (mode === 'cancel') {
                    await page.waitForFunction(() => document.getElementById('uvm-upload-progress').textContent === '已取消。');
                    assert.equal(await page.evaluate(() => window.uvmUI.isBusy()), false);
                    assert.equal(await page.locator('#uvm-upload-submit').isDisabled(), false);
                } else {
                    await page.locator('#uvm-upload-check').waitFor({ state: 'visible' });
                    assert.equal(await page.evaluate(() => window.uvmUI.isBusy()), true);
                    assert.equal(await page.locator('#uvm-upload-submit').isDisabled(), true);
                }
            }
            assert.equal(count('source-upload-begin'), 1);
            assert.equal(count('source-upload-chunk'), 1, 'Cancellation stops sending new chunks');
            assert.equal(count('source-upload-finish'), 0, 'Cancellation must never finalize the package');
            // Playwright does not expose every keepalive request after pagehide;
            // the Python harness verifies the actual server-side beacon instead.
            if (mode !== 'beacon') assert.equal(count('source-upload-cancel'), 1, 'Cancellation is attempted once without a hidden retry');
        } else if (mode === 'success') {
            await page.waitForFunction(name => document.getElementById('uvm-cache-list').textContent.includes(name), filename);
            assert.match(await page.locator('#uvm-driver-cache-body').innerText(), /构建 3/);
            assert.match(await page.locator('#uvm-cache-list').innerText(), /构建 4/);
            assert.equal(count('source-upload-cancel'), 0);
            assert.equal(count('source-upload-begin'), 1);
            assert.ok(count('source-upload-chunk') >= 3, 'Actual PHP post_max_size must split the binary into multiple requests');
            assert.equal(count('source-upload-finish'), 1);
            await page.reload();
            assert.match(await page.locator('#uvm-driver-cache-body').innerText(), /构建 3/);
            assert.match(await page.locator('#uvm-cache-list').innerText(), /构建 4/);
            assert.equal(count('source-upload-finish'), 1, 'Fresh GET must not replay finalization');
            const candidate = page.locator('#uvm-cache-list tr[data-cache-filename="' + filename + '"]');
            await candidate.locator('.uvm-submit-form button').click();
            await page.waitForFunction(name => document.getElementById('uvm-driver-cache-body').textContent.includes(name), filename);
            assert.match(await page.locator('#uvm-driver-cache-body').innerText(), /构建 4/);
            assert.equal(count('cache-select'), 1, 'Only the explicit native form changes the boot selection');
            assert.equal(count('source-upload-finish'), 1, 'Selection is not another import');
        } else if (mode === 'bad-md5') {
            await page.waitForFunction(() => document.getElementById('uvm-upload-progress').textContent === '导入未完成。' && !window.uvmUI.isBusy());
            assert.match(await page.locator('#uvm-browser-message-driver').innerText(), /MD5/);
            assert.equal(await page.locator('#uvm-upload-submit').isDisabled(), false);
            assert.equal(count('source-upload-finish'), 1);
            assert.equal(count('source-upload-cancel'), 1, 'Known failed import must clean only its staging session');
            assert.match(await page.locator('#uvm-driver-cache-body').innerText(), /构建 3/);
        } else if (mode === 'auth-expired') {
            await page.locator('#uvm-upload-check').waitFor({ state: 'visible' });
            assert.equal(await page.evaluate(() => window.uvmUI.isBusy()), true);
            assert.equal(await page.locator('#uvm-upload-submit').isDisabled(), true);
            assert.equal(count('source-upload-begin'), 1);
            assert.equal(count('source-upload-chunk'), 1);
            assert.equal(count('source-upload-finish'), 0);
            assert.equal(count('source-upload-cancel'), 1, 'Failed cleanup stays unknown without retry');
        } else if (mode === 'finish-unknown') {
            await page.locator('#uvm-upload-check').waitFor({ state: 'visible' });
            assert.equal(await page.evaluate(() => window.uvmUI.isBusy()), true);
            assert.equal(await page.locator('#uvm-upload-submit').isDisabled(), true);
            assert.match(await page.locator('#uvm-upload-progress').innerText(), /结果未确认/);
            assert.equal(count('source-upload-finish'), 1);
            assert.equal(count('source-upload-cancel'), 0, 'An unknown finish may already be committed; do not cancel or retry it');
            await page.locator('#uvm-upload-check').click();
            await page.waitForFunction(name => document.getElementById('uvm-cache-list').textContent.includes(name), filename);
            assert.match(await page.locator('#uvm-driver-cache-body').innerText(), /构建 3/);
            assert.equal(count('source-upload-finish'), 1, 'Recovery is a GET, never a second import');
        } else {
            throw new Error('Unknown upload browser test mode: ' + mode);
        }
        for (const post of posts) {
            assert.match(post.type, /^application\/x-www-form-urlencoded\b/i, 'Every control, chunk and beacon must pass native auth without multipart');
            assert.equal(post.fields.get('csrf_token'), 'fixture-upload-csrf');
            assert.equal(post.fields.get('uvm_csrf_token'), 'fixture-upload-csrf');
            assert.equal(post.fields.get('uvm_tab'), 'driver');
            assert.equal(post.fields.has('path'), false);
            if (post.fields.get('uvm_action') === 'source-upload-chunk') {
                assert.match(post.fields.get('chunk_data'), /^[A-Za-z0-9_-]+$/);
                assert.ok(post.fields.get('chunk_data').length <= 196608, 'Respect the real 256K PHP post limit including base64 overhead');
            }
        }
        assert.deepEqual(errors, []);
        console.log('real upload gateway browser passed: ' + mode + ' (' + posts.length + ' URL-encoded POSTs)');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
