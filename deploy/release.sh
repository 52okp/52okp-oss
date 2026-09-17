#!/usr/bin/env bash
set -euo pipefail
ROOT=${1:?项目路径}; RELEASE=${2:?发布标识}; PHP=${3:?PHP路径}; HEALTH=${4:?健康检查HTTPS URL}
[[ "$ROOT" =~ ^/www/wwwroot/[a-zA-Z0-9_-]+$ && "$RELEASE" =~ ^[0-9]+-[0-9]+$ && "$PHP" =~ ^/[a-zA-Z0-9/_.-]+$ && "$HEALTH" =~ ^https://[a-zA-Z0-9.-]+/health$ ]] || exit 1
[[ "$(realpath -e "$ROOT")" == "$ROOT" && ! -L "$ROOT/releases" && ! -L "$ROOT/shared" ]] || { echo '项目及数据目录必须为真实目录'; exit 1; }
[[ -d "$ROOT/releases" && -f "$ROOT/shared/config/config.php" && -f "$ROOT/shared/config/admin.php" ]] || { echo '先完成服务器初始化'; exit 1; }
exec 9>"$ROOT/deploy.lock"; flock -x 9
DEST="$ROOT/releases/$RELEASE"
[[ ! -e "$DEST" ]] || exit 1
mkdir "$DEST"
tar -xzf "$ROOT/$RELEASE.tar.gz" -C "$DEST" --no-same-owner
export OSS_CONFIG="$ROOT/shared/config/config.php"
find "$DEST" -name '*.php' -print0 | xargs -0 -n1 "$PHP" -l
"$PHP" "$DEST/tests/check.php"
printf '%s\n' "$RELEASE" > "$DEST/.release"
PREVIOUS=$(readlink "$ROOT/current" || true)
if [[ -n "$PREVIOUS" ]]; then
    [[ "$PREVIOUS" =~ ^releases/[0-9]+-[0-9]+$ && -d "$ROOT/$PREVIOUS" ]] || { echo 'current 指向非受管发布，停止'; exit 1; }
elif [[ -e "$ROOT/current" ]]; then echo 'current 必须是受管符号链接'; exit 1; fi
ln -s "releases/$RELEASE" "$ROOT/current.next"
mv -Tf "$ROOT/current.next" "$ROOT/current"
rollback() {
    trap - ERR INT TERM
    if [[ -n "$PREVIOUS" ]]; then ln -s "$PREVIOUS" "$ROOT/current.rollback"; mv -Tf "$ROOT/current.rollback" "$ROOT/current";
    else unlink "$ROOT/current"; fi
    echo "上线失败，已回退；失败代码保留在 $DEST"
}
trap 'rollback; exit 1' ERR INT TERM
if ! curl --fail --silent --show-error --retry 3 --retry-delay 2 --max-time 15 "$HEALTH" | "$PHP" -r '$data=json_decode(stream_get_contents(STDIN),true);exit(($data["ok"]??false)===true && ($data["release"]??"")===$argv[1]?0:1);' "$RELEASE"; then rollback; exit 1; fi
trap - ERR INT TERM
rm -- "$ROOT/$RELEASE.tar.gz"
# 清理仅限严格匹配的旧发布，保护 current 和上一个版本；默认保留最近5版。
mapfile -t OLD < <(find "$ROOT/releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -rn | tail -n +6)
for item in "${OLD[@]}"; do
    [[ "$item" =~ ^[0-9]+-[0-9]+$ && "$item" != "$RELEASE" && "releases/$item" != "$PREVIOUS" ]] || continue
    target=$(realpath -e "$ROOT/releases/$item")
    [[ "$target" == "$ROOT/releases/$item" ]] || exit 1
    rm -rf -- "$target"
done
echo "上线成功：$RELEASE"
