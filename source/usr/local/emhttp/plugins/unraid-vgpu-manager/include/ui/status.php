<?php
$uvm_state = is_array($uvm_runtime['runtime'] ?? null) ? $uvm_runtime['runtime'] : [];
$uvm_process_known = !empty($uvm_state['checks']['processes_known']);
$uvm_mdev_known = !empty($uvm_state['checks']['mdevs_known']);
$uvm_manager_pids = is_array($uvm_state['manager_pids'] ?? null) ? $uvm_state['manager_pids'] : [];
$uvm_vgpud_pids = is_array($uvm_state['vgpud_pids'] ?? null) ? $uvm_state['vgpud_pids'] : [];
$uvm_owners = is_array($uvm_state['legacy_owners'] ?? null) ? $uvm_state['legacy_owners'] : [];
$uvm_status_inventory = is_array($uvm_status['device_inventory'] ?? null) ? $uvm_status['device_inventory'] : [];
$uvm_status_inventory_rows = is_array($uvm_status_inventory['devices'] ?? null) ? $uvm_status_inventory['devices'] : [];
$uvm_status_active_count = count(array_filter($uvm_status_inventory_rows, static fn($device) => is_array($device) && ($device['active'] ?? null) === true));
$uvm_status_defined_count = count(array_filter($uvm_status_inventory_rows, static fn($device) => is_array($device) && ($device['defined'] ?? null) === true));
$uvm_log_categories = ['plugin' => '插件升级助手', 'vgpu' => 'vGPU 管理器', 'kernel' => 'NVIDIA 内核'];
$uvm_log_category = $uvm_logs['category'] ?? 'plugin';
if (!is_string($uvm_log_category) || !isset($uvm_log_categories[$uvm_log_category])) $uvm_log_category = 'plugin';
$uvm_upgrade_labels = ['idle' => '尚无升级任务', 'current' => '当前内核缓存已就绪', 'disabled' => '自动更新已关闭', 'downloading' => '正在下载驱动', 'ready' => '驱动已准备好', 'missing' => '目标内核驱动尚未发布', 'error' => '准备失败', 'blocked' => '等待迁移启动入口'];
$uvm_install_labels = ['installed' => '已完成', 'already_active' => '驱动已加载', 'installing' => '正在安装', 'failed' => '失败', 'blocked' => '等待迁移启动入口', 'missing_package' => '缺少匹配包'];
?>
<section class="uvm-panel">
  <h2>运行状态</h2>
  <div class="uvm-scroll"><table>
    <thead><tr><th>项目</th><th>状态</th></tr></thead>
    <tbody>
      <tr><td>vGPU 开机管理</td><td><?=!empty($uvm_runtime_settings['enabled']) ? '开启' : '关闭'?><?php if ($uvm_pending): ?> · 设置待应用<?php endif; ?></td></tr>
      <tr><td>nvidia-vgpu-mgr</td><td><?=!$uvm_process_known ? '读取失败' : ($uvm_manager_pids ? '运行中 · PID ' . uvm_h(implode(', ', $uvm_manager_pids)) : '未运行')?></td></tr>
      <tr><td>nvidia-vgpud</td><td><?=!$uvm_process_known ? '读取失败' : ($uvm_vgpud_pids ? '运行中 · PID ' . uvm_h(implode(', ', $uvm_vgpud_pids)) : '当前无常驻进程')?></td></tr>
      <tr><td>vGPU 配置列表</td><td><?=!empty($uvm_state['profiles_present']) ? '可用' : '未发现'?></td></tr>
      <tr><td>vGPU 设备</td><td><?php if ($uvm_status_inventory): ?><?=!empty($uvm_status_inventory['checks']['active_known']) ? $uvm_status_active_count . ' 个已创建' : '活动设备读取不完整'?> · <?=!empty($uvm_status_inventory['checks']['defined_known']) ? $uvm_status_defined_count . ' 个系统定义' : '系统定义读取不完整'?><?php else: ?><?=$uvm_mdev_known ? count($uvm_state['active_mdevs'] ?? []) . ' 个活动设备' : '读取失败'?><?php endif; ?> · <?=count($uvm_devices)?> 个插件配置<br><span class="uvm-muted">UUID、type 等详情见“vGPU 管理”。</span></td></tr>
      <tr><td>宿主机 CUDA 参数</td><td><code><?=uvm_h(($uvm_state['host_cuda_parameter'] ?? '') !== '' ? $uvm_state['host_cuda_parameter'] : '未知')?></code></td></tr>
    </tbody>
  </table></div>
  <h3>驱动模块</h3>
  <div class="uvm-status">
    <?php foreach (['nvidia', 'nvidia_modeset', 'nvidia_drm', 'nvidia_uvm', 'nvidia_vgpu_vfio', 'mdev'] as $module): ?>
    <span><code><?=uvm_h($module)?></code>：<?=is_dir('/sys/module/' . $module) ? '已加载' : '未加载'?></span>
    <?php endforeach; ?>
  </div>
  <h3>启动入口</h3>
  <?php if ($uvm_owners): ?>
  <p>检测到其他启动入口。迁移开机管理前，请停用对应插件或 User Scripts 任务。</p>
  <ul><?php foreach ($uvm_owners as $owner): ?><li><code><?=uvm_h($owner)?></code></li><?php endforeach; ?></ul>
  <?php else: ?><p class="uvm-muted">未检测到重复的 vGPU 启动入口。</p><?php endif; ?>
  <details><summary>运行详情</summary><pre><?=uvm_h(json_encode(uvm_redact($uvm_runtime), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE))?></pre></details>
  <?php if ($uvm_status_inventory): ?><details><summary>设备清单详情</summary><pre><?=uvm_h(json_encode(uvm_redact($uvm_status_inventory), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE))?></pre></details><?php endif; ?>
</section>

<script>
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('uvm-log-form');
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (window.uvmUI.isBusy()) return;
      // Unraid appends csrf_token to every form. Only these read-only filters
      // belong in the URL, not native or plugin form fields.
      const url = new URL(window.location.href);
      url.search = '';
      url.hash = '';
      url.searchParams.set('uvm_view', 'status');
      const category = form.querySelector('[name="log_category"]').value;
      url.searchParams.set('log_category', ['plugin', 'vgpu', 'kernel'].includes(category) ? category : 'plugin');
      window.location.assign(url.href);
    });
  });
})();
</script>

<section class="uvm-panel">
  <h2>驱动任务</h2>
  <div class="uvm-scroll"><table>
    <thead><tr><th>任务</th><th>状态</th><th>版本 / 内核</th></tr></thead>
    <tbody>
      <tr><td>驱动缓存</td><td><?=$uvm_selected_package ? (($uvm_selected_package['source'] ?? '') === 'local' ? '已导入' : '已下载') : '尚无缓存'?></td><td><?=uvm_h($uvm_selected_package['driver'] ?? '')?><?=isset($uvm_selected_package['kernel']) ? ' · ' . uvm_h($uvm_selected_package['kernel']) : ''?></td></tr>
      <tr><td>升级准备</td><td><?=uvm_h($uvm_upgrade_labels[$uvm_upgrade['status'] ?? 'idle'] ?? ($uvm_upgrade['status'] ?? '尚无升级任务'))?></td><td><?=uvm_h($uvm_upgrade['package']['driver'] ?? '')?><?=!empty($uvm_upgrade['target_kernel']) ? ' · ' . uvm_h($uvm_upgrade['target_kernel']) : ''?></td></tr>
      <tr><td>开机安装</td><td><?=uvm_h($uvm_install_labels[$uvm_installation['status'] ?? ''] ?? ($uvm_installation['status'] ?? '尚无安装记录'))?></td><td><?=uvm_h($uvm_installation['driver'] ?? '')?><?=!empty($uvm_installation['kernel']) ? ' · ' . uvm_h($uvm_installation['kernel']) : ''?></td></tr>
    </tbody>
  </table></div>
  <?php foreach (['升级准备' => $uvm_upgrade, '开机安装' => $uvm_installation] as $task_label => $task): ?>
  <?php if (in_array($task['status'] ?? '', ['error', 'missing', 'failed', 'blocked', 'missing_package'], true) && !empty($task['message'])): ?><p><?=uvm_h($task_label)?>：<?=uvm_h(uvm_redact($task['message']))?></p><?php endif; ?>
  <?php endforeach; ?>
  <details><summary>任务详情</summary><pre><?=uvm_h(json_encode(uvm_redact(['cache' => $uvm_selected_package, 'upgrade' => $uvm_upgrade, 'installation' => $uvm_installation]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE))?></pre></details>
</section>

<section class="uvm-panel">
  <h2>日志</h2>
  <form method="get" id="uvm-log-form">
    <input type="hidden" name="uvm_view" value="status">
    <div class="uvm-actions">
      <label>类别 <select name="log_category" aria-label="日志类别"><?php foreach ($uvm_log_categories as $key => $label): ?><option value="<?=uvm_h($key)?>" <?=$uvm_log_category === $key ? 'selected' : ''?>><?=uvm_h($label)?></option><?php endforeach; ?></select></label>
      <button type="submit">刷新日志</button>
    </div>
  </form>
  <?php if (!empty($uvm_logs['lines'])): ?>
  <pre id="uvm-log-output"><?=uvm_h(implode("\n", $uvm_logs['lines']))?></pre>
  <?php else: ?><p class="uvm-muted"><?=uvm_h($uvm_logs['message'] ?? '日志尚未生成。')?></p><?php endif; ?>
  <?php if (!empty($uvm_logs['truncated'])): ?><p class="uvm-muted">显示最近的匹配记录，最多 200 行；较长内容已截断。</p><?php endif; ?>
</section>
