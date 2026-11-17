# 开发与验证

## 结构

生产环境使用 Unraid 自带的 PHP 8.1+、Bash、curl、tar、xz、modinfo、mdevctl 和 libvirt 工具。
Python 用于开发测试和打包。插件源代码位于 `source/usr/local/emhttp/plugins/unraid-vgpu-manager/`。

| 入口或文件 | 用途 |
| --- | --- |
| `*.page`、`include/ui-page.php`、`include/ui/` | 原生标签页、表单分发和页面状态。Unraid 在同一请求渲染三个子页，`state.php` 只处理一次提交。 |
| `Application.php`、`Store.php`、`cli.php` | CLI 调用、事务及配置锁。网页通过 CLI 操作，守护进程不继承配置锁。 |
| `Source.php`、`Download.php`、`Upload.php` | 下载源、包校验与分块导入。 |
| `PackageIdentity.php`、`PackageHistory.php`、`NativeBoot.php` | 解析包名和构建号，按内核保存缓存回执、选择与原生安装副本。旧回执缺失构建号时仅兼容 `-1.txz`。 |
| `Upgrade.php`、`Installer.php`、`Runtime.php` | 准备升级缓存与启动副本、核验 Unraid 原生安装、启动 vGPU 服务与设备。 |
| 原生 `post-hooks/unraid-vgpu-manager`、`include/upgrade-check.php` | 系统更新成功后同步复用 CLI 准备驱动；与保留的定时兜底共享事务锁。 |
| `Inventory.php`、`StartupInventory.php`、`Logs.php` | 汇总设备、识别已知启动入口、读取分类日志并过滤凭据。 |

`.page` 的 PHP 入口用完整的 `<?php ... ?>`，并设置 `Markdown="false"`。
启动入口扫描只读取配置和可解析的固定绝对路径，不执行脚本。运行与存储目录见 [系统升级联动](OS-UPGRADES.md)。

## 测试

```bash
bash tests/run-tests.sh
```

可用 `PHP_BIN=/path/to/php` 指定 PHP CLI。套件用临时目录、模拟命令和本地 HTTP 服务。
为运行真实本地鉴权网关用例，设置 `NGINX_BIN`、`PHP_FPM_BIN`。
可选浏览器用例需要 Playwright、本地 Chromium 和 WebGUI CSS/WOFF。
[字体与浏览器依赖](UI-TYPOGRAPHY.md) · [验证范围](VALIDATION.md)

真实 TXZ 的被动导入可用 `tests/integration/verify-driver-cache.php` 检查。
输入 TXZ 旁需要同名 MD5，另传一个独立工作目录。命令只校验归档、模块和缓存回执。

```bash
mkdir -p work
vgpu_cache_test_dir=$(mktemp -d "$PWD/work/vgpu-cache-integration.XXXXXXXX")
php -n tests/integration/verify-driver-cache.php \
  /path/to/nvidia-595.58.03-6.18.58-Unraid-2.txz "$vgpu_cache_test_dir"
```

### 真实包管理器集成

日常的原生开机回归用固定 `rc.modules` 和隔离环境中的真实 `installpkg --root`。
先准备相邻驱动仓库的 pkgtools 私有安装根，再用真实 TXZ 检查旧版插件安装流程和配置事务。

```bash
PHP_BIN=.tools/php/root/usr/bin/php8.4 \
  python3 tests/integration/real-pkgtools-install.py \
  --work /absolute/path/unraid-vgpu/work/pkgtools-lifecycle.具体目录 \
  --new-package /absolute/path/nvidia-595.58.03-6.18.58-Unraid-2.txz \
  --old-package /absolute/path/nvidia-580.159.03-6.18.58-Unraid-1.txz \
  --scenario upgrade
```

环境至少预留 4 GiB，同一个私有安装根依次运行各场景。`fresh`、`reinstall` 不传旧包，
`reinstall` 需要已安装的目标包。升级用例可用 `--fault-after-upgrade failure` 或
`--fault-after-upgrade timeout` 验证配置恢复。结果保存在私有根中。
[真实包回归记录](VALIDATION.md#真实插件安装入口2026-11-15未发布)

`PkgtoolsMigration.php` 与驱动仓库保留相同内容，跨版本用 `旧记录%新包`，
安装后检查唯一 pkgtools 记录。配置事务由 `InstallConfiguration.php` 和 `InstallContract.php` 实现，
超时保留备份并阻止同一 boot 再次安装。

## 离线预览包

```bash
python3 scripts/build-plugin.py --version 2026.11.17.2
```

`dist/2026.11.17.2/` 包含 TXZ、MD5 和内嵌同一 TXZ 的 `offline/unraid-vgpu-manager.plg`。
在线安装定义使用 `--public-base` 和 `--update-url` 指定无凭据的公开 HTTPS 地址。
使用新版本或新输出目录可避免覆盖旧成品。

## 发布

1. 更新 `.github/release.json` 的版本、tag 和 prerelease，并准备 `docs/releases/TAG.md`。
2. 构建在线 PLG，核对后同步到根目录 `unraid-vgpu-manager.plg`。
3. 执行本地测试和 `python3 scripts/publish-github.py --dry-run`，再提交并推送。
4. `main` 上的发布清单或工作流发生变更时触发 GitHub Actions。测试通过后发布在线 PLG、插件 TXZ 和 MD5。

发布器校验附件字节，拒绝替换已有的不同内容或移动 tag。实际发布和附件可在
[GitHub Releases](https://github.com/ManuZhu0728/unraid-vgpu-plugin/releases) 核对。

## CLI

`scripts/rc.vgpu` 调用 `include/cli.php`，从 stdin 读取 JSON。返回 `{"ok":true,"data":{...}}`
或 `{"ok":false,"error":"..."}`，失败时退出非零。生产入口限 Unraid/root；测试直接传临时根和模拟 runner。

- `status`、`logs`、`plan` 只读。`logs` 支持 `plugin`、`vgpu`、`kernel`，最多返回最近 200 行匹配内容。
- `config-save` 保存设置。驱动恢复、升级预下载和 vGPU 启动分别对应 `driver.enabled`、`upgrade.enabled`、`runtime.enabled`。
- `source-test`、`source-versions`、`source-download`、`source-import` 和 `source-upload-*` 处理驱动获取。
  上传经 URL 编码表单分块提交，网关消耗 `csrf_token` 后页面仍校验 `uvm_csrf_token`。
- `device-create` 只接受新 UUID，`device-save` 兼容旧客户端的幂等保存；其余设备操作为
  `device-start`、`device-stop`、`device-remove`。
- `upgrade-check` 从 `changes.txt` 第一段版本说明识别目标内核，CLI 可传 `target_kernel`，`force: true`
  可跳过重试等待。通知就绪前必须完成启动包核验；`boot_ready=false` 明确表示仅缓存或准备失败。
- `boot` 核验原生安装后按设置初始化 vGPU。`ensure` 只处理 vGPU 运行时。

发布前后的开发记录见 [各版本说明](releases/) 和
[整理前的开发记录](https://github.com/ManuZhu0728/unraid-vgpu-plugin/blob/60d0d445a40e39d1dd2122d9f47c9fda083eeab9/docs/DEVELOPMENT.md)。
