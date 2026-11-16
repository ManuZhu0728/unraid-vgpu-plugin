# 界面字体核对（2026-11-16）

结论：截图中的不一致有代码依据，不全是字号或浏览器缩放。正文、代码字段、代码块、输入框
和按钮分别继承不同规则；同一个 UUID 在表格与输入框里的字体确实不同。本轮只审计，不改样式。

## 依据与范围

插件 `include/ui-page.php` 和 `include/ui/{driver,vgpu,status}.php`；官方 WebGUI 7.3.4 固定修订
[`31c22f8`](https://github.com/unraid/webgui/tree/31c22f8327cca774dcbddd573dc76f70e87b2f10) 中的
[基础样式](https://github.com/unraid/webgui/blob/31c22f8327cca774dcbddd573dc76f70e87b2f10/emhttp/plugins/dynamix/styles/default-base.css)、
[字体声明](https://github.com/unraid/webgui/blob/31c22f8327cca774dcbddd573dc76f70e87b2f10/emhttp/plugins/dynamix/styles/default-fonts.css)
及默认 Dynamix/黑白主题样式。未访问 NAS、Firefox 登录态或用户的 Edge。

`tests/test_ui_typography.cjs` 将实际插件模板与以上官方 CSS/WOFF 在本机 Chromium 离线渲染，
核对 21 个节点 × 黑白主题 × 两档根字号，并记录 computed style / CDP 实际平台字体。
下表 px 数值是根字号 62.5%、浏览器基准 16px 时的本地样本，不代表截图中的缩放后像素。

## 各部分的实际规则

| 部分 | 字体来源 | 样本字号/字重 | 差异原因 |
| --- | --- | --- | --- |
| GPU 名称、配置名称、状态、正文 | 原生 html 的 Clear Sans + sans-serif 回退 | 13px / 400 | 插件未覆盖全局字体 |
| 表头 | 同正文 | 13px / 700 | th 默认粗体 |
| h2 / h3 | 同正文；插件指定 1.2em / 1.05em | h2 15.6px / 700 | 独立标题层级 |
| UUID、type、PCI、驱动/内核版本 | `<code>` 的浏览器默认 monospace | 13px / 400 | 官方与插件都没有为 code 显式指定统一字体 |
| 设备 UUID/type/profile 输入框、下拉框 | 原生表单规则 Clear Sans | 13px / 400 | 不继承旁边 code 的等宽规则；输入框行高 20px |
| VM XML、JSON 详情、运行详情、日志 | 原生 pre 的 bitstream 别名 | 13px / 400，行高 18px | 与默认 code 不是同一条字体规则 |
| profile_override 多行编辑区 | 插件 `.uvm-field textarea` 的 monospace | 13px / 400，行高 20px | 插件覆盖了原生 textarea 的 Clear Sans |
| 详情/启动/停止/移除、VM XML 等按钮 | 原生按钮 Clear Sans | 11px / 700 | 字距另设 1.8px，拉丁字母大写，因此更散、更粗 |
| 原生页签 | 继承 Clear Sans + sans-serif | 14px / 400，行高 14px | 与普通按钮不同，仍保留 1.8px 字距，不强制大写 |
| `.uvm-muted` 辅助信息 | 同正文 | 12.22px / 400，透明度 .75 | 插件设为父字号 .94 倍 |
| 表单 small 帮助 | 同正文 | 10.83px / 400，行高 15.71px | small 默认缩小，插件再设行高 1.45、透明度 .8 |

根字号切到 100% 时，rem/em 字号随之放大，而按钮的 1.8px 字距不按比例变化。
字体家族在黑白主题中相同。字体面本身及中文回退会让不同系统的字形、字重和视觉密度进一步不同。

## 字体名称与实际绘制不是一回事

本地 Linux 样本中，正文的拉丁字母实际使用下载的 Clear Sans，中文回退到 Noto Sans CJK SC；
code 的 monospace 解析为 DejaVu Sans Mono。官方名为 bitstream 的 WOFF 内部家族名实际报告为
Source Code Pro；这只是 CSS 别名和字体内部名称的区别，不是插件额外加载了一套字体。
未显示文字的输入控件可能不返回平台字形记录，但 computed style 仍可核对。

用户截图来自 Windows Edge，不能据此断言中文实际用了微软雅黑、宋体或其他具体字体，也不能
把 Linux 的 DejaVu/Noto 结论直接套过去。确认截图机器的精确字体需该浏览器的 Rendered Fonts，
本轮遵守“不操作 Unraid”边界，没有采集用户页面。

## 建议的下一次收敛范围（本版未实施）

保留“正文/表单”和“代码”两种语义：正文继承 Unraid；UUID/type/PCI/版本、XML/日志和覆盖编辑区
统一显式等宽栈并让相同标识在展示/输入时一致。按钮先保持原生风格，若调整字距，应只限定插件
范围并回归黑白主题、两档字号、窄屏、长 UUID、详情/XML 展开和操作按钮排列；不要全局覆盖 WebGUI。

## 复核

使用已有本地依赖设置 `PHP_BIN`、`PLAYWRIGHT_MODULE`、`PLAYWRIGHT_CHROMIUM_EXECUTABLE`、
`WEBGUI_STYLES` 后运行 `node tests/test_ui_typography.cjs`。后者目录应包含官方基础/主题 CSS、
Clear Sans 与 bitstream 的普通/粗体 WOFF。可通过 `TYPOGRAPHY_EVIDENCE` 指定本地 JSON 输出。
测试不自动下载字体、不连接 NAS，不使用既有浏览器资料目录。
