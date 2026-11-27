# 本地验证记录

## 正式版 2026.11.28.2：真实 mdevctl CLI 合同

- 在本机保留的真实 mdevctl 1.3.0 中复现旧 `stop -u UUID -p PCI` 参数错误：退出码 2，
  尚未写入模拟 remove 文件。此前的模拟 runner 没有校验这项命令合同，遗漏了这一错误。
- 新增 4 项真实二进制测试，使用隔离命名空间中的私有 `/etc`、普通文件模拟的 sysfs 与无 GPU 的 `/dev`：
  核对 stop/undefine 的实际帮助、旧参数失败、新 UUID-only stop 写入 remove、
  带父设备限制的 undefine 仅删除目标定义，同 UUID 的其他父设备定义保留。
- 发布 CI 必须准备真实 mdevctl 并运行这组合同测试；不安装 mdevctl 服务、不使用真实 GPU。
- 模拟 runner 也严格限制 stop 的 UUID-only 参数；运行时 46 项场景和应用层 354 项检查通过，
  覆盖普通退出码、超时、失败后保留定义/配置，且不向页面泄漏任意 callout stderr。
- 完整 PHP 套件及 87 项 Python 测试通过，无 skip；包括真实本机鉴权网关、浏览器用例与隔离原生 pkgtools。
  正式版打包预检通过，根 PLG 与确定性 TXZ 一致。

本地日志：`/tmp/vgpu-plugin-stop-cli.ySg6UVHs-all-tests.log` 和 `...-release-dry-run.log`。
本轮未连接或操作 Unraid，没有停止真实 vGPU、容器或服务；真实 mdevctl 测试不等于驱动并发验收，
现有设备的停止及 2Q 切换仍由用户更新插件后验收。

## 正式版 2026.11.28.1：单设备停止与整卡占用分离

- 完整 PHP 套件及 83 项 Python 测试通过，无 skip；启用 localhost nginx/PHP-FPM、浏览器网关和隔离原生 pkgtools 测试。
- 单设备 FD 归属 32 项场景通过，覆盖目标/其他 VFIO 组、cdev 与组号不同、内核 fdinfo、匿名设备 FD、
  sysfs cdev 父设备、设备别名、失效或冲突身份及未知归属拒绝。
- 运行时 45 项场景通过，包含宿主 CUDA/NVML/DRM 不阻断单设备生命周期、目标 VM（含暂停）与 VFIO 占用仍阻断、
  其他 VM/mdev 不被修改、最后一个 mdev 的停止/重建、原 UUID 从 1Q 切换到 2Q、停止失败/超时/未消失时保留定义、
  解除定义前再次核对 VM，以及冷初始化和安装前的整卡占用保护不变。
- Application/Process 集成 354 项检查通过，核对停止保留配置、成功移除才删除配置、失败不丢失身份，
  不发出 manager 重启、模块卸载、整卡重置或容器停止命令。
- 页面布局 60 项及 12 项展开场景通过；正式版发布预检通过，根 PLG 与确定性 TXZ 一致。

本地日志：`/tmp/vgpu-plugin-target-usage.qBA2TiYq-final-all-tests.log`、
`/tmp/vgpu-plugin-target-usage.qBA2TiYq-ui-layout.log` 和 `...-final-release-dry-run.log`。
本次修复没有连接或操作 Unraid，没有使用真实 GPU，也没有改动驱动、host-CUDA hook 或 MMIO 实现。
上述测试验证插件操作边界，不替代实机并发保证；用户验收需覆盖 Immich 实际 CUDA 任务及 Beszel 监控活跃时，
停止、移除并重建最后一个 vGPU，核对任务连续性与新增 GPU 告警。

## 正式版 2026.11.26.1：下载进度、取消与助手日志

- 完整 PHP 套件及 83 项 Python 测试通过，无 skip；包含私有命名空间内的原生启动合同与真实 pkgtools 回归。
- 新增 7 项真实 localhost nginx auth_request / PHP-FPM 用例，使用实际 curl、tar、xz 和惰性包夹具，
  覆盖实际浏览器下载/进度/取消、持锁期间控制请求、错误任务 ID、鉴权和 CSRF 失败、HTTP/MD5 失败及临时文件清理。
- 助手日志区分 busy、真实错误和取消，验证 15 分钟退避、脱敏及重复日志抑制；旧助手在私有测试根中协作交接，无进程扫描或信号终止。
- 进度浏览器回归通过 9 项控制场景和 16 项黑白主题/字号/宽窄屏组合；既有下载 20 项行为/16 项布局、
  页面布局 60 项及 12 项展开场景、字体 32 项组合/384 项展开检查均通过。
- 稳定版发布预检通过，根 PLG 与确定性 TXZ 一致；发布说明保留一句中文。

证据保存在开发机 `work/download-controls-20261126.0eRA5lWe/`。
全程没有连接或操作 Unraid，没有使用真实 GPU；实机验收由用户更新插件后执行。

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
