<?php
$uvm_runtime_snapshot = is_array($uvm_runtime['runtime'] ?? null) ? $uvm_runtime['runtime'] : [];
$uvm_mdevs_known = ($uvm_runtime_snapshot['checks']['mdevs_known'] ?? false) === true;
$uvm_active_mdevs = is_array($uvm_runtime_snapshot['active_mdevs'] ?? null) ? $uvm_runtime_snapshot['active_mdevs'] : [];
$uvm_inventory = is_array($uvm_status['device_inventory'] ?? null) ? $uvm_status['device_inventory'] : [];
$uvm_inventory_rows = is_array($uvm_inventory['devices'] ?? null) ? $uvm_inventory['devices'] : [];
$uvm_inventory_checks = is_array($uvm_inventory['checks'] ?? null) ? $uvm_inventory['checks'] : ['active_known' => $uvm_mdevs_known, 'defined_known' => false];
$uvm_inventory_messages = is_array($uvm_inventory['messages'] ?? null) ? $uvm_inventory['messages'] : [];
$uvm_readonly_panels = [];
// Older backend responses still expose saved configurations; keep their controls usable.
if (!$uvm_inventory) {
    foreach ($uvm_devices as $device) {
        if (!is_array($device)) continue;
        $actual = $uvm_active_mdevs[$device['uuid'] ?? ''] ?? null;
        $uvm_inventory_rows[] = $device + [
            'active' => is_array($actual) ? true : ($uvm_mdevs_known ? false : null),
            'defined' => null, 'managed' => true, 'configuration' => $device,
            'active_type' => is_array($actual) ? ($actual['type'] ?? '') : '',
            'defined_type' => '', 'configured_type' => $device['type'] ?? '',
        ];
    }
}
?>
<section class="uvm-panel">
  <h2>已有 vGPU 设备</h2>
  <?php if (!$uvm_inventory_rows): ?>
  <p class="uvm-muted"><?=!empty($uvm_inventory_checks['active_known']) && !empty($uvm_inventory_checks['defined_known']) ? '未发现 vGPU 设备。' : '设备列表暂不完整。'?></p>
  <?php else: ?>
  <div class="uvm-scroll"><table id="uvm-inventory-table"><colgroup><col style="width:22%"><col style="width:7%"><col style="width:14%"><col style="width:10%"><col style="width:10%"><col style="width:5%"><col style="width:32%"></colgroup><thead><tr><th>UUID / VM XML</th><th>type</th><th>配置名称 / 显存</th><th>物理 GPU / PCI</th><th>状态</th><th>来源</th><th>操作</th></tr></thead><tbody>
  <?php foreach ($uvm_inventory_rows as $device):
    if (!is_array($device)) continue;
    $uuid = (string)($device['uuid'] ?? '');
    $pci = (string)($device['pci'] ?? '');
    $validUuid = preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD', $uuid) === 1;
    $configuration = is_array($device['configuration'] ?? null) ? $device['configuration'] : null;
    $managed = !empty($device['managed']) && $configuration !== null;
    $active = is_bool($device['active'] ?? null) ? $device['active'] : null;
    $defined = is_bool($device['defined'] ?? null) ? $device['defined'] : null;
    $statusLabel = $active === true ? '已创建' : ($active === false ? ($defined === true ? '已定义，未创建' : '未创建') : '状态未知');
    $typeVersions = array_values(array_unique(array_filter([
        $device['active_type'] ?? '', $device['defined_type'] ?? '', $device['configured_type'] ?? '',
    ], static fn($value) => is_string($value) && $value !== '')));
    $identityDiffers = count($typeVersions) > 1 || ($active === true && ($device['active_type'] ?? '') === '' && $typeVersions) || ($configuration !== null && ($device['profile'] ?? '') !== '' && ($configuration['profile'] ?? '') !== ($device['profile'] ?? ''));
    $activeTypeLabel = $active === true ? (($device['active_type'] ?? '') ?: '未知') : ($active === false ? '未创建' : '未知');
    $definedTypeLabel = $defined === true ? (($device['defined_type'] ?? '') ?: '未知') : ($defined === false ? '无' : '未知');
    $xml = "<hostdev mode='subsystem' type='mdev' managed='no' model='vfio-pci' display='off' ramfb='off'>\n  <source>\n    <address uuid='" . $uuid . "'/>\n  </source>\n</hostdev>";
    $panelId = 'uvm-device-' . hash('sha256', $pci . '|' . $uuid);
    $uvm_readonly_panels[] = compact('panelId', 'pci', 'uuid', 'validUuid', 'configuration', 'managed', 'defined', 'active', 'definedTypeLabel', 'activeTypeLabel', 'statusLabel', 'identityDiffers', 'xml') + ['definition_start' => $device['definition_start'] ?? ''];
  ?>
    <tr data-uuid="<?=uvm_h($uuid)?>" data-pci="<?=uvm_h($pci)?>" data-device-source="<?=$managed ? 'plugin' : 'system'?>">
      <td><code><?=uvm_h($uuid)?></code><?php if ($validUuid): ?><br><button type="button" class="uvm-toggle-xml uvm-readonly-toggle" id="<?=uvm_h($panelId)?>-xml-control" data-device-key="<?=uvm_h($pci . '|' . $uuid)?>" aria-controls="<?=uvm_h($panelId)?>-xml" aria-expanded="false">VM XML</button><?php endif; ?></td>
      <td><code><?=uvm_h(($device['type'] ?? '') ?: '未知')?></code><?php if ($identityDiffers): ?><details><summary>配置差异</summary><p>当前设备：<code><?=uvm_h($activeTypeLabel)?></code><br>系统定义：<code><?=uvm_h($definedTypeLabel)?></code><br>插件保存：<code><?=uvm_h(($device['configured_type'] ?? '') ?: '无')?></code><?php if ($configuration !== null): ?><br>插件配置：<?=uvm_h($configuration['profile'] ?? '')?><?php endif; ?></p></details><?php endif; ?></td>
      <td><?=uvm_h(($device['profile'] ?? '') ?: '未知')?><?php if (($device['framebuffer'] ?? '') !== ''): ?><br><span class="uvm-muted"><?=uvm_h($device['framebuffer'])?></span><?php endif; ?></td>
      <td><code><?=uvm_h($pci)?></code><?php if (($device['iommu_group'] ?? null) !== null): ?><br><span class="uvm-muted">IOMMU <?=uvm_h($device['iommu_group'])?></span><?php endif; ?></td>
      <td><?=$statusLabel?><?php if ($defined === true): ?><br><span class="uvm-muted">系统定义 · <?=($device['definition_start'] ?? '') === 'auto' ? '自动启动' : (($device['definition_start'] ?? '') === 'manual' ? '手动启动' : '启动方式未知')?></span><?php elseif ($defined === null): ?><br><span class="uvm-muted">系统定义未知</span><?php endif; ?></td>
      <td><?=$managed ? '插件配置' : '系统设备'?></td>
      <td><div class="uvm-actions" style="margin-top:0">
        <button type="button" class="uvm-toggle-details uvm-readonly-toggle" id="<?=uvm_h($panelId)?>-details-control" data-device-key="<?=uvm_h($pci . '|' . $uuid)?>" aria-controls="<?=uvm_h($panelId)?>-details" aria-expanded="false">详情</button>
        <?php if ($managed): ?>
        <?php foreach (['device-start' => '启动', 'device-stop' => '停止', 'device-remove' => '移除'] as $action => $label): ?>
        <form method="post" class="uvm-submit-form" <?=in_array($action, ['device-stop', 'device-remove'], true) ? 'data-confirm="请确认使用此设备的虚拟机已关闭。继续？"' : ''?>><?php uvm_form_hidden($action, $uvm_csrf, ['uuid' => $uuid]); ?><button type="submit" <?=!$uvm_controls || !$validUuid || $active === null || ($action === 'device-start' && ($active === true || empty($uvm_runtime_settings['enabled']))) || ($action === 'device-stop' && $active !== true) ? 'disabled' : ''?>><?=uvm_h($label)?></button></form>
        <?php endforeach; ?>
        <?php endif; ?>
      </div></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php foreach ($uvm_readonly_panels as $panel): ?>
  <section class="uvm-readonly-panel uvm-device-details" id="<?=uvm_h($panel['panelId'])?>-details" data-device-key="<?=uvm_h($panel['pci'] . '|' . $panel['uuid'])?>" aria-labelledby="<?=uvm_h($panel['panelId'])?>-details-title" tabindex="-1" hidden>
    <h3 id="<?=uvm_h($panel['panelId'])?>-details-title">设备详情：<?=uvm_h($panel['pci'])?> · <?=uvm_h($panel['uuid'])?></h3>
    <button type="button" class="uvm-close-readonly" data-readonly-control="<?=uvm_h($panel['panelId'])?>-details-control">关闭详情</button>
    <p>UUID：<code><?=uvm_h($panel['uuid'])?></code><br>物理 GPU / PCI：<code><?=uvm_h($panel['pci'])?></code><br>来源：<?=$panel['managed'] ? '插件配置' : '系统设备'?></p>
    <p>插件保存：<?php if ($panel['configuration'] !== null): ?><code><?=uvm_h($panel['configuration']['pci'] ?? '')?></code> · <?=uvm_h($panel['configuration']['profile'] ?? '')?> · <code><?=uvm_h(($panel['configuration']['type'] ?? '') ?: '未记录')?></code><?php else: ?>未保存<?php endif; ?></p>
    <p>系统定义：<code><?=uvm_h($panel['definedTypeLabel'])?></code><?php if ($panel['defined'] === true): ?> · <code><?=uvm_h($panel['pci'])?></code> · <?=$panel['definition_start'] === 'auto' ? '自动启动' : ($panel['definition_start'] === 'manual' ? '手动启动' : '启动方式未知')?><?php endif; ?></p>
    <p>当前运行：<?=$panel['statusLabel']?> · <code><?=uvm_h($panel['activeTypeLabel'])?></code><?php if ($panel['active'] === true): ?> · <code><?=uvm_h($panel['pci'])?></code><?php endif; ?></p>
    <p class="uvm-muted"><?=$panel['identityDiffers'] ? '保存、定义或运行身份存在差异；查看详情不会修改或修复设备。' : '此处仅供查看，不会保存、启动或重新配置设备。'?></p>
    <?php if ($panel['validUuid']): ?><p>VM XML</p><pre class="uvm-detail-xml"><?=uvm_h($panel['xml'])?></pre><?php endif; ?>
  </section>
  <?php if ($panel['validUuid']): ?>
  <section class="uvm-readonly-panel uvm-device-xml" id="<?=uvm_h($panel['panelId'])?>-xml" data-device-key="<?=uvm_h($panel['pci'] . '|' . $panel['uuid'])?>" aria-labelledby="<?=uvm_h($panel['panelId'])?>-xml-title" tabindex="-1" hidden>
    <h3 id="<?=uvm_h($panel['panelId'])?>-xml-title">VM XML：<?=uvm_h($panel['pci'])?> · <?=uvm_h($panel['uuid'])?></h3>
    <button type="button" class="uvm-close-readonly" data-readonly-control="<?=uvm_h($panel['panelId'])?>-xml-control">关闭 VM XML</button>
    <pre class="uvm-vm-xml"><?=uvm_h($panel['xml'])?></pre><button type="button" class="uvm-copy-xml">复制 XML</button>
  </section>
  <?php endif; ?>
  <?php endforeach; ?>
  <?php endif; ?>
  <?php foreach ($uvm_inventory_messages as $inventoryMessage): if (is_string($inventoryMessage) && $inventoryMessage !== ''): ?><p class="uvm-muted"><?=uvm_h($inventoryMessage)?></p><?php endif; endforeach; ?>

</section>

<style>
#uvm-inventory-table { table-layout: fixed; min-width: 1220px; }
#uvm-inventory-table th, #uvm-inventory-table td { overflow-wrap: anywhere; }
.uvm-readonly-panel { min-width: 0; max-width: 100%; margin-top: 14px; padding-top: 12px; border-top: 1px solid #8884; overflow-wrap: anywhere; }
.uvm-readonly-panel pre { max-width: 100%; box-sizing: border-box; }
#uvm-profile-body td { overflow-wrap: anywhere; }
</style>

<section class="uvm-panel" id="uvm-device-create">
    <h2>添加设备</h2>
    <p class="uvm-muted">保存新的设备配置，不会启动设备或自动接管现有运行时。可填写虚拟机 XML 中已有的 UUID；已保存的 UUID 不会被覆盖。</p>
    <form method="post" class="uvm-device-create-form" id="uvm-device-form">
      <?php uvm_form_hidden('device-create', $uvm_csrf); ?>
      <div id="uvm-device-create-message" class="uvm-note" role="status" aria-live="polite" hidden></div>
      <fieldset id="uvm-device-fields" <?=!$uvm_controls ? 'disabled' : ''?>>
      <div class="uvm-grid" style="margin-top:14px">
        <label class="uvm-field"><span>mdev UUID</span><input name="uuid" id="uvm-device-uuid" type="text" maxlength="36" required pattern="[0-9a-fA-F]{8}(-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}" value="<?=uvm_h($uvm_new_uuid)?>"><small>使用虚拟机 XML 中的 UUID，或生成新的 UUID。</small></label>
        <label class="uvm-field"><span>物理 GPU</span><select name="pci" id="uvm-device-pci" required><option value="">选择 GPU</option><?php foreach ($uvm_gpus as $pci => $gpu): ?><option value="<?=uvm_h($pci)?>"><?=uvm_h($pci . ' · NVIDIA ' . $gpu['device'])?></option><?php endforeach; ?></select></label>
        <label class="uvm-field"><span>配置名称</span><input name="profile" id="uvm-device-profile" type="text" maxlength="128" required readonly placeholder="在下表选择配置"></label>
        <label class="uvm-field"><span>mdev 类型</span><input name="type" id="uvm-device-type" type="text" maxlength="32" readonly placeholder="nvidia-…"><small>按当前驱动自动匹配。</small></label>
      </div>
      <div class="uvm-scroll"><table><thead><tr><th>选择</th><th>配置名称</th><th>类型</th><th>显存</th><th>显示头</th><th>最大分辨率</th><th>FRL</th><th>可用 / 上限</th></tr></thead><tbody id="uvm-profile-body"><tr><td colspan="8">选择 GPU 后显示可用配置。</td></tr></tbody></table></div>
      <p class="uvm-muted">可用数量由驱动报告，实际分配受物理显存限制。</p>
      <?php if (!$uvm_gpus): ?><div class="uvm-note">未发现 NVIDIA 显卡，请检查驱动。</div><?php endif; ?>
      <div class="uvm-actions"><button type="submit" id="uvm-save-device">保存新设备</button><button type="button" id="uvm-new-device">生成 UUID</button></div>
      </fieldset>
    </form>
</section>

<section class="uvm-panel">
  <h2>开机管理</h2>
  <form method="post" class="uvm-submit-form" id="uvm-runtime-form" autocomplete="off">
    <?php uvm_form_hidden('config-save', $uvm_csrf, ['config_section' => 'runtime']); ?>
    <fieldset <?=!$uvm_controls ? 'disabled' : ''?>>
      <label class="uvm-field"><span>vGPU 初始化</span><select name="runtime_enabled"><option value="0" <?=empty($uvm_runtime_settings['enabled']) ? 'selected' : ''?>>关闭</option><option value="1" <?=!empty($uvm_runtime_settings['enabled']) ? 'selected' : ''?>>开启</option></select><small>开机启动 vGPU 服务并创建已配置的设备。驱动安装在“驱动管理”页单独设置；首次启用请停用原 vGPU 启动脚本。</small></label>
      <details class="uvm-advanced"><summary>高级设置</summary>
        <div class="uvm-grid">
          <label class="uvm-field"><span>UnlockRS</span><select name="runtime_unlock"><option value="1" <?=!empty($uvm_runtime_settings['unlock']) ? 'selected' : ''?>>开启</option><option value="0" <?=empty($uvm_runtime_settings['unlock']) ? 'selected' : ''?>>关闭</option></select></label>
          <label class="uvm-field"><span>UnlockRS 库</span><select name="runtime_unlock_library"><?php foreach (['/usr/lib64/libvgpu_unlock_rs.so', '/usr/lib/libvgpu_unlock_rs.so'] as $library): ?><option value="<?=uvm_h($library)?>" <?=$uvm_runtime_settings['unlock_library'] === $library ? 'selected' : ''?>><?=uvm_h($library)?></option><?php endforeach; ?></select></label>
        </div>
        <input type="hidden" name="runtime_xml_override" value="<?=!empty($uvm_runtime_settings['xml_override']) ? '1' : '0'?>">
        <p class="uvm-muted">vgpuConfig.xml 使用当前驱动包中的版本。</p>
        <p><label><input type="checkbox" name="override_replace" value="1" id="uvm-override-replace"> 更新 profile_override.toml</label></p>
        <label class="uvm-field"><span>配置覆盖</span><textarea name="override_content" id="uvm-override-content" spellcheck="false" maxlength="65536" disabled><?=uvm_h($uvm_override)?></textarea><small>勾选后保存；清空内容可移除自定义配置。</small></label>
      </details>
      <div class="uvm-actions"><button type="submit">保存启动设置</button></div>
    </fieldset>
  </form>
</section>


<script>
(function () {
  'use strict';
  const root = document.getElementById('uvm-manager-vgpu');
  const ui = window.uvmUI.forTab('vgpu');
  if (!root || !ui) return;
  const gpus = <?=uvm_json($uvm_gpus)?>;
  const replaceOverride = document.getElementById('uvm-override-replace');
  const override = document.getElementById('uvm-override-content');
  function overrideOptions() { override.disabled = !replaceOverride.checked; }
  replaceOverride.addEventListener('change', overrideOptions);
  overrideOptions();

  function showReadonly(control, open) {
    const panel = document.getElementById(control.getAttribute('aria-controls'));
    if (!panel || !root.contains(panel)) return;
    panel.hidden = !open;
    control.setAttribute('aria-expanded', String(open));
    const target = open ? panel : control;
    target.focus({preventScroll: true});
    target.scrollIntoView({block: 'nearest'});
  }
  root.querySelectorAll('.uvm-readonly-toggle').forEach(function (control) {
    control.addEventListener('click', function () { showReadonly(control, control.getAttribute('aria-expanded') !== 'true'); });
  });
  root.querySelectorAll('.uvm-close-readonly').forEach(function (button) {
    button.addEventListener('click', function () {
      const control = document.getElementById(button.dataset.readonlyControl);
      if (control) showReadonly(control, false);
    });
  });

  const form = document.getElementById('uvm-device-form');
  const pci = document.getElementById('uvm-device-pci');
  const profile = document.getElementById('uvm-device-profile');
  const type = document.getElementById('uvm-device-type');
  const uuid = document.getElementById('uvm-device-uuid');
  const body = document.getElementById('uvm-profile-body');
  const generateUuid = document.getElementById('uvm-new-device');
  const fields = document.getElementById('uvm-device-fields');
  const notice = document.getElementById('uvm-device-create-message');
  const successMessage = '设备配置已保存，本次保存未启动设备。';
  const flashKey = 'uvm-device-create-success';
  let completed = false;
  function message(text, error) {
    notice.textContent = text;
    notice.className = error ? 'uvm-note uvm-error' : 'uvm-note uvm-success';
    notice.hidden = false;
  }
  try {
    const savedUuid = window.sessionStorage.getItem(flashKey);
    window.sessionStorage.removeItem(flashKey);
    if (/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/.test(savedUuid || '')) {
      const savedRow = root.querySelector('#uvm-inventory-table tr[data-device-source="plugin"][data-uuid="' + savedUuid + '"]');
      message(savedRow ? successMessage : '保存请求已确认，但当前设备列表不可核验；请刷新状态，不要重复提交。', !savedRow);
    }
  } catch (_) { /* Storage may be unavailable; the authoritative list still refreshes. */ }
  function newUuid() {
    if (!window.crypto || !window.crypto.getRandomValues) return null;
    const bytes = window.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, function (value) { return value.toString(16).padStart(2, '0'); }).join('');
    return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
  }
  function cell(row, text) {
    const node = document.createElement('td');
    node.textContent = text;
    row.appendChild(node);
    return node;
  }
  function renderProfiles(selectedName) {
    body.replaceChildren();
    const rows = gpus[pci.value] ? gpus[pci.value].profiles : [];
    if (!rows.length) {
      const row = document.createElement('tr');
      const node = cell(row, pci.value ? '当前 GPU 暂无可用配置，请查看状态与日志。' : '选择 GPU 后显示可用配置。');
      node.colSpan = 8;
      body.appendChild(row);
      return;
    }
    rows.forEach(function (entry) {
      const row = document.createElement('tr');
      const select = document.createElement('input');
      select.type = 'radio';
      select.name = 'uvm_profile_picker';
      select.value = entry.type;
      select.setAttribute('aria-label', entry.name);
      const current = selectedName === entry.name;
      select.checked = current;
      select.disabled = Number(entry.available) === 0 && !current;
      if (current) row.className = 'uvm-profile-current';
      select.addEventListener('change', function () {
        profile.value = entry.name;
        type.value = entry.type;
        body.querySelectorAll('tr').forEach(function (item) { item.classList.remove('uvm-profile-current'); });
        row.classList.add('uvm-profile-current');
      });
      cell(row, '').appendChild(select);
      [entry.name, entry.type, entry.framebuffer, entry.heads, entry.resolution, entry.frl, entry.available + ' / ' + (entry.maximum || '未知')].forEach(function (value) { cell(row, value); });
      body.appendChild(row);
      if (current) type.value = entry.type;
    });
  }
  pci.addEventListener('change', function () {
    profile.value = '';
    type.value = '';
    renderProfiles('');
  });
  generateUuid.addEventListener('click', function () {
    if (ui.isBusy() || fields.disabled || completed) return;
    const value = newUuid();
    if (!value) {
      message('浏览器无法生成 UUID；当前 UUID 已保留，也可手动填写。', true);
      return;
    }
    uuid.value = value;
  });
  root.querySelectorAll('.uvm-copy-xml').forEach(function (button) {
    button.addEventListener('click', async function () {
      const snippet = button.parentElement.querySelector('.uvm-vm-xml');
      try {
        if (!navigator.clipboard) throw new Error('clipboard unavailable');
        await navigator.clipboard.writeText(snippet.textContent);
        ui.message('XML 已复制。', false);
      } catch (_) {
        const selection = window.getSelection();
        const range = document.createRange();
        range.selectNodeContents(snippet);
        selection.removeAllRanges();
        selection.addRange(range);
        ui.message('已选中 XML，请手动复制。', false);
      }
    });
  });
  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    if (ui.isBusy() || fields.disabled || completed) return;
    if (!form.reportValidity()) return;
    if (!profile.value || !type.value) {
      message('请先选择 GPU 配置。', true);
      return;
    }
    // This form contains text only; avoid multipart at the native auth gateway.
    const data = new URLSearchParams(new FormData(form));
    const submittedUuid = uuid.value.trim().toLowerCase();
    const controller = new AbortController();
    const timeout = window.setTimeout(function () { controller.abort(); }, 30000);
    ui.setBusy(true);
    fields.disabled = true;
    form.classList.add('uvm-form-busy');
    message('正在保存设备配置…', false);
    try {
      const response = await window.fetch(window.location.href, {method: 'POST', body: data, credentials: 'same-origin', signal: controller.signal});
      const html = await response.text();
      if (html.length > 1048576) throw new Error('unknown response');
      const document = new DOMParser().parseFromString(html, 'text/html');
      const results = document.querySelectorAll('script#uvm-device-create-result[type="application/json"]');
      if (results.length !== 1) throw new Error('unknown response');
      const result = JSON.parse(results[0].textContent);
      if (result && result.ok === false && typeof result.error === 'string' && result.error !== '') {
        message(result.error, true);
        return;
      }
      if (!response.ok || !result || result.ok !== true || !result.data || result.data.changed !== true || result.data.uuid !== submittedUuid) throw new Error('unknown response');
      completed = true;
      try { window.sessionStorage.setItem(flashKey, submittedUuid); } catch (_) { /* Refresh does not depend on storage. */ }
      const target = new URL(window.location.href);
      target.searchParams.set('uvm_view', 'vgpu');
      target.searchParams.delete('csrf_token');
      target.searchParams.delete('uvm_csrf_token');
      target.hash = '';
      window.location.replace(target.toString());
    } catch (_) {
      message(completed ? '设备配置保存已确认，但列表刷新未完成；请手动刷新页面，不要重复提交。' : '未能确认保存结果，配置草稿已保留。请求可能已完成，请先刷新并检查设备列表，确认后再重试。', true);
    } finally {
      window.clearTimeout(timeout);
      if (!completed) {
        fields.disabled = false;
        ui.setBusy(false);
        form.classList.remove('uvm-form-busy');
      }
    }
  });
  renderProfiles('');
})();
</script>
