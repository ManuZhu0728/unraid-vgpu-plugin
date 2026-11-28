<?php // Driver source configuration, downloads, local import and OS preparation. ?>
<div class="uvm-actions">
  <label for="uvm-driver-mode">获取方式</label>
  <select id="uvm-driver-mode">
    <option value="online" <?=$uvm_driver_mode === 'online' ? 'selected' : ''?>>在线下载</option>
    <option value="local" <?=$uvm_driver_mode === 'local' ? 'selected' : ''?>>本地上传</option>
  </select>
</div>
  <section class="uvm-panel" id="uvm-online-panel" <?=$uvm_driver_mode === 'local' ? 'hidden' : ''?>>
    <h2>驱动下载</h2>
    <form method="post" class="uvm-submit-form" id="uvm-source-form" autocomplete="off">
      <?php uvm_form_hidden('config-save', $uvm_csrf, ['config_section' => 'source']); ?>
      <input type="hidden" name="source_simple" value="1">
      <fieldset <?=!$uvm_controls ? 'disabled' : ''?>>
      <div class="uvm-grid">
        <label class="uvm-field"><span>下载地址</span><input name="source_url" id="uvm-source-url" type="url" maxlength="1024" placeholder="GitHub / GitLab 仓库、Release 或驱动包链接" value="<?=uvm_h($uvm_source['url'] ?: $uvm_source['repository'])?>"><small>自动识别来源，并匹配 <code><?=uvm_h(php_uname('r'))?></code>。</small></label>
        <label class="uvm-field"><span>Token（私有仓库）</span><input name="credential_token" id="uvm-credential-token" type="password" maxlength="4096" value="" autocomplete="new-password" spellcheck="false"><small><?=!empty($uvm_status['credential_configured']) ? '已保存。留空保留，输入新 Token 可替换。' : '公开仓库留空。'?></small></label>
      </div>
      <input type="hidden" name="source_repository" value="<?=uvm_h($uvm_source['repository'])?>">
      <details class="uvm-advanced"><summary>高级设置</summary>
        <div class="uvm-grid">
          <label class="uvm-field"><span>平台</span><select name="source_provider" id="uvm-source-provider"><?php foreach (['auto'=>'自动识别','github'=>'GitHub','gitlab'=>'GitLab','direct'=>'文件直链'] as $key=>$label): ?><option value="<?=uvm_h($key)?>" <?=($uvm_source['mode'] === 'auto' ? $key === 'auto' : $uvm_source['provider'] === $key) ? 'selected' : ''?>><?=uvm_h($label)?></option><?php endforeach; ?></select></label>
          <label class="uvm-field"><span>下载方式</span><select name="source_mode" id="uvm-source-mode"><?php foreach (['auto'=>'自动','releases'=>'Releases','generic'=>'GitLab Generic Packages'] as $key=>$label): ?><option value="<?=uvm_h($key)?>" <?=$uvm_source['mode'] === $key ? 'selected' : ''?>><?=uvm_h($label)?></option><?php endforeach; ?></select></label>
          <label class="uvm-field"><span>固定驱动版本</span><input name="source_driver" type="text" maxlength="32" placeholder="自动（优先当前版本）" value="<?=uvm_h($uvm_source['driver'])?>"></label>
          <label class="uvm-field"><span>固定构建号</span><input name="source_build" type="text" inputmode="numeric" pattern="[1-9][0-9]*" maxlength="19" placeholder="自动" value="<?=uvm_h($uvm_source['build'])?>"><small>配合固定驱动版本使用。Releases 留空取同版本最新构建；已固定构建时，回退需同步修改此处。</small></label>
          <label class="uvm-field"><span>认证方式</span><select name="credential_type" id="uvm-credential-type"><?php foreach (['auto'=>'自动','private-token'=>'GitLab PRIVATE-TOKEN','deploy-token'=>'GitLab Deploy Token','bearer'=>'Bearer Token'] as $key=>$label): ?><option value="<?=uvm_h($key)?>"><?=uvm_h($label)?></option><?php endforeach; ?></select></label>
          <label class="uvm-field"><span>已保存 Token</span><select name="credential_change" id="uvm-credential-change"><option value="auto">保留；输入新值时替换</option><option value="remove">删除</option></select></label>
          <label class="uvm-field"><span>用户名</span><input name="credential_username" id="uvm-credential-username" type="text" maxlength="256" value="" autocomplete="off"></label>
          <label class="uvm-field"><span>GitLab 项目 ID</span><input name="source_project_id" type="text" maxlength="128" value="<?=uvm_h($uvm_source['project_id'])?>" placeholder="自动"></label>
          <label class="uvm-field"><span>Release 标签模板</span><input name="source_tag_template" type="text" maxlength="1024" value="<?=uvm_h($uvm_source['tag_template'])?>"></label>
          <label class="uvm-field"><span>Generic Package 名称</span><input name="source_package_name" type="text" maxlength="128" value="<?=uvm_h($uvm_source['package_name'])?>"></label>
          <label class="uvm-field"><span>Generic 版本模板</span><input name="source_package_version_template" type="text" maxlength="1024" value="<?=uvm_h($uvm_source['package_version_template'])?>"></label>
        </div>
        <p class="uvm-muted">通常保持自动即可。Deploy Token 下载私有包时，可直接粘贴 Package Registry 中的 TXZ 链接。</p>
      </details>
      <div class="uvm-actions">
        <button type="submit" name="source_connect" value="1">查找驱动</button>
        <button type="submit" name="source_connect" value="0">保存驱动设置</button>
      </div>
      </fieldset>
    </form>

    <?php if ($uvm_versions): ?>
    <div class="uvm-scroll"><table><thead><tr><th>驱动版本</th><th>构建号</th><th>内核</th><th>文件</th></tr></thead><tbody>
    <?php foreach ($uvm_versions as $version): if (!is_array($version)) continue; ?><tr><td><code><?=uvm_h($version['driver'] ?? '')?></code></td><td><code><?=uvm_h($version['build'] ?? '未记录')?></code></td><td><code><?=uvm_h($version['kernel'] ?? '')?></code></td><td><code><?=uvm_h($version['filename'] ?? '')?></code></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <form method="post" class="uvm-submit-form uvm-inline-form" id="uvm-download-form" data-kernel="<?=uvm_h(($uvm_snapshot['kernel'] ?? '') ?: php_uname('r'))?>">
      <?php uvm_form_hidden('source-download', $uvm_csrf); ?>
      <div class="uvm-actions">
        <select name="download_package" aria-label="下载版本与构建">
          <option value="">自动选择<?=($uvm_source['driver'] !== '' ? '（' . uvm_h($uvm_source['driver']) . ($uvm_source['build'] !== '' ? ' · 构建 ' . uvm_h($uvm_source['build']) : '') . '）' : '（优先当前版本）')?></option>
          <?php foreach ($uvm_versions as $version): if (!is_array($version)) continue; ?><option value="<?=uvm_h($version['filename'] ?? '')?>"><?=uvm_h($version['driver'] ?? '')?> · 构建 <?=uvm_h($version['build'] ?? '未记录')?></option><?php endforeach; ?>
        </select>
        <button type="submit" <?=!$uvm_controls || ($uvm_source['url'] === '' && $uvm_source['repository'] === '') ? 'disabled' : ''?>>下载到缓存</button>
      </div>
    </form>
    <div id="uvm-download-feedback" class="uvm-note uvm-download-feedback" role="status" aria-live="polite" tabindex="-1" hidden>
      <p id="uvm-download-message"></p>
      <progress id="uvm-download-meter" max="1" aria-label="驱动下载与校验进度"></progress>
      <p id="uvm-download-stats" class="uvm-muted"></p>
      <p id="uvm-download-elapsed" class="uvm-muted" aria-live="off"></p>
      <button type="button" id="uvm-download-cancel" hidden>取消下载</button>
      <p class="uvm-muted">下载仅保存到缓存，不会安装、重载驱动或重启服务。</p>
      <a id="uvm-download-check" hidden>重新打开页面核对缓存</a>
    </div>
  </section>

  <section class="uvm-panel" id="uvm-local-panel" <?=$uvm_driver_mode !== 'local' ? 'hidden' : ''?>>
    <h2>本地导入</h2>
    <form id="uvm-upload-form" data-kernel="<?=uvm_h(($uvm_snapshot['kernel'] ?? '') ?: php_uname('r'))?>">
      <fieldset <?=!$uvm_controls ? 'disabled' : ''?>>
      <div class="uvm-grid">
        <label class="uvm-field"><span>驱动包</span><input id="uvm-upload-package" type="file" accept=".txz" required></label>
        <label class="uvm-field"><span>MD5 文件（必选）</span><input id="uvm-upload-checksum" type="file" accept=".md5,.txt" required></label>
      </div>
      <div class="uvm-actions"><button type="submit" id="uvm-upload-submit">导入到缓存</button><button type="button" id="uvm-upload-cancel" hidden>取消</button><span id="uvm-upload-progress" role="status"></span></div>
      <progress id="uvm-upload-meter" max="100" value="0" hidden></progress>
      </fieldset>
    </form>
    <a id="uvm-upload-check" hidden>重新打开页面核对缓存</a>
    <p class="uvm-muted">必须同时选择对应的 MD5 文件，比对通过才导入缓存。导入后在“已下载驱动”明确选择下次启动构建。</p>
  </section>

<section class="uvm-panel" id="uvm-driver-policy-panel">
  <h2>驱动启动与升级</h2>
  <form method="post" class="uvm-submit-form" id="uvm-driver-policy-form">
    <?php uvm_form_hidden('config-save', $uvm_csrf, ['config_section' => 'driver']); ?>
    <fieldset <?=!$uvm_controls ? 'disabled' : ''?>>
    <div class="uvm-grid">
      <label class="uvm-field"><span>开机恢复驱动</span><select name="driver_enabled"><option value="0" <?=empty($uvm_settings['driver']['enabled']) ? 'selected' : ''?>>关闭</option><option value="1" <?=!empty($uvm_settings['driver']['enabled']) ? 'selected' : ''?>>开启</option></select><small>由 Unraid 在下次开机安装选中的构建，插件核验后初始化 vGPU。本地导入也适用；首次启用请停用原驱动安装入口。</small></label>
      <label class="uvm-field"><span>系统升级预下载</span><select name="upgrade_enabled"><option value="0" <?=empty($uvm_settings['upgrade']['enabled']) ? 'selected' : ''?>>关闭</option><option value="1" <?=!empty($uvm_settings['upgrade']['enabled']) ? 'selected' : ''?>>开启</option></select><small>从已配置的下载源提前准备新内核驱动。关闭后仍可从现有缓存开机恢复。</small></label>
    </div>
    <p class="uvm-muted">vGPU 初始化在“vGPU 管理”页单独设置。</p>
    <div class="uvm-actions"><button type="submit">保存启动与升级设置</button></div>
    </fieldset>
  </form>
  <p>开机恢复：<?=!empty($uvm_settings['driver']['enabled']) ? '开启' : '关闭'?><?php if ($uvm_installation): ?> · 安装状态：<?=uvm_h(['installed'=>'已完成','already_active'=>'驱动已加载','installing'=>'正在安装','pending_reboot'=>'等待重启安装所选构建','failed'=>'失败','blocked'=>'等待迁移启动入口','missing_package'=>'缺少匹配包','disabled'=>'未启用'][$uvm_installation['status'] ?? ''] ?? ($uvm_installation['status'] ?? ''))?> · <code><?=uvm_h($uvm_installation['kernel'] ?? '')?></code><?php endif; ?></p>
  <?php if (in_array($uvm_installation['status'] ?? '', ['failed','blocked','missing_package','pending_reboot'], true)): ?><p class="uvm-muted"><?=uvm_h(uvm_redact($uvm_installation['message'] ?? ''))?></p><?php endif; ?>
</section>

<section class="uvm-panel" id="uvm-driver-cache">
  <h2>驱动缓存</h2>
  <p id="uvm-selected-package">最近选择：<?php if ($uvm_selected_package): ?><code><?=uvm_h($uvm_selected_package['filename'])?></code><?php else: ?>尚未选择<?php endif; ?>。每个内核分别保存启动构建；下载或导入不会改变选择。</p>
  <div id="uvm-driver-cache-body">
  <?php if ($uvm_selected_package): ?>
  <p>NVIDIA <code><?=uvm_h($uvm_selected_package['driver'] ?? '')?></code> · 构建 <code><?=uvm_h($uvm_selected_package['build'] ?? '未记录')?></code> · <code><?=uvm_h($uvm_selected_package['kernel'] ?? '')?></code> · <?=($uvm_selected_package['source'] ?? '') === 'local' ? '本地导入' : '在线下载'?></p>
  <p class="uvm-muted uvm-cache-filename"><code><?=uvm_h($uvm_selected_package['filename'] ?? basename($uvm_selected_package['path'] ?? ''))?></code></p>
  <details><summary>文件信息</summary><pre><?=uvm_h(json_encode(uvm_redact($uvm_selected_package), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE))?></pre></details>
  <?php else: ?><p class="uvm-muted">下载或上传驱动后，在下方选择下次启动构建。</p><?php endif; ?>
  </div>
</section>
<section class="uvm-panel" id="uvm-downloaded-panel" data-controls="<?=$uvm_controls ? '1' : '0'?>">
  <h2>已下载驱动</h2>
  <p class="uvm-muted">列出所有内核的缓存包。“Unraid 启动安装”标记表示该内核实际准备的唯一启动包。当前选中、启动安装、升级准备和上次成功安装等引用的包不可删除。仅有“内核回退记录”的包，可确认后同时删除缓存、同名 MD5 和对应记录；以后回退需重新下载或上传。删除不会卸载正在运行的驱动。</p>
  <div id="uvm-cache-list">
  <?php $uvm_cache = $uvm_status['package_cache'] ?? null; ?>
  <?php if (!is_array($uvm_cache) || ($uvm_cache['available'] ?? null) !== true): ?>
    <p class="uvm-muted">无法安全读取已下载列表，删除已禁用。请重新打开页面核对。</p>
  <?php elseif (!$uvm_cache['packages']): ?><p class="uvm-muted">暂无已下载驱动。</p>
  <?php else: ?>
    <div class="uvm-scroll"><table class="uvm-cache-table"><thead><tr><th>文件 / 版本</th><th>内核</th><th>大小</th><th>引用 / 保护原因</th><th>操作</th></tr></thead><tbody>
    <?php foreach ($uvm_cache['packages'] as $cached): ?>
      <tr data-cache-filename="<?=uvm_h($cached['filename'])?>">
        <td><code><?=uvm_h($cached['filename'])?></code><p class="uvm-muted">NVIDIA <code><?=uvm_h($cached['driver'])?></code> · 构建 <code><?=uvm_h($cached['build'])?></code></p></td>
        <td><code><?=uvm_h($cached['kernel'])?></code></td><td><?=uvm_h(number_format($cached['size'] / 1048576, 1))?> MiB</td>
        <td><?=uvm_h(implode('、', $cached['references']) ?: '未被引用')?></td>
        <td><form method="post" class="uvm-submit-form"><?php uvm_form_hidden('cache-select', $uvm_csrf, ['cache_filename' => $cached['filename']]); ?><button type="submit" <?=!$uvm_controls ? 'disabled' : ''?>>设为下次启动构建</button></form><form method="post" class="uvm-cache-delete-form" data-history-confirmation="<?=!empty($cached['requires_history_confirmation']) ? '1' : '0'?>"><?php uvm_form_hidden('cache-delete', $uvm_csrf, ['cache_filename' => $cached['filename']]); ?><button type="submit" <?=!$uvm_controls || $cached['deletable'] !== true ? 'disabled' : ''?>>删除</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
  </div>
  <div id="uvm-cache-message" class="uvm-note" role="status" tabindex="-1" hidden></div>
  <a id="uvm-cache-check" hidden>重新打开页面核对缓存</a>
</section>
  <section class="uvm-panel">
    <h2>升级准备</h2>
    <p>系统升级预下载：<?=!empty($uvm_settings['upgrade']['enabled']) ? '开启' : '关闭'?></p>
    <?php $uvm_baseline_phase = ($uvm_upgrade['phase'] ?? '') === 'baseline';
    $uvm_upgrade_button = ['cancelled' => '继续准备', 'interrupted' => '核对后重试'][$uvm_upgrade['status'] ?? ''] ?? '检查升级准备';
    $uvm_upgrade_kernel = $uvm_baseline_phase ? ($uvm_upgrade['current_kernel'] ?? '') : ($uvm_upgrade['target_kernel'] ?? '');
    $uvm_upgrade_label = ['idle'=>'尚无升级任务','current'=>'当前内核缓存已就绪','disabled'=>'升级预下载已关闭','downloading'=>$uvm_baseline_phase ? '正在准备当前内核恢复缓存' : '正在预下载新内核驱动','staging'=>'正在核验新内核启动安装包','ready'=>'驱动已准备好','missing'=>'所需内核驱动尚未发布','error'=>'准备失败','cancelled'=>'准备已手动取消，不会自动重试','blocked'=>'等待处理启动入口冲突'];
    $uvm_upgrade_label['interrupted'] = '停止结果未确认，自动重试已暂停';
    if (($uvm_upgrade['status'] ?? '') === 'ready' && ($uvm_upgrade['boot_ready'] ?? null) === false) $uvm_upgrade_label['ready'] = '驱动已缓存，未准备启动安装包'; ?>
    <p id="uvm-upgrade-state"><?=uvm_h($uvm_upgrade_label[$uvm_upgrade['status'] ?? 'idle'] ?? ($uvm_upgrade['status'] ?? '尚无升级任务'))?><?php if ($uvm_upgrade_kernel !== ''): ?> · <code><?=uvm_h($uvm_upgrade_kernel)?></code><?php endif; ?><?php if (!empty($uvm_upgrade['package']['driver'])): ?> · NVIDIA <code><?=uvm_h($uvm_upgrade['package']['driver'])?></code> · 构建 <code><?=uvm_h($uvm_upgrade['package']['build'] ?? '未记录')?></code><?php endif; ?></p>
    <p id="uvm-upgrade-detail" class="uvm-muted"><?=uvm_h(uvm_redact($uvm_upgrade['message'] ?? ''))?></p>
    <div id="uvm-upgrade-progress" role="status" aria-live="polite" hidden>
      <p id="uvm-upgrade-progress-phase"></p>
      <progress id="uvm-upgrade-meter" max="1" aria-label="后台驱动下载进度"></progress>
      <p id="uvm-upgrade-stats" class="uvm-muted"></p>
      <button type="button" id="uvm-upgrade-cancel" hidden>取消准备</button>
    </div>
    <form method="post" class="uvm-submit-form" id="uvm-upgrade-check-form"><?php uvm_form_hidden('upgrade-check', $uvm_csrf); ?><button id="uvm-upgrade-check-button" type="submit" <?=!$uvm_controls || empty($uvm_settings['upgrade']['enabled']) ? 'disabled' : ''?>><?=uvm_h($uvm_upgrade_button)?></button></form>
  </section>

<script>
(function () {
  'use strict';
  const ui = window.uvmUI.forTab('driver');
  const message = ui.message;
  const picker = document.getElementById('uvm-driver-mode');
  let inputMode = picker.value;
  picker.addEventListener('change', function () {
    if (window.uvmUI.isBusy()) { picker.value = inputMode; return; }
    inputMode = picker.value;
    document.getElementById('uvm-online-panel').hidden = inputMode !== 'online';
    document.getElementById('uvm-local-panel').hidden = inputMode !== 'local';
    const url = new URL(window.location.href);
    if (inputMode === 'local') url.searchParams.set('driver_mode', 'local');
    else url.searchParams.delete('driver_mode');
    window.history.replaceState(null, '', url.href);
  });
  const provider = document.getElementById('uvm-source-provider');
  const mode = document.getElementById('uvm-source-mode');
  function sourceOptions() {
    const github = provider.value === 'github';
    const direct = provider.value === 'direct';
    mode.querySelector('option[value="generic"]').disabled = github || direct;
    if ((github || direct) && mode.value === 'generic') mode.value = 'auto';
  }
  provider.addEventListener('change', sourceOptions);
  document.getElementById('uvm-source-url').addEventListener('change', function () {
    provider.value = 'auto';
    mode.value = 'auto';
    document.getElementById('uvm-credential-type').value = 'auto';
    document.querySelector('[name="source_project_id"]').value = '';
    sourceOptions();
  });
  sourceOptions();
  const credentialChange = document.getElementById('uvm-credential-change');
  function credentialOptions() {
    const remove = credentialChange.value === 'remove';
    document.getElementById('uvm-credential-token').disabled = remove;
    document.getElementById('uvm-credential-type').disabled = remove;
    document.getElementById('uvm-credential-username').disabled = remove;
  }
  credentialChange.addEventListener('change', credentialOptions);
  credentialOptions();

  const cacheList = document.getElementById('uvm-cache-list');
  const cacheMessage = document.getElementById('uvm-cache-message');
  const cacheCheck = document.getElementById('uvm-cache-check');
  const csrf = <?=uvm_json($uvm_csrf)?>;
  function monoText(value) {
    const node = document.createElement('code'); node.textContent = value; return node;
  }
  function packageIdentity(info, includeKernel) {
    const identity = document.createElement('p');
    identity.append('NVIDIA ', monoText(info.driver), ' · 构建 ', monoText(info.build));
    if (includeKernel) identity.append(' · ', monoText(info.kernel), ' · 在线下载');
    return identity;
  }
  function renderCacheList(inventory) {
    cacheList.replaceChildren();
    if (!inventory || inventory.available !== true || !Array.isArray(inventory.packages)) {
      const note = document.createElement('p');
      note.className = 'uvm-muted';
      note.textContent = '无法安全读取已下载列表，删除已禁用。请重新打开页面核对。';
      cacheList.append(note);
      cacheCheck.hidden = false;
      return;
    }
    if (!inventory.packages.length) {
      const note = document.createElement('p'); note.className = 'uvm-muted'; note.textContent = '暂无已下载驱动。'; cacheList.append(note); return;
    }
    const scroll = document.createElement('div'); scroll.className = 'uvm-scroll';
    const table = document.createElement('table'); table.className = 'uvm-cache-table';
    const head = table.createTHead().insertRow();
    ['文件 / 版本', '内核', '大小', '引用 / 保护原因', '操作'].forEach(function (text) { const cell = document.createElement('th'); cell.textContent = text; head.append(cell); });
    const body = table.createTBody();
    inventory.packages.forEach(function (item) {
      const row = body.insertRow(); row.dataset.cacheFilename = item.filename;
      const file = row.insertCell(); file.append(monoText(item.filename));
      const identity = packageIdentity(item, false); identity.className = 'uvm-muted'; file.append(identity);
      row.insertCell().append(monoText(item.kernel));
      row.insertCell().textContent = (Number(item.size) / 1048576).toFixed(1) + ' MiB';
      row.insertCell().textContent = Array.isArray(item.references) ? (item.references.join('、') || '未被引用') : '引用状态未知';
      const form = document.createElement('form'); form.method = 'post'; form.className = 'uvm-cache-delete-form';
      const references = item.references;
      const historyOnly = Array.isArray(references) && references.length === 1 && references[0] === '内核回退记录';
      const confirmHistory = historyOnly && item.requires_history_confirmation === true;
      form.dataset.historyConfirmation = confirmHistory ? '1' : '0';
      Object.entries({uvm_action: 'cache-delete', uvm_tab: 'driver', csrf_token: csrf, uvm_csrf_token: csrf, cache_filename: item.filename}).forEach(function (entry) {
        const field = document.createElement('input'); field.type = 'hidden'; field.name = entry[0]; field.value = entry[1]; form.append(field);
      });
      const button = document.createElement('button'); button.type = 'submit'; button.textContent = '删除';
      button.disabled = item.deletable !== true || !Array.isArray(references) || (references.length > 0 && !confirmHistory) || document.getElementById('uvm-downloaded-panel').dataset.controls !== '1';
      form.append(button);
      const actions = row.insertCell();
      const select = document.createElement('form'); select.method = 'post'; select.className = 'uvm-submit-form';
      Object.entries({uvm_action: 'cache-select', uvm_tab: 'driver', csrf_token: csrf, uvm_csrf_token: csrf, cache_filename: item.filename}).forEach(function (entry) {
        const field = document.createElement('input'); field.type = 'hidden'; field.name = entry[0]; field.value = entry[1]; select.append(field);
      });
      const choose = document.createElement('button'); choose.type = 'submit'; choose.textContent = '设为下次启动构建';
      choose.disabled = document.getElementById('uvm-downloaded-panel').dataset.controls !== '1';
      select.append(choose); actions.append(select, form);
    });
    scroll.append(table); cacheList.append(scroll);
  }

  const downloadForm = document.getElementById('uvm-download-form');
  const downloadButton = downloadForm.querySelector('button[type="submit"]');
  const downloadPicker = downloadForm.querySelector('[name="download_package"]');
  const downloadFeedback = document.getElementById('uvm-download-feedback');
  const downloadMessage = document.getElementById('uvm-download-message');
  const downloadMeter = document.getElementById('uvm-download-meter');
  const downloadElapsed = document.getElementById('uvm-download-elapsed');
  const downloadCheck = document.getElementById('uvm-download-check');
  let downloadPending = false;
  let downloadNeedsCheck = false;
  let manualJobId = '';
  let settledJobId = '';
  let observedTask = null;
  let progressTimer = null;
  let progressInFlight = false;
  let upgradePending = false;
  const downloadCancel = document.getElementById('uvm-download-cancel');
  const downloadStats = document.getElementById('uvm-download-stats');
  const upgradeProgress = document.getElementById('uvm-upgrade-progress');
  const upgradePhase = document.getElementById('uvm-upgrade-progress-phase');
  const upgradeMeter = document.getElementById('uvm-upgrade-meter');
  const upgradeStats = document.getElementById('uvm-upgrade-stats');
  const upgradeCancel = document.getElementById('uvm-upgrade-cancel');
  const prefetchEnabled = <?=!empty($uvm_settings['upgrade']['enabled']) ? 'true' : 'false'?>;
  const controlsEnabled = <?=$uvm_controls ? 'true' : 'false'?>;
  const checkUrl = new URL(window.location.href);
  checkUrl.searchParams.set('uvm_view', 'driver');
  checkUrl.searchParams.delete('csrf_token');
  checkUrl.searchParams.delete('uvm_csrf_token');
  checkUrl.hash = '';
  downloadCheck.href = checkUrl.toString();
  cacheCheck.href = checkUrl.toString();

  async function downloadControl(action, id) {
    const body = new URLSearchParams({uvm_action: action, uvm_tab: 'driver',
      csrf_token: <?=uvm_json($uvm_csrf)?>, uvm_csrf_token: <?=uvm_json($uvm_csrf)?>});
    if (id) body.set('job_id', id);
    const controller = new AbortController();
    const timeout = window.setTimeout(function () { controller.abort(); }, 10000);
    try {
      const response = await window.fetch(window.location.href.split('#')[0], {method: 'POST', body: body,
        credentials: 'same-origin', signal: controller.signal});
      const text = await response.text();
      if (!response.ok || text.length > 131072) throw new Error('progress unavailable');
      const html = new DOMParser().parseFromString(text, 'text/html');
      const markers = html.querySelectorAll('script#uvm-download-control-result[type="application/json"]');
      if (markers.length !== 1) throw new Error('progress unavailable');
      const result = JSON.parse(markers[0].textContent);
      if (!result || result.ok !== true || !result.data || result.data.ok !== true) throw new Error('progress unavailable');
      return result.data;
    } finally { window.clearTimeout(timeout); }
  }
  function validTask(task) {
    return task && typeof task === 'object' && (task.status === 'idle' ||
      (/^[a-f0-9]{32}$/.test(task.id || '') && ['manual', 'upgrade'].includes(task.kind) &&
       ['active', 'completed', 'cancelled', 'failed', 'interrupted'].includes(task.status) &&
       ['manual', 'baseline', 'target'].includes(task.scope) &&
       ['resolving', 'downloading', 'verifying', 'publishing'].includes(task.phase) &&
       /^\d+\.\d+\.\d+-Unraid$/.test(task.kernel || '') &&
       Number.isSafeInteger(task.downloaded_bytes) && task.downloaded_bytes >= 0 && task.downloaded_bytes <= 2147483648 &&
       (task.total_bytes === null || (Number.isSafeInteger(task.total_bytes) && task.total_bytes > 0 && task.total_bytes <= 2147483648)) &&
       Number.isSafeInteger(task.speed_bytes_per_second) && task.speed_bytes_per_second >= 0 &&
       typeof task.cancellable === 'boolean' && typeof task.cancel_requested === 'boolean'));
  }
  function bytes(value) { return (value / 1048576).toFixed(1) + ' MiB'; }
  function renderUpgrade(state) {
    if (!state || typeof state.status !== 'string') return;
    const labels = {idle: '尚无升级任务', current: '当前内核缓存已就绪', disabled: '升级预下载已关闭',
      downloading: state.phase === 'baseline' ? '正在准备当前内核恢复缓存' : '正在预下载新内核驱动',
      staging: '正在核验新内核启动安装包', ready: state.boot_ready === false ? '驱动已缓存，未准备启动安装包' : '驱动已准备好',
      missing: '所需内核驱动尚未发布', error: '准备失败', cancelled: '准备已手动取消，不会自动重试', interrupted: '停止结果未确认，自动重试已暂停'};
    if (!Object.hasOwn(labels, state.status)) return;
    const line = document.getElementById('uvm-upgrade-state');
    line.textContent = labels[state.status];
    const kernel = state.phase === 'baseline' ? state.current_kernel : state.target_kernel;
    if (typeof kernel === 'string' && /^\d+\.\d+\.\d+-Unraid$/.test(kernel)) { line.append(' · ', monoText(kernel)); }
    document.getElementById('uvm-upgrade-detail').textContent = typeof state.message === 'string' ? state.message : '';
    document.getElementById('uvm-upgrade-check-button').textContent = state.status === 'cancelled' ? '继续准备' : (state.status === 'interrupted' ? '核对后重试' : '检查升级准备');
  }
  function renderProgress(task) {
    if (!validTask(task)) throw new Error('invalid progress');
    observedTask = task;
    document.getElementById('uvm-upgrade-check-button').disabled = !controlsEnabled || !prefetchEnabled || task.status === 'active' || upgradePending;
    if (task.status === 'idle' || (task.kind === 'manual' && task.id === settledJobId && !downloadPending) ||
        (task.kind === 'manual' && manualJobId && task.id !== manualJobId)) return;
    const manual = task.kind === 'manual';
    const active = task.status === 'active';
    const meter = manual ? downloadMeter : upgradeMeter;
    const stats = manual ? downloadStats : upgradeStats;
    const cancel = manual ? downloadCancel : upgradeCancel;
    const label = manual ? downloadMessage : upgradePhase;
    const scope = manual ? '手动驱动下载' : (task.scope === 'baseline' ? '当前内核恢复缓存' : '新内核预下载');
    const phases = {resolving: '查询版本与校验信息', downloading: '接收驱动包', verifying: '校验缓存，尚未完成', publishing: '提交已校验结果，此阶段不可中断'};
    label.textContent = active ? scope + ' · ' + task.kernel + ' · ' + (task.cancel_requested ? '取消请求已发送，等待任务确认…' : phases[task.phase])
      : ({completed: '任务已完成，请核对缓存及启动选择。', cancelled: '下载已取消，未完成文件已清理，已有缓存保留。', failed: '任务失败，请查看具体错误并核对缓存。', interrupted: '任务停止结果未确认；请先刷新核对进度和缓存。'})[task.status];
    if (manual) { downloadFeedback.hidden = false; }
    else { upgradeProgress.hidden = false; }
    meter.hidden = !active;
    if (task.phase === 'downloading' && task.total_bytes !== null && task.downloaded_bytes <= task.total_bytes) {
      meter.value = task.downloaded_bytes / task.total_bytes;
      stats.textContent = bytes(task.downloaded_bytes) + ' / ' + bytes(task.total_bytes) + '（' + Math.floor(100 * meter.value) + '%）';
    } else {
      meter.removeAttribute('value');
      stats.textContent = task.phase === 'downloading' ? '已接收 ' + bytes(task.downloaded_bytes) + '，总大小未知' : '';
    }
    if (task.phase === 'downloading' && task.speed_bytes_per_second > 0) stats.textContent += ' · 平均 ' + bytes(task.speed_bytes_per_second) + '/s';
    cancel.hidden = !active;
    cancel.disabled = !active || !task.cancellable || task.cancel_requested;
    cancel.dataset.jobId = task.id;
    if (manual && !downloadPending) {
      downloadButton.disabled = active || downloadNeedsCheck;
      downloadPicker.disabled = active || downloadNeedsCheck;
      if (!active) { downloadCheck.hidden = false; }
    }
  }
  function scheduleProgress(delay = 1500) {
    window.clearTimeout(progressTimer);
    progressTimer = window.setTimeout(pollProgress, delay);
  }
  async function pollProgress() {
    if (progressInFlight) return;
    progressInFlight = true;
    let failed = false;
    try {
      const result = await downloadControl('download-status');
      renderUpgrade(result.upgrade);
      renderProgress(result.task);
    } catch (_) {
      failed = true;
      const message = '进度暂不可用，任务可能仍在进行；请刷新核对，不要重复下载。';
      if (downloadPending) { downloadStats.textContent = message; downloadCancel.disabled = true; }
      if (observedTask && observedTask.kind === 'upgrade' && observedTask.status === 'active') { upgradeStats.textContent = message; upgradeCancel.disabled = true; }
    } finally {
      progressInFlight = false;
      if (downloadPending || upgradePending || prefetchEnabled || (observedTask && observedTask.status === 'active')) {
        scheduleProgress(failed ? 5000 : (observedTask && observedTask.status === 'active' ? 1500 : 5000));
      }
    }
  }
  for (const button of [downloadCancel, upgradeCancel]) button.addEventListener('click', async function () {
    if (button.disabled || !/^[a-f0-9]{32}$/.test(button.dataset.jobId || '')) return;
    const id = button.dataset.jobId;
    button.disabled = true;
    try {
      const result = await downloadControl('download-cancel', id);
      if (!validTask(result.task) || result.task.id !== id || typeof result.accepted !== 'boolean') throw new Error('unconfirmed cancellation');
      if (!(id === settledJobId && !downloadPending)) renderProgress(result.task);
      scheduleProgress(100);
    } catch (_) {
      const text = '未能确认取消结果，任务可能仍在进行；请刷新进度后再试。';
      (button === downloadCancel ? downloadStats : upgradeStats).textContent = text;
      scheduleProgress(1500);
    }
  });
  const initialTask = <?=uvm_json($uvm_download_task)?>;
  if (validTask(initialTask) && initialTask.status !== 'idle') renderProgress(initialTask);
  if (prefetchEnabled || initialTask.status === 'active') scheduleProgress(1000);
  document.getElementById('uvm-upgrade-check-form').addEventListener('submit', async function (event) {
    event.preventDefault();
    const form = event.currentTarget;
    const button = document.getElementById('uvm-upgrade-check-button');
    if (upgradePending || ui.isBusy() || button.disabled) return;
    const body = new URLSearchParams(new FormData(form));
    body.set('uvm_upgrade_async', '1');
    const controller = new AbortController();
    const timeout = window.setTimeout(function () { controller.abort(); }, 3630000);
    upgradePending = true;
    ui.setBusy(true);
    button.disabled = true;
    scheduleProgress(1000);
    try {
      const response = await window.fetch(window.location.href.split('#')[0], {method: 'POST', body,
        credentials: 'same-origin', signal: controller.signal});
      const text = await response.text();
      if (!response.ok || text.length > 131072) throw new Error('unconfirmed preparation');
      const html = new DOMParser().parseFromString(text, 'text/html');
      const markers = html.querySelectorAll('script#uvm-upgrade-result[type="application/json"]');
      if (markers.length !== 1) throw new Error('unconfirmed preparation');
      const result = JSON.parse(markers[0].textContent);
      if (result && result.ok === true && result.data && ['current', 'ready', 'disabled'].includes(result.data.status)) renderUpgrade(result.data);
      else if (result && result.ok === false && result.outcome === 'failed' && typeof result.error === 'string') {
        document.getElementById('uvm-upgrade-detail').textContent = result.error;
      } else throw new Error('unconfirmed preparation');
    } catch (_) {
      document.getElementById('uvm-upgrade-detail').textContent = '未能确认准备结果，任务可能仍在进行；请查看进度或刷新核对，不要重复提交。';
    } finally {
      window.clearTimeout(timeout);
      upgradePending = false;
      ui.setBusy(false);
      button.disabled = false;
      scheduleProgress(100);
    }
  });
  window.addEventListener('beforeunload', function (event) {
    if (!downloadPending && !upgradePending) return;
    event.preventDefault();
    event.returnValue = '';
  });
  function showCachedPackage(packageInfo) {
    const body = document.getElementById('uvm-driver-cache-body');
    const fragment = document.createDocumentFragment();
    const identity = packageIdentity(packageInfo, true);
    const filename = document.createElement('p');
    filename.className = 'uvm-muted uvm-cache-filename';
    filename.append(monoText(packageInfo.filename));
    const details = document.createElement('details');
    const summary = document.createElement('summary');
    summary.textContent = '文件信息';
    const data = document.createElement('pre');
    data.textContent = JSON.stringify(packageInfo, null, 2);
    details.append(summary, data);
    const label = document.createElement('p'); label.textContent = '刚下载的缓存，尚未改变下次启动选择。';
    fragment.append(identity, label, filename, details);
    body.replaceChildren(fragment);
  }
  downloadForm.addEventListener('submit', async function (event) {
    event.preventDefault();
    if (ui.isBusy() || downloadButton.disabled || downloadNeedsCheck || !downloadForm.reportValidity()) return;
    // Capture the exact selection and both CSRF fields before disabling inputs;
    // unsaved URL/token/configuration fields are not part of this form.
    // Text-only POSTs must use ordinary form encoding: multipart can stall
    // Unraid's nginx auth_request -> PHP-FPM chain before our handler runs.
    const data = new URLSearchParams(new FormData(downloadForm));
    data.set('uvm_download_async', '1');
    const random = new Uint8Array(16);
    window.crypto.getRandomValues(random);
    manualJobId = Array.from(random, value => value.toString(16).padStart(2, '0')).join('');
    data.set('download_job_id', manualJobId);
    const requestedFilename = downloadPicker.value;
    const pickerWasDisabled = downloadPicker.disabled;
    const controller = new AbortController();
    const started = performance.now();
    const elapsed = function () {
      const seconds = Math.floor((performance.now() - started) / 1000);
      downloadElapsed.textContent = '已用时 ' + Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
    };
    // Longer than the backend's 900s bound, so it can return its own result.
    const timeout = window.setTimeout(function () { controller.abort(); }, 930000);
    const ticker = window.setInterval(elapsed, 1000);
    let outcome = 'unknown';
    ui.setBusy(true);
    downloadPending = true;
    downloadButton.disabled = true;
    downloadPicker.disabled = true;
    downloadForm.classList.add('uvm-form-busy');
    downloadForm.setAttribute('aria-busy', 'true');
    downloadFeedback.hidden = false;
    downloadFeedback.className = 'uvm-note uvm-download-feedback';
    downloadFeedback.setAttribute('role', 'status');
    downloadMeter.hidden = false;
    downloadMeter.removeAttribute('value');
    downloadCheck.hidden = true;
    downloadMessage.textContent = '正在下载并校验驱动包，请稍候…';
    downloadStats.textContent = '等待服务器进度…';
    downloadCancel.hidden = true;
    scheduleProgress(1000);
    elapsed();
    downloadFeedback.focus();
    try {
      const response = await window.fetch(window.location.href.split('#')[0], {
        method: 'POST', body: data, credentials: 'same-origin', signal: controller.signal
      });
      const text = await response.text();
      if (!response.ok || text.length > 1048576) throw new Error('unconfirmed response');
      const html = new DOMParser().parseFromString(text, 'text/html');
      const markers = html.querySelectorAll('script#uvm-download-result[type="application/json"]');
      if (markers.length !== 1) throw new Error('unconfirmed response');
      const result = JSON.parse(markers[0].textContent);
      if (result && result.ok === true && result.outcome === 'cancelled') {
        outcome = 'cancelled';
        downloadMessage.textContent = '下载已取消；未完成文件已清理，已有缓存和运行中的驱动保留。';
        downloadMeter.hidden = true;
        return;
      }
      if (result && result.ok === false && result.outcome === 'failed' && typeof result.error === 'string' && result.error) {
        outcome = 'failed';
        downloadMessage.textContent = '下载未完成：' + result.error;
        downloadFeedback.className = 'uvm-note uvm-error uvm-download-feedback';
        downloadFeedback.setAttribute('role', 'alert');
        downloadMeter.hidden = true;
        return;
      }
      const packageInfo = result && result.data && result.data.package;
      if (!result || result.ok !== true || result.outcome !== 'cached' || !packageInfo ||
          typeof packageInfo.driver !== 'string' || !/^\d+\.\d+\.\d+$/.test(packageInfo.driver) ||
          !Number.isSafeInteger(packageInfo.build) || packageInfo.build < 1 ||
          packageInfo.kernel !== downloadForm.dataset.kernel ||
          packageInfo.filename !== 'nvidia-' + packageInfo.driver + '-' + packageInfo.kernel + '-' + packageInfo.build + '.txz' ||
          (requestedFilename && packageInfo.filename !== requestedFilename) ||
          packageInfo.checksum_verified !== true || typeof packageInfo.md5 !== 'string' || !/^[a-f0-9]{32}$/.test(packageInfo.md5)) {
        throw new Error('unconfirmed cache identity');
      }
      outcome = 'cached';
      downloadMeter.value = 1;
      downloadFeedback.className = 'uvm-note uvm-success uvm-download-feedback';
      downloadMessage.textContent = '驱动已下载并校验，请在已下载列表选择下次启动构建。当前选择及运行驱动未改变。';
      showCachedPackage(packageInfo);
      renderCacheList(result.data.package_cache);
    } catch (_) {
      downloadCheck.hidden = false;
      if (outcome === 'cached') {
        downloadNeedsCheck = true;
        downloadMessage.textContent = '下载与校验已确认，但缓存界面更新未完成；请重新打开页面核对，不要重复下载。';
      } else {
        downloadNeedsCheck = true;
        downloadFeedback.className = 'uvm-note uvm-warning uvm-download-feedback';
        downloadFeedback.setAttribute('role', 'alert');
        downloadMessage.textContent = '未能确认下载结果。请求可能仍在服务器进行，请重新打开页面核对缓存后再试。';
        downloadMeter.hidden = true;
      }
    } finally {
      window.clearTimeout(timeout);
      window.clearInterval(ticker);
      if (outcome !== 'unknown') settledJobId = manualJobId;
      manualJobId = '';
      downloadCancel.hidden = true;
      downloadPending = false;
      downloadForm.classList.remove('uvm-form-busy');
      downloadForm.setAttribute('aria-busy', 'false');
      if (!downloadNeedsCheck) {
        downloadButton.disabled = false;
        downloadPicker.disabled = pickerWasDisabled;
        ui.setBusy(false);
      }
    }
  });

  cacheList.addEventListener('submit', async function (event) {
    const form = event.target.closest('.uvm-cache-delete-form');
    if (!form) return;
    event.preventDefault();
    const button = form.querySelector('button');
    if (ui.isBusy() || button.disabled || document.getElementById('uvm-downloaded-panel').dataset.controls !== '1') return;
    const data = new URLSearchParams(new FormData(form));
    const filename = data.get('cache_filename');
    const forgetHistory = form.dataset.historyConfirmation === '1';
    const warning = forgetHistory ? '\n同时删除对应内核的回退记录，该内核将失去离线驱动缓存；以后回退需重新下载或上传。' : '';
    if (!window.confirm('删除缓存包 ' + filename + ' 及其同名 MD5？' + warning + '\n此操作不可本地撤销；需要恢复时请重新下载或上传。不会卸载当前驱动。')) return;
    if (forgetHistory) data.set('cache_forget_history', '1');
    data.set('uvm_cache_delete_async', '1');
    const controller = new AbortController();
    const timeout = window.setTimeout(function () { controller.abort(); }, 45000);
    let unknown = true;
    ui.setBusy(true);
    button.disabled = true;
    cacheMessage.hidden = false; cacheMessage.className = 'uvm-note'; cacheMessage.setAttribute('role', 'status');
    cacheMessage.textContent = '正在删除缓存包…'; cacheMessage.focus(); cacheCheck.hidden = true;
    try {
      const response = await fetch(window.location.href.split('#')[0], { method: 'POST', body: data, credentials: 'same-origin', signal: controller.signal });
      const text = await response.text();
      if (!response.ok || text.length > 1048576) throw new Error('unconfirmed response');
      const html = new DOMParser().parseFromString(text, 'text/html');
      const markers = html.querySelectorAll('script#uvm-cache-delete-result[type="application/json"]');
      if (markers.length !== 1) throw new Error('unconfirmed response');
      const result = JSON.parse(markers[0].textContent);
      if (result && result.ok === false && result.outcome === 'failed' && typeof result.error === 'string') {
        unknown = false;
        renderCacheList(null);
        cacheMessage.className = 'uvm-note uvm-error'; cacheMessage.setAttribute('role', 'alert');
        cacheMessage.textContent = result.error + ' 请重新打开页面核对列表后再试。';
        return;
      }
      if (!result || result.ok !== true || !result.data || result.data.changed !== true || result.data.filename !== filename || !Array.isArray(result.data.removed) || !result.data.removed.includes(filename)) throw new Error('unconfirmed deletion');
      unknown = false;
      renderCacheList(result.data.package_cache);
      cacheMessage.className = 'uvm-note uvm-success';
      cacheMessage.textContent = typeof result.message === 'string' ? result.message : '缓存包已删除，需要时请重新下载或上传。';
    } catch (_) {
      renderCacheList(null);
      cacheMessage.className = 'uvm-note uvm-warning'; cacheMessage.setAttribute('role', 'alert');
      cacheMessage.textContent = '未能确认删除结果。请重新打开页面核对缓存，不要重复提交。';
    } finally {
      window.clearTimeout(timeout);
      if (!unknown) ui.setBusy(false);
    }
  });

  const uploadForm = document.getElementById('uvm-upload-form');
  const uploadButton = document.getElementById('uvm-upload-submit');
  const uploadCancel = document.getElementById('uvm-upload-cancel');
  const uploadProgress = document.getElementById('uvm-upload-progress');
  const uploadMeter = document.getElementById('uvm-upload-meter');
  const uploadCheck = document.getElementById('uvm-upload-check');
  uploadCheck.href = checkUrl.toString();
  const browserChunkSize = <?=uvm_json(uvm_upload_chunk_size())?>;
  let cancelUpload = false;
  let activeUploadId = '';
  let uploadFinalizing = false;
  let uploadPending = false;
  window.addEventListener('beforeunload', function (event) {
    if (!uploadPending) return;
    event.preventDefault(); event.returnValue = '';
  });
  window.addEventListener('pagehide', function () {
    if (!activeUploadId || uploadFinalizing || !navigator.sendBeacon) return;
    const data = new URLSearchParams();
    data.append('uvm_action', 'source-upload-cancel');
    data.append('uvm_tab', 'driver');
    data.append('csrf_token', csrf);
    data.append('uvm_csrf_token', csrf);
    data.append('upload_id', activeUploadId);
    navigator.sendBeacon(window.location.href.split('#')[0], data);
  });
  uploadCancel.addEventListener('click', function () {
    cancelUpload = true;
    uploadProgress.textContent = '正在取消…';
    uploadCancel.disabled = true;
  });
  function uploadError(text, unknown) {
    const error = new Error(text); error.unknown = Boolean(unknown); return error;
  }
  async function encodeUploadChunk(chunk) {
    const bytes = new Uint8Array(await chunk.arrayBuffer());
    let binary = '';
    for (let index = 0; index < bytes.length; index += 16384) binary += String.fromCharCode.apply(null, bytes.subarray(index, index + 16384));
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  async function uploadRequest(action, fields, chunk) {
    // All upload requests, including binary chunks and pagehide cancellation,
    // use URL-encoded text so native auth subrequests never parse multipart.
    const data = new URLSearchParams();
    data.append('uvm_action', action);
    data.append('uvm_tab', 'driver');
    data.append('csrf_token', csrf);
    data.append('uvm_csrf_token', csrf);
    Object.entries(fields).forEach(function (entry) { data.append(entry[0], String(entry[1])); });
    if (chunk) data.append('chunk_data', await encodeUploadChunk(chunk));
    const controller = new AbortController();
    const timer = window.setTimeout(function () { controller.abort(); }, action === 'source-upload-finish' ? 930000 : 95000);
    try {
      const response = await fetch(window.location.href.split('#')[0], {
        method: 'POST', body: data, credentials: 'same-origin', signal: controller.signal
      });
      const text = await response.text();
      if (!response.ok || text.length > 1048576) throw uploadError('未能确认上传响应，请重新打开页面核对缓存。', true);
      const html = new DOMParser().parseFromString(text, 'text/html');
      const results = html.querySelectorAll('script#uvm-upload-result[type="application/json"]');
      if (results.length !== 1) throw uploadError('未收到唯一的上传结果，请重新打开页面核对缓存。', true);
      const parsed = JSON.parse(results[0].textContent);
      if (parsed && parsed.ok === false && parsed.outcome === 'failed' && typeof parsed.error === 'string') throw uploadError(parsed.error, false);
      if (!parsed || parsed.ok !== true || !parsed.data || typeof parsed.data !== 'object') throw uploadError('上传结果未知，请重新打开页面核对缓存。', true);
      return parsed.data;
    } catch (error) {
      if (typeof error.unknown !== 'boolean') throw uploadError('上传连接中断或结果无法确认，请重新打开页面核对缓存。', true);
      throw error;
    } finally { window.clearTimeout(timer); }
  }
  uploadForm.addEventListener('submit', async function (event) {
    event.preventDefault();
    if (window.uvmUI.isBusy()) return;
    const file = document.getElementById('uvm-upload-package').files[0];
    const checksum = document.getElementById('uvm-upload-checksum').files[0];
    if (!file || !file.name.endsWith('.txz') || file.size < 1 || file.size > 2147483648) {
      message('请选择不超过 2 GiB 的 TXZ 驱动包。', true);
      return;
    }
    if (!checksum || checksum.size < 1 || checksum.size > 4096) { message('必须同时选择非空且不超过 4 KiB 的 MD5 文件。', true); return; }
    if (browserChunkSize < 65536) { message('服务器的 PHP 上传限制过小，无法上传。', true); return; }
    window.uvmUI.setBusy(true);
    uploadPending = true;
    uploadFinalizing = false;
    cancelUpload = false;
    uploadCheck.hidden = true;
    uploadButton.disabled = true;
    uploadCancel.hidden = false;
    uploadCancel.disabled = false;
    uploadMeter.hidden = false;
    uploadMeter.value = 0;
    uploadProgress.textContent = '准备上传…';
    let uploadId = '';
    let finished = false;
    let cancelAttempted = false;
    let needsCheck = false;
    try {
      const checksumText = (await checksum.text()).trim();
      const checksumMatch = checksumText.match(/^([a-fA-F0-9]{32})(?:[ \t]+\*?([^\r\n]+))?$/);
      if (!checksumMatch || (checksumMatch[2] && checksumMatch[2] !== file.name)) throw uploadError('MD5 格式无效或文件名与所选驱动包不匹配。', false);
      const begun = await uploadRequest('source-upload-begin', {
        filename: file.name, total: file.size, checksum: checksumText
      });
      if (!/^[a-f0-9]{32}$/.test(begun.upload_id) || begun.offset !== 0 || begun.total !== file.size || !Number.isSafeInteger(begun.max_chunk_size) || begun.max_chunk_size < 1 || begun.max_chunk_size > 1048576) throw uploadError('上传初始化结果无效，请核对缓存。', true);
      uploadId = begun.upload_id;
      activeUploadId = uploadId;
      let offset = Number(begun.offset);
      const chunkSize = Math.min(browserChunkSize, Number(begun.max_chunk_size));
      while (offset < file.size) {
        if (cancelUpload) break;
        const nextOffset = Math.min(offset + chunkSize, file.size);
        const chunk = await uploadRequest('source-upload-chunk', {upload_id: uploadId, offset: offset}, file.slice(offset, nextOffset));
        if (chunk.upload_id !== uploadId || chunk.offset !== nextOffset || chunk.total !== file.size) throw uploadError('上传进度回执不一致，请核对缓存。', true);
        offset = nextOffset;
        const percentage = Math.floor(offset * 100 / file.size);
        uploadMeter.value = percentage;
        uploadProgress.textContent = percentage + '% · ' + (offset / 1048576).toFixed(1) + ' / ' + (file.size / 1048576).toFixed(1) + ' MiB';
      }
      if (cancelUpload) {
        cancelAttempted = true;
        const cancelled = await uploadRequest('source-upload-cancel', {upload_id: uploadId});
        if (cancelled.ok !== true || cancelled.upload_id !== uploadId) throw uploadError('未确认取消结果，请核对缓存。', true);
        uploadId = '';
        activeUploadId = '';
        uploadProgress.textContent = '已取消。';
      } else {
        uploadCancel.hidden = true;
        uploadFinalizing = true;
        uploadProgress.textContent = '上传完成，正在校验…';
        const imported = await uploadRequest('source-upload-finish', {upload_id: uploadId});
        const pkg = imported.package;
        if (!pkg || pkg.filename !== file.name || pkg.kernel !== uploadForm.dataset.kernel || pkg.checksum_verified !== true || typeof pkg.md5 !== 'string' || pkg.md5.toLowerCase() !== checksumMatch[1].toLowerCase()) throw uploadError('未确认导入包或 MD5 校验回执，请核对缓存。', true);
        uploadId = '';
        activeUploadId = '';
        finished = true;
        uploadProgress.textContent = '导入完成。';
        window.location.replace(checkUrl.toString());
      }
    } catch (error) {
      needsCheck = error.unknown === true || cancelAttempted;
      message(error.message || '导入失败。', true);
      uploadProgress.textContent = needsCheck ? '结果未确认；请核对缓存，不要重复提交。' : '导入未完成。';
    } finally {
      if (uploadId && !cancelAttempted && !(uploadFinalizing && needsCheck)) {
        cancelAttempted = true;
        try {
          const cleared = await uploadRequest('source-upload-cancel', {upload_id: uploadId});
          if (cleared.ok !== true || cleared.upload_id !== uploadId) throw uploadError('取消回执无效。', true);
        } catch (_) { needsCheck = true; uploadProgress.textContent = '临时上传清理未确认；请核对缓存，不要重复提交。'; }
      }
      activeUploadId = '';
      uploadPending = false;
      uploadCheck.hidden = !needsCheck;
      if (!finished && !needsCheck) {
        window.uvmUI.setBusy(false);
        uploadButton.disabled = false;
        uploadCancel.hidden = true;
      }
      if (needsCheck) { uploadCancel.hidden = true; uploadButton.disabled = true; }
    }
  });

})();
</script>
