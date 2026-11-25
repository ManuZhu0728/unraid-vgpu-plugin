// Real rendered UI and official styles; every HTTP request is intercepted locally.
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..');
const php = process.env.PHP_BIN;
const styles = process.env.WEBGUI_STYLES;
const prefix = path.join(root, 'source/usr/local/emhttp/plugins/unraid-vgpu-manager');
const defaults = JSON.parse(execFileSync(php, ['-n', '-r', 'require $argv[1]; echo json_encode(Vgpu\\Store::defaults());', path.join(prefix, 'include/bootstrap.php')], {encoding: 'utf8'}));
defaults.source.url = 'https://driver.example.test/owner/repo';
defaults.source.repository = defaults.source.url;
defaults.source.provider = 'gitlab';
const base = 'https://unraid.example.test/Settings/unraid-vgpu-manager?uvm_view=driver';
const css = ['default-color-palette.css','default-base.css','default-dynamix.css'].map(file => readFileSync(path.join(styles,file),'utf8')).join('\n');
const fonts = ['clear-sans','bitstream'].flatMap(family => [['','normal'],['-bold','bold']].map(([suffix,weight]) => `@font-face{font-family:${family};font-weight:${weight};src:url(data:font/woff;base64,${readFileSync(path.join(styles,family+suffix+'.woff')).toString('base64')})}`)).join('\n');
const marker = (id, data) => '<main><script>window.BAD_RESPONSE_EXECUTED=true</script><script type="application/json" id="'+id+'">'+JSON.stringify(data).replace(/</g,'\\u003c')+'</script></main>';
const makeTask = (scope='baseline') => ({id:'a'.repeat(32),kind:scope==='manual'?'manual':'upgrade',status:'active',scope,phase:'downloading',
    kernel:scope==='target'?'6.18.64-Unraid':'6.18.58-Unraid',filename:'',downloaded_bytes:67108864,total_bytes:268435456,
    speed_bytes_per_second:1048576,cancellable:true,cancel_requested:false});
let task = makeTask();
let upgrade = {};
let theme = 'white', size = '62.5%', manual = false, cancelMode = 'accepted';
let releaseDownload, releaseUpgrade;
const calls = [];
function render() {
    const fixture = {single_tab:'driver',status:{settings:{...defaults,upgrade:{enabled:!manual,driver_branch:'595'}},devices:[],
        runtime:{ok:true,runtime:{kernel:'6.18.58-Unraid',loaded_driver:'595.58.03'}},upgrade,download_task:task}};
    const output = JSON.parse(execFileSync(php,['-n',path.join(root,'tests/test_ui.php'),'--render-fixture'],{input:JSON.stringify(fixture),encoding:'utf8'}));
    return '<!doctype html><html style="--custom-font-size:'+size+'"><meta charset="utf-8"><style>'+css+'\n'+readFileSync(path.join(styles,'themes',theme+'.css'),'utf8')+'\n'+fonts+'\n#fixture-driver-panel{display:block}</style><body>'+output.html+'</body></html>';
}
(async () => {
    const browser = await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE});
    const page = await browser.newPage({viewport:{width:1920,height:1080}});
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    page.setDefaultTimeout(10000);
    let revision = 0, layouts = 0;
    try {
        await page.route('**/*', async route => {
            const request = route.request();
            assert.equal(new URL(request.url()).origin,new URL(base).origin,'Never contact a NAS or external network');
            if (request.method()==='GET') return route.fulfill({contentType:'text/html',body:render()});
            const fields = new URLSearchParams(request.postData()||'');
            calls.push(Object.fromEntries(fields));
            assert.match(request.headers()['content-type'],/^application\/x-www-form-urlencoded/);
            assert.equal(fields.get('csrf_token'),'fixture-ui-csrf');
            assert.equal(fields.get('uvm_csrf_token'),'fixture-ui-csrf');
            const action = fields.get('uvm_action');
            if (action==='download-status') return route.fulfill({contentType:'text/html',body:marker('uvm-download-control-result',{ok:true,data:{ok:true,task,upgrade}})});
            if (action==='download-cancel') {
                assert.equal(fields.get('job_id'),task.id);
                if (cancelMode==='network') return route.abort('connectionreset');
                if (cancelMode==='stale') return route.fulfill({contentType:'text/html',body:marker('uvm-download-control-result',{ok:true,data:{ok:true,accepted:false,task:{...task,id:'b'.repeat(32)}}})});
                task = {...task,cancel_requested:true};
                return route.fulfill({contentType:'text/html',body:marker('uvm-download-control-result',{ok:true,data:{ok:true,accepted:true,task}})});
            }
            if (action==='source-download') {
                task = {...makeTask('manual'),id:fields.get('download_job_id')};
                assert.match(task.id,/^[a-f0-9]{32}$/);
                return new Promise(resolve => { releaseDownload = async () => { await route.fulfill({contentType:'text/html',body:marker('uvm-download-result',{ok:true,outcome:'cancelled'})}); resolve(); }; });
            }
            assert.equal(action,'upgrade-check');
            assert.equal(fields.get('uvm_upgrade_async'),'1');
            task = makeTask('target');
            return new Promise(resolve => { releaseUpgrade = async () => { await route.fulfill({contentType:'text/html',body:marker('uvm-upgrade-result',{ok:true,data:upgrade})}); resolve(); }; });
        });
        const reset = async () => { await page.goto(base+'&fixture='+String(++revision)); await page.evaluate(() => document.fonts.ready); };
        upgrade = {status:'downloading',phase:'baseline',current_kernel:'6.18.58-Unraid',target_kernel:'6.18.64-Unraid'};
        await reset();
        assert.match(await page.locator('#uvm-upgrade-state').textContent(),/正在准备当前内核恢复缓存.*6\.18\.58/);
        assert.match(await page.locator('#uvm-upgrade-progress-phase').textContent(),/当前内核恢复缓存/);
        assert.equal(await page.locator('#uvm-upgrade-meter').getAttribute('value'),'0.25');
        assert.match(await page.locator('#uvm-upgrade-stats').textContent(),/64\.0 MiB \/ 256\.0 MiB（25%）/);
        await page.locator('#uvm-upgrade-cancel').click();
        assert.match(await page.locator('#uvm-upgrade-progress-phase').textContent(),/等待任务确认/);
        assert.equal(task.status,'active','An accepted request must not claim completed cancellation');
        task = {...task,status:'cancelled',cancellable:false};
        upgrade = {...upgrade,status:'cancelled',message:'用户取消'};
        await page.waitForFunction(() => document.getElementById('uvm-upgrade-check-button').textContent==='继续准备');
        assert.equal(calls.filter(c=>c.uvm_action==='upgrade-check').length,0,'No automatic restart after cancellation');
        const originalUrl = page.url();
        await page.locator('#uvm-upgrade-check-button').click();
        await page.waitForFunction(() => window.uvmUI.isBusy());
        assert.equal(page.url(),originalUrl,'Continue preparation stays on the current interactive page');
        assert.equal(calls.filter(c=>c.uvm_action==='upgrade-check').length,1);
        task = {...task,status:'completed',cancellable:false};
        upgrade = {status:'ready',phase:'target',target_kernel:'6.18.64-Unraid',boot_ready:true};
        await releaseUpgrade();
        await page.waitForFunction(() => !window.uvmUI.isBusy());
        assert.match(await page.locator('#uvm-upgrade-state').textContent(),/驱动已准备好/);

        task = makeTask(); upgrade = {status:'downloading',phase:'baseline',current_kernel:'6.18.58-Unraid'};
        cancelMode='network'; await reset();
        await page.locator('#uvm-upgrade-cancel').click();
        assert.match(await page.locator('#uvm-upgrade-stats').textContent(),/未能确认取消结果/);
        assert.equal(task.cancel_requested,false);
        cancelMode='stale'; await reset();
        await page.locator('#uvm-upgrade-cancel').click();
        assert.match(await page.locator('#uvm-upgrade-stats').textContent(),/未能确认取消结果/);
        assert.equal(task.status,'active');

        cancelMode='accepted'; task={...makeTask(),phase:'publishing',cancellable:false}; await reset();
        assert.equal(await page.locator('#uvm-upgrade-cancel').isDisabled(),true);
        task={...makeTask('target'),total_bytes:null}; upgrade={status:'downloading',phase:'target',target_kernel:'6.18.64-Unraid'}; await reset();
        assert.equal(await page.locator('#uvm-upgrade-meter').getAttribute('value'),null);
        assert.match(await page.locator('#uvm-upgrade-stats').textContent(),/总大小未知/);

        for (theme of ['white','black']) for (size of ['62.5%','75%']) for (const width of [1920,360]) for (const scope of ['baseline','target']) {
            task=makeTask(scope); upgrade={status:'downloading',phase:scope,current_kernel:'6.18.58-Unraid',target_kernel:'6.18.64-Unraid'};
            await page.setViewportSize({width,height:1080}); await reset();
            const viewport=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth}));
            assert.ok(viewport.scroll<=viewport.width+1,'Active progress/cancel stays within '+theme+'/'+size+'/'+width+'/'+scope);
            assert.equal(await page.locator('#uvm-upgrade-cancel').isVisible(),true);
            layouts++;
        }
        manual=true; task={status:'idle'}; upgrade={status:'idle'};
        await page.setViewportSize({width:1280,height:1080}); await reset();
        const url=page.url();
        await page.locator('#uvm-download-form button[type=submit]').click();
        await page.waitForFunction(()=>!document.getElementById('uvm-download-cancel').hidden);
        assert.equal(await page.locator('#uvm-download-meter').getAttribute('value'),'0.25');
        await page.locator('#uvm-download-cancel').click();
        task={...task,status:'cancelled',cancellable:false};
        await releaseDownload();
        await page.waitForFunction(()=>document.getElementById('uvm-download-form').getAttribute('aria-busy')==='false');
        assert.match(await page.locator('#uvm-download-message').textContent(),/下载已取消/);
        assert.equal(page.url(),url);
        assert.equal(await page.evaluate(()=>window.BAD_RESPONSE_EXECUTED),undefined,'Response wrapper scripts are never executed');
        assert.deepEqual(errors,[]);
        console.log('Download progress/cancel browser passed: 9 control scenarios and '+layouts+' active theme/font/viewport layouts; no NAS used.');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
