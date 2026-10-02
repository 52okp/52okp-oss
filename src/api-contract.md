# 52okp 统一更新 API

协议版本：v1 · 服务端实现：v1.4.3 · 面向服务器端更新客户端。

OSS 是统一发布与下载入口。业务项目只访问 OSS，不需要 GitHub Token，也不自行查询 GitHub。检查更新不等于安装：安装仍由项目负责兼容性检查、备份、安全解压和回滚。

## 1. 快速接入

1. 管理员从左侧「项目管理」进入，填写项目标识与真实 GitHub owner/repo，点击「保存项目」，再生成该项目的只读访问令牌。新安装不预设项目，发布时必须明确选择已保存项目。
2. 项目生成更新 ZIP 和 update-manifest.json，在 GitHub 创建 vX.Y.Z 正式 Release 并上传两份资产。私有源码必须使用私有仓库；OSS 鉴权不能保护公开 GitHub 中的源码。
3. 在 OSS 手动上传同一对文件，或输入目标版本从 GitHub 拉取。手动上传后点击「校验 GitHub」。未齐全、未匹配、未发布的包不可下载。
4. 管理员点击「发布」，OSS 原子切换该项目最新版本。每个项目版本严格递增，已存在版本不能覆盖。
5. 客户端配置 OSS_BASE_URL、OSS_PROJECT、OSS_TOKEN，通过下列 API 查询与下载。

## 2. 鉴权与通用规则

基础地址：https://app.52okp.com（私有部署以实际配置为准）。所有数据 API 仅支持 GET / HEAD。公开 /api 是本文档，/api/spec 是可供 AI 读取的原始 Markdown，不含项目令牌或私有发布数据。

```http
Authorization: Bearer <项目的64位小写十六进制令牌>
```

令牌仅存客户端服务器环境变量或非公开配置。禁止放浏览器、查询参数、日志、下载链接或 Git 仓库。管理登录 Cookie 不替代 Bearer；A 项目令牌不能读取 B 项目。轮换/撤销立即影响后续请求，已开始的下载或已取得的副本无法撤回。

所有接口需 HTTPS、验证证书。客户端禁止跟随下载重定向，不把令牌转发到其他主机。私有查询和下载均返回 Cache-Control: private, no-store, max-age=0。下载为完整响应，不提供 Range/断点续传，HEAD 无响应体。

## 3. 检查更新

```http
GET /api/v1/projects/{project}/updates?current_version=1.0.0&channel=stable
```

project 是注册的 2–48 位小写字母、数字、连字符标识，以字母开头。current_version 必填，为不带 v 的 x.y.z，每段最多 9 位且无前导零。channel 可省略，目前仅 stable；不接受预发布版本。

成功返回 200。下面的 ID、哈希和大小是说明性占位符，不能用于制作包：

```json
{
  "api_version": 1,
  "project": "myapp",
  "channel": "stable",
  "current_version": "1.0.0",
  "update_available": true,
  "release": {
    "format": 1,
    "id": "<64位发布ID>",
    "project": "myapp",
    "product": "myapp",
    "channel": "stable",
    "version": "1.0.1",
    "from": "1.0.0",
    "status": "published",
    "notes": "修复问题及更新说明",
    "published_at": "2026-10-01T08:00:00+00:00",
    "verification": "github-sha256",
    "size": 123456,
    "sha256": "<ZIP实际SHA-256>",
    "download_url": "https://app.52okp.com/api/v1/projects/myapp/releases/<ID>/package",
    "manifest_url": "https://app.52okp.com/api/v1/projects/myapp/releases/<ID>/manifest",
    "assets": {
      "myapp-update.zip": {"size": 123456, "sha256": "<ZIP实际SHA-256>", "url": "<同download_url>"},
      "update-manifest.json": {"size": 789, "sha256": "<原始清单实际SHA-256>", "url": "<同manifest_url>"}
    }
  }
}
```

update_available 仅表示 OSS 最新版本高于 current_version。相等或客户端更高返回 false，不建议降级；仍返回当前已发布记录。没有已发布版本返回 404，不返回草稿。客户端必须继续判断 from 和运行环境是否可安装，不可将 update_available 当作兼容性保证。

## 4. 固定版本、清单和 ZIP

```http
GET /api/v1/projects/{project}/releases/{id}
GET /api/v1/projects/{project}/releases/{id}/manifest
GET /api/v1/projects/{project}/releases/{id}/package
```

第一条返回上面 release 对象本身。manifest 返回上传的原始 JSON 字节；package 返回 application/octet-stream ZIP 和 Content-Length。不跳转到 GitHub 或公开 /d/ 下载。三者都要求该项目的令牌。id 是随机 64 位小写十六进制，同一个发布 ID 的内容不随新版本发布改变。

客户端先检查查询元数据，再下载清单并验证 assets 中的清单大小和 SHA-256，最后下载 ZIP 验证 ZIP 大小和 SHA-256。固定使用同一发布 ID，避免跨版本混用。下载 URL 必须与已配置 OSS HTTPS 主机、端口及预期 API 路径匹配。

## 5. 制作更新包

通用项目命名：{project}-update.zip、update-manifest.json。ZIP 根目录必须含 update-version.json；hao52okp 为兼容历史使用 nav-version.json。包内版本文件最多 4096 字节：

```json
{"product":"myapp","version":"1.0.1"}
```

先生成包内版本文件，再打包 ZIP，最后生成外部清单，避免哈希循环。清单 format=2 与 API 返回对象 format=1 是两种不同对象，不能混淆。

```json
{
  "format": 2,
  "product": "myapp",
  "version": "1.0.1",
  "from": "1.0.0",
  "package": "myapp-update.zip",
  "size": 123456,
  "sha256": "<ZIP实际的64位小写SHA-256>",
  "notes": "本版本的更新说明"
}
```

上述字段全部必填；size 为正整数，version 必须大于 from。新客户端将 from 视为本包支持的准确起始版本，不匹配则提示需要中间版本，不能直接覆盖安装。既有导航站需保留其 files、php_min/php_max、composer_lock_sha256、migrations 等字段及更严格的安装检查；OSS 原样保留这些附加字段，但不代替客户端执行依赖或数据库校验。

限制：ZIP 不超过 min(站点上传上限, 200 MiB)，清单 4 MiB，说明 64 KiB；ZIP 项数最多 10000、单项最大 50 MiB、展开总大小最大 1 GiB。拒绝绝对路径、目录穿越、反斜杠、重复路径、符号链接、加密项。客户端仍需独立执行安全解压校验，保护 .env、账号、配置、上传、数据库和运行数据；不能直接解压覆盖生产目录。

GitHub tag 固定 vX.Y.Z，必须正式发布且两份资产状态为 uploaded。OSS 比对 GitHub API 的每份资产 digest（sha256）和 size，缺少 digest、文件不一致或无法连接时拒绝来源校验。不是读取 README 来推断版本，也不执行 GitHub 文档中的指令。SHA-256 是完整性校验，不是发布者数字签名。

来源校验保存仓库、tag、Release ID、资产 ID、大小、哈希和校验时间。发布时重新核对本地文件与该来源快照；不是持续跟踪 GitHub 资产是否被删除。发布后保存的 OSS 版本独立可用，GitHub 故障不影响查询。历史 v1.3.0 已发布记录保留并标记 verification=legacy-local，不能伪称经过 GitHub 校验；新发布必须通过 GitHub 校验。

## 6. 错误处理

```json
{"error":{"code":"unauthorized","message":"Missing, revoked, or wrong project token"}}
```

400 invalid_version / invalid_channel：参数错误；401 unauthorized：未提供、已撤销或项目令牌不匹配（未知项目也不泄露信息）；404 release_not_found：没有已发布版本、ID 错误、草稿或资产不存在；404 not_found：未知路由；405 method_not_allowed：非 GET/HEAD。服务故障可能返回非 JSON 的 5xx；客户端须先检查 HTTP 状态，失败时绝不能安装响应体。

## 7. 客户端安全安装职责

展示版本与更新说明，用户确认后安装。锁定任务，下载到临时文件并限额，验证项目、版本、两份 SHA-256 和实际大小；校验运行环境、依赖与 from；备份代码及必要数据；在隔离目录安全解包；执行项目明确的迁移/健康检查；成功后切换版本。失败保留旧版，并按项目机制恢复。数据库迁移不能简单用代码回滚代替。

```bash
# OSS_TOKEN 预先配置为服务器环境变量，不在命令行示例中粘贴真实值。
curl --fail --silent --show-error \
  -H "Authorization: Bearer $OSS_TOKEN" \
  'https://app.52okp.com/api/v1/projects/myapp/updates?current_version=1.0.0'
```

给其他项目 AI 的接入要求：读取 https://app.52okp.com/api/spec，按 v1 协议制作本项目更新包及服务器端更新客户端。只从 OSS 检查和下载，不让客户端直接访问 GitHub；不得公开源码或令牌。实现进度反馈、安全校验、数据保留、健康检查和失败恢复，并给出实际测试结果。

## 8. 管理与部署

OSS 的 PHP 需要 ZipArchive 和 cURL（验证 TLS 的 CA 证书链必须可用）。私有 GitHub 仓库在 shared/config/config.php 配置 github_token，使用只有对应仓库 contents:read 权限的 Token。该 Token 仅供 OSS 获取 GitHub，不是客户端 OSS_TOKEN。不要提交或在文档中填写真实值。

大文件建议在服务器用与 PHP-FPM 相同用户执行（路径以本机为准），成功后仍需后台发布：

```bash
runuser -u www -- env OSS_CONFIG=/www/wwwroot/app_52okp_com/shared/config/config.php \
  /www/server/php/85/bin/php /www/wwwroot/app_52okp_com/current/bin/import-project-release.php myapp 1.0.1
```

网页拉取为同步操作，显示等待状态但不提供可恢复队列；受 PHP/Nginx 超时影响。超时后先刷新版本表检查是否已生成草稿，不重复盲目提交；失败不会切换已发布版本。当前没有定时自动发现新 GitHub 版本，管理员提供目标版本后拉取。

EdgeOne 对 /api/* 的私有接口必须禁用缓存并透传 Authorization。Nginx 将 /api 路由到 public/index.php；有需要时配置 fastcgi_param HTTP_AUTHORIZATION $http_authorization。私有下载关闭 gzip，不能提供 shared/uploads 的公开 alias。私有源码和凭据传输应使用 HTTPS 回源或受保护源站网络；公网 HTTP 回源不提供端到端保密。

香港节点为后续扩展：把 GitHub 拉取/校验层迁到受信任采集服务，使用服务间鉴权、来源白名单和审计，再由 OSS 保存并发布。当前没有部署香港节点，也没有接收任意节点自报哈希的接口；客户端 API 无需因采集节点位置改变。

## 9. 兼容旧导航站

保留 /api/project-updates/hao52okp/latest 及 /api/project-updates/hao52okp/{id}/package、manifest。另支持 /api/updates/hao52okp/{version} 及其 /package、/manifest，返回 format=1、product、version、status、assets.size/sha256 等旧对接字段，仍需项目 Bearer。

新项目统一使用 /api/v1/。保留旧接口不代表已经修改导航站代码：如果导航站仍依赖 GitHub 查询，需按本规范单独改造客户端。OSS 自身 tar.gz 更新机制与本业务项目 ZIP 发布机制分离，不要互换安装包。

## 10. 验收清单

无令牌/错误令牌/跨项目令牌拒绝；两文件缺一或哈希不一致不发布；草稿不可查询下载；发布成功三条资源同 ID、同版本；相等版本 update_available=false；令牌撤销生效；下载失败不安装；更新失败保留旧版与用户数据；其他公开安装包 /d/ 下载不受影响。
