# 开发与验证

基线为 novidio `2026.07.13c` / `299af973bf564036e978eff9dc2e8e3e2f65da9d`。
打包器只打包 `source/usr/local/emhttp/plugins/unraid-vgpu-manager/`，不会混入旧插件。

## 边界

- 构建、测试仅在开发机运行，不连接 Unraid、不启动 NVIDIA daemon、不运行驱动安装器。
- 运行时使用 Unraid 自带 PHP 8.1+、Bash、curl、tar、xz、modinfo、mdevctl 和 libvirt 工具。
  Python 仅用于开发机测试/打包，不要求 Unraid 安装 Python。
- `Application.php` 是统一事务入口，页面不得直接调用 mdevctl 或写配置。
- Store 使用 close-on-exec 锁句柄，所有写操作共用锁，后台 daemon 不继承锁。
- Runtime 管理已安装驱动；本预览不安装 NVIDIA TXZ、不在线重载模块、不替用户停 VM/Docker。
- Download 仅做被动检查，不执行归档内安装脚本。
- 模拟测试通过不代表已经实机验收。

## 测试

```bash
bash tests/run-tests.sh
```

可用 `PHP_BIN=/path/to/php` 指定 PHP CLI；也识别本地
`.tools/php/root/usr/bin/php8.4`。本次将 Debian PHP CLI 包解压到 `.tools`，没有向宿主
安装 PHP。测试使用临时目录和模拟命令/HTTP，不读取真实凭据。

## 离线预览包

```bash
python3 scripts/build-plugin.py --version 2026.11.02.3
```

产物在 `dist/2026.11.02.3/`：插件 TXZ、MD5、自包含离线 `offline/unraid-vgpu-manager.plg`。
离线定义内嵌同一 TXZ，仅用于尚无公开地址时交付。首次安装不需要 GitLab token，
也不下载/安装 NVIDIA 驱动。不同于原版只有下载链接的 `.plg`。

生成常规 URL 安装器，地址仅用于插件自身：

```bash
python3 scripts/build-plugin.py \
  --version 2026.11.02.3 \
  --output dist/public-preview \
  --public-base https://github.com/ManuZhu0728/unraid-vgpu-plugin/releases/download/v2026.11.02.3-alpha.1 \
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
发布后仍需核对附件、在线 PLG 和工作流结果；发布成功不等于实机验收。

## CLI

`scripts/rc.vgpu` 调用 `include/cli.php`，请求 JSON 经 stdin；响应为
`{"ok":true,"data":{...}}` 或 `{"ok":false,"error":"..."}`，失败返回非零。
生产 CLI 只允许 Unraid/root，不能通过环境变量或请求切换测试 root。
测试直接构造 `Paths($temporaryRoot)` 和模拟 runner。

命令：`status`、`plan`、`config-save`、`source-test`、`source-versions`、`source-download`、
`device-save`、`device-start`、`device-stop`、`device-remove`、`ensure`、`boot`。
status/plan 只读，其余加锁。

## 生产部署前

1. 使用公开插件仓库，不复制私有构建仓库历史或凭据。
2. 核对上游/依赖声明、安装包内容和脱敏输出。
3. 单独审查维护迁移：备份旧入口、保留驱动安装链，再导入配置并停用旧启动入口。
4. 缓存驱动的安装/激活另行实现和审查，不能把下载成功显示为驱动升级成功。
5. 经单独授权测试开机、VM 停启、Docker CUDA、并发与显存压力。
