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
        <label class="uvm-field"><span>下载地址</span><input name="source_url" id="uvm-source-url" type="url" maxlength="1024" placeholder="GitHub / GitLab 仓库、Release 或驱动包链接" value="<?=uvm_h($uvm_source['url'] ?: $uvm_source['repository'])?>"><small>自动识别来源，并匹配 <?=uvm_h(php_uname('r'))?>。</small></label>
        <label class="uvm-field"><span>Token（私有仓库）</span><input name="credential_token" id="uvm-credential-token" type="password" maxlength="4096" value="" autocomplete="new-password" spellcheck="false"><small><?=!empty($uvm_status['credential_configured']) ? '已保存。留空保留，输入新 Token 可替换。' : '公开仓库留空。'?></small></label>
      </div>
      <div class="uvm-grid" style="margin-top:14px">
        <label class="uvm-field"><span>跟随 Unraid 更新驱动</span><select name="upgrade_enabled"><option value="0" <?=empty($uvm_settings['upgrade']['enabled']) ? 'selected' : ''?>>关闭</option><option value="1" <?=!empty($uvm_settings['upgrade']['enabled']) ? 'selected' : ''?>>开启</option></select><small>提前下载目标内核包，下次开机安装。首次启用请迁移原驱动启动入口。</small></label>
      </div>
      <input type="hidden" name="source_repository" value="<?=uvm_h($uvm_source['repository'])?>">
      <details class="uvm-advanced"><summary>高级设置</summary>
        <div class="uvm-grid">
          <label class="uvm-field"><span>平台</span><select name="source_provider" id="uvm-source-provider"><?php foreach (['auto'=>'自动识别','github'=>'GitHub','gitlab'=>'GitLab','direct'=>'文件直链'] as $key=>$label): ?><option value="<?=uvm_h($key)?>" <?=($uvm_source['mode'] === 'auto' ? $key === 'auto' : $uvm_source['provider'] === $key) ? 'selected' : ''?>><?=uvm_h($label)?></option><?php endforeach; ?></select></label>
          <label class="uvm-field"><span>下载方式</span><select name="source_mode" id="uvm-source-mode"><?php foreach (['auto'=>'自动','releases'=>'Releases','generic'=>'GitLab Generic Packages'] as $key=>$label): ?><option value="<?=uvm_h($key)?>" <?=$uvm_source['mode'] === $key ? 'selected' : ''?>><?=uvm_h($label)?></option><?php endforeach; ?></select></label>
          <label class="uvm-field"><span>固定驱动版本</span><input name="source_driver" type="text" maxlength="32" placeholder="自动（优先当前版本）" value="<?=uvm_h($uvm_source['driver'])?>"></label>
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
    <div class="uvm-scroll"><table><thead><tr><th>驱动版本</th><th>内核</th><th>文件</th></tr></thead><tbody>
    <?php foreach ($uvm_versions as $version): if (!is_array($version)) continue; ?><tr><td><?=uvm_h($version['driver'] ?? '')?></td><td><?=uvm_h($version['kernel'] ?? '')?></td><td><?=uvm_h($version['filename'] ?? '')?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <form method="post" class="uvm-submit-form uvm-inline-form">
      <?php uvm_form_hidden('source-download', $uvm_csrf); ?>
      <div class="uvm-actions">
        <select name="download_driver" aria-label="下载版本">
          <option value="">自动选择<?=($uvm_source['driver'] !== '' ? '（' . uvm_h($uvm_source['driver']) . '）' : '（优先当前版本）')?></option>
          <?php foreach ($uvm_versions as $version): if (!is_array($version)) continue; ?><option value="<?=uvm_h($version['driver'] ?? '')?>"><?=uvm_h($version['driver'] ?? '')?></option><?php endforeach; ?>
        </select>
        <button type="submit" <?=!$uvm_controls || ($uvm_source['url'] === '' && $uvm_source['repository'] === '') ? 'disabled' : ''?>>下载到缓存</button>
      </div>
    </form>
  </section>

  <section class="uvm-panel" id="uvm-local-panel" <?=$uvm_driver_mode !== 'local' ? 'hidden' : ''?>>
    <h2>本地导入</h2>
    <form id="uvm-upload-form" enctype="multipart/form-data">
      <fieldset <?=!$uvm_controls ? 'disabled' : ''?>>
      <div class="uvm-grid">
        <label class="uvm-field"><span>驱动包</span><input id="uvm-upload-package" type="file" accept=".txz" required></label>
        <label class="uvm-field"><span>MD5 文件（可选）</span><input id="uvm-upload-checksum" type="file" accept=".md5,.txt"></label>
      </div>
      <div class="uvm-actions"><button type="submit" id="uvm-upload-submit">导入到缓存</button><button type="button" id="uvm-upload-cancel" hidden>取消</button><span id="uvm-upload-progress" role="status"></span></div>
      <progress id="uvm-upload-meter" max="100" value="0" hidden></progress>
      </fieldset>
    </form>
    <p class="uvm-muted">上传后自动校验。开启“跟随 Unraid 更新驱动”后，匹配的缓存将在下次开机安装。</p>
  </section>


<section class="uvm-panel">
  <h2>驱动缓存</h2>
  <?php if ($uvm_selected_package): ?>
  <p>NVIDIA <?=uvm_h($uvm_selected_package['driver'] ?? '')?> · <code><?=uvm_h($uvm_selected_package['kernel'] ?? '')?></code> · <?=($uvm_selected_package['source'] ?? '') === 'local' ? '本地导入' : '在线下载'?></p>
  <p class="uvm-muted"><?=uvm_h($uvm_selected_package['filename'] ?? basename($uvm_selected_package['path'] ?? ''))?></p>
  <details><summary>文件信息</summary><pre><?=uvm_h(json_encode(uvm_redact($uvm_selected_package), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE))?></pre></details>
  <?php else: ?><p class="uvm-muted">尚无缓存，下载或上传一个匹配内核的驱动包。</p><?php endif; ?>
</section>
  <section class="uvm-panel">
    <h2>系统升级</h2>
    <?php $uvm_upgrade_label = ['idle'=>'尚无升级任务','current'=>'当前内核缓存已就绪','disabled'=>'自动更新已关闭','downloading'=>'正在下载驱动','ready'=>'驱动已准备好','missing'=>'目标内核驱动尚未发布','error'=>'准备失败','blocked'=>'等待处理启动入口冲突']; ?>
    <p><?=uvm_h($uvm_upgrade_label[$uvm_upgrade['status'] ?? 'idle'] ?? ($uvm_upgrade['status'] ?? '尚无升级任务'))?><?php if (!empty($uvm_upgrade['target_kernel'])): ?> · <code><?=uvm_h($uvm_upgrade['target_kernel'])?></code><?php endif; ?><?php if (!empty($uvm_upgrade['package']['driver'])): ?> · NVIDIA <?=uvm_h($uvm_upgrade['package']['driver'])?><?php endif; ?></p>
    <?php if (in_array($uvm_upgrade['status'] ?? '', ['error','missing','blocked'], true) && !empty($uvm_upgrade['message'])): ?><p class="uvm-muted"><?=uvm_h(uvm_redact($uvm_upgrade['message']))?></p><?php endif; ?>
    <?php if ($uvm_installation): ?>
    <p>开机安装：<?=uvm_h(['installed'=>'已完成','already_active'=>'驱动已加载','installing'=>'正在安装','failed'=>'失败','blocked'=>'等待迁移启动入口','missing_package'=>'缺少匹配包'][$uvm_installation['status'] ?? ''] ?? ($uvm_installation['status'] ?? ''))?> · <code><?=uvm_h($uvm_installation['kernel'] ?? '')?></code></p>
    <?php if (in_array($uvm_installation['status'] ?? '', ['failed','blocked','missing_package'], true)): ?><p class="uvm-muted"><?=uvm_h(uvm_redact($uvm_installation['message'] ?? ''))?></p><?php endif; ?>
    <?php endif; ?>
    <form method="post" class="uvm-submit-form"><?php uvm_form_hidden('upgrade-check', $uvm_csrf); ?><button type="submit" <?=!$uvm_controls || empty($uvm_settings['upgrade']['enabled']) ? 'disabled' : ''?>>检查升级准备</button></form>
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

  const uploadForm = document.getElementById('uvm-upload-form');
  const uploadButton = document.getElementById('uvm-upload-submit');
  const uploadCancel = document.getElementById('uvm-upload-cancel');
  const uploadProgress = document.getElementById('uvm-upload-progress');
  const uploadMeter = document.getElementById('uvm-upload-meter');
  const csrf = <?=uvm_json($uvm_csrf)?>;
  const browserChunkSize = <?=uvm_json(uvm_upload_chunk_size())?>;
  let cancelUpload = false;
  let activeUploadId = '';
  window.addEventListener('pagehide', function () {
    if (!activeUploadId || !navigator.sendBeacon) return;
    const data = new FormData();
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
  async function uploadRequest(action, fields, chunk) {
    const data = new FormData();
    data.append('uvm_action', action);
    data.append('uvm_tab', 'driver');
    data.append('csrf_token', csrf);
    data.append('uvm_csrf_token', csrf);
    Object.entries(fields).forEach(function (entry) { data.append(entry[0], String(entry[1])); });
    if (chunk) data.append('chunk', chunk, 'chunk.bin');
    const controller = new AbortController();
    const timer = window.setTimeout(function () { controller.abort(); }, action === 'source-upload-finish' ? 900000 : 90000);
    try {
      const response = await fetch(window.location.href.split('#')[0], {
        method: 'POST', body: data, credentials: 'same-origin', signal: controller.signal
      });
      if (!response.ok) throw new Error('上传请求失败（HTTP ' + response.status + '）。');
      const html = new DOMParser().parseFromString(await response.text(), 'text/html');
      const result = html.getElementById('uvm-upload-result');
      if (!result) throw new Error('未收到上传结果，请刷新页面检查登录状态。');
      const parsed = JSON.parse(result.textContent);
      if (!parsed.ok) throw new Error(parsed.error || '导入失败。');
      return parsed.data;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('上传请求超时，请刷新页面检查缓存。');
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
    if (checksum && checksum.size > 4096) { message('MD5 文件不能超过 4 KiB。', true); return; }
    if (browserChunkSize < 65536) { message('服务器的 PHP 上传限制过小，无法上传。', true); return; }
    window.uvmUI.setBusy(true);
    cancelUpload = false;
    uploadButton.disabled = true;
    uploadCancel.hidden = false;
    uploadCancel.disabled = false;
    uploadMeter.hidden = false;
    uploadMeter.value = 0;
    uploadProgress.textContent = '准备上传…';
    let uploadId = '';
    let finished = false;
    try {
      const begun = await uploadRequest('source-upload-begin', {
        filename: file.name, total: file.size, checksum: checksum ? await checksum.text() : ''
      });
      uploadId = begun.upload_id;
      activeUploadId = uploadId;
      let offset = Number(begun.offset);
      const chunkSize = Math.min(browserChunkSize, Number(begun.max_chunk_size));
      if (!uploadId || !Number.isInteger(offset) || offset !== 0 || !Number.isInteger(chunkSize) || chunkSize < 1) throw new Error('上传初始化结果无效。');
      while (offset < file.size) {
        if (cancelUpload) break;
        const nextOffset = Math.min(offset + chunkSize, file.size);
        const chunk = await uploadRequest('source-upload-chunk', {upload_id: uploadId, offset: offset}, file.slice(offset, nextOffset));
        if (Number(chunk.offset) !== nextOffset) throw new Error('上传进度不一致，请重新导入。');
        offset = nextOffset;
        const percentage = Math.floor(offset * 100 / file.size);
        uploadMeter.value = percentage;
        uploadProgress.textContent = percentage + '% · ' + (offset / 1048576).toFixed(1) + ' / ' + (file.size / 1048576).toFixed(1) + ' MiB';
      }
      if (cancelUpload) {
        await uploadRequest('source-upload-cancel', {upload_id: uploadId});
        uploadId = '';
        activeUploadId = '';
        uploadProgress.textContent = '已取消。';
      } else {
        uploadCancel.hidden = true;
        uploadProgress.textContent = '上传完成，正在校验…';
        await uploadRequest('source-upload-finish', {upload_id: uploadId});
        uploadId = '';
        activeUploadId = '';
        finished = true;
        uploadProgress.textContent = '导入完成。';
        window.location.replace(window.location.href.split('#')[0]);
      }
    } catch (error) {
      message(error.message || '导入失败。', true);
      uploadProgress.textContent = '导入未完成。';
    } finally {
      if (uploadId) {
        try { await uploadRequest('source-upload-cancel', {upload_id: uploadId}); } catch (_) {}
      }
      activeUploadId = '';
      if (!finished) {
        window.uvmUI.setBusy(false);
        uploadButton.disabled = false;
        uploadCancel.hidden = true;
      }
    }
  });

})();
</script>

