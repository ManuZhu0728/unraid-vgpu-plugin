# Unraid vGPU Manager

下载 NVIDIA 驱动，管理虚拟 GPU。插件公开发布，驱动包可以来自自己的 GitHub 或 GitLab 仓库。

## 安装

在 Unraid 的 **Plugins → Install Plugin** 中填写：

```text
https://raw.githubusercontent.com/ManuZhu0728/unraid-vgpu-plugin/main/unraid-vgpu-manager.plg
```

## 下载驱动

插件包含三个标签页：

| 页面 | 功能 |
| --- | --- |
| 驱动管理 | 在线下载、本地上传、驱动缓存、开机恢复和升级预下载 |
| vGPU 管理 | 开机管理、设备配置与操作、VM XML、UnlockRS |
| 状态与日志 | 模块与服务状态、启动入口、驱动任务、分类日志 |

1. 在“驱动管理 → 在线下载”的“下载地址”粘贴仓库、Release 页面或 TXZ 下载链接。
2. 私有源填写 Token，公开源留空。
3. 点击“查找驱动”，选择版本后下载到缓存。

平台和内核会自动识别。默认优先选择当前已安装的驱动版本，没有相同版本时选择匹配内核的最高版本。
从 Releases 选择同一驱动时，优先最高构建号；版本列表也可单独选择旧构建。
模板、项目 ID 等选项放在“高级设置”，旧版保存的配置仍可使用。

支持以下地址：

- GitHub / GitLab 仓库。
- 按内核发布的 Release，例如 `6.18.58-Unraid`。
- 旧格式 Release，例如 `v6.18.58-595.58.03`。
- GitLab Generic Package API 下载链接，以及直接指向驱动 TXZ 的 HTTPS 链接。

私有 GitLab 的 API Token 可以读取版本列表。只读 Deploy Token 使用 `read_package_registry`：
插件可按已知驱动版本定位 Generic 包：构建 1 使用 `build/v{kernel}-{driver}`，
后续构建使用 `build/v{kernel}-{driver}-buildN`。
Deploy Token 不枚举 Releases；要下载其他构建，可粘贴包链接，或在高级设置固定驱动版本和构建号。

## 本地导入

在“驱动管理”将获取方式切换为“本地上传”，选择 TXZ，点击“导入到缓存”。
可以附带 MD5 文件；大文件会分块上传，页面显示进度。

下载和导入都会检查归档、内核及模块版本。文件名使用统一格式：

```text
nvidia-595.58.03-6.18.58-Unraid-1.txz
nvidia-595.58.03-6.18.58-Unraid-2.txz
```

最后的数字是打包构建号，不是 NVIDIA 驱动版本。同一驱动重新打包后使用新构建号，旧包继续保留。
下载或导入完成后，开启“开机恢复驱动”，插件会在下次开机安装匹配的缓存包。
本地上传不需要配置仓库或开启预下载。

## vGPU 管理

打开“vGPU 管理”，顶部“已有 vGPU 设备”会汇总宿主机活动设备、mdevctl 已定义设备和插件保存的配置。
表格直接显示 UUID、type、配置名称、显存、PCI、状态与来源；旧脚本创建的设备也能查看。
未启动的系统定义同样保留在列表中。只有本插件保存的设备提供编辑、启动、停止和移除操作。

在“vGPU 管理”中展开“添加设备”，选择显卡和配置，并将设备 UUID 写入虚拟机 XML。
UnlockRS 和 profile_override 位于该页的“高级设置”。驱动设置与 vGPU 启动设置分别保存。
使用原插件或 User Scripts 的用户，先完成启动入口迁移，再开启“vGPU 初始化”。

## 查看状态与日志

“状态与日志”显示驱动模块、vGPU 服务、设备和启动入口，以及缓存、升级准备和开机安装结果。
日志可切换为插件升级助手、vGPU 管理器或 NVIDIA 内核，点击“刷新日志”更新最近记录。

## Unraid 更新

三个设置分别负责不同工作：

| 设置 | 功能 |
| --- | --- |
| 开机恢复驱动 | 每次启动从缓存安装当前内核的驱动 |
| 系统升级预下载 | 提前下载新内核对应的驱动包 |
| vGPU 初始化 | 加载 vGPU 服务并恢复配置的虚拟 GPU |

前两个设置在“驱动管理”，本地上传和在线下载模式都能使用；vGPU 初始化在“vGPU 管理”。
只使用 Docker GPU 时可以不开启 vGPU 初始化。关闭预下载后，开机恢复仍可继续使用已有缓存。

开启“系统升级预下载”后，助手先准备当前内核缓存，再监听 Unraid 升级。
更新系统后，等待“新内核驱动已准备”通知再重启。要让插件负责安装，需同时开启“开机恢复驱动”。

自动选择保持当前 NVIDIA 分支，例如 R595；高级设置可固定完整版本及构建号。
已经选好的当前内核缓存会保留，不因查找驱动而自动换包。
目标包尚未发布时会通知等待，旧内核缓存继续保留。

新安装的三个开关默认关闭。旧版已开启“跟随 Unraid 更新驱动”的配置，会保留预下载与开机恢复两项功能。
首次启用驱动恢复或 vGPU 初始化前，需将对应的原插件或启动脚本入口迁移到本插件。
详见 [系统升级联动](docs/OS-UPGRADES.md)。

## 配置与开发

配置和缓存位于 `/boot/config/plugins/unraid-vgpu-manager/`。Token 单独保存，
请将闪存和配置备份按敏感文件保管。

```bash
bash tests/run-tests.sh
python3 scripts/build-plugin.py --version 2026.11.03.2
```

更多内容：[开发文档](docs/DEVELOPMENT.md) · [下载源格式](docs/DRIVER-SOURCES.md) ·
[验证记录](docs/VALIDATION.md) · [上游来源](docs/UPSTREAM.md)

[公开仓库](https://github.com/ManuZhu0728/unraid-vgpu-plugin) ·
[版本下载](https://github.com/ManuZhu0728/unraid-vgpu-plugin/releases)
