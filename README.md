# PHP 文件云存储

中文管理后台，公开下载直链，不包含 APP 版本管理。目标运行环境 PHP 8.5、Nginx、Linux 宝塔。无数据库或 Composer 依赖。

## v1.1.0 后台界面

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
