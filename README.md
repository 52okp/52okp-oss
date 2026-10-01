# PHP 文件云存储

中文文件管理后台，支持普通文件公开下载及 hao52okp 私有更新包发布。目标运行环境 PHP 8.5、Nginx、Linux 宝塔。无数据库或 Composer 依赖。

## v1.3.0 hao52okp 私有更新发布

后台「更新包发布」入口选择 hao52okp，同时上传 `hao52okp-update.zip` 与 `update-manifest.json`。兼容导航站 `scripts/build-update.php` 产生的 format 2 清单，保留原始清单内容。需要 PHP-FPM 启用 ZIP 扩展。ZIP 上限为站点限制与 200 MB 的较小值，清单上限 4 MB；Nginx/PHP/CDN 的 POST 限制需容纳两个文件和 multipart 开销。

上传时检查清单 product、package、version/from、大小与 SHA-256、ZIP 一致性和内置 `nav-version.json`。不执行或解压源码。完整校验成功后才创建草稿；点击「发布」会再次校验 ZIP 和原始清单哈希，在同一个元数据锁和原子替换操作中更新已发布指针。上传中、失败和草稿均不影响当前发布。发布必须严格递增版本，重复版本不可覆盖；历史已发布包继续可供有权限的客户端下载。

首次使用展开「私有接口与访问令牌」生成 256 位随机令牌，仅显示一次，数据库只保存 SHA-256。将令牌保存在导航站服务器配置中。轮换或撤销立即使旧令牌失效，撤销后需生成新令牌才能继续查询/下载。令牌不可放入 URL、浏览器端或仓库。

接口（均为 GET/HEAD，使用 `Authorization: Bearer <令牌>`）：

- `/api/project-updates/hao52okp/latest`：已发布版本、from、notes、size、sha256、published_at、download_url、manifest_url。无已发布版本返回 404。
- `/api/project-updates/hao52okp/{发布ID}/package`：私有 ZIP。
- `/api/project-updates/hao52okp/{发布ID}/manifest`：原始 JSON 清单。

无令牌/错误令牌/撤销令牌返回 401；未发布包返回 404。下载地址本身不授予权限，两次下载也必须携带请求头。支持完整下载，不实现 Range；客户端获取后必须再次校验大小、SHA-256、清单文件哈希和其原有安装安全规则。响应声明 `Cache-Control: private, no-store` 与 `Vary: Authorization`。源代码由 PHP 鉴权后流式输出，不经公开 X-Accel 路径。

**上线配置**：EdgeOne 对 `/api/project-updates/*` 禁止缓存并透传 Authorization；Nginx 不得为这一路径配置静态文件 alias。若 fastcgi 未传递 Authorization，在 PHP location 添加 `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`。公网必须使用 HTTPS；当前 HTTP 回源链路不提供端到端加密，生产私有源码建议使用 HTTPS 回源或受保护的源站网络。

**历史公开包**：属于项目 `hao52okp`、或文件名为 `hao52okp-update.zip` / `update-manifest.json` 的原公开下载被拒绝，也不能通过改分类重新公开。其他项目安装包保持原下载方式。此前已被 CDN 缓存的公开包必须在 EdgeOne 清除对应 `/d/{文件ID}` 缓存；此前已被下载的副本不能撤回。用其他项目名/文件名上传过的源码需管理员核查并移除公开文件。本功能不自动猜测压缩包是否源码。

当前改动提供存储端发布和接口；导航站现有 GitHub 更新客户端需要另行切换到该查询 API，并为查询/清单/ZIP 三种请求携带令牌。不能只更改下载 URL。

## v1.1.0 后台界面

v1.2.0 新增文件分类：项目名称为大分类，Windows、安卓、iOS、Mac Intel、Mac Apple 芯片为平台小分类。上传必须提供项目和平台，版本号选填；同批文件使用相同分类。项目名称可直接输入新增或选择已使用名称。文件列表支持项目与平台联合筛选及编辑分类，旧文件默认未分类，可逐个补充；不移动文件或改变公开下载链接。分类仅存放在文件元数据中，删除最后一个对应文件后，该项目不再出现在选择列表。

蓝白卡片式布局，包含真实文件数量、上传文件总大小、存储目录状态；支持拖拽、多文件逐个上传及每个文件的进度反馈。文件列表支持搜索、类型筛选、排序、分页、复制链接和删除。程序更新沿用现有本地服务及安全机制；系统信息只读，未添加虚构容量、历史增长或日志。上传完成后点击刷新列表同步统计。无 JavaScript 时可单文件上传、下载、复制/手动选取链接、删除并刷新查看更新状态。

静态资源改用 `/cloud.css`、`/update.js`、`/dashboard.js`，避开旧路径的 CDN 缓存。后续 CDN 缓存键仍需保留 `v` 参数，后台页面和状态接口不应缓存。

## 宝塔首次安装

1. 先在宝塔确认 PHP 8.5 实际可用；不可用时不要擅自降级生产环境。安装 Nginx、PHP 8.5，确认 session、json、密码哈希功能；上传需启用 file_uploads。服务器安装 bash、curl、flock；网页更新还需要系统 Python 3.9+ 和 systemd。
2. 创建独立网站，绑定 `app.52okp.com` 并申请 HTTPS 证书；HTTP 跳转 HTTPS。
3. 将仓库代码先上传到 `/www/wwwroot/app_52okp_com/releases/首次发布标识`（标识格式如 `2026091701-1`）。以 root 执行 `bash deploy/setup.sh /www/wwwroot/app_52okp_com ossdeploy www`。`www` 必须替换为真实 PHP-FPM 用户；Nginx 用户也需要 oss-files 组的读取权限。重新登录部署用户、重启相关服务使权限生效。不要使用 chmod 777。
4. 将 `config.example.php` 复制到 `/www/wwwroot/app_52okp_com/shared/config/config.php`，模板已设置 `base_url=https://app.52okp.com` 和 `shared=/www/wwwroot/app_52okp_com/shared`，按需调整 max_bytes。文件归 PHP-FPM 用户，权限 0640，组 oss-files。配置不能提交 Git。
5. 在 root 终端以 PHP-FPM 用户运行初始化（按实际 PHP 路径调整，在首次 release 目录执行）：`runuser -u www -- env OSS_CONFIG=/www/wwwroot/app_52okp_com/shared/config/config.php /www/server/php/85/bin/php bin/init.php`。密码从交互标准输入读取，不放命令参数；输入前可用 `stty -echo` 隐藏回显，完成后务必 `stty echo`。已有账号不会被覆盖。管理员配置权限 0600。
6. 在 `/www/wwwroot/app_52okp_com` 下创建 current 符号链接到首次 release，例如 `ln -s releases/2026091701-1 current`。网站根目录设置为 `/www/wwwroot/app_52okp_com/current/public`。参考 `deploy/nginx.conf.example` 修改宝塔 HTTPS server 块，核实 PHP socket，删除重复通用 PHP 规则。宝塔 open_basedir 如开启，必须允许 shared 目录和系统上传临时目录。执行 `nginx -t` 后重载。
7. PHP 设置 `upload_max_filesize=512M`、`post_max_size=520M`、`max_execution_time=300`、`max_input_time=300`；设置 PHP-FPM 可写的独立 upload_tmp_dir。Nginx `client_max_body_size=520m`，与应用默认 512 MiB 上限留出 multipart 开销。相关代理/CDN也可能有更低限制，必须实测。第一版普通上传，无分片恢复；下载支持断点续传。
8. 登录并上传小文件与大文件，复制链接；在无痕窗口下载。上传内容以随机无扩展名存储，Nginx internal 目录只作为静态字节返回，不会执行脚本。

## GitHub 发布包 + 网页点击更新

已改为无需公网SSH的更新方式。所有push运行验证，只有仓库实际默认分支在通过测试后生成GitHub Release代码包；也支持手动运行。Actions不会连接或更新生产服务器，不再使用SSH_* Secrets或DEPLOY_ENABLED。

管理员登录后台点击“检查更新”“更新程序”，由非root的本地systemd服务下载、验证、发布到新releases目录，原子切换current并健康检查，失败尝试回滚。shared中的账号、文件和元数据不变。

现有站点需一次性完整上传新版代码并安装本地服务。详细步骤、安全边界和排错见 [网页更新安装说明](网页更新安装说明.md)。以后不需要重复上传或运行root安装命令。

## 验证与备份

本地：`php tests/check.php`、`php tests/http.php`、`python3 -B -m unittest discover -s tests -p 'test_*.py' -v`，对全部 PHP 文件执行 `php -l`。CI使用PHP8.5和Linux，并验证更新包安全、成功切换、失败回滚及重启恢复；Windows跳过Linux锁和符号链接测试。本地 PHP 内置服务器不能处理 X-Accel-Redirect，下载内容验证必须用Nginx。

Nginx设计依据：[FastCGI响应头处理](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html#fastcgi_ignore_headers)及[internal location](https://nginx.org/en/docs/http/ngx_http_core_module.html#internal)。请勿配置忽略X-Accel-Redirect；internal目录不能被客户端直接访问。

上线验收：未登录无法管理；错误 CSRF 返回403；5次登录尝试后限速；同名上传生成不同直链；下载文件 SHA256 与原文件一致；`curl -I 直链` 检查HEAD，`curl -H 'Range: bytes=0-9' 直链` 检查206及10字节内容，用 `curl -C - -O 直链` 检查续传。直接访问 `/_files/标识`、配置和元数据必须失败。删除后原直链404。部署前后重复上述测试确认链接和账号保持有效。

手动回滚：以部署用户在项目根目录选择已验证旧版本，创建临时符号链接 `ln -s releases/旧标识 current.rollback`，再 `mv -Tf current.rollback current`；在无并发部署时执行，并检查health和登录下载。绝不能回滚或覆盖shared。

备份整个 shared（含uploads、metadata、config）到加密且受限的异地备份。为确保文件与元数据一致，备份期间暂停管理上传/删除或停止 PHP-FPM，下载可继续。恢复时一起恢复上传及元数据，并还原权限。sessions可不恢复，用户需重新登录。JSON方式适合个人小规模文件列表，操作采用独立锁及原子替换；目录磁盘满时需排查孤立文件，备份不能只取uploads。

网页更新服务尚需在真实服务器一次性安装并验收；代码和CI通过不能代替生产环境的更新、回滚和下载测试。
# 程序版本

对外版本从 `v1.0.0` 开始，在 `deploy/version.txt` 统一维护。后续修复使用 `v1.0.1`，功能更新使用 `v1.1.0`。后台和 GitHub Release 标题显示此版本；`build-*` 是更新服务兼容性所需的内部构建标识，不用于对外版本展示。同版本重新构建仍可更新，安装判断和回滚继续使用构建标识。
# v1.4.0 统一更新中心

公开对接规范：`/api`（网页）与 `/api/spec`（原始 Markdown，供其他项目/AI 读取）。规范源码为 `src/api-contract.md`，与应用一起发布。

- 管理端支持注册多个项目、绑定 GitHub `owner/repo`、独立生成/轮换/撤销项目令牌。
- ZIP/清单可手动上传，或按明确版本从 GitHub 拉取。标准包名为 `{project}-update.zip`；标准清单 `update-manifest.json`；包内 `update-version.json`（导航站兼容 `nav-version.json`）。
- 只接受 `vX.Y.Z` 正式 Release；比对两份 GitHub 资产的大小、SHA-256 digest。缺失或不匹配时不允许发布。手动上传后需点击来源校验，再手动发布。
- 新 API：`GET /api/v1/projects/{project}/updates?current_version=1.0.0`，返回最新已发布记录、说明、双文件哈希与受保护下载 URL；固定版本及下载路径见 `/api`。查询不依赖 GitHub 在线。
- 旧导航站令牌与发布记录保留。历史记录显示 `legacy-local`，不冒充 GitHub 验证；新草稿要求先绑定真实仓库并验证。保留 `/api/project-updates/hao52okp/*`，补充 `/api/updates/hao52okp/{version}` 兼容入口。

部署新增要求：PHP cURL + ZIP、有效 CA 证书；私有仓库在服务器配置 `github_token`（只读 contents 权限，不能提交到 Git）。EdgeOne 对私有 `/api/*` 禁用缓存、透传 Authorization；私有传输使用 HTTPS 回源或受保护源站网络。普通安装包下载方式不变。

网页拉取是同步请求，有等待提示，受 PHP/Nginx 超时限制；大文件可用 `bin/import-project-release.php 项目标识 1.0.1`（以 PHP-FPM 用户、正确 OSS_CONFIG 运行）。它只创建草稿，不自动发布。没有定时发现新版本；香港采集节点尚未实现/部署，未来替换采集层不改变客户端 API。客户端安装/备份/回滚仍需各项目按规范实现。

历史部署说明仅供迁移参考；业务项目 API 契约以 `/api` 为准，OSS 自身 tar.gz 更新与业务 ZIP 分开。
# v1.4.1 后台分页面导航

后台左侧分为文件管理、上传文件、项目管理、更新包发布、程序更新、系统信息；各页面有独立 `?view=` 地址，刷新和浏览器前进/后退可保留页面。不再把所有模块堆在长页面中。

「项目管理」直接显示注册表单及已保存项目，保存成功留在当前页并提示。空白安装和没有数据的默认占位不再预置 hao52okp；已有真实仓库、令牌、历史发布记录继续保留。上传/拉取必须主动选择项目，服务端不再隐式回退到导航站。
