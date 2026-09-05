#!/usr/bin/env python3
from __future__ import annotations

import hashlib
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

ROOT = Path(__file__).resolve().parents[1]
DIST = ROOT / "dist"
ARCHIVE = DIST / "skypay-woocommerce.zip"
PREFIX = "skypay-woocommerce"
EXCLUDED_PARTS = {
    ".git",
    ".github",
    ".phpunit.cache",
    ".wp-env",
    "assets/src",
    "bin",
    "dist",
    "node_modules",
    "tests",
    "vendor",
}
EXCLUDED_FILES = {
    ".distignore",
    ".gitignore",
    ".wp-env.json",
    ".wp-env.override.json",
    "composer.json",
    "composer.lock",
    "package.json",
    "package-lock.json",
    "phpcs.xml.dist",
    "phpstan.neon.dist",
    "phpunit.xml.dist",
    "webpack.config.js",
}


def included(path: Path) -> bool:
    relative = path.relative_to(ROOT)
    joined = "/".join(relative.parts)
    return (
        path.is_file()
        and relative.name not in EXCLUDED_FILES
        and not any(joined == part or joined.startswith(f"{part}/") for part in EXCLUDED_PARTS)
    )


DIST.mkdir(exist_ok=True)
with ZipFile(ARCHIVE, "w", ZIP_DEFLATED, compresslevel=9) as archive:
    for path in sorted(ROOT.rglob("*")):
        if included(path):
            archive.write(path, f"{PREFIX}/{path.relative_to(ROOT).as_posix()}")

digest = hashlib.sha256(ARCHIVE.read_bytes()).hexdigest()
(DIST / "skypay-woocommerce.zip.sha256").write_text(
    f"{digest}  skypay-woocommerce.zip\n",
    encoding="ascii",
)
print(f"Built {ARCHIVE}")
print(f"SHA-256 {digest}")
