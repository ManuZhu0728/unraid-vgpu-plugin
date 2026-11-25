// Real localhost nginx auth_request -> PHP-FPM -> Application -> owned curl.
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
    const url = process.argv[2];
    const browser = await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE});
    try {
        const page = await browser.newPage({viewport:{width:1280,height:900}});
        page.setDefaultTimeout(10000);
        const errors=[];
        page.on('pageerror',e=>errors.push(e.message));
        await page.route('**/*', route=>{
            assert.equal(new URL(route.request().url()).origin,new URL(url).origin,'No NAS or external browser request');
            return route.continue();
        });
        await page.goto(url);
        await page.locator('#uvm-download-form button[type=submit]').click();
        await page.waitForFunction(()=>!document.getElementById('uvm-download-cancel').hidden);
        assert.match(await page.locator('#uvm-download-stats').textContent(),/MiB/);
        await page.locator('#uvm-download-cancel').click();
        await page.waitForFunction(()=>document.getElementById('uvm-download-form').getAttribute('aria-busy')==='false');
        assert.match(await page.locator('#uvm-download-message').textContent(),/下载已取消/);
        assert.equal(page.url(),url);
        await page.locator('#uvm-upgrade-check-button').click();
        await page.waitForFunction(()=>!document.getElementById('uvm-upgrade-cancel').hidden);
        assert.match(await page.locator('#uvm-upgrade-progress-phase').textContent(),/新内核预下载.*6\.18\.64/);
        await page.locator('#uvm-upgrade-cancel').click();
        await page.waitForFunction(()=>document.getElementById('uvm-upgrade-check-button').textContent==='继续准备');
        assert.equal(page.url(),url);
        assert.deepEqual(errors,[]);
        console.log('real download gateway browser passed: manual/upgrade progress and acknowledged cancellation; no Unraid/GPU access');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
