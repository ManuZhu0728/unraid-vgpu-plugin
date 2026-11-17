# 上游来源

本插件源自 Novidio Unraid vGPU Driver `2026.07.13c`，基线提交为
`299af973bf564036e978eff9dc2e8e3e2f65da9d`。原 README、PLG、升级助手和软件包均可从该提交查阅，
`upstream` remote 和 Git 历史保留了原始来源。
[上游基线](https://github.com/novidio/unraid-novidio-vgpu-driver/tree/299af973bf564036e978eff9dc2e8e3e2f65da9d) ·
[原 README](https://github.com/novidio/unraid-novidio-vgpu-driver/blob/299af973bf564036e978eff9dc2e8e3e2f65da9d/README.md)

沿用上游的插件结构、sysfs profile 展示与 mdev/XML 管理思路。`unraid-vgpu-manager` 使用自己的下载器、
事务入口和设备操作流程，发布包中只含插件程序。迁移时，同一 GPU 只使用一个启动管理入口。
[迁移步骤](OS-UPGRADES.md#首次迁移)

上游致谢 stl88083365、mbuchel、LIL'pingu、DualCoder、snowman 和 midi。

原生标签页参考 Appdata Backup 与 Unraid WebGUI。三个子页同次载入，由插件分别处理表单和页面状态。
[Appdata Backup](https://github.com/Commifreak/unraid-appdata.backup) ·
[Unraid WebGUI 分页实现](https://github.com/unraid/webgui/blob/master/emhttp/plugins/dynamix/include/DefaultPageLayout/MainContentTabbed.php)
