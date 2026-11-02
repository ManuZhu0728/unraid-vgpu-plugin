# 本地验证记录（2026-11-02）

对象：独立插件 `unraid-vgpu-manager`；本地预览 `.2`，首个公开 Alpha 为 `2026.11.02.3`。
公开插件与私有驱动仓库独立，未安装到 Unraid。原私有驱动构建仓库和已构建 NVIDIA
驱动包未修改；服务器服务、VM、容器和文件未操作。

## 已执行

- PHP 8.4 CLI 语法检查；Bash `bash -n`；UI JavaScript `node --check`。
- `bash tests/run-tests.sh`：
  - Application/Process：93 项检查，含凭据 source_id 绑定及跨文件写入失败状态保护。
  - Download：19 个场景，含 GitHub/GitLab/Generic、权限错误、跨源认证清除、MD5、
    归档路径/链接安全、模块 version/vermagic；含真实被动 tar/xz 小归档检查。
  - Runtime：31 个场景，含旧入口/未知所有者拒绝接管、冷启动与重复 ensure、快照漂移、
    VM/VFIO/宿主容器 FD 占用、停止失败保留定义、profile 名重解析、PID 复用。
  - Store/Process：18 项安全检查。
  - UI：87 项检查，真实页面渲染但模拟 CLI/sysfs；覆盖 CSRF/同源/XSS、凭据不回填、
    无自动网络、操作失败不报成功、Unraid 模板 eval 环境下的固定后端路径。
  - Packaging：2 项 Python 用例，核对新插件唯一 payload、root 所有者、离线 base64
    与 TXZ 字节一致、MD5、公开地址不含凭据、安装器无驱动下载/热卸载。
- 使用现有 `595.58.03 / 6.18.58-Unraid` TXZ 调用下载器的被动审计：真实 tar/xz 和
  隔离 kmod 的 modinfo 全部通过。只向临时目录抽取模块，不安装、不执行安装脚本。
- 包内模块、库和 XML 检查不代表真实 GPU 功能已验收。
- 发布器：13 项模拟 API 用例通过，覆盖三附件白名单、tag 不移动、同版本不同内容
  不覆盖、匿名下载字节验证、认证跳转拒绝；本地 dry-run 构建与根 PLG 一致。

## 公开发布状态

目标为 `ManuZhu0728/unraid-vgpu-plugin`。公开 Alpha 使用固定 raw/main PLG 更新入口，
附件为在线 PLG、插件 TXZ 和 MD5，不包含 NVIDIA 驱动或凭据。
main 的发布清单或工作流变化触发测试；只有测试通过才发布，并匿名核对远端附件字节。
实际发布状态见仓库 Releases/Actions；代码推送到附件上传完成前可能短暂返回 404。

### 首版发布验收（2026-11-02）

- [Alpha 1](https://github.com/ManuZhu0728/unraid-vgpu-plugin/releases/tag/v2026.11.02.3-alpha.1)
  已公开发布，tag 绑定 `c0a7e719f358cbf598c3ecfdcb441065623a2b77`。
- [自动工作流](https://github.com/ManuZhu0728/unraid-vgpu-plugin/actions/runs/41432256501)
  的 test 和 release 均成功。
- 独立匿名下载三个附件通过；固定 raw/main PLG 与 Release PLG 字节一致。
- TXZ 大小 55,816 字节，MD5 与附件和 PLG 一致；归档仅包含新插件目录的
  11 个 root-owned 普通文件，无 NVIDIA 驱动或凭据。
- 以上仅为发布和下载验收；没有连接或修改用户 Unraid，运行管理仍默认关闭。

## 构建记录

`.1` 是第一次集成预览，保留在 `dist/2026.11.02.1/`，**不要交付/安装**。
随后修正了 `.page` 的固定路径以及离线安装定义的标准文件名；最终预览使用 `.2`。
`.3` 增加公开在线安装入口和自动发布流程，不改变 `.2` 的运行时程序。
源码或配置变化后不覆盖已生成包，使用新版本/输出目录。

## 尚未执行

- 未对学校 GitLab 使用真实 token 做连接/下载测试。
- 未将插件放进真实 Unraid WebGUI，也未验证其实际主题、反向代理和 PHP 配置。
- 未迁移旧插件/User Script；新运行管理默认关闭，检测到旧入口时拒绝接管。
- 未实现/执行缓存驱动的自动安装或热更新；下载成功只表示缓存验证通过。
- 未进行 GPU 实机、VM 生命周期、Docker CUDA 或显存压力验收。
- 未改变或修复 NVIDIA 内核 MMIO 警告。

因此本包是公开 Alpha，不是可以直接替换现用启动链的生产版本。维护迁移仍需单独确认。
