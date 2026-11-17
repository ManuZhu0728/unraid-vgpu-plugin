# 界面字体规则与核对（2026-11-16）

## Alpha 17：已统一的规则

正文、表单、按钮和标签页使用 Unraid 原生样式。插件内展示的 UUID、type、PCI、版本及 XML、日志、JSON 和
override 编辑区使用同一等宽字体栈，优先 `bitstream`。行内代码保持周围文字字号。
CSS 作用域限定在插件内容内。

### 回归覆盖

浏览器用官方 WebGUI 7.3.4 CSS 和 WOFF 测试白色与黑色主题、两档根字号、窄屏到桌面宽度及等宽字体回退。
在当次 Linux Chromium 上，实际字体为 WOFF 中的 Source Code Pro，缺少 WOFF 时回退 Liberation Mono。
布局测试另检查长标识、详情/XML 展开、焦点返回及表格滚动。Windows 实际渲染字体需在对应浏览器中查看。

## 复核

用 `PHP_BIN`、`PLAYWRIGHT_MODULE` 和 `PLAYWRIGHT_CHROMIUM_EXECUTABLE` 指定本地依赖，
`WEBGUI_STYLES` 指向含官方基础/主题 CSS 与 Clear Sans、bitstream 普通及粗体 WOFF 的目录。

```bash
node tests/test_ui_typography.cjs
node tests/test_ui_layout.cjs
```

可设置 `TYPOGRAPHY_EVIDENCE` 保存字体测量 JSON。
[整理前的字体审计](https://github.com/ManuZhu0728/unraid-vgpu-plugin/blob/60d0d445a40e39d1dd2122d9f47c9fda083eeab9/docs/UI-TYPOGRAPHY.md)
