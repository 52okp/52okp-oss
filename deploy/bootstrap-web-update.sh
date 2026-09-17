#!/usr/bin/env bash
set -euo pipefail
# One-time installation from a NEW release directory, never overwrites the running code.
ROOT=${1:?项目路径}; DEPLOY_USER=${2:?更新用户}; WEB_USER=${3:?PHP用户}; PHP=${4:?PHP路径}; HEALTH=${5:?HTTPS健康URL}
[[ $(id -u) == 0 && "$ROOT" =~ ^/www/wwwroot/[a-zA-Z0-9_-]+$ && "$DEPLOY_USER" =~ ^[a-z_][a-z0-9_-]*$ && "$DEPLOY_USER" != root && "$WEB_USER" =~ ^[a-z_][a-z0-9_-]*$ ]] || exit 1
[[ "$PHP" =~ ^/[a-zA-Z0-9/_.-]+$ && "$HEALTH" =~ ^https://[a-zA-Z0-9.-]+/health$ && $(realpath -e "$ROOT") == "$ROOT" ]] || exit 1
SRC=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
NAME=$(basename "$SRC")
[[ "$NAME" =~ ^[0-9]+-[0-9]+$ && "$SRC" == "$ROOT/releases/$NAME" && -L "$ROOT/current" ]] || { echo '请将新版上传到单独的 releases/数字-数字 目录，不要覆盖当前版本'; exit 1; }
PREVIOUS=$(readlink "$ROOT/current")
[[ "$PREVIOUS" =~ ^releases/[0-9]+-[0-9]+(-[a-f0-9]{12})?$ && "$PREVIOUS" != "releases/$NAME" && -d "$ROOT/$PREVIOUS" && ! -L "$ROOT/$PREVIOUS" ]] || exit 1
if find "$SRC" -type l -print -quit | grep -q .; then echo '代码目录中不允许链接文件'; exit 1; fi
# SRC has been resolved and checked as one NEW release inside this project's releases.
chown -R "$DEPLOY_USER:oss-files" "$SRC"
find "$SRC" -type d -exec chmod 2750 {} +
find "$SRC" -type f -exec chmod 640 {} +
find "$SRC" -name '*.php' -print0 | xargs -0 -n1 "$PHP" -l
runuser -u "$DEPLOY_USER" -- "$PHP" "$SRC/tests/check.php"
bash "$SRC/deploy/install-updater.sh" "$ROOT" "$DEPLOY_USER" "$WEB_USER" "$PHP" "$HEALTH"
touch "$ROOT/deploy.lock"; chown "$DEPLOY_USER:oss-files" "$ROOT/deploy.lock"; chmod 660 "$ROOT/deploy.lock"
exec 9>"$ROOT/deploy.lock"; flock -x 9
PREVIOUS=$(readlink "$ROOT/current")
[[ "$PREVIOUS" =~ ^releases/[0-9]+-[0-9]+(-[a-f0-9]{12})?$ && "$PREVIOUS" != "releases/$NAME" && -d "$ROOT/$PREVIOUS" && ! -L "$ROOT/$PREVIOUS" ]] || exit 1
TAG="bootstrap-$NAME"
printf '%s\n' "$TAG" > "$SRC/.release"
rollback() {
    trap - ERR INT TERM
    ln -s "$PREVIOUS" "$ROOT/.bootstrap-rollback"
    mv -Tf "$ROOT/.bootstrap-rollback" "$ROOT/current"
    echo '新版检查失败，已切回原代码；本地服务保留，账号和上传文件未修改。'
}
ln -s "releases/$NAME" "$ROOT/.bootstrap-next"
mv -Tf "$ROOT/.bootstrap-next" "$ROOT/current"
trap 'rollback; exit 1' ERR INT TERM
if ! curl --fail --silent --show-error --retry 3 --retry-delay 2 --max-time 15 "$HEALTH?release=$TAG" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN),true);exit(($d["ok"]??false)===true && ($d["release"]??"")===$argv[1]?0:1);' "$TAG"; then rollback; exit 1; fi
trap - ERR INT TERM
echo '网页更新功能已安装并通过健康检查。登录后台即可检查更新、更新程序。'
