# 随 Unraid 更新驱动

## 使用

1. 保存下载地址及所需 Token，开启“系统升级预下载”。助手先准备当前内核缓存，再检查系统升级。
2. 如需在开机时安装，开启“开机恢复驱动”。本地上传的包也可设为启动构建。
3. 更新 Unraid 后，等待“新内核驱动已准备”通知，再按正常流程重启。

“升级准备”显示目标内核、驱动版本和状态。缺包或网络失败时，助手通知并每 15 分钟重试。
可点击“检查升级准备”立即检查。系统重启仍由用户安排。

自动选择沿用当前 NVIDIA 分支，Releases 中优先该驱动的最高构建号。高级设置可固定完整版本及构建号。
当前内核已选的构建会保留。首次启用驱动恢复时，已明确选中的当前内核包优先确定分支。
已启用恢复后如需切换分支，固定目标驱动版本，或导入并选中目标包后重新开启“开机恢复驱动”。
[Generic Package 的选择规则](DRIVER-SOURCES.md#学校-gitlab)

## 首次迁移

新安装的驱动恢复、预下载和 vGPU 初始化均默认关闭。旧版 `upgrade.enabled=true` 迁移后仍开启
预下载与驱动恢复，旧启用 boot ID 写入 `driver.enabled_boot_id`。明确保存的 `driver.enabled=false`
优先；读取旧设置时只在内存转换，下次保存才写回。

插件会检查已知旧插件、启用的 User Scripts、`go`、可解析的固定路径引用和 GPU/VFIO 占用。
另外手工核对动态脚本引用、其他插件事件、`/boot/extra` 和 `plugins-nextboot`。

### 维护窗口步骤

1. 保留原启动方式，下载或上传目标内核的 TXZ 与 MD5，确认版本及构建号并选为启动包。保留旧可用驱动包。
2. 备份闪存、旧 PLG、User Scripts 任务、VM XML、mdev 定义和实际使用的 override。
   若由本插件接管 vGPU，在“添加设备”中保存原 UUID、PCI 和 profile，保持 VM XML 不变。
3. 记录并暂停 GPU 工作负载及相关自动启动。停用旧 vGPU 启动任务，备份会自动安装的旧插件 PLG。
   核对旧插件卸载动作、其他安装入口及 `go` 中仍需保留的设置。
4. 保存所需的 UnlockRS 和 override，开启“开机恢复驱动”；需要 vGPU 时开启“vGPU 初始化”。正常重启。
5. 核对安装状态、构建号、`cudahost`、服务和 mdev，再依次启动 GPU 容器与 VM。
   确认 UUID 和显存规格，记录本次开机时序。验收通过后恢复自动启动，再验证一次普通重启。

已有 `/boot/config/nvidia-vgpu/profile_override.toml` 时，将仍需使用的配置填入插件高级设置。
启动使用驱动包配套的 XML 与 UnlockRS。自定义模块参数的持久来源应另行核对，例如
`/boot/config/modprobe.d`。

## 原生安装分工（Alpha 18）

`driver-cache/<内核>/` 保存多版本缓存；`packages/<内核>/` 只为该内核准备选中的 TXZ 和 MD5。
下载或上传只写缓存，显式选择才会切换启动构建。开启恢复时，升级预下载可为目标内核准备启动包，
同时保留旧内核的选择。关闭恢复会撤下本插件的启动副本，闪存上的缓存继续保留。

Unraid 的 `rc.modules` / pkgtools 先安装驱动并处理模块依赖。插件沿用原有 `.plg` 入口核验 pkgtools
记录、来源、MD5、模块指纹及加载状态，再按设置初始化 vGPU。核验失败时保留状态和缓存，
GPU 已被占用时停止初始化并报错。实机需确认驱动与 vGPU 是否早于需要它们的容器和 VM。

回退到旧内核时仍核对当前下载源、驱动分支、版本和构建限制。历史回执用于保留旧选择，
实际安装需根据本次启动的包记录和模块核验。

### 恢复旧启动方式

若接管失败，先保存安装状态与 syslog，保持 GPU 工作负载停止。关闭本插件的三个开关，
恢复备份的 PLG、驱动选择、User Scripts 调度与配置，再正常重启并确认旧路径工作。

## 文件

除标注绝对路径的日志外，以下内容位于 `/boot/config/plugins/unraid-vgpu-manager/`。

| 路径 | 用途 |
| --- | --- |
| `settings.json` | 各开关、启用时的 boot ID 及下载策略 |
| `upgrade-state.json` | 升级准备状态与目标包回执 |
| `driver-state.json` | 开机安装状态及上次成功回执 |
| `package-history/<内核>-Unraid.json` | 按内核保存历史包回执 |
| `driver-cache/<内核>/` | 多构建驱动缓存 |
| `packages/<内核>/` | Unraid 启动安装副本 |
| `/var/log/unraid-vgpu-manager-upgrade.log` | 升级助手日志 |

隔离启动和旧版安装事务的验证范围见 [本地验证记录](VALIDATION.md)。
