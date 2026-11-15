# 本地验证记录

## 最终候选回归（2026-11-15，未发布）

完整 `bash tests/run-tests.sh` 通过：安装器 71 场景 / 1887 检查、配置事务 20 场景 / 97 检查、
生命周期 12 场景 / 1005 检查、按内核历史 26 检查、升级准备 749 检查、Runtime 37 场景、
启动入口 21 场景 / 123 检查、Application 303 检查、UI 552 检查及 Python 34 个测试。
本地日志为 `/tmp/vgpu-plugin-final-regression-20261115.log`。

最终生产源码再次完成真实 build 2 重复安装：
`work/pkgtools-lifecycle.l683q5ax/plugin-cases/plugin-reinstall-1794721687073457363/`
（位于相邻驱动仓库）。安装阶段耗时 60.767 秒，核验六模块、87 个库链接、13 项配置及备份。
测试前后实际源码、测试程序和 HEAD 指纹一致；当时 HEAD 为 `49bb03d`，未提交源码的摘要
也完整保存在 `production-source-before.json`，并非只用 HEAD 代表测试对象。

新 `PackageHistory` 在真实安装后写入本次成功 boot ID，与安装状态和成功包身份一致，
历史文件权限为 0600、目录为 0700。既有缓存 TXZ 和 MD5 文件的内容、inode、mtime、权限
及所有者未变。该测试仍使用私有 ROOT 和测试 boot ID，不加载 GPU。

启动检查覆盖启用的 User Scripts、go 和 `/boot/config` 内固定绝对路径引用，输出路径、
行号及引用关系。注释、打印、查询和简单静态监测 substitution 不算初始化入口；相关动态
调用和超出扫描边界的引用保留为待核对项，不执行脚本。禁用任务不阻止初始化。

另以临时文件验证首次迁移：旧设备仍 active 时保存同一 UUID 不执行 GPU 命令；已有相同
PCI/type 的 inactive 定义直接启动，不重新 define。UUID 与 `NVIDIA RTXA5500-1Q` 保持不变。
实机启动时序、实际 1GiB 分配、宿主 CUDA、容器和系统回退留待维护窗口验证。

## 按内核保留回退记录（2026-11-15，未发布）

新增独立 `PackageHistory`，修复升级助手更新唯一所选包后，旧内核虽有缓存却无法安装的问题。
记录保留包身份、原始授权 boot ID 和下载源信息；只有实际安装成功才附加成功安装 boot ID。
历史包仍通过当前来源、分支、固定版本/build 和完整缓存检查，不扫描裸 TXZ，也不跳过安装。

本地生命周期复现并通过：旧内核包只被选中、从未被插件安装 → 准备新内核 → 新内核安装 →
助手更新当前选择 → 断网回到旧内核 → 从历史记录完成旧包安装。成功与后续失败均保留两个
内核的记录；换来源、坏 JSON、缺包、MD5 改变及包内模块内核不匹配均拒绝安装。
显式选择优先于历史，损坏的低优先级历史不会遮蔽有效的显式选择。

目标回归：历史记录 26 项检查、安装器 71 场景 / 1887 检查、升级助手 749 项检查、
应用生命周期 12 场景 / 1005 检查通过。这些测试使用临时文件、模拟下载和安装器，
不代表已经在 Unraid 上完成系统升级或回退。

## 真实插件安装入口（2026-11-15，未发布）

生产代码基线为 `1756d8d`，通过实际 `Download::importLocal()`、
`Runtime::installationPreflight()`、`Installer::boot()` 和配置事务运行真实包测试。
使用原版 Slackware pkgtools 42，以及实际安装的 nvidia-ctk、真实 modinfo、
指定私有根的 depmod 和指定私有缓存的 ldconfig；没有加载驱动或调用 Runtime.ensure。

| 场景 | 结果 | `plugin-cases/` 下的证据目录 |
| --- | --- | --- |
| build 2 首次安装 | 通过 | `plugin-fresh-1794719581120166188` |
| build 2 重复安装 | 通过 | `plugin-reinstall-1794719655987097490` |
| R580 → R595 build 2 | 通过 | `plugin-upgrade-1794719915973821148` |
| 原始 experimental R595 build 1 → 标准名 build 2 | 通过 | `plugin-upgrade-1794720027495162693` |

证据根目录：相邻驱动仓库的 `work/pkgtools-lifecycle.l683q5ax/`。
每个目录保留 `summary.json`、命令输出、配置快照与安装回执。
首轮首次安装因测试断言误把数组作为严格 bool 参数传入而失败，该现场保留在
`plugin-fresh-1794719494531357414`；修正测试后完整重跑通过，不把首轮记为验收成功。

每轮核验六个模块的完整内容、87 个库链接和 12 项配置对象；重复安装额外验证已有
runtime `.new`，共 13 项。Docker 只新增 NVIDIA runtime，其他 JSON 设置及权限保留。
两种旧包升级均确认真实 pkgtools 删除旧包登记的裸 Docker 配置和旧包记录，随后恢复用户
配置，并只留下目标包记录。原始 experimental 包未改名、未复制成标准包名。

还用真实 `ldconfig -n` 对私有库目录检查兼容别名，三个重点别名的目标、内容、inode 和
硬链接数未改变。这不等于完整 Unraid 动态链接环境已验证：测试的 ROOT 非空，
proc/sys 和 boot ID 是测试夹具，宿主目录只读且不可访问真实 GPU。
实际 Unraid 冷启动及运行功能仍分别验收。

### 真实包后的受控失败与超时

同一隔离根中又运行了 R580 → build 2 的两个故障处理场景。真实 pkgtools 均先正常完成，
其命令日志保留 code 0；随后由独立、受控 PHP 子进程提供错误或超时结果给生产安装器。
这验证真实包变更后的恢复逻辑，不代表观测到了真实 pkgtools 或 GPU 故障。

- `plugin-upgrade-fault-failure-1794721004230205160`：子进程实际 exit 9，安装器记录 failed，
  配置事务为 `failed_restored`；12 项配置对象的内容、权限、软链接及原本不存在的文件状态恢复。
- `plugin-upgrade-fault-timeout-1794721112918555822`：生产 Process 的 1 秒超时对受控子进程
  发出 SIGTERM，waitpid 确认以信号 15 结束并完成回收，连同回收耗时 1.006 秒。
  安装器记录 failed、事务为 `completion_unknown`，不自动恢复配置；12 项原配置备份及权限通过核验。
- 两轮同一次 boot 的再次调用均为 `already_attempted`，没有新增安装或 GPU 命令。
  新包登记、六模块和 87 个库链接仍在，未把配置恢复称为整包回滚。

每轮的 `production-source-before.json` / `production-source-after.json` 记录实际工作树源码、
测试程序摘要和 Git HEAD，前后逐项一致。输入 TXZ 未改写，受控子进程均已结束。

## 安装契约与失败门禁（2026-11-15，未发布）

插件使用本次归档校验产生的安装计划，恢复配置后补齐包内 `.new` 并保留管理员原有
待处理模板，显式注册 Docker、刷新模块依赖和动态库缓存，然后核验全部已声明模块。
本项目候选包含六模块，第三方包不含可选 peermem 时仍可使用；不额外重放第三方 doinst。

真实 pkgtools 测试发现 `nvidia-驱动-内核-Unraid-build` 的驱动版本进入了 pkgtools NAME：
直接传新包路径时，跨版本会并装旧包。共享 `PkgtoolsMigration.php` 改为明确
`旧记录%新包`，安装后检查唯一记录。多个驱动记录或独立旧式数据库在安装前提示维护。

安装超时的同 boot 重入也已复现并修复：旧代码可经“清空选择 → boot → 恢复选择”
覆盖失败状态。现在先检查安装状态；Runtime 的初始化、设备启停/移除也检查同 boot
安装失败门禁，关闭驱动恢复开关不绕过它。状态查看及配置保存仍可用。

完整回归通过：安装器 63 场景 / 1605 检查、配置事务 20 场景 / 97 检查、
下载 42 场景、生命周期 10 场景 / 818 检查、Runtime 35 场景、Application 303 检查，
其余 PHP 回归和 Python 21 项通过。新增覆盖安装后各命令超时、完整验证先于加载、
模块内容损坏、跨版本包记录以及待处理模板保留。上述数量是本轮模拟回归，真实包结果单列。

原生 pkgtools 在 live ROOT 下还会自行调用 ldconfig。插件自己的显式刷新使用 `-X`，
不能据此推断整个原生安装过程从未执行过不带 `-X` 的刷新。

## 真实 build 2 缓存联调（2026-11-15，未发布）

在 `codex/package-build-identity` 分支重新运行原有完整回归，通过后使用实际驱动包
`nvidia-595.58.03-6.18.58-Unraid-2.txz` 调用生产 `Download::importLocal()` 与
`verifyCached()`；命令执行器使用真实 tar、xz 和 modinfo，没有模拟包内容。

- 导入内核 `6.18.58-Unraid`、驱动 `595.58.03`、build `2` 与输入一致。
- 同名 MD5 校验及重新读取缓存归档通过；错误 build、MD5、内核回执被拒绝。
- 重复导入保留既有缓存 inode 和 mtime，输入文件未改变。
- 未创建设置、凭据或驱动安装状态；没有联网、安装包或访问 GPU。

可复现入口：`tests/integration/verify-driver-cache.php`。
初次证据：`work/vgpu-cache-integration.jAO7fXsq/result.json`；
安装计划改动后的复验：`work/vgpu-cache-integration.zSTwhy2H/result.json`，同样通过。
此结果仅证明真实包能够进入插件缓存；安装流程、启动时序及实机功能另行验收。

## 开机配置保留与独立控制（2026-11-06，未发布）

第二阶段继续在 `codex/package-build-identity` 开发，保留第一阶段的包构建身份改动。

- 设置拆分为“开机恢复驱动”“系统升级预下载”“vGPU 初始化”，在线下载和本地上传均可配置。
- schema 1 的旧开关及启用 boot ID 在读取时映射到新结构；读状态不写配置，旧表单不会重置新增开关。
- 安装前备份 Docker、Container Runtime、UnlockRS、GRID 许可和 NVIDIA 模块参数，
  包括已有 `.new` 文件及许可文件。安装后恢复原配置，仅给 Docker 合并 NVIDIA runtime。
- 普通失败恢复原配置并保留事务记录；安装超时保留备份，不与可能仍在执行的安装进程并发恢复。
- 集成验证覆盖仅预下载、离线本地包恢复、关闭预下载后继续恢复、普通重启恢复、
  安装包覆盖配置后的恢复、安装失败阻止 vGPU 初始化，以及三开关的全部八种组合。
- 启用恢复时按有效的显式包选择确定驱动分支，避免当前加载的 R595 错误阻止已选 R580。
  跨分支本地选择到冷启动安装的完整流程通过，过期下载源回执不重新授权。

`bash tests/run-tests.sh` 全部通过：配置事务 17 个场景 / 83 项，安装器 45 个场景 / 771 项，
完整生命周期 10 个场景 / 728 项，Application 247 项、UI 587 项、Store 40 项、升级准备 615 项。
下载、上传、包身份、运行时和设备清单回归通过；Python 21 个测试通过，包含打包、模拟发布与 HTTP 上传。
另外验证了标准 `/var/run → /run` 别名、Docker 值类型保留与不支持的多级配置链接在安装前拒绝。
语法检查和 `git diff --check` 通过。

配置事务备份位于运行目录，可能包含许可凭据，不作为日志附件公开。
本轮没有修改服务器、已构建驱动包或发布版本；实机冷启动验收仍需后续安排。

## 驱动包构建身份（2026-11-06，未发布）

开发分支：`codex/package-build-identity`。公开插件仍为 Alpha 7；本轮未修改版本号和 PLG。

第一阶段完成：用“内核 + 驱动版本 + 构建号”识别驱动包，贯通下载、本地上传、缓存、
升级准备、开机安装和页面选择。构建号是 TXZ 的打包修订号，与 NVIDIA 驱动版本独立。

- 统一 `PackageIdentity` 解析规则。兼容旧的构建 1 回执，拒绝新构建缺失或冲突的身份信息。
- Releases 可分别选择构建 1、2、10，自动选择按数字排序；Generic 构建 1 保持原地址，
  后续构建使用 `-buildN` 地址，与驱动发布器一致。
- 固定版本或构建号与所选文件冲突时，在下载或上传前提示；上传完成时再次核对设置。
- 已缓存的不同构建分别保留。相同 NVIDIA 版本的构建切换等待重启，不在运行中替换驱动。
- 安装器用本次启动的成功安装回执确认实际构建；旧版仅凭 `modinfo` 得出的
  `already_active` 状态不作为构建已安装的证据。
- 完整流程测试覆盖：选择构建 2 → 下载 → 开机安装 → 选择构建 1 → 等待重启 → 回退安装，
  以及升级新内核时选择同一 NVIDIA 分支的最高构建 10。

`bash tests/run-tests.sh` 全部通过：包身份 193 项、Application 164 项、上传 57 项、
UI 435 项、升级准备 593 项；下载 40 个场景、安装器 40 个场景 / 601 项、
生命周期 5 个场景 / 438 项；现有运行时、设备清单及其他回归通过。
Python 21 个测试通过，包含插件打包、模拟发布和 6 个 localhost HTTP 上传测试。
`git diff --check` 通过。

这些验证使用模拟驱动包和运行时，尚未进行实机冷启动测试。本轮没有修改驱动构建仓库、
已有驱动包或 Unraid 服务器。下一阶段处理开机安装的配置保留，并拆分驱动恢复、
系统升级预下载和 vGPU 初始化的控制设置。

## 已有 vGPU 设备清单（2026-11-03）

版本：`2026.11.03.2` / Alpha 7，开发分支：`codex/vgpu-device-inventory`。

- “vGPU 管理”顶部汇总活动设备、mdevctl 系统定义和插件配置，直接展示 UUID、type、配置和 PCI。
- 按 PCI + UUID 合并；保留实际、系统定义和插件保存的身份信息，不自动改写配置。
- 外部设备无编辑或启停操作，查询只读取本地文件；Runtime、Store 和启动脚本未修改。
- 设备清单 64 项、UI 404 项检查通过。完整回归及 6 项真实 localhost HTTP 上传测试通过。
  覆盖未导入设备、未启动定义、跨显卡重复 UUID、部分读取失败、未知状态和恶意显示内容。
- 打包后逐文件核对源码；TXZ 包含 28 个插件文件，大小为 76,312 字节，离线 PLG 内嵌内容及 MD5 一致。

测试未连接或修改真实 Unraid。

## 原生分页入口修复（2026-11-03）

版本：`2026.11.03.1` / Alpha 6，修复分支：`codex/native-tab-php-rendering`。

Alpha 5 的三个 `.page` 入口遗漏了 PHP 结束标记。Unraid 默认先对页面执行 Markdown 转换，
未闭合的 PHP 因而被转为普通文本。原有测试直接执行 PHP，漏掉了这一步。

- 三个入口补齐 `?>`，并设置 `Markdown="false"`。
- 用 Unraid 7.3.4 的真实 Markdown 解析器在内存中复现旧版显示源码、修复后执行的差异。
  解析器来自官方 WebGUI 提交 `31c22f8327cca774dcbddd573dc76f70e87b2f10`。
- 测试夹具在替换后端前先处理原生页面边界；新增三页旧版失败及修复成功的检查。
- UI 327 项检查通过，完整回归与 6 个 localhost HTTP 上传测试通过。
- 构建及发布 dry-run 通过。仅修改插件页面入口，没有改动 NVIDIA 驱动、运行时或升级逻辑。

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
