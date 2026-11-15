# 开发与验证

基线为 novidio `2026.07.13c` / `299af973bf564036e978eff9dc2e8e3e2f65da9d`。
插件源码位于 `source/usr/local/emhttp/plugins/unraid-vgpu-manager/`。

## 当前集成目标（2026-11-15）

由本插件接替 novidio + User Scripts，统一驱动获取、缓存、冷启动安装、vGPU 初始化和
Unraid 升级预下载。公开插件与私有驱动源继续分开发布，保留原有 UUID 和 1GiB Q 配置。

当前在 `codex/package-build-identity` 开发，与驱动仓库
`codex/driver-package-lifecycle` 联调。真实候选包为
`nvidia-595.58.03-6.18.58-Unraid-2.txz`；build 2 是打包修订，不是新 NVIDIA 版本，
没有新增 MMIO 或 host-CUDA 补丁。

推进顺序：

1. 收尾驱动构建修复与证据，保留旧包和输入。
2. 对齐两仓库的冷安装契约，验证真实 pkgtools 对旧裸配置包和新 `.new` 包的处理。
3. 联调真实 TXZ 导入、缓存、包身份、安装回执及三个独立开关。
4. 准备插件测试版、私有驱动附件和维护迁移步骤；确认范围后再合并、推送与发布。
5. 新入口、驱动缓存及恢复路径就位后，经授权停用旧入口并进行实机冷启动迁移。
6. 验收 CUDA、Emby、Windows vGPU、并发/停启、Immich 显存与普通重启。
7. 本地验证系统升级预下载和异常路径；后续实际系统升级及回退单独记录。

本地验证不代表实机验收。配置恢复不等于整个驱动包回退；驱动切换在下次冷启动完成。
MMIO 修复另开阶段，不随安装流程改动一起交付。

### 本地候选

候选版本 `2026.11.15.1` / `v2026.11.15.1-alpha.8`，成品位于
`dist/candidate-2026.11.15.1-alpha.8/`。包含插件 TXZ、MD5、在线 PLG 和内嵌包的离线 PLG；
插件本身不包含 NVIDIA 驱动。在线 PLG 的链接在正式发布后才可下载。
本地准备发布元数据不等于已经合并或发布，公开版本仍需以 GitHub 实际状态为准。

驱动候选继续使用现有 build 2，不重新编译。GitLab 成品 Release 为 `6.18.58-Unraid`，
新增 `nvidia-595.58.03-6.18.58-Unraid-2.txz` 和同名 MD5，保留 R580、R595 build 1，
共享输入仍为 `inputs-595.58.03-r1`。发布前分别确认两个仓库的提交和附件范围。

发布前仍需补齐的生命周期项目：

- 按内核的历史授权回执已实现并通过本地生命周期回归；实际旧内核回退仍需实测。
- 已加入 User Scripts、`/boot/config/go` 和固定绝对路径引用的只读检查；首次迁移仍需
  核对动态引用、`/boot/extra`、`plugins-nextboot` 及其他插件事件入口。
- 在 Unraid 7.3.4 实际启动流程中验证 GPU 就绪早于 Docker/VM 使用 GPU。
  2026-11-15 只读核对已确认插件安装在 go/emhttp 之前同步执行，迁移后的 GPU 就绪时间仍需实测。

## 结构

- 开发机测试使用临时文件、模拟 HTTP/命令和本地 PHP HTTP 服务。
- 运行时使用 Unraid 自带 PHP 8.1+、Bash、curl、tar、xz、modinfo、mdevctl 和 libvirt 工具。
  Python 仅用于开发机测试/打包，不要求 Unraid 安装 Python。
- `Application.php` 是统一事务入口，页面不得直接调用 mdevctl 或写配置。
- Store 使用 close-on-exec 锁句柄，所有写操作共用锁，后台 daemon 不继承锁。
- `Source.php` 解析下载链接；`Download.php` 处理版本匹配和归档校验。
- `PackageIdentity.php` 统一包名、构建号及缓存回执的解析，供下载、上传、升级与安装器共用。
  回执中的内核带 `-Unraid`，构建号规范化为正整数；旧版缺失构建号只兼容 `-1.txz`。
  `source.build` 是可选字符串设置，留空为自动选择；空值不参与源策略指纹，兼容既有回执。
- `PackageHistory.php` 在 `package-history/<内核>-Unraid.json` 保存完整来源及原始授权 boot ID。
  每个内核独立存储，避免挤占 `driver-state.json` 的大小限制；它与安装器和升级助手共用策略校验。
  选择顺序为显式选择、已准备包、上次成功包、当前内核历史；历史不作为本次开机已安装的证明。
  升级助手替换旧选择前核验并归档，安装完成后保存实际成功记录；没有记录的裸 TXZ 不自动安装。
- `Upload.php` 处理本地分块上传，完成后复用下载器的校验和缓存逻辑。
- `Upgrade.php` 准备当前/目标内核缓存；独立 PHP 助手监听系统升级。
- `Installer.php` 在后续启动安装已验证缓存；`Runtime.php` 随后恢复 vGPU。
- `InstallConfiguration.php` 在包安装前建立配置事务，安装后恢复已有配置并仅合并 Docker 的 NVIDIA runtime。
  事务位于运行目录，备份不进入 JSON 状态或日志；失败回执提供事务路径。
- `InstallContract.php` 定义包内配置模板与模块的安装契约。`Download::inspectCached()`
  生成本次安装的模板内容和模块摘要，不把这些临时数据写入长期包回执。
- `PkgtoolsMigration.php` 与驱动仓库同字节共享：根据 pkgtools 文件清单选择旧记录，
  跨版本用 `旧记录%新包`，安装后确认唯一目标记录。两仓库联调测试检查该文件没有漂移。
- 插件在配置恢复后补齐并处理包内 `.new`，保留管理员已有的待处理模板；显式注册 Docker、
  执行 depmod 和 `ldconfig -X`，核验所有包内目标模块后才加载驱动。不额外重放第三方 doinst。
  五个 vGPU 必需模块始终检查；包内存在 peermem 时也检查，因此本项目 build 2 检查六模块。
- 安装或安装后命令超时保留事务，当前 boot 禁止再次安装及 GPU 写操作；配置保存与状态查看仍可用。
  配置事务提交后若原生驱动激活失败，保留已验证的配置，不把它误报为整包已回滚。
- 设置 schema 2 独立保存 `driver.enabled`、`upgrade.enabled`、`runtime.enabled`。
  `Store::settings()` 仅在读取旧配置时映射旧开关及 boot ID，不自动落盘；明确的 driver 设置优先。
- WebUI 使用原生 `xmenu` + `Tabs`：主 `.page` 定义菜单，三个子 `.page` 分别显示驱动、vGPU、状态与日志。
  `include/ui-page.php` 负责公共状态栏，`include/ui/` 保存公共函数、请求处理和各页内容。
  Unraid 在同一请求内渲染全部子页；`state.php` 只处理一次提交和状态读取，各页使用独立 DOM 标识。
  表单用 `uvm_tab` 标记目标标签，按页面限制操作；驱动页分开保存 source/credential 和 driver/upgrade，vGPU 页只保存 runtime/override。
  driver 表单遗漏的开关保持原值，提交值只接受 `0`/`1`；旧 source 表单的 `upgrade_enabled` 仅修改预下载，不修改驱动恢复。
  状态页的已安装构建取 `last_success.package`，等待重启或失败的目标取 `attempted_package`，不把所选缓存当作已安装包。
  PHP 分页入口使用完整的 `<?php ... ?>`，并设置 `Markdown="false"`；普通被包含的 `.php` 文件不经过 Markdown。
- `Logs.php` 从固定日志文件读取最近记录，按类别筛选并过滤凭据。
- `Inventory.php` 合并运行时 sysfs 设备、`/etc/mdevctl.d/<PCI>/<UUID>` 系统定义与插件配置，
  只读取本地文件，不调用 mdevctl、不修改配置。按 PCI + UUID 区分设备，保留实际和保存的 type。
- `StartupInventory.php` 为 Runtime 提供启动入口证据，保留兼容的 `legacy_owners`，
  并在 `startup_inventory` 中返回入口、文件摘要、行号、引用链和扫描范围。读取不执行脚本。
  固定绝对路径引用只跟随 `/boot/config` 内的普通文件；不推测相对路径的执行目录或展开变量。
  深度上限 4、文件数 64、单文件 64 KiB、合计 1 MiB。相关但未解析的初始化入口要求迁移核对，
  无关的备份脚本不会因此阻止 vGPU 初始化；禁用任务只列入清单。扫描范围不包含所有系统启动器。

## 测试

```bash
bash tests/run-tests.sh
```

可用 `PHP_BIN=/path/to/php` 指定 PHP CLI；也识别本地
`.tools/php/root/usr/bin/php8.4`。本次将 Debian PHP CLI 包解压到 `.tools`，没有向宿主
安装 PHP。测试使用临时目录和模拟命令/HTTP，不读取真实凭据。

真实驱动包的被动导入与缓存核验可单独执行（需要 `tar`、`xz`、`modinfo` 和 PHP CLI）：

```bash
mkdir -p work
vgpu_cache_test_dir=$(mktemp -d "$PWD/work/vgpu-cache-integration.XXXXXXXX")
PATH=/usr/sbin:/usr/bin:/sbin:/bin \
  "${PHP_BIN:-.tools/php/root/usr/bin/php8.4}" -n \
  tests/integration/verify-driver-cache.php \
  /path/to/nvidia-595.58.03-6.18.58-Unraid-2.txz "$vgpu_cache_test_dir"
```

输入包旁需有同名 `.md5`。测试在独立目录保留缓存副本及 `result.json`；检查导入、
三元身份、重复导入及错误回执拒绝，不安装驱动、不修改真实插件配置。

### 真实包管理器集成

先在相邻驱动仓库按照其 README 运行 `tests/integration/real_pkgtools_lifecycle.py`，
取得一个保留的 `work/pkgtools-lifecycle.*` 私有根目录，再运行插件入口：

```bash
PHP_BIN=.tools/php/root/usr/bin/php8.4 \
  python3 tests/integration/real-pkgtools-install.py \
  --work /absolute/path/unraid-vgpu/work/pkgtools-lifecycle.具体目录 \
  --new-package /absolute/path/nvidia-595.58.03-6.18.58-Unraid-2.txz \
  --old-package /absolute/path/nvidia-580.159.03-6.18.58-Unraid-1.txz \
  --scenario upgrade
```

也支持 `--scenario fresh` 和 `--scenario reinstall`（两者省略 `--old-package`）；
reinstall 要求私有根已经装有目标包。不同场景顺序运行，不能并发使用同一个私有根。
脚本复用真实 pkgtools 隔离环境，要求至少 4 GiB 可用空间，保留输入包与各轮证据。
它在插件生产安装入口中执行真实工具，但 proc/sys、boot ID 和安装根为夹具，
不验证 GPU 加载、守护进程或 Unraid 启动顺序。结果及范围见 [验证记录](VALIDATION.md)。

升级场景可加 `--fault-after-upgrade failure` 或 `--fault-after-upgrade timeout`。
真实 pkgtools 成功后，测试另起受控 PHP 子进程，分别产生 exit 9 或实际超时并回收；
安装器使用该结果验证配置恢复、完成未知及同 boot 重试限制。日志区分原始包管理器结果与
测试注入，不会把注入事件写成真实驱动故障，也不声称完整驱动包已回滚。
每轮保存实际源码和测试程序的前后摘要，要求测试期间不修改源码。

## 离线预览包

```bash
python3 scripts/build-plugin.py --version 2026.11.03.2
```

产物在 `dist/2026.11.03.2/`：插件 TXZ、MD5、自包含离线 `offline/unraid-vgpu-manager.plg`。
离线定义内嵌同一 TXZ；在线定义从公开 Release 下载插件包。

生成常规 URL 安装器，地址仅用于插件自身：

```bash
python3 scripts/build-plugin.py \
  --version 2026.11.03.2 \
  --output dist/public-preview \
  --public-base https://github.com/ManuZhu0728/unraid-vgpu-plugin/releases/download/v2026.11.03.2-alpha.7 \
  --update-url https://raw.githubusercontent.com/ManuZhu0728/unraid-vgpu-plugin/main/unraid-vgpu-manager.plg
```

不能把 token 放进地址。Alpha 使用固定 raw PLG，而不是只适用于正式版的 latest 地址。
默认不覆盖同名产物，
代码变化后使用新版本或新输出目录。没有提供地址时，不生成假冒可在线安装的定义。

## 发布

1. 更新 `.github/release.json` 的版本、tag 和 prerelease，并准备同名 `docs/releases/TAG.md`。
2. 按上述方法构建，把生成的在线 PLG 同步到根目录 `unraid-vgpu-manager.plg`；不得放离线版。
3. 本地测试、审核并提交；推送 main 的 release.json 或发布工作流变化触发 GitHub Actions。
4. 测试成功后使用仓库级 `GITHUB_TOKEN` 发布三个附件：在线 PLG、插件 TXZ、MD5。
   不读取用户 token、不发布 NVIDIA 驱动、不上传本地预览目录。

发布器拒绝覆盖已有的不同内容或移动 tag；同提交下可补齐缺失附件。
`python3 scripts/publish-github.py --dry-run` 可构建并核对发布计划，不访问写 API。
发布后核对附件、在线 PLG 和工作流结果，实机测试记录另存于验证文档。

## CLI

`scripts/rc.vgpu` 调用 `include/cli.php`，请求 JSON 经 stdin；响应为
`{"ok":true,"data":{...}}` 或 `{"ok":false,"error":"..."}`，失败返回非零。
生产 CLI 只允许 Unraid/root，不能通过环境变量或请求切换测试 root。
测试直接构造 `Paths($temporaryRoot)` 和模拟 runner。

命令：`status`、`logs`、`plan`、`config-save`、`source-test`、`source-versions`、`source-download`、
`source-import`、`source-upload-begin`、`source-upload-chunk`、`source-upload-finish`、
`source-upload-cancel`、`upgrade-check`、`device-save`、`device-start`、`device-stop`、`device-remove`、`ensure`、`boot`。
status/logs/plan 只读，其余加锁。

`logs` 接受 `category: plugin | vgpu | kernel`，返回最近 200 行匹配记录。
页面日志通过 GET 刷新，不改变设置。

`upgrade-check` 读取系统目标内核并准备缓存。CLI 可通过 stdin 显式提供 `target_kernel`，
页面只能使用系统升级元数据。`boot` 在 driver.enabled 启用时先运行安装器，成功后才调用 Runtime。
`ensure` 不安装驱动；`upgrade-check` 与后台助手只受 upgrade.enabled 控制；Runtime 使用 runtime.enabled。
`force: true` 跳过失败后的重试等待；页面“检查升级准备”使用此选项。
助手不持有事务锁，子 CLI 使用同一把锁，忙时重试。

上传请求通过本页 POST 和原生 CSRF 校验。PHP 确认分块来自 HTTP 上传后传给 CLI，
CLI 校验临时路径和所有者，再写入独立上传会话。每块最多 1 MiB，总量最多 2 GiB。
页面从 Unraid 外层 HTML 中提取带标识的 JSON 结果；导入完成以 GET 导航更新页面。

表单和上传同时发送原生 `csrf_token` 与插件字段 `uvm_csrf_token`。Unraid 网关可在校验后
移除原生字段，页面仍核对插件字段；若原生字段仍在，两者都必须匹配。HTTP 测试模拟网关消费字段。

## 生产部署前

1. 使用公开插件仓库，不复制私有构建仓库历史或凭据。
2. 核对上游/依赖声明、安装包内容和脱敏输出。
3. 单独审查维护迁移：备份旧入口、保留驱动安装链，再导入配置并停用旧启动入口。
4. 分别验收目标内核预下载、冷启动安装、普通重启恢复和失败状态。
5. 经单独授权测试开机、VM 停启、Docker CUDA、并发与显存压力。
