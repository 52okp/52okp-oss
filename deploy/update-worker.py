#!/usr/bin/env python3
"""Root-installed, unprivileged updater. Only the fixed public GitHub repo is trusted."""
from __future__ import annotations

import contextlib
import hashlib
import json
import os
import re
import shutil
import signal
import subprocess
import sys
import tarfile
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path, PurePosixPath

try:
    import fcntl
except ImportError:
    fcntl = None

REPOSITORY = "52okp/52okp-oss"
TAG = re.compile(r"build-[0-9]+-[0-9]+\Z")
RELEASE_DIR = re.compile(r"releases/[0-9]+-[0-9]+(?:-[a-f0-9]{12})?\Z")
MAX_PACKAGE = 20 * 1024 * 1024
MAX_EXPANDED = 64 * 1024 * 1024
DIRECTORIES = {"public", "src", "bin", "deploy", "tests"}
ROOT_FILES = {"README.md", "config.example.php", ".release", "release.json", ".gitattributes", "网页更新安装说明.md"}


def atomic_json(path, data, mode=0o660):
    path = Path(path)
    fd, temporary = tempfile.mkstemp(prefix=".update-", dir=path.parent)
    try:
        if hasattr(os, "fchmod"):
            os.fchmod(fd, mode)
        else:
            os.chmod(temporary, mode)
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            json.dump(data, stream, ensure_ascii=False)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def read_json(path):
    path = Path(path)
    if not path.exists():
        return {}
    if path.is_symlink() or path.stat().st_size > 65536:
        raise RuntimeError("更新队列文件无效")
    with path.open(encoding="utf-8") as stream:
        data = json.load(stream)
    if not isinstance(data, dict):
        raise RuntimeError("更新队列格式无效")
    return data


@contextlib.contextmanager
def locked(path):
    if fcntl is None:
        raise RuntimeError("更新服务仅支持 Linux")
    descriptor = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o660)
    try:
        fcntl.flock(descriptor, fcntl.LOCK_EX)
        yield
    finally:
        os.close(descriptor)


def fetch_release(tag=None):
    if tag is not None and not TAG.fullmatch(tag):
        raise RuntimeError("无效版本标识")
    suffix = "latest" if tag is None else "tags/" + tag
    url = "https://api.github.com/repos/" + REPOSITORY + "/releases/" + suffix
    request = urllib.request.Request(url, headers={"User-Agent": "52okp-oss-updater/1", "Accept": "application/vnd.github+json"})
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            raw = response.read(2 * 1024 * 1024 + 1)
    except urllib.error.HTTPError as error:
        if error.code == 404:
            raise RuntimeError("GitHub 暂无可用发布包，请先等待 Actions 发布完成") from error
        raise RuntimeError("GitHub 接口请求失败，可能达到限速，请稍后重试") from error
    if len(raw) > 2 * 1024 * 1024:
        raise RuntimeError("发布信息过大")
    return validate_release(json.loads(raw), tag)


def validate_release(data, expected=None):
    tag = data.get("tag_name", "")
    if not isinstance(tag, str) or not TAG.fullmatch(tag) or data.get("draft") or data.get("prerelease") or (expected is not None and tag != expected):
        raise RuntimeError("发布版本不符合更新协议")
    assets = [asset for asset in data.get("assets", []) if asset.get("name") == "52okp-oss.tar.gz"]
    if len(assets) != 1:
        raise RuntimeError("发布包缺失或重复")
    asset = assets[0]
    url = "https://github.com/" + REPOSITORY + "/releases/download/" + tag + "/52okp-oss.tar.gz"
    digest = asset.get("digest", "")
    if asset.get("browser_download_url") != url or not isinstance(digest, str) or not re.fullmatch(r"sha256:[a-f0-9]{64}", digest):
        raise RuntimeError("发布包来源或 SHA-256 校验信息无效")
    size = asset.get("size", 0)
    if not isinstance(size, int) or not 0 < size <= MAX_PACKAGE:
        raise RuntimeError("发布包大小无效")
    return {"tag": tag, "name": str(data.get("name") or tag)[:200], "published": str(data.get("published_at", ""))[:40], "url": url, "sha256": digest[7:], "size": size}


class HttpsRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        if not newurl.startswith("https://"):
            raise RuntimeError("拒绝非 HTTPS 下载跳转")
        return super().redirect_request(request, fp, code, msg, headers, newurl)


def download_package(release, target):
    opener = urllib.request.build_opener(HttpsRedirect())
    request = urllib.request.Request(release["url"], headers={"User-Agent": "52okp-oss-updater/1"})
    digest = hashlib.sha256()
    length = 0
    deadline = time.monotonic() + 90
    with opener.open(request, timeout=20) as response, open(target, "xb") as stream:
        while True:
            block = response.read(65536)
            if not block:
                break
            length += len(block)
            if length > MAX_PACKAGE or time.monotonic() > deadline:
                raise RuntimeError("下载包过大或下载超时")
            stream.write(block)
            digest.update(block)
    if length != release["size"] or digest.hexdigest() != release["sha256"]:
        raise RuntimeError("发布包 SHA-256 或大小校验失败，未切换程序")


def extract_package(archive, destination):
    destination = Path(destination)
    seen = set()
    total = 0
    with tarfile.open(archive, "r:gz") as package:
        for index, member in enumerate(package):
            if index >= 1000:
                raise RuntimeError("发布包文件数量过多")
            name = member.name.rstrip("/")
            parts = name.split("/")
            if not name or "\\" in name or any(part in {"", ".", ".."} for part in parts) or PurePosixPath(name).is_absolute():
                raise RuntimeError("拒绝发布包路径穿越")
            if parts[0] not in DIRECTORIES and not (len(parts) == 1 and name in ROOT_FILES):
                raise RuntimeError("发布包包含非程序目录")
            if name in seen or not (member.isdir() or member.isreg()) or member.issparse():
                raise RuntimeError("拒绝重复文件、链接或特殊文件")
            seen.add(name)
            target = destination.joinpath(*parts)
            if member.isdir():
                target.mkdir(mode=0o750, parents=True, exist_ok=True)
                continue
            total += member.size
            if member.size < 0 or member.size > 16 * 1024 * 1024 or total > MAX_EXPANDED:
                raise RuntimeError("发布包解压大小超限")
            target.parent.mkdir(mode=0o750, parents=True, exist_ok=True)
            descriptor = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o640)
            with os.fdopen(descriptor, "wb") as output, package.extractfile(member) as source:
                shutil.copyfileobj(source, output)


def validate_manifest(destination, tag):
    destination = Path(destination)
    manifest = read_json(destination / "release.json")
    if manifest.get("protocol") != 1 or manifest.get("repository") != REPOSITORY or manifest.get("tag") != tag or not re.fullmatch(r"[a-f0-9]{40}", str(manifest.get("commit", ""))):
        raise RuntimeError("发布清单无效")
    if (destination / ".release").read_text(encoding="utf-8").strip() != tag:
        raise RuntimeError("发布标识不一致")
    for required in ["public/index.php", "src/bootstrap.php", "src/updater.php", "tests/check.php"]:
        if not (destination / required).is_file():
            raise RuntimeError("发布包缺少必要程序文件")
    return manifest


def validate_current(root):
    root = Path(root)
    current = root / "current"
    if not current.is_symlink():
        raise RuntimeError("current 必须是指向受管发布目录的符号链接")
    target = os.readlink(current)
    if not RELEASE_DIR.fullmatch(target) or (root / target).is_symlink() or (root / target).resolve().parent != (root / "releases").resolve() or not (root / target).is_dir():
        raise RuntimeError("current 指向非受管目录，停止更新")
    return target


def switch_current(root, target):
    root = Path(root)
    if not RELEASE_DIR.fullmatch(target) or not (root / target).is_dir() or (root / target).is_symlink():
        raise RuntimeError("切换目标无效")
    temporary = root / (".current-" + os.urandom(12).hex())
    try:
        os.symlink(target, temporary)
        os.replace(temporary, root / "current")
    finally:
        if temporary.is_symlink():
            temporary.unlink()


def check_health(url, expected):
    for attempt in range(5):
        try:
            request = urllib.request.Request(url + "?release=" + expected, headers={"Cache-Control": "no-cache", "User-Agent": "52okp-oss-updater/1"})
            with urllib.request.urlopen(request, timeout=8) as response:
                data = json.loads(response.read(65536))
            if data.get("ok") is True and data.get("release") == expected:
                return True
        except (OSError, ValueError):
            pass
        if attempt < 4:
            time.sleep(2)
    return False


def run_php_tests(php, destination):
    for source in sorted(Path(destination).rglob("*.php")):
        result = subprocess.run([php, "-l", str(source)], stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=15)
        if result.returncode:
            raise RuntimeError("PHP 语法检查失败：" + str(source.relative_to(destination)))
    result = subprocess.run([php, str(Path(destination) / "tests/check.php")], stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=30)
    if result.returncode:
        raise RuntimeError("新版本基础测试失败，未切换程序")


class Worker:
    def __init__(self, config):
        self.root = Path(config["root"])
        if not re.fullmatch(r"/www/wwwroot/[a-zA-Z0-9_-]+", str(self.root)) or self.root.resolve() != self.root:
            raise RuntimeError("项目路径无效")
        for directory in [self.root / "releases", self.root / "shared", self.root / "shared/updater", self.root / "shared/update-cache"]:
            if not directory.is_dir() or directory.is_symlink():
                raise RuntimeError("更新目录不存在或是符号链接")
        self.queue = self.root / "shared/updater"
        self.cache = self.root / "shared/update-cache"
        self.php = config["php"]
        self.health = config["health"]
        if not re.fullmatch(r"/[a-zA-Z0-9/_.-]+", self.php) or not re.fullmatch(r"https://[a-zA-Z0-9.-]+/health", self.health):
            raise RuntimeError("更新服务配置无效")

    def status(self, job, phase, message, latest=None):
        with locked(self.queue / "queue.lock"):
            old = read_json(self.queue / "status.json")
            atomic_json(self.queue / "status.json", {"job": job, "phase": phase, "message": message[:1000], "updated": int(time.time()), "latest": latest if latest is not None else old.get("latest")})

    def heartbeat(self):
        atomic_json(self.queue / "heartbeat.json", {"at": int(time.time())})

    def claim(self):
        with locked(self.queue / "queue.lock"):
            request = self.queue / "request.json"
            if not request.exists():
                return None
            data = read_json(request)
            os.replace(request, self.queue / "running.json")
            return data

    def recover(self):
        with locked(self.root / "deploy.lock"):
            journal = read_json(self.cache / "journal.json")
            recovered = False
            if journal:
                validate_current(self.root)
                switch_current(self.root, journal["previous"])
                (self.cache / "journal.json").unlink()
                marker = self.root / journal["previous"] / ".release"
                old_tag = marker.read_text().strip() if marker.is_file() else "manual"
                healthy = check_health(self.health, old_tag)
                self.status(journal.get("job", ""), "failed", "更新服务中断，已切回更新前版本" + ("且健康" if healthy else "，但健康检查未通过，请检查网站"))
                recovered = True
            if (self.queue / "running.json").exists():
                request = read_json(self.queue / "running.json")
                if not recovered:
                    self.status(request.get("id", ""), "failed", "上次任务被中断；请检查当前网站后重新检查更新")
                (self.queue / "running.json").unlink()

    def install(self, job, tag):
        with locked(self.root / "deploy.lock"):
            previous = validate_current(self.root)
            release = fetch_release(tag)
            latest = fetch_release()
            if release["tag"] != latest["tag"]:
                raise RuntimeError("最新版本已变化，请重新检查更新")
            required_space = MAX_EXPANDED + 2 * release["size"] + 20 * 1024 * 1024
            if shutil.disk_usage(self.root).free < required_space:
                raise RuntimeError("磁盘剩余空间不足，停止更新以保护现有数据")
            self.status(job, "downloading", "正在下载并校验 GitHub 发布包", release)
            package = self.cache / (job + ".tar.gz")
            destination = self.root / "releases" / (tag.removeprefix("build-") + "-" + job[:12])
            try:
                download_package(release, package)
                destination.mkdir(mode=0o750)
                self.status(job, "validating", "正在安全解压并检查新版本")
                extract_package(package, destination)
                validate_manifest(destination, tag)
                run_php_tests(self.php, destination)
                new_target = "releases/" + destination.name
                # Write rollback journal BEFORE changing the symlink; recover after SIGKILL/reboot.
                atomic_json(self.cache / "journal.json", {"job": job, "previous": previous, "next": new_target}, 0o640)
                self.status(job, "switching", "正在切换版本并验证网站健康状态")
                switch_current(self.root, new_target)
                if not check_health(self.health, tag):
                    raise RuntimeError("新版本健康检查失败")
                (self.cache / "journal.json").unlink()
                (destination / ".healthy").touch(mode=0o640)
                self.status(job, "success", "程序更新成功：" + tag, release)
                try:
                    self.prune(previous)
                except Exception as error:
                    print("更新成功，但旧发布清理失败：", error, flush=True)
            except BaseException as error:
                if (self.cache / "journal.json").exists():
                    switch_current(self.root, previous)
                    (self.cache / "journal.json").unlink()
                    old_marker = self.root / previous / ".release"
                    old_tag = old_marker.read_text().strip() if old_marker.is_file() else "manual"
                    restored = check_health(self.health, old_tag)
                    if isinstance(error, (KeyboardInterrupt, SystemExit)):
                        self.status(job, "failed", "服务停止，已切回原版本" + ("且健康" if restored else "，请检查网站健康状态"))
                        raise
                    raise RuntimeError(str(error) + ("；已回滚且原版本健康" if restored else "；已切回原版本，但健康检查未通过，请检查网站")) from error
                raise
            finally:
                if package.is_file():
                    package.unlink()

    def prune(self, previous):
        current = validate_current(self.root)
        candidates = []
        for directory in (self.root / "releases").iterdir():
            relative = "releases/" + directory.name
            if not RELEASE_DIR.fullmatch(relative) or directory.is_symlink() or not directory.is_dir() or directory.stat().st_uid != os.getuid() or not (directory / ".healthy").is_file():
                continue
            candidates.append(directory)
        candidates.sort(key=lambda item: item.stat().st_mtime, reverse=True)
        for directory in candidates[5:]:
            if "releases/" + directory.name in {current, previous} or directory.resolve().parent != (self.root / "releases").resolve():
                continue
            try:
                shutil.rmtree(directory)
            except OSError as error:
                print("旧发布清理失败：", error, flush=True)

    def process(self, request):
        job = request.get("id", "")
        try:
            if not isinstance(job, str) or not re.fullmatch(r"[a-f0-9]{32}", job) or request.get("type") not in {"check", "install"}:
                raise RuntimeError("更新请求无效")
            if request["type"] == "check":
                self.status(job, "checking", "正在检查 GitHub 已测试的发布版本")
                release = fetch_release()
                self.status(job, "checked", "已获取最新可用版本：" + release["tag"], release)
            else:
                tag = request.get("tag", "")
                if not isinstance(tag, str) or not TAG.fullmatch(tag):
                    raise RuntimeError("更新版本标识无效")
                self.install(job, tag)
        except Exception as error:
            print("更新失败：", error, flush=True)
            self.status(str(job)[:32], "failed", str(error))
        finally:
            with locked(self.queue / "queue.lock"):
                running = self.queue / "running.json"
                if running.exists():
                    running.unlink()

    def run(self):
        with locked(self.cache / "worker.lock"):
            self.recover()
            while True:
                self.heartbeat()
                request = self.claim()
                if request is not None:
                    self.process(request)
                time.sleep(3)


def main():
    if fcntl is None or os.getuid() == 0:
        raise RuntimeError("更新服务必须以 Linux 非 root 用户运行")
    if len(sys.argv) != 2:
        raise RuntimeError("需要提供 root 管理的 JSON 配置文件")
    config = read_json(sys.argv[1])
    # On shutdown, process finally/rollback runs; SIGKILL recovery uses journal.
    def shutdown(signum, frame):
        raise KeyboardInterrupt("更新服务正在停止")
    signal.signal(signal.SIGTERM, shutdown)
    Worker(config).run()


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        pass
