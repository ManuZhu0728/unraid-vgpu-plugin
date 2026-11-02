<?php
$uvm_runtime_snapshot = is_array($uvm_runtime['runtime'] ?? null) ? $uvm_runtime['runtime'] : [];
$uvm_mdevs_known = ($uvm_runtime_snapshot['checks']['mdevs_known'] ?? false) === true;
$uvm_active_mdevs = is_array($uvm_runtime_snapshot['active_mdevs'] ?? null) ? $uvm_runtime_snapshot['active_mdevs'] : [];
?>
<section class="uvm-panel">
  <h2>开机管理</h2>
  <form method="post" class="uvm-submit-form" id="uvm-runtime-form" autocomplete="off">
    <?php uvm_form_hidden('config-save', $uvm_csrf, ['config_section' => 'runtime']); ?>
    <fieldset <?=!$uvm_controls ? 'disabled' : ''?>>
      <label class="uvm-field"><span>开机管理 vGPU</span><select name="runtime_enabled"><option value="0" <?=empty($uvm_runtime_settings['enabled']) ? 'selected' : ''?>>关闭</option><option value="1" <?=!empty($uvm_runtime_settings['enabled']) ? 'selected' : ''?>>开启</option></select><small>开机创建下面配置的设备。首次启用前，请停用原来的 vGPU 启动脚本。</small></label>
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

<section class="uvm-panel">
  <h2>vGPU 设备</h2>
  <?php if (!$uvm_devices): ?><p class="uvm-muted">尚未添加设备。</p><?php else: ?>
  <div class="uvm-scroll"><table><thead><tr><th>UUID / VM XML</th><th>GPU / 配置</th><th>状态</th><th>操作</th></tr></thead><tbody>
  <?php foreach ($uvm_devices as $device):
    if (!is_array($device)) continue;
    $uuid = (string)($device['uuid'] ?? '');
    $validUuid = preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $uuid) === 1;
    $active = $validUuid && ($uvm_mdevs_known ? isset($uvm_active_mdevs[$uuid]) : is_dir('/sys/bus/mdev/devices/' . $uuid));
    $xml = "<hostdev mode='subsystem' type='mdev' managed='no' model='vfio-pci' display='off' ramfb='off'>\n  <source>\n    <address uuid='" . $uuid . "'/>\n  </source>\n</hostdev>";
  ?>
    <tr><td><code><?=uvm_h($uuid)?></code><?php if ($validUuid): ?><details><summary>VM XML</summary><pre class="uvm-vm-xml"><?=uvm_h($xml)?></pre><button type="button" class="uvm-copy-xml">复制 XML</button></details><?php endif; ?></td>
      <td><code><?=uvm_h($device['pci'] ?? '')?></code><br><?=uvm_h($device['profile'] ?? '')?><br><code><?=uvm_h($device['type'] ?? '')?></code></td>
      <td><?=$active ? '已创建' : '未创建'?></td>
      <td><div class="uvm-actions" style="margin-top:0">
        <button type="button" class="uvm-edit-device" data-device="<?=uvm_h(uvm_json($device))?>" <?=!$uvm_controls ? 'disabled' : ''?>>编辑</button>
        <?php foreach (['device-start' => '启动', 'device-stop' => '停止', 'device-remove' => '移除'] as $action => $label): ?>
        <form method="post" class="uvm-submit-form" <?=in_array($action, ['device-stop', 'device-remove'], true) ? 'data-confirm="请确认使用此设备的虚拟机已关闭。继续？"' : ''?>><?php uvm_form_hidden($action, $uvm_csrf, ['uuid' => $uuid]); ?><button type="submit" <?=!$uvm_controls || !$validUuid || ($action === 'device-start' && ($active || empty($uvm_runtime_settings['enabled']))) || ($action === 'device-stop' && !$active) ? 'disabled' : ''?>><?=uvm_h($label)?></button></form>
        <?php endforeach; ?>
      </div></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div><?php endif; ?>

  <details class="uvm-advanced" id="uvm-device-editor" <?=$uvm_action === 'device-save' ? 'open' : ''?>>
    <summary id="uvm-device-editor-title">添加设备</summary>
    <form method="post" class="uvm-submit-form" id="uvm-device-form">
      <?php uvm_form_hidden('device-save', $uvm_csrf); ?>
      <fieldset <?=!$uvm_controls ? 'disabled' : ''?>>
      <div class="uvm-grid" style="margin-top:14px">
        <label class="uvm-field"><span>mdev UUID</span><input name="uuid" id="uvm-device-uuid" type="text" maxlength="36" required pattern="[0-9a-fA-F]{8}(-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}" value="<?=uvm_h($uvm_new_uuid)?>"><small>使用虚拟机 XML 中的 UUID，或生成新的 UUID。</small></label>
        <label class="uvm-field"><span>物理 GPU</span><select name="pci" id="uvm-device-pci" required><option value="">选择 GPU</option><?php foreach ($uvm_gpus as $pci => $gpu): ?><option value="<?=uvm_h($pci)?>"><?=uvm_h($pci . ' · NVIDIA ' . $gpu['device'])?></option><?php endforeach; ?></select></label>
        <label class="uvm-field"><span>配置名称</span><input name="profile" id="uvm-device-profile" type="text" maxlength="128" required readonly placeholder="在下表选择配置"></label>
        <label class="uvm-field"><span>mdev 类型</span><input name="type" id="uvm-device-type" type="text" maxlength="32" readonly placeholder="nvidia-…"><small>按当前驱动自动匹配。</small></label>
      </div>
      <div class="uvm-scroll"><table><thead><tr><th>选择</th><th>配置名称</th><th>类型</th><th>显存</th><th>显示头</th><th>最大分辨率</th><th>FRL</th><th>可用 / 上限</th></tr></thead><tbody id="uvm-profile-body"><tr><td colspan="8">选择 GPU 后显示可用配置。</td></tr></tbody></table></div>
      <p class="uvm-muted">可用数量由驱动报告，实际分配受物理显存限制。</p>
      <?php if (!$uvm_gpus): ?><div class="uvm-note">未发现 NVIDIA 显卡，请检查驱动。</div><?php endif; ?>
      <div class="uvm-actions"><button type="submit">保存设备</button><button type="button" id="uvm-new-device">生成 UUID</button></div>
      </fieldset>
    </form>
  </details>
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

  const editor = document.getElementById('uvm-device-editor');
  const editorTitle = document.getElementById('uvm-device-editor-title');
  const form = document.getElementById('uvm-device-form');
  const pci = document.getElementById('uvm-device-pci');
  const profile = document.getElementById('uvm-device-profile');
  const type = document.getElementById('uvm-device-type');
  const uuid = document.getElementById('uvm-device-uuid');
  const body = document.getElementById('uvm-profile-body');
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
  root.querySelectorAll('.uvm-edit-device').forEach(function (button) {
    button.addEventListener('click', function () {
      if (ui.isBusy()) return;
      try {
        const device = JSON.parse(button.dataset.device);
        uuid.value = device.uuid;
        if (!gpus[device.pci]) {
          const existing = Array.from(pci.options).find(function (option) { return option.value === device.pci; });
          if (!existing) {
            const option = document.createElement('option');
            option.value = device.pci;
            option.textContent = device.pci + '（当前未发现）';
            pci.appendChild(option);
          }
        }
        pci.value = device.pci;
        profile.value = device.profile;
        type.value = device.type;
        renderProfiles(device.profile);
        editor.open = true;
        editorTitle.textContent = '编辑设备';
        form.scrollIntoView({behavior: 'smooth', block: 'center'});
      } catch (_) { ui.message('无法读取设备配置，请刷新后重试。', true); }
    });
  });
  document.getElementById('uvm-new-device').addEventListener('click', function () {
    if (ui.isBusy()) return;
    if (!window.crypto || !window.crypto.getRandomValues) {
      ui.message('浏览器无法生成 UUID，请刷新页面获取新的 UUID。', true);
      return;
    }
    const bytes = window.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, function (value) { return value.toString(16).padStart(2, '0'); }).join('');
    uuid.value = [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
    pci.value = '';
    profile.value = '';
    type.value = '';
    editorTitle.textContent = '添加设备';
    renderProfiles('');
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
  form.addEventListener('submit', function (event) {
    if (!profile.value || !type.value) {
      event.preventDefault();
      ui.message('请先选择 GPU 配置。', true);
    }
  });
  renderProfiles('');
})();
</script>
