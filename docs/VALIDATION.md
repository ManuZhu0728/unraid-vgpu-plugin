# 本地验证记录（2026-11-02）

## 原生分页预览

开发分支：`codex/native-tabs`。版本：`2026.11.02.7`，发布标签：`v2026.11.02.7-alpha.5`。

- 三个原生标签页：驱动管理、vGPU 管理、状态与日志；每页显示 GPU 概览。
- 驱动与运行时表单独立保存，新增日志分类；启动、安装和下载选择逻辑保持原样。
- 按 Unraid 的真实加载方式，在同一 PHP 请求内依次执行全部子页，检查请求只处理一次、
  状态只读一次、DOM ID 唯一、提示归属及当前标签恢复。
- UI 309 项检查、日志 33 项检查、Python 21 个用例全部通过，含 6 个真实 localhost HTTP 上传测试。
  下载器、升级准备、安装器、Runtime 和生命周期回归继续通过。
- 实际执行共享 JavaScript 验证忙碌状态、提示隔离和分页恢复；日志刷新仅将类别放入 URL，
  不序列化 Unraid 自动添加的 CSRF 字段。PHP/Bash/渲染后 JS 语法及 `git diff --check` 通过。
- `dist/2026.11.02.7/` 内含本地 TXZ 和离线 PLG。TXZ 为 73,792 字节、27 个插件文件；
  逐文件核对源码，离线安装器内嵌字节及 MD5 一致。旧产物保留。
- 发布前统一模板文件末尾格式；最终公开构建在 `dist/public-2026.11.02.7-alpha.5-final/`，
  TXZ 为 73,788 字节。发布器 dry-run 通过，根 PLG 与生成的在线安装器一致。

以上均为开发机验证，没有安装到真实 Unraid。

## Alpha 4

版本：`2026.11.02.6`，分支：`codex/csrf-native-post`。

实机反馈的错误来自页面对 `$_POST['csrf_token']` 的二次检查，并非 GitLab 鉴权。
此前模拟环境保留了原生字段，未覆盖 Unraid 网关处理后移除它的路径。
本版保留原生字段供网关使用，额外校验 `uvm_csrf_token`，并继续检查请求来源。
表单、上传和离页取消均发送两个字段；旧页面需刷新后再提交。

`bash tests/run-tests.sh` 通过：UI 145 项检查，Python 21 个用例（含 6 个真实本地 HTTP
上传测试），其他下载器、安装器、运行时和升级准备测试继续通过。测试未连接 Unraid。

## Alpha 3

开发分支：`codex/plugin-download-ux`，已合并到 `main`。版本：`2026.11.02.5`。

- [Alpha 3](https://github.com/ManuZhu0728/unraid-vgpu-plugin/releases/tag/v2026.11.02.5-alpha.3)
  已公开发布，tag 绑定 `68dc0b1c54333e19101b9136bbff5f852874e124`。
- [自动工作流](https://github.com/ManuZhu0728/unraid-vgpu-plugin/actions/runs/41445250659)
  的 test、release 均成功；附件为 PLG、插件 TXZ、MD5。
- 保持原 raw/main 更新入口，旧 Alpha 1 可以检查新版。

- 新增“跟随 Unraid 更新驱动”：缓存当前内核、预下载目标内核、通知准备结果。
- 开机安装器核对缓存、内核和驱动分支，安装成功后恢复宿主 GPU 或 vGPU。
- 目标包准备不替换当前内核选择；后续普通重启继续恢复缓存。
- 运行中安装插件不替换活动驱动；安装失败不继续启动 vGPU。
- 下载、上传、WebGUI、发布器及原运行时回归一起验证；模拟安装器不执行真实包安装。

`bash tests/run-tests.sh` 全部通过：

- 升级准备：370 项检查。
- 开机安装器：32 个场景 / 435 项检查。
- 完整生命周期：4 个场景 / 299 项检查，含首次开启后未升级系统的普通重启。
- Application/Process：127 项；UI：127 项；源解析：91 项；上传：46 项。
- 下载：32 个场景；Runtime：31 个场景；Store：18 项；Python：19 个用例。
- PHP、Bash、页面 JavaScript 语法检查及 `git diff --check` 通过。
- 本地安装包位于 `dist/2026.11.02.5/`，TXZ 为 69,652 字节；离线 PLG 内嵌同一包。
- 发布器 dry-run 通过：三个公开附件与根 PLG 一致，没有访问 GitHub 写接口。

默认关闭升级联动；真实 Unraid 升级、迁移及重启测试尚未执行。

## Alpha 2 本地改动

分支：`codex/plugin-download-ux`。版本：`2026.11.02.4`，尚未发布。

- 精简状态、下载页和 README；主表单为链接和可选 Token，高级参数折叠。
- Source：91 项解析检查；Download：32 个场景，包含内核标签、旧标签、包直链、
  裸 MD5、自动选择和学校 Deploy Token 的已知版本定位。
- Application：104 项；上传：46 项；UI：116 项；原 Runtime/Store 回归继续通过。
- Python：19 项，包含真实本地 PHP HTTP 服务的 multipart 上传及 CSRF/伪造路径测试。
- 分块上传的进度、取消和 HTML 包装响应已联调；完成后 GET 导航，避免重复提交旧表单。
- 自动更新：本版没有升级目标内核检测、预下载或开机驱动安装，现用启动入口保持原样。

以上是开发机验证；尚待真实 Unraid 上测试私有源下载与大文件浏览器上传。

## Alpha 1 记录

以下各节保留首版的验证与发布记录。

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
