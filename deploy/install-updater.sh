#!/usr/bin/env bash
set -euo pipefail
ROOT=${1:?项目路径}; DEPLOY_USER=${2:?更新服务用户}; WEB_USER=${3:?PHP-FPM用户}; PHP=${4:?PHP绝对路径}; HEALTH=${5:?HTTPS健康检查URL}
[[ $(id -u) == 0 ]] || { echo '此一次性安装脚本需在宝塔 root 终端运行'; exit 1; }
[[ "$ROOT" =~ ^/www/wwwroot/[a-zA-Z0-9_-]+$ && "$DEPLOY_USER" =~ ^[a-z_][a-z0-9_-]*$ && "$DEPLOY_USER" != root && "$WEB_USER" =~ ^[a-z_][a-z0-9_-]*$ ]] || exit 1
[[ "$PHP" =~ ^/[a-zA-Z0-9/_.-]+$ && "$HEALTH" =~ ^https://[a-zA-Z0-9.-]+/health$ ]] || exit 1
[[ $(realpath -e "$ROOT") == "$ROOT" && ! -L "$ROOT/releases" && ! -L "$ROOT/shared" ]] || { echo '项目必须使用真实目录'; exit 1; }
[[ -f "$ROOT/shared/config/config.php" && -f "$ROOT/shared/config/admin.php" && -L "$ROOT/current" ]] || { echo '先完成站点和账号初始化，current需为链接'; exit 1; }
[[ -x /usr/bin/python3 ]] || { echo '请先安装系统 python3（3.9或以上）'; exit 1; }
/usr/bin/python3 -c 'import sys; assert sys.version_info >= (3,9), "需要 Python 3.9 或以上"'
command -v systemctl >/dev/null
id "$DEPLOY_USER" >/dev/null; id "$WEB_USER" >/dev/null
getent group oss-files >/dev/null
SRC=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
[[ -f "$SRC/src/updater.php" && -f "$SRC/deploy/update-worker.py" ]] || { echo '请完整上传新版代码'; exit 1; }
"$PHP" -l "$SRC/src/updater.php"
usermod -aG oss-files "$DEPLOY_USER"; usermod -aG oss-files "$WEB_USER"
# Only root/releases change ownership; never recursively chown persistent data.
chown "$DEPLOY_USER:oss-files" "$ROOT" "$ROOT/releases"
chmod 2750 "$ROOT" "$ROOT/releases"
for dir in updater update-cache; do [[ ! -L "$ROOT/shared/$dir" ]] || exit 1; done
install -d -o "$WEB_USER" -g oss-files -m 2770 "$ROOT/shared/updater"
install -d -o "$DEPLOY_USER" -g oss-files -m 2750 "$ROOT/shared/update-cache"
touch "$ROOT/shared/updater/queue.lock" "$ROOT/shared/updater/enabled"
chown "$WEB_USER:oss-files" "$ROOT/shared/updater/queue.lock" "$ROOT/shared/updater/enabled"
chmod 660 "$ROOT/shared/updater/queue.lock" "$ROOT/shared/updater/enabled"
install -d -o root -g root -m 755 /usr/local/lib/52okp-oss
systemctl stop 52okp-oss-updater.service 2>/dev/null || true
install -o root -g root -m 644 "$SRC/deploy/update-worker.py" /usr/local/lib/52okp-oss/update-worker.py
printf '{"root":"%s","php":"%s","health":"%s"}\n' "$ROOT" "$PHP" "$HEALTH" > /etc/52okp-oss-updater.json
chmod 644 /etc/52okp-oss-updater.json
sed -e "s|@USER@|$DEPLOY_USER|g" -e "s|@ROOT@|$ROOT|g" "$SRC/deploy/52okp-oss-updater.service.example" > /etc/systemd/system/52okp-oss-updater.service
chmod 644 /etc/systemd/system/52okp-oss-updater.service
systemctl daemon-reload
systemctl enable --now 52okp-oss-updater.service
systemctl --no-pager --full status 52okp-oss-updater.service
echo '本地服务安装完成。重启 PHP-FPM/Nginx 使组权限生效，然后登录后台检查更新。无需 SSH 公网端口。'
