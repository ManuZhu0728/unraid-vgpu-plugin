# Unraid vGPU Manager

下载 NVIDIA 驱动，管理虚拟 GPU。插件公开发布，驱动包可以来自自己的 GitHub 或 GitLab 仓库。

## 安装

在 Unraid 的 **Plugins → Install Plugin** 中填写：

```text
https://raw.githubusercontent.com/ManuZhu0728/unraid-vgpu-plugin/main/unraid-vgpu-manager.plg
```

## 下载驱动

1. 在“下载地址”粘贴仓库、Release 页面或 TXZ 下载链接。
2. 私有源填写 Token，公开源留空。
3. 点击“查找驱动”，选择版本后下载到缓存。

平台和内核会自动识别。默认优先选择当前已安装的驱动版本，没有相同版本时选择匹配内核的最高版本。
模板、项目 ID 等选项放在“高级设置”，旧版保存的配置仍可使用。

支持以下地址：

- GitHub / GitLab 仓库。
- 按内核发布的 Release，例如 `6.18.58-Unraid`。
- 旧格式 Release，例如 `v6.18.58-595.58.03`。
- GitLab Generic Package API 下载链接，以及直接指向驱动 TXZ 的 HTTPS 链接。

私有 GitLab 的 API Token 可以读取版本列表。只读 Deploy Token 使用 `read_package_registry`：
对于本项目的 `build/v{kernel}-{driver}` 格式，插件可按当前驱动版本定位包；
要下载其他版本，可以粘贴该包的下载链接或在高级设置中指定版本。

## 本地导入

选择本地 TXZ，点击“导入到缓存”。可以附带 MD5 文件；大文件会分块上传，页面显示进度。

下载和导入都会检查归档、内核及模块版本。文件名使用统一格式：

```text
nvidia-595.58.03-6.18.58-Unraid-1.txz
```

下载或导入完成后，可由插件在下次开机安装匹配的缓存包。

## vGPU 管理

在“vGPU 启动设置”中配置 UnlockRS，然后添加设备，并将设备 UUID 写入虚拟机 XML。
使用原插件或 User Scripts 的用户，先完成启动入口迁移，再开启“开机管理 vGPU”。

## Unraid 更新

在页面开启“跟随 Unraid 更新驱动”后：

1. 助手先准备当前内核的驱动缓存，供普通重启恢复。
2. 更新 Unraid 时，自动识别目标内核并下载对应驱动，完成后通知。
3. 下次开机安装匹配包，再恢复 vGPU。后续每次开机也从缓存恢复驱动。

自动选择保持当前 NVIDIA 分支，例如 R595；高级设置可固定完整版本。
目标包尚未发布时会通知等待，旧内核缓存继续保留。

该功能默认关闭。首次使用需将原驱动插件或启动脚本迁移到本插件。
详见 [系统升级联动](docs/OS-UPGRADES.md)。

## 配置与开发

配置和缓存位于 `/boot/config/plugins/unraid-vgpu-manager/`。Token 单独保存，
请将闪存和配置备份按敏感文件保管。

```bash
bash tests/run-tests.sh
python3 scripts/build-plugin.py --version 2026.11.02.6
```

更多内容：[开发文档](docs/DEVELOPMENT.md) · [下载源格式](docs/DRIVER-SOURCES.md) ·
[验证记录](docs/VALIDATION.md) · [上游来源](docs/UPSTREAM.md)

[公开仓库](https://github.com/ManuZhu0728/unraid-vgpu-plugin) ·
[版本下载](https://github.com/ManuZhu0728/unraid-vgpu-plugin/releases)
