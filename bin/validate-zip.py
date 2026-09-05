#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path
from zipfile import ZipFile

archive_path = Path(sys.argv[1] if len(sys.argv) > 1 else "dist/skypay-woocommerce.zip")
required = {
    "skypay-woocommerce/skypay-woocommerce.php",
    "skypay-woocommerce/readme.txt",
    "skypay-woocommerce/LICENSE",
    "skypay-woocommerce/privacy.md",
    "skypay-woocommerce/uninstall.php",
    "skypay-woocommerce/assets/build/blocks.js",
    "skypay-woocommerce/assets/build/blocks.asset.php",
    "skypay-woocommerce/includes/class-skypay-gateway.php",
    "skypay-woocommerce/languages/skypay-woocommerce-ar.mo",
}
forbidden_fragments = (
    ".env",
    ".git/",
    ".phpunit.cache/",
    ".wp-env",
    "id_ed25519",
    "id_rsa",
    "node_modules/",
    "vendor/",
)
secret_markers = (b"sk_live_", b"-----BEGIN PRIVATE KEY-----")

with ZipFile(archive_path) as archive:
    names = set(archive.namelist())
    missing = required - names
    if missing:
        raise SystemExit(f"Missing required files: {sorted(missing)}")
    bad_names = [name for name in names if any(fragment in name for fragment in forbidden_fragments)]
    if bad_names:
        raise SystemExit(f"Forbidden packaged paths: {bad_names}")
    for info in archive.infolist():
        if info.file_size > 2_000_000:
            raise SystemExit(f"Unexpectedly large file: {info.filename}")
        content = archive.read(info)
        if any(marker in content for marker in secret_markers):
            raise SystemExit(f"Possible credential material in {info.filename}")

print(f"Validated {archive_path} ({len(names)} files)")
