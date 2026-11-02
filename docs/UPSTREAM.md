# 上游来源

- 仓库：https://github.com/novidio/unraid-novidio-vgpu-driver
- 基线：`2026.07.13c`
- 提交：`299af973bf564036e978eff9dc2e8e3e2f65da9d`
- 开发分支：`codex/plugin-integration`

开发分支保留上游 Git 历史，公开推送沿用该历史和旧文件供比较。
`upstream/` 中的旧 `.plg`/helper、旧包及 `source/.../novidio-vgpu-driver/`
不进入新插件构建。新 ID 为 `unraid-vgpu-manager`，但不能与旧插件/User Script 同时
管理同一 GPU；独立 ID 本身不消除双入口。

复用上游结构、界面组织、sysfs profile 展示和 mdev/XML 管理思路；下载层、统一事务
入口和安全运行控制为本次改造。NVIDIA 驱动本体不包含在插件包中。

上游致谢：stl88083365、mbuchel、LIL'pingu、DualCoder、snowman、midi。
原 README 保留于 [UPSTREAM-README](UPSTREAM-README.md)，来源也可由 upstream remote 追溯。

分页组织参考 [Appdata Backup](https://github.com/Commifreak/unraid-appdata.backup) 的原生标签页。
加载与切换行为核对了 [Unraid WebGUI 的分页实现](https://github.com/unraid/webgui/blob/master/emhttp/plugins/dynamix/include/DefaultPageLayout/MainContentTabbed.php)；
三页内容在同一请求中载入，插件自行隔离表单处理与页面状态。

公开仓库：https://github.com/ManuZhu0728/unraid-vgpu-plugin 。首版为 Alpha，分发内容
仅限插件程序，不包含 NVIDIA 驱动。记录来源不代表任意第三方驱动或输入包可再分发。
