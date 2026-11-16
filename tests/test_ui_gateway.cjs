// Real localhost HTTP only: no mocked POST or replacement success receipts.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const url = process.argv[2];
assert.equal(new URL(url).hostname, '127.0.0.1', 'Never contact a NAS');
const free = 'nvidia-595.58.03-6.18.58-Unraid-2.txz';
const selected = 'nvidia-595.58.03-6.18.58-Unraid-3.txz';
(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } : {}) });
    try {
        const page = await browser.newPage();
        page.setDefaultTimeout(10000);
        const errors = [], posts = [];
        page.on('pageerror', e => errors.push(e.message));
        page.on('dialog', dialog => dialog.accept());
        await page.route('**/*', route => {
            const request = route.request();
            assert.equal(new URL(request.url()).origin, new URL(url).origin);
            if (request.method() === 'POST') posts.push({body: request.postData(), type: request.headers()['content-type']});
            return route.continue();
        });
        const row = filename => page.locator('#uvm-cache-list tr[data-cache-filename="' + filename + '"]');
        await page.goto(url);
        assert.equal(await row(free).count(), 1);
        assert.equal(await row(selected).locator('button').isDisabled(), true);
        await page.locator('#uvm-source-url').fill('https://unsaved.example.test/中文?a=1&b=2');
        await page.locator('#uvm-credential-token').fill('unsaved-fixture-secret');
        await row(free).locator('button').click();
        await page.waitForFunction(() => document.getElementById('uvm-cache-message').classList.contains('uvm-success'));
        assert.equal(await row(free).count(), 0);
        assert.equal(await row(selected).locator('button').isDisabled(), true);
        assert.equal(page.url(), url);
        assert.equal(posts.length, 1, 'One explicit deletion, no automatic retry');
        assert.match(posts[0].type, /^application\/x-www-form-urlencoded\b/i);
        const fields = new URLSearchParams(posts[0].body);
        assert.equal(fields.get('uvm_action'), 'cache-delete');
        assert.equal(fields.get('cache_filename'), free);
        assert.equal(fields.get('uvm_cache_delete_async'), '1');
        assert.deepEqual(fields.getAll('csrf_token'), ['fixture-delete-csrf', 'fixture-delete-csrf']);
        assert.equal(fields.get('uvm_csrf_token'), 'fixture-delete-csrf');
        assert.ok(!posts[0].body.includes('unsaved') && !posts[0].body.includes('credential_token'));
        assert.match(await page.locator('#uvm-source-url').inputValue(), /unsaved/);
        await page.reload();
        assert.equal(await row(free).count(), 0, 'A fresh server GET must not resurrect the deleted row');
        assert.equal(await row(selected).locator('button').isDisabled(), true);
        assert.equal(posts.length, 1, 'Refreshing must not replay the POST');
        assert.deepEqual(errors, []);
        console.log('real gateway browser passed: URL encoding, duplicate native CSRF, actual TXZ/MD5 deletion, protected selection and fresh GET');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
