# 界面字体规则与核对（2026-11-16）

## Alpha 17：已统一的规则

正文和普通表单保留 Unraid 原生字体；按钮/页签保留原生字体、字号、字重、字距、颜色、边框和
内边距。UUID/type/PCI 等表单控件也保留原生字体，不覆盖整个 input/select 或把按钮变成代码字体。
profile_override 是代码编辑区，单独使用统一等宽栈。

插件 `.uvm-wrap` 内的 code、pre 和代码编辑区共同使用 `--uvm-font-mono`：
bitstream → ui-monospace → SFMono-Regular → Menlo → Monaco → Consolas → Liberation Mono →
DejaVu Sans Mono → monospace。优先使用 WebGUI 已加载的原生字体，失败时统一回退，不捆绑或
额外下载字体。内联 code 为 1em，保留父级正文/辅助信息/标题字号；不强制所有位置同字号或行高。

| 保持原生 | 使用统一等宽 |
| --- | --- |
| 正文、状态、标签、配置名称、帮助文字 | 列表和详情展示的 UUID、type、PCI |
| 普通文本/密码/URL/文件输入框与下拉框 | GPU 概览、版本列表、缓存、升级准备、任务的驱动/内核版本及构建号 |
| 所有原生按钮和页签 | 详情/XML 标题中的技术标识、动态生成的 profile type |
| 插件之外所有 WebGUI 内容 | XML、日志、JSON 详情、覆盖代码编辑区、缓存文件名 |

初始 PHP 页面和下载/删除后的动态 DOM 保持相同的 code 标签。数据仍通过转义或 textContent
插入，不解析为 HTML；详情/XML 位于表格外，字体统一不改变展开操作、表单数据和设备行为。

### 回归覆盖

`tests/test_ui_typography.cjs` 覆盖黑白主题、62.5%/100% 根字号、320/360/768/1920px，以及原生
等宽字体可用/缺失，共 32 组。检查 20 个等宽与 16 个原生节点，直接比较与插件外原生对照的
computed style；按钮还核对颜色、背景、边框和内边距。用平台字形记录确认 UUID/XML/日志实际
使用同一家族，缺失原生 WOFF 时也保持一致。Linux 测试不代替 Windows Edge 的实际字体证明。

每组对多行/长内容、同 UUID 不同 PCI 的 6 个详情/XML 控件开关，共 384 次表格/按钮几何检查，
并检查窄屏局部滚动、焦点、键盘、草稿不变及零网络请求。旧混合字体负对照应被相同断言识别。
原有布局、下载、缓存、新增回归均加载官方 Clear Sans 与 bitstream 普通/粗体，另保留旧布局负对照。

## Alpha 16 审计基线（历史，未统一前）

当时截图中的不一致有代码依据，不全是字号或浏览器缩放。正文、代码字段、代码块、输入框和按钮
分别继承不同规则；同一个 UUID 在表格与输入框里的字体确实不同。Alpha 16 仅审计，Alpha 17
采用以上受限规则；以下表格保留作为修复前的对照，不代表当前代码仍混用默认等宽字体。

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

## Alpha 16 时的收敛建议与本次选择

保留“正文/表单”和“代码”两种语义：正文继承 Unraid；UUID/type/PCI/版本、XML/日志和覆盖编辑区
统一显式等宽栈。按用户本次要求，普通表单保持原生字体，没有实施此前“展示/输入都改等宽”的
可选建议。按钮不调整字距或风格，也不全局覆盖 WebGUI；相关回归见当前规则。

## 复核

使用已有本地依赖设置 `PHP_BIN`、`PLAYWRIGHT_MODULE`、`PLAYWRIGHT_CHROMIUM_EXECUTABLE`、
`WEBGUI_STYLES` 后运行 `node tests/test_ui_typography.cjs`。后者目录应包含官方基础/主题 CSS、
Clear Sans 与 bitstream 的普通/粗体 WOFF。可通过 `TYPOGRAPHY_EVIDENCE` 指定本地 JSON 输出。
测试不自动下载字体、不连接 NAS，不使用既有浏览器资料目录。
