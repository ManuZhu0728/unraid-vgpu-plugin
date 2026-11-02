# 开发与验证

基线为 novidio `2026.07.13c` / `299af973bf564036e978eff9dc2e8e3e2f65da9d`。
插件源码位于 `source/usr/local/emhttp/plugins/unraid-vgpu-manager/`。

## 结构

- 开发机测试使用临时文件、模拟 HTTP/命令和本地 PHP HTTP 服务。
- 运行时使用 Unraid 自带 PHP 8.1+、Bash、curl、tar、xz、modinfo、mdevctl 和 libvirt 工具。
  Python 仅用于开发机测试/打包，不要求 Unraid 安装 Python。
- `Application.php` 是统一事务入口，页面不得直接调用 mdevctl 或写配置。
- Store 使用 close-on-exec 锁句柄，所有写操作共用锁，后台 daemon 不继承锁。
- `Source.php` 解析下载链接；`Download.php` 处理版本匹配和归档校验。
- `Upload.php` 处理本地分块上传，完成后复用下载器的校验和缓存逻辑。
- `Upgrade.php` 准备当前/目标内核缓存；独立 PHP 助手监听系统升级。
- `Installer.php` 在后续启动安装已验证缓存；`Runtime.php` 随后恢复 vGPU。

## 测试

```bash
bash tests/run-tests.sh
```

可用 `PHP_BIN=/path/to/php` 指定 PHP CLI；也识别本地
`.tools/php/root/usr/bin/php8.4`。本次将 Debian PHP CLI 包解压到 `.tools`，没有向宿主
安装 PHP。测试使用临时目录和模拟命令/HTTP，不读取真实凭据。

## 离线预览包

```bash
python3 scripts/build-plugin.py --version 2026.11.02.5
```

产物在 `dist/2026.11.02.5/`：插件 TXZ、MD5、自包含离线 `offline/unraid-vgpu-manager.plg`。
离线定义内嵌同一 TXZ；在线定义从公开 Release 下载插件包。

生成常规 URL 安装器，地址仅用于插件自身：

```bash
python3 scripts/build-plugin.py \
  --version 2026.11.02.5 \
  --output dist/public-preview \
  --public-base https://github.com/ManuZhu0728/unraid-vgpu-plugin/releases/download/v2026.11.02.5-alpha.3 \
  --update-url https://raw.githubusercontent.com/ManuZhu0728/unraid-vgpu-plugin/main/unraid-vgpu-manager.plg
```

不能把 token 放进地址。Alpha 使用固定 raw PLG，而不是只适用于正式版的 latest 地址。
默认不覆盖同名产物，
代码变化后使用新版本或新输出目录。没有提供地址时，不生成假冒可在线安装的定义。

## 发布

1. 更新 `.github/release.json` 的版本、tag 和 prerelease，并准备同名 `docs/releases/TAG.md`。
2. 按上述方法构建，把生成的在线 PLG 同步到根目录 `unraid-vgpu-manager.plg`；不得放离线版。
3. 本地测试、审核并提交；推送 main 的 release.json 或发布工作流变化触发 GitHub Actions。
4. 测试成功后使用仓库级 `GITHUB_TOKEN` 发布三个附件：在线 PLG、插件 TXZ、MD5。
   不读取用户 token、不发布 NVIDIA 驱动、不上传本地预览目录。

发布器拒绝覆盖已有的不同内容或移动 tag；同提交下可补齐缺失附件。
`python3 scripts/publish-github.py --dry-run` 可构建并核对发布计划，不访问写 API。
发布后核对附件、在线 PLG 和工作流结果，实机测试记录另存于验证文档。

## CLI

`scripts/rc.vgpu` 调用 `include/cli.php`，请求 JSON 经 stdin；响应为
`{"ok":true,"data":{...}}` 或 `{"ok":false,"error":"..."}`，失败返回非零。
生产 CLI 只允许 Unraid/root，不能通过环境变量或请求切换测试 root。
测试直接构造 `Paths($temporaryRoot)` 和模拟 runner。

命令：`status`、`plan`、`config-save`、`source-test`、`source-versions`、`source-download`、
`source-import`、`source-upload-begin`、`source-upload-chunk`、`source-upload-finish`、
`source-upload-cancel`、`upgrade-check`、`device-save`、`device-start`、`device-stop`、`device-remove`、`ensure`、`boot`。
status/plan 只读，其余加锁。

`upgrade-check` 读取系统目标内核并准备缓存。CLI 可通过 stdin 显式提供 `target_kernel`，
页面只能使用系统升级元数据。`boot` 在更新开关启用时先运行安装器，成功后才调用 Runtime。
`force: true` 跳过失败后的重试等待；页面“检查升级准备”使用此选项。
助手不持有事务锁，子 CLI 使用同一把锁，忙时重试。

上传请求通过本页 POST 和原生 CSRF 校验。PHP 确认分块来自 HTTP 上传后传给 CLI，
CLI 校验临时路径和所有者，再写入独立上传会话。每块最多 1 MiB，总量最多 2 GiB。
页面从 Unraid 外层 HTML 中提取带标识的 JSON 结果；导入完成以 GET 导航更新页面。

## 生产部署前

1. 使用公开插件仓库，不复制私有构建仓库历史或凭据。
2. 核对上游/依赖声明、安装包内容和脱敏输出。
3. 单独审查维护迁移：备份旧入口、保留驱动安装链，再导入配置并停用旧启动入口。
4. 分别验收目标内核预下载、冷启动安装、普通重启恢复和失败状态。
5. 经单独授权测试开机、VM 停启、Docker CUDA、并发与显存压力。
