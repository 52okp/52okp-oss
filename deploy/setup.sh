#!/usr/bin/env bash
set -euo pipefail
# 以 root 运行；必须显式指定三个参数。
ROOT=${1:?项目绝对路径}; DEPLOY_USER=${2:?部署用户}; WEB_USER=${3:?PHP-FPM用户}
[[ "$ROOT" =~ ^/www/wwwroot/[a-zA-Z0-9_-]+$ ]] || { echo '项目路径必须是 /www/wwwroot 下单独目录'; exit 1; }
[[ "$DEPLOY_USER" =~ ^[a-z_][a-z0-9_-]*$ && "$WEB_USER" =~ ^[a-z_][a-z0-9_-]*$ && "$DEPLOY_USER" != root ]] || exit 1
id "$WEB_USER" >/dev/null
id "$DEPLOY_USER" >/dev/null 2>&1 || useradd -m -s /bin/bash "$DEPLOY_USER"
getent group oss-files >/dev/null || groupadd oss-files
usermod -aG oss-files "$DEPLOY_USER"; usermod -aG oss-files "$WEB_USER"
install -d -o "$DEPLOY_USER" -g oss-files -m 2750 "$ROOT" "$ROOT/releases" "$ROOT/shared"
for dir in config uploads metadata sessions; do
    install -d -o "$WEB_USER" -g oss-files -m 2770 "$ROOT/shared/$dir"
done
echo '目录就绪。重新登录部署用户，并重启 PHP-FPM 使组权限生效。配置和管理员需手动初始化。'
