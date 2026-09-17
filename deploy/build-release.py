#!/usr/bin/env python3
"""Build a code-only package. Never includes .git, shared, secrets or server config."""
from __future__ import annotations
import argparse
import io
import json
import re
import tarfile
from pathlib import Path


def build(root, output, tag, commit):
    if not re.fullmatch(r"build-[0-9]+-[0-9]+", tag) or not re.fullmatch(r"[a-f0-9]{40}", commit):
        raise ValueError("Invalid release identity")
    root = Path(root)
    version = (root / 'deploy/version.txt').read_text(encoding='utf-8').strip()
    if not re.fullmatch(r'v[0-9]+\.[0-9]+\.[0-9]+', version):
        raise ValueError('Invalid program version')
    paths = []
    for folder in ["public", "src", "bin", "deploy", "tests"]:
        for path in (root / folder).rglob("*"):
            if path.is_symlink():
                raise ValueError("Symlinks are not allowed in packages")
            if path.is_file() and "__pycache__" not in path.parts and path.suffix not in {".pyc", ".log"}:
                paths.append(path)
    paths += [root / name for name in ["README.md", "config.example.php", ".gitattributes", "网页更新安装说明.md"]]
    with tarfile.open(output, "w:gz") as package:
        for path in sorted(paths):
            package.add(path, arcname=path.relative_to(root).as_posix(), recursive=False)
        generated = {".release": tag + "\n", "release.json": json.dumps({"protocol": 1, "repository": "52okp/52okp-oss", "tag": tag, "version": version, "commit": commit})}
        for name, text in generated.items():
            data = text.encode("utf-8")
            info = tarfile.TarInfo(name)
            info.size = len(data)
            info.mode = 0o640
            package.addfile(info, io.BytesIO(data))


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--tag", required=True)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    build(Path(__file__).resolve().parent.parent, args.output, args.tag, args.commit)
