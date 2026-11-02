<?php
// Fixed native tab entrypoint, also usable when opening a child page directly.
if (!in_array($uvm_tab ?? '', ['driver', 'vgpu', 'status'], true)) return;
require_once __DIR__ . '/ui/common.php';
if (!isset($uvm_request_initialized)) require __DIR__ . '/ui/state.php';
if ($uvm_upload_request) return;
if ($uvm_tab === 'status' && !isset($uvm_logs)) {
    $category = $_GET['log_category'] ?? 'plugin';
    if (!is_string($category) || !in_array($category, ['plugin', 'vgpu', 'kernel'], true)) $category = 'plugin';
    try {
        $uvm_logs = uvm_cli('logs', ['category' => $category]);
    } catch (Throwable $exception) {
        $uvm_logs = ['category' => $category, 'available' => false, 'lines' => [], 'message' => '日志读取失败：' . uvm_redact($exception->getMessage())];
    }
}

$uvm_render_shared = !isset($uvm_ui_assets_rendered);
$uvm_ui_assets_rendered = true;
?>
<?php if ($uvm_render_shared): ?>
<style>
.uvm-wrap [hidden] { display: none !important; }
.uvm-wrap { max-width: 1400px; margin: 18px 0; }
.uvm-wrap h2 { margin: 0 0 12px; font-size: 1.2em; }
.uvm-wrap h3 { margin: 20px 0 10px; font-size: 1.05em; }
.uvm-panel { border: 1px solid #8885; border-radius: 5px; padding: 18px; margin: 16px 0; }
.uvm-note { padding: 12px 15px; margin: 12px 0; border-left: 4px solid #7596b4; background: #7596b412; line-height: 1.6; }
.uvm-warning { border-left-color: #d29b36; background: #d29b3612; }
.uvm-error { border-left-color: #c75555; background: #c7555512; white-space: pre-wrap; }
.uvm-success { border-left-color: #548c65; background: #548c6512; }
.uvm-muted { opacity: .75; font-size: .94em; }
.uvm-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px 22px; }
.uvm-field { display: block; }
.uvm-field > span { display: block; margin-bottom: 5px; }
.uvm-field input:not([type=checkbox]), .uvm-field select, .uvm-field textarea { width: 100%; box-sizing: border-box; min-height: 32px; }
.uvm-field small { display: block; margin: 5px 0; line-height: 1.45; opacity: .8; }
.uvm-field textarea { min-height: 180px; font-family: monospace; }
.uvm-wrap fieldset { border: 0; padding: 0; margin: 0; min-width: 0; }
.uvm-wrap fieldset:disabled { opacity: .65; }
.uvm-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 14px; }
.uvm-actions form, .uvm-inline-form { display: inline-block; margin: 0; }
.uvm-scroll { overflow-x: auto; }
.uvm-wrap table { width: 100%; border-collapse: collapse; margin: 12px 0; }
.uvm-wrap th, .uvm-wrap td { padding: 9px; text-align: left; vertical-align: top; border-bottom: 1px solid #8884; white-space: normal; }
.uvm-wrap code, .uvm-wrap pre { overflow-wrap: anywhere; }
.uvm-wrap pre { white-space: pre-wrap; margin: 10px 0; padding: 12px; background: #8881; max-height: 450px; overflow: auto; }
.uvm-status { display: flex; gap: 22px; flex-wrap: wrap; margin: 10px 0; }
.uvm-status span { min-width: 170px; }
.uvm-wrap button { margin: 0; }
.uvm-wrap summary { cursor: pointer; }
.uvm-form-busy { opacity: .65; }
.uvm-profile-current { background: #7596b418; }
.uvm-advanced { margin-top: 16px; }
.uvm-advanced > .uvm-grid { margin-top: 14px; }
#uvm-upload-meter { width: 100%; margin-top: 12px; }
</style>
<?php endif; ?>


<div class="uvm-wrap" id="uvm-manager-<?=uvm_h($uvm_tab)?>" data-tab="<?=uvm_h($uvm_tab)?>">
  <div class="uvm-status uvm-panel" aria-label="GPU 概览">
    <span>GPU：<?=uvm_h(implode('、', $uvm_gpu_labels) ?: '未检测到')?></span>
    <span>驱动：<code><?=uvm_h(($uvm_snapshot['loaded_driver'] ?? '') ?: (uvm_read('/sys/module/nvidia/version') ?: '未加载'))?></code></span>
    <span>内核：<code><?=uvm_h(($uvm_snapshot['kernel'] ?? '') ?: php_uname('r'))?></code></span>
    <span>vGPU：<?=!empty($uvm_snapshot['checks']['mdevs_known']) ? count($uvm_snapshot['active_mdevs'] ?? []) . ' 个已创建' : '状态未知'?></span>
  </div>
  <?php if ($uvm_error !== '' && ($uvm_action === '' || $uvm_request_tab === $uvm_tab)): ?><div class="uvm-note uvm-error" role="alert"><?=uvm_h($uvm_error)?></div><?php endif; ?>
  <?php if ($uvm_message !== '' && $uvm_request_tab === $uvm_tab): ?><div class="uvm-note uvm-success" role="status"><?=uvm_h($uvm_message)?></div><?php endif; ?>
  <?php if ($uvm_csrf === ''): ?><div class="uvm-note uvm-error">页面会话已失效，请重新打开插件页面。</div><?php endif; ?>
  <div id="uvm-browser-message-<?=uvm_h($uvm_tab)?>" class="uvm-note" role="status" hidden></div>
  <?php if ($uvm_tab === 'vgpu' && $uvm_pending): ?><div class="uvm-note">vGPU 启动设置有待应用的更改。</div><?php endif; ?>
<?php if ($uvm_render_shared): ?>
<script>
(function () {
  'use strict';
  let busy = false;
  window.uvmUI = {
    isBusy: function () { return busy; },
    setBusy: function (value) { busy = Boolean(value); },
    forTab: function (tab) {
      return {
        isBusy: window.uvmUI.isBusy,
        setBusy: window.uvmUI.setBusy,
        message: function (text, error) {
          const notice = document.getElementById('uvm-browser-message-' + tab);
          notice.textContent = text;
          notice.hidden = false;
          notice.className = error ? 'uvm-note uvm-error' : 'uvm-note';
        }
      };
    }
  };
  const preferredTab = <?=uvm_json($uvm_preferred_tab)?>;
  if (preferredTab) document.addEventListener('DOMContentLoaded', function () {
    const index = {driver: 1, vgpu: 2, status: 3}[preferredTab];
    const root = document.getElementById('uvm-manager-' + preferredTab);
    const panel = root && root.closest('[role="tabpanel"]');
    const nativeTab = panel ? document.querySelector('[role="tab"][aria-controls="' + panel.id + '"]') : document.getElementById('tab' + index);
    if (nativeTab) nativeTab.click();
  });
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/ui/' . $uvm_tab . '.php'; ?>
  <?php if ($uvm_action_data !== null && $uvm_request_tab === $uvm_tab): ?>
  <details class="uvm-advanced"><summary>操作详情</summary><pre><?=uvm_h(json_encode(uvm_redact($uvm_action_data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE))?></pre></details>
  <?php endif; ?>
</div>
<script>
(function () {
  'use strict';
  const root = document.getElementById(<?=uvm_json('uvm-manager-' . $uvm_tab)?>);
  const ui = window.uvmUI.forTab(<?=uvm_json($uvm_tab)?>);
  root.querySelectorAll('.uvm-submit-form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (event.defaultPrevented) return;
      if (ui.isBusy()) { event.preventDefault(); return; }
      if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) { event.preventDefault(); return; }
      ui.setBusy(true);
      form.classList.add('uvm-form-busy');
      ui.message('正在处理…', false);
    });
  });
})();
</script>
