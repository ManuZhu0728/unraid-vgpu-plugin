# Unraid vGPU Manager（Alpha）

基于 novidio 新版派生，分开管理**公开插件自身**和**用户配置的驱动下载源**。
公开插件仓库为 [ManuZhu0728/unraid-vgpu-plugin](https://github.com/ManuZhu0728/unraid-vgpu-plugin)。
当前为 Alpha：已进行无硬件测试，尚未在真实 Unraid 上验收，不建议直接替换现用启动链。

## 功能与边界

- 安装管理界面不依赖驱动仓库或 token；未配置不删除插件。
- 支持 GitHub Releases、GitLab Releases/Generic，包括学校自建 GitLab。
- 凭据不进入 URL、命令参数或插件包；跨源跳转不转发 token。
- 按当前内核筛选驱动，校验 MD5、XZ/TAR、模块 version/vermagic 和 vGPU/CUDA 必需文件。
- 下载只进缓存，不回退来源、不清理旧包、不自动切换驱动。
- 所有页面写操作经统一后端和锁；运行管理默认关闭。
- 设备保存 UUID、PCI、profile 名；停止失败不删除定义，未知占用拒绝破坏性操作。

**Alpha 仅管理已经安装的驱动；缓存不代表驱动已安装。** 缓存驱动的安装和服务器
迁移需另行审查/授权。模拟测试不等于实机通过，MMIO 不属于这轮改造。

## 安装入口

在 Unraid 的 Plugins → Install Plugin 中使用：

```text
https://raw.githubusercontent.com/ManuZhu0728/unraid-vgpu-plugin/main/unraid-vgpu-manager.plg
```

安装只下载公开的插件管理程序，不需要学校 GitLab token，也不携带 NVIDIA 驱动。
运行管理默认关闭；现有 novidio 插件或 User Scripts 仍在使用时，不要启用新运行管理。
先备份并明确唯一启动入口，再单独安排维护迁移。

版本附件和已知边界见 [Releases](https://github.com/ManuZhu0728/unraid-vgpu-plugin/releases)
及 [首版说明](docs/releases/v2026.11.02.3-alpha.1.md)。不使用 `releases/latest/download`，
因为 Alpha 是预发布；根目录 PLG 作为固定更新入口，指向明确版本的附件。

## 私有 GitLab 配置示例

在页面保存以下非敏感字段；真实 token 不写进仓库：

| 字段 | 示例 |
| --- | --- |
| 平台 / 模式 | GitLab / Generic Package |
| 仓库 | `https://gitlab.example.com/group/unraid-vgpu`（替换为自己的项目） |
| 驱动 | `595.58.03` |
| Package 名称 | `build` |
| Package 版本模板 | `v{kernel}-{driver}` |
| 凭据 | Deploy Token，或有读取权限的 Private Token |

Generic 使用明确版本；Deploy Token 只需 `read_package_registry`，但不能查询普通
Releases API。Releases 发现需要 API 读取权限。GitHub 私有源使用目标仓库 Contents
只读 token；公开源可不填。token 单独保存、页面不回填。

驱动文件如 `nvidia-595.58.03-6.18.58-Unraid-1.txz` 和同名 `.md5`，不下载底包、分卷或
构建输入。首版包校验面向本项目 merged vGPU/CUDA 格式，不承诺兼容任意第三方格式。
novidio 风格的 GitHub tag 可配置 `{kernel}-Unraid`。

## 测试与打包

```bash
bash tests/run-tests.sh
python3 scripts/build-plugin.py --version 2026.11.02.3
```

产物在 `dist/2026.11.02.3/`，包括插件 TXZ、MD5 和无需联网的
`offline/unraid-vgpu-manager.plg`。离线和在线定义使用相同文件名，避免遗留双启动定义。
在线安装器和自动发布方法见 [开发文档](docs/DEVELOPMENT.md)。

## 配置安全

持久目录为 `/boot/config/plugins/unraid-vgpu-manager/`，包含配置、独立凭据和驱动缓存。
Unraid 闪存常为 FAT，不能只靠 `chmod 600` 保证持久凭据私密；闪存和配置备份都按
敏感文件处理。推荐使用项目级、只读、可撤销的 token。

保存 override 不会修改运行快照；默认不覆盖驱动配套 XML，不强杀进程、不热卸载驱动。
卸载插件不自动停止 GPU 任务或删除持久凭据/缓存；维护迁移与凭据撤销要明确处理。

`upstream/novidio-vgpu-driver.plg` 和旧源码只是保留的上游对照，**不要用于安装本插件**。
构建器仅打包 `source/usr/local/emhttp/plugins/unraid-vgpu-manager/`。
来源与致谢见 [UPSTREAM](docs/UPSTREAM.md)。
