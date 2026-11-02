# 下载源格式

## 自动匹配

页面的“下载地址”支持仓库、Release 页面和 TXZ 文件直链。平台由 URL 识别；
当前运行内核决定包名。高级设置可以固定驱动版本，默认优先已安装版本，其次是最高匹配版本。

新式 Release 使用内核作为标签：

```text
6.18.58-Unraid
├── nvidia-580.159.03-6.18.58-Unraid-1.txz
├── nvidia-580.159.03-6.18.58-Unraid-1.txz.md5
├── nvidia-595.58.03-6.18.58-Unraid-1.txz
└── nvidia-595.58.03-6.18.58-Unraid-1.txz.md5
```

旧的 `v6.18.58-595.58.03` 标签仍可读取。GitHub 私有附件通过资产 API 下载；
GitLab 私有源使用同源认证。MD5 支持裸哈希及“哈希 + 文件名”两种格式。

## 学校 GitLab

仓库链接加 API Token 可以查找 Releases。Deploy Token 只允许读 Package Registry：

- 自动模式可用当前驱动版本定位 `build/v{kernel}-{driver}` 中的包。
- 其他版本可在高级设置指定，或直接粘贴 Generic API 的 TXZ 下载链接。
- Generic API 链接格式为 `/api/v4/projects/<ID或编码路径>/packages/generic/build/<版本>/<文件名>`。

自动识别只在同一仓库内选择访问方式。认证和网络错误会直接显示，跨域下载不会携带原站 Token。

## 驱动仓库发布布局

建议以后新增按内核汇总的 Release，保留已有包地址：

| 内容 | 格式 |
| --- | --- |
| 内核汇总 Release | `6.18.58-Unraid` |
| 原有 Generic Package 版本 | `v6.18.58-595.58.03` |
| 共用构建输入 Release | `inputs-595.58.03-r1` |

汇总页链接现有 TXZ/MD5，多个驱动版本采用合并保留；不移动旧 tag、不重传驱动包。
构建脚本中 Release tag 与 Generic 版本目前共用字段，迁移发布器时需要先拆开。
本次插件改动只增加读取兼容，没有更改学校仓库的现有 Release。

## 本地文件

浏览器按最多 1 MiB 分块上传，后端在独立临时目录拼接，完成后调用与在线下载相同的归档检查。
附带 MD5 时校验文件内容；未附带时记录本地计算值。取消或校验失败会清理该上传会话。
