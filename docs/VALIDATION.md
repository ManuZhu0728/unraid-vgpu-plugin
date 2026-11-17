# 本地验证记录

## Alpha 19 原生更新 post-hook（2026-11-17）

`2026.11.17.2` 发布前在开发机完成验证。
[Alpha 19 发布说明](releases/v2026.11.17.2-alpha.19.md)

- 7.3.4、7.4.0 原生 post-hook 调度函数与真实 Bash 入口覆盖同步顺序、事件筛选、失败、缺失后端，
  插件准备失败不会把系统更新本身改报为失败。测试仅映射临时目录，不执行完整系统更新器。
- 升级准备 925 项检查，含缓存/启动副本校验顺序、状态先落盘再通知、重复检查去重、多 build、
  切换构建、损坏启动 MD5、不安全发布目标和缓存-only 提示；同步适配器另有 19 项检查。
- 完整 PHP 套件与 69 项 Python 测试通过，无 skip，启用真实本机 nginx/PHP-FPM 鉴权网关、
  浏览器上传/取消/失败、缓存删除以及既有原生启动/真实 pkgtools 隔离用例。
- 独立浏览器回归通过黑白主题、字号、窄屏、字体回退、详情/XML 展开、缓存、下载与编辑器测试。

证据位于开发机 `work/upgrade-post-hook-20261117.qOnc0nVi/`。
没有部署到 Unraid 或操作服务器，7.3.4 实机验收由用户执行。

## Alpha 18 原生启动安装与选包（2026-11-17）

`2026.11.17.1` 的发布前验证在开发机完成。
[Alpha 18 发布说明](releases/v2026.11.17.1-alpha.18.md)

- 使用未改写的 Unraid `rc.modules` 和隔离环境内的真实 Slackware `installpkg --root`，
  核对包安装、depmod、插件核验与 vGPU 初始化顺序。模块元数据、守护进程和 mdev 使用测试夹具。
- 验证多构建共存、下次启动切换、各内核独立选择、升级准备、缓存迁移、中断恢复和关闭恢复。
  包记录、来源或指纹不符时停止初始化；已配置设备保留原 UUID。
- PHP 套件与 63 项 Python 用例通过，覆盖真实本机 nginx/PHP-FPM 鉴权网关、上传、取消、删除和显式选包。
  浏览器回归覆盖黑白主题、字号、窄屏、设备布局和字体隔离。

Unraid 7.3.4 实机需核对缓存迁移、当前内核选中构建、冷启动安装、vGPU 就绪及首个 GPU 客户端启动。
迁移或回退可按 [系统升级联动](OS-UPGRADES.md) 操作。

## 真实插件安装入口（2026-11-15，未发布）

在源码 `1756d8d` 上，以原版 Slackware pkgtools 42 验证了旧版插件的安装流程。R595 build 2 首次安装、
重装、R580 升级和 R595 build 1 升级均通过。每轮核对六个模块内容、87 个库链接、唯一包记录及原有配置。
记录位于相邻驱动仓库 `work/pkgtools-lifecycle.l683q5ax/plugin-cases/`。

真实 pkgtools 安装完成后，测试另行注入故障。子进程报错时恢复了配置并记录 `failed_restored`，
超时时记录 `completion_unknown` 并保留备份。同一 boot 的再次调用返回 `already_attempted`。
原生启动顺序另由 Alpha 18 测试覆盖。

## 公开发布状态

插件版本、更新包和发布结果可在 GitHub 查阅。初版发布核对了匿名下载、MD5 及在线 PLG，
各版本的附件和实机验收情况见对应说明。
[GitHub Releases](https://github.com/ManuZhu0728/unraid-vgpu-plugin/releases) ·
[首版 Actions](https://github.com/ManuZhu0728/unraid-vgpu-plugin/actions/runs/41432256501)

历次 Alpha 的环境、测试矩阵、摘要和证据目录保留在
[整理前的验证记录](https://github.com/ManuZhu0728/unraid-vgpu-plugin/blob/60d0d445a40e39d1dd2122d9f47c9fda083eeab9/docs/VALIDATION.md)。
