import hashlib
import importlib.util
import io
import json
import os
import sys
import tarfile
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parent.parent


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


worker = load("oss_worker", ROOT / "deploy/update-worker.py")
builder = load("oss_builder", ROOT / "deploy/build-release.py")
TAG = "build-12345-1"
SHA = "a" * 40


def release_data(**overrides):
    data = {"tag_name": TAG, "draft": False, "prerelease": False, "assets": [{"name": "52okp-oss.tar.gz", "size": 100, "digest": "sha256:" + "b" * 64, "browser_download_url": "https://github.com/52okp/52okp-oss/releases/download/" + TAG + "/52okp-oss.tar.gz"}]}
    data.update(overrides)
    return data


class PackageTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="oss-updater-test-")
        self.root = Path(self.temp.name)

    def tearDown(self):
        self.temp.cleanup()

    def archive(self, name, kind=tarfile.REGTYPE, content=b"test", link=""):
        path = self.root / "bad.tar.gz"
        with tarfile.open(path, "w:gz") as stream:
            info = tarfile.TarInfo(name)
            info.type = kind
            info.linkname = link
            info.size = len(content) if kind == tarfile.REGTYPE else 0
            stream.addfile(info, io.BytesIO(content) if info.size else None)
        destination = self.root / "unpacked"
        destination.mkdir()
        return path, destination

    def test_valid_release(self):
        self.assertEqual(worker.validate_release(release_data())["tag"], TAG)

    def test_invalid_tag(self):
        for tag in ["../etc", "main", "build-1-1\n", "build-1-1;sh"]:
            with self.subTest(tag=tag), self.assertRaises(RuntimeError):
                worker.validate_release(release_data(tag_name=tag))

    def test_reject_unpublished_release(self):
        for options in [{"draft": True}, {"prerelease": True}]:
            with self.subTest(options=options), self.assertRaises(RuntimeError):
                worker.validate_release(release_data(**options))

    def test_reject_changed_asset_source(self):
        data = release_data()
        data["assets"][0]["browser_download_url"] = "https://evil.example/package"
        with self.assertRaises(RuntimeError):
            worker.validate_release(data)

    def test_requires_github_sha256(self):
        data = release_data()
        data["assets"][0].pop("digest")
        with self.assertRaises(RuntimeError):
            worker.validate_release(data)

    def test_reject_oversize_package(self):
        data = release_data()
        data["assets"][0]["size"] = worker.MAX_PACKAGE + 1
        with self.assertRaises(RuntimeError):
            worker.validate_release(data)

    def test_reject_paths(self):
        for name in ["../escape", "/etc/passwd", "public/../../escape", "public\\escape", "shared/config/config.php", "public//x"]:
            with self.subTest(name=name), tempfile.TemporaryDirectory(dir=self.root) as scratch:
                with tarfile.open(Path(scratch) / "bad.tar.gz", "w:gz") as stream:
                    info = tarfile.TarInfo(name); info.size = 1
                    stream.addfile(info, io.BytesIO(b"x"))
                destination = Path(scratch) / "dest"; destination.mkdir()
                with self.assertRaises(RuntimeError):
                    worker.extract_package(Path(scratch) / "bad.tar.gz", destination)

    def test_reject_symlink(self):
        archive, destination = self.archive("public/link", tarfile.SYMTYPE, link="/etc")
        with self.assertRaises(RuntimeError):
            worker.extract_package(archive, destination)

    def test_reject_hardlink(self):
        archive, destination = self.archive("public/link", tarfile.LNKTYPE, link="src/bootstrap.php")
        with self.assertRaises(RuntimeError):
            worker.extract_package(archive, destination)

    def test_reject_device(self):
        archive, destination = self.archive("public/device", tarfile.CHRTYPE)
        with self.assertRaises(RuntimeError):
            worker.extract_package(archive, destination)

    def test_duplicate_files(self):
        archive = self.root / "duplicate.tar.gz"
        with tarfile.open(archive, "w:gz") as stream:
            for _ in range(2):
                info = tarfile.TarInfo("public/test"); info.size = 1
                stream.addfile(info, io.BytesIO(b"x"))
        destination = self.root / "dest"; destination.mkdir()
        with self.assertRaises(RuntimeError):
            worker.extract_package(archive, destination)

    def test_build_and_validate(self):
        archive = self.root / "package.tar.gz"
        builder.build(ROOT, archive, TAG, SHA)
        destination = self.root / "dest"; destination.mkdir()
        worker.extract_package(archive, destination)
        self.assertEqual(worker.validate_manifest(destination, TAG)["commit"], SHA)
        self.assertFalse((destination / "shared").exists())
        self.assertFalse((destination / ".git").exists())
        self.assertFalse((destination / ".github").exists())

    def test_manifest_mismatch(self):
        worker.atomic_json(self.root / "release.json", {"protocol": 1, "repository": "evil/repo", "tag": TAG, "commit": SHA})
        with self.assertRaises(RuntimeError):
            worker.validate_manifest(self.root, TAG)

    def test_atomic_json(self):
        worker.atomic_json(self.root / "state.json", {"name": "中文"})
        self.assertEqual(worker.read_json(self.root / "state.json"), {"name": "中文"})
        self.assertEqual(list(self.root.glob(".update-*")), [])

    def test_https_redirect_only(self):
        with self.assertRaises(RuntimeError):
            worker.HttpsRedirect().redirect_request(None, None, 302, "", {}, "http://example.com")

    def test_digest_mismatch_stops_download(self):
        response = io.BytesIO(b"test")
        with mock.patch.object(worker.urllib.request, "build_opener") as opener:
            opener.return_value.open.return_value = response
            with self.assertRaises(RuntimeError):
                worker.download_package({"url": "https://github.com/test", "size": 4, "sha256": "0" * 64}, self.root / "download")

    def test_download_valid_digest(self):
        with mock.patch.object(worker.urllib.request, "build_opener") as opener:
            opener.return_value.open.return_value = io.BytesIO(b"test")
            worker.download_package({"url": "https://github.com/test", "size": 4, "sha256": hashlib.sha256(b"test").hexdigest()}, self.root / "download")
        self.assertEqual((self.root / "download").read_bytes(), b"test")


@unittest.skipUnless(worker.fcntl is not None and hasattr(os, "symlink"), "需要 Linux 文件锁及符号链接")
class SwitchTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="oss-switch-test-")
        self.root = Path(self.temp.name)
        for path in ["releases/100-1", "shared/updater", "shared/update-cache", "shared/uploads", "shared/config"]:
            (self.root / path).mkdir(parents=True, exist_ok=True)
        (self.root / "shared/uploads/persistent").write_bytes(b"upload")
        (self.root / "shared/config/admin.php").write_bytes(b"account")
        os.symlink("releases/100-1", self.root / "current")
        self.service = object.__new__(worker.Worker)
        self.service.root = self.root
        self.service.queue = self.root / "shared/updater"
        self.service.cache = self.root / "shared/update-cache"
        self.service.php = "php"
        self.service.health = "https://example.com/health"
        self.job = "d" * 32
        self.archive = self.root / "valid.tar.gz"
        builder.build(ROOT, self.archive, TAG, SHA)

    def tearDown(self):
        self.temp.cleanup()

    def install_with_health(self, health):
        release = worker.validate_release(release_data())
        def download(info, path):
            Path(path).write_bytes(self.archive.read_bytes())
        with mock.patch.object(worker, "fetch_release", return_value=release), mock.patch.object(worker, "download_package", side_effect=download), mock.patch.object(worker, "run_php_tests"), mock.patch.object(worker, "check_health", side_effect=health):
            self.service.install(self.job, TAG)

    def assert_persistent(self):
        self.assertEqual((self.root / "shared/uploads/persistent").read_bytes(), b"upload")
        self.assertEqual((self.root / "shared/config/admin.php").read_bytes(), b"account")

    def test_successful_switch(self):
        self.install_with_health([True])
        self.assertEqual(os.readlink(self.root / "current"), "releases/12345-1-" + self.job[:12])
        self.assertEqual(worker.read_json(self.service.queue / "status.json")["phase"], "success")
        self.assertFalse((self.service.cache / "journal.json").exists())
        self.assert_persistent()

    def test_failed_health_rolls_back(self):
        with self.assertRaisesRegex(RuntimeError, "已回滚且原版本健康"):
            self.install_with_health([False, True])
        self.assertEqual(os.readlink(self.root / "current"), "releases/100-1")
        self.assert_persistent()

    def test_invalid_php_never_switches(self):
        release = worker.validate_release(release_data())
        with mock.patch.object(worker, "fetch_release", return_value=release), mock.patch.object(worker, "download_package", side_effect=lambda info, path: Path(path).write_bytes(self.archive.read_bytes())), mock.patch.object(worker, "run_php_tests", side_effect=RuntimeError("PHP检查失败")):
            with self.assertRaises(RuntimeError):
                self.service.install(self.job, TAG)
        self.assertEqual(os.readlink(self.root / "current"), "releases/100-1")
        self.assert_persistent()

    def test_restart_recovers_journal(self):
        (self.root / "releases/200-1").mkdir()
        worker.atomic_json(self.service.cache / "journal.json", {"job": self.job, "previous": "releases/100-1", "next": "releases/200-1"})
        worker.switch_current(self.root, "releases/200-1")
        with mock.patch.object(worker, "check_health", return_value=True):
            self.service.recover()
        self.assertEqual(os.readlink(self.root / "current"), "releases/100-1")
        self.assert_persistent()

    def test_reject_arbitrary_switch(self):
        with self.assertRaises(RuntimeError):
            worker.switch_current(self.root, "../shared")

    def test_real_php_validation(self):
        destination = self.root / "releases/300-1"; destination.mkdir()
        worker.extract_package(self.archive, destination)
        worker.run_php_tests(self.service.php, destination)


if __name__ == "__main__":
    unittest.main()
