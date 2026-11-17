# Unraid vGPU Manager

在 Unraid 下载或导入 NVIDIA 驱动，选择下次开机使用的构建，并管理 vGPU 设备。
插件在 GitHub 公开发布，可使用自己的 GitHub 或 GitLab 驱动仓库。

## 安装

在 Unraid 的 **Plugins → Install Plugin** 中粘贴以下地址。

```text
https://raw.githubusercontent.com/ManuZhu0728/unraid-vgpu-plugin/main/unraid-vgpu-manager.plg
```

## 下载驱动

1. 在“驱动管理 → 在线下载”中填写仓库、Release 页面或 TXZ 下载地址。私有源填写 Token。
2. 点击“查找驱动”，选择当前内核对应的构建并下载。默认优先已安装的驱动版本，同一版本优先最高构建号。
3. 在“已下载驱动”点击“设为下次启动构建”。需要 Unraid 在下次开机安装时，开启“开机恢复驱动”。

页面显示下载、校验阶段和耗时。结果不明确时，点击“重新打开页面核对缓存”确认后再重试。
私有 GitLab 的 API Token 可查询 Releases，Deploy Token 可按已知版本读 Generic 包。
[下载地址、Token 和固定构建号](docs/DRIVER-SOURCES.md)

## 缓存管理

“已下载驱动”按内核列出版本、构建号、大小和引用。可删除未被引用的 TXZ 与 MD5。
启动、升级或回退需要的包会显示保护原因。需要恢复已删除的包时，重新下载或上传。

## 本地导入

在“驱动管理 → 本地上传”同时选择 TXZ 和发布者提供的 MD5，点击“导入到缓存”。
页面显示上传和校验状态。断网或回执不明确时，先核对缓存。导入完成后在缓存列表选定启动构建，
本地模式也可以使用“开机恢复驱动”。

下载和导入均检查文件校验值、归档、内核及模块版本。文件名末尾是打包构建号，同一个 NVIDIA 版本可有多个构建。

```text
nvidia-595.58.03-6.18.58-Unraid-1.txz
nvidia-595.58.03-6.18.58-Unraid-2.txz
```

### 原生启动安装

`driver-cache/<内核>/` 保留多个构建。`packages/<内核>/` 只存该内核选中的启动包和 MD5。
Unraid 在开机时安装启动包。插件随后核对 pkgtools 记录、MD5、模块指纹及加载状态，再按设置初始化 vGPU。

切换构建安排在下次重启。关闭恢复或卸载插件会撤下本插件的启动副本，缓存留在闪存。
每个内核单独保存选择。原生启动次序和实机核对见 [系统升级联动](docs/OS-UPGRADES.md)。

## vGPU 管理

“已有 vGPU 设备”汇总当前活动设备、mdevctl 定义和插件配置。“详情”可查看各来源的 UUID、PCI 和 type，
也可展开 VM XML。插件只提供对自己保存的设备执行启动、停止或移除的操作。

通过“添加设备”选择显卡和 profile，再填写 VM XML 的 UUID，或为新 VM 生成 UUID。
保存只新增配置，已有 UUID 可在列表中查看。启用 vGPU 初始化后，开机可恢复已保存的设备。
UnlockRS 和 `profile_override` 在该页“高级设置”。

使用旧插件或 User Scripts 的机器，先迁移启动入口，再启用“vGPU 初始化”。
[迁移步骤](docs/OS-UPGRADES.md#首次迁移)

## 查看状态与日志

“状态与日志”显示驱动模块、vGPU 服务、设备、启动来源、开机安装及升级准备状态。
可分别查看插件升级助手、vGPU 管理器和 NVIDIA 内核日志。

## Unraid 更新

| 开关 | 用途 |
| --- | --- |
| 开机恢复驱动 | 将选定构建准备给该内核的 Unraid 原生安装 |
| 系统升级预下载 | 准备新内核的驱动缓存 |
| vGPU 初始化 | 启动 vGPU 服务并恢复设备 |

三个开关独立，新安装时均关闭。仅使用宿主机或 Docker 的 GPU 时，可关闭 vGPU 初始化。
启用预下载后，更新 Unraid 并等到“新内核驱动已准备”通知，再安排重启。
同时开启开机恢复，才能将准备好的包加入原生安装目录。
[选择策略与回退](docs/OS-UPGRADES.md)

## 配置与开发

配置与缓存位于 `/boot/config/plugins/unraid-vgpu-manager/`。Token 单独保存，备份闪存时请妥善保管。

```bash
bash tests/run-tests.sh
python3 scripts/build-plugin.py --version 2026.11.17.1
```

[开发文档](docs/DEVELOPMENT.md) · [验证记录](docs/VALIDATION.md) · [上游来源](docs/UPSTREAM.md) ·
[版本下载](https://github.com/ManuZhu0728/unraid-vgpu-plugin/releases)
