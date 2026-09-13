#!/usr/bin/env python3
"""Build and validate the canonical Core Blueprint Backups release package."""
from __future__ import annotations

import argparse
import hashlib
import re
import shutil
import subprocess
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ROOT_NAME = "core-blueprint-backups"
MAIN_FILE = "core-blueprint-backups.php"
EXPECTED_VERSION = "1.0.0-rc1"
EXPECTED_REQUIRES_PHP = "8.4"
EXPECTED_API = "1.0"
REQUIRED_PHP_MINORS = {(8, 4), (8, 5)}
EXPECTED_LOCALES = ("nl_NL", "de_DE", "fr_FR", "es_ES", "it_IT", "pt_PT")
RUNTIME_FILES = ("core-blueprint-backups.php", "uninstall.php")
RUNTIME_DIRS = ("src", "assets", "languages")
DEV_PATH_PARTS = {".git", ".github", "docs", "tests", "tools", "node_modules", "vendor", "__pycache__"}
JUNK_NAMES = {".DS_Store", "Thumbs.db"}


def fail(message: str) -> None:
    raise SystemExit(f"ERROR: {message}")


def run(command: list[str], *, capture: bool = False) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(command, cwd=ROOT, text=True, capture_output=capture)
    if result.returncode:
        detail = (result.stderr or result.stdout or "").strip()
        fail(f"command failed: {' '.join(command)}" + (f": {detail}" if detail else ""))
    return result


def source_contract(root: Path) -> None:
    text = (root / MAIN_FILE).read_text(encoding="utf-8")
    header = re.search(r"^\s*\*\s*Version:\s*([^\s]+)", text, re.M)
    constant = re.search(r"define\(\s*'CB_BACKUPS_VERSION'\s*,\s*'([^']+)'\s*\)", text)
    required_api = re.search(r"define\(\s*'CB_BACKUPS_REQUIRED_API'\s*,\s*'([^']+)'\s*\)", text)
    php = re.search(r"^\s*\*\s*Requires PHP:\s*([^\s]+)", text, re.M)
    if not header or not constant or not required_api or not php:
        fail("could not read Backups version/API/PHP contract")
    if header.group(1) != EXPECTED_VERSION or constant.group(1) != EXPECTED_VERSION:
        fail(f"Backups version must remain exactly {EXPECTED_VERSION}")
    if required_api.group(1) != EXPECTED_API:
        fail(f"Backups must require Base API {EXPECTED_API}")
    if php.group(1) != EXPECTED_REQUIRES_PHP:
        fail(f"Requires PHP must remain {EXPECTED_REQUIRES_PHP}")


def run_i18n_check() -> None:
    check = ROOT / "tools" / "i18n" / "check"
    if not check.is_file():
        fail("missing canonical i18n check")
    run([str(check)], capture=True)


def php_runtime(binary: str) -> tuple[int, int, str]:
    result = run(
        [binary, "-r", 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION, ".", PHP_RELEASE_VERSION;'],
        capture=True,
    )
    version = result.stdout.strip()
    match = re.fullmatch(r"(\d+)\.(\d+)\.(\d+)", version)
    if not match:
        fail(f"could not parse PHP version from {binary}: {version!r}")
    major, minor, _patch = map(int, match.groups())
    return major, minor, version


def copy_runtime(source: Path, staged: Path) -> None:
    staged.mkdir(parents=True, exist_ok=True)
    for relative in RUNTIME_FILES:
        src = source / relative
        if not src.is_file():
            fail(f"missing runtime file: {relative}")
        shutil.copy2(src, staged / relative)

    for directory in RUNTIME_DIRS:
        src_dir = source / directory
        if not src_dir.is_dir():
            fail(f"missing runtime directory: {directory}")
        for path in sorted(src_dir.rglob("*")):
            if path.is_symlink():
                fail(f"symlink not allowed: {path.relative_to(source)}")
            if not path.is_file():
                continue
            if path.name in JUNK_NAMES or path.suffix == ".pyc":
                continue
            rel = path.relative_to(source)
            target = staged / rel
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(path, target)


def compile_translations(staged: Path) -> None:
    if shutil.which("msgfmt") is None:
        fail("GNU gettext msgfmt is required to build release translations")
    lang = staged / "languages"
    for existing in lang.glob(f"{ROOT_NAME}-*.mo"):
        existing.unlink()
    for locale in EXPECTED_LOCALES:
        po = lang / f"{ROOT_NAME}-{locale}.po"
        mo = lang / f"{ROOT_NAME}-{locale}.mo"
        if not po.is_file():
            fail(f"missing reviewed PO catalog for {locale}")
        run(["msgfmt", "--check-format", "--check-header", "-o", str(mo), str(po)])
        if not mo.is_file() or mo.stat().st_size == 0:
            fail(f"compiled MO is missing for {locale}")


def check_css(staged: Path) -> int:
    count = 0
    for path in staged.joinpath("assets/css").rglob("*.css"):
        text = path.read_text(encoding="utf-8", errors="ignore")
        depth = 0
        in_comment = False
        i = 0
        while i < len(text):
            if not in_comment and text.startswith("/*", i):
                in_comment = True
                i += 2
                continue
            if in_comment and text.startswith("*/", i):
                in_comment = False
                i += 2
                continue
            if not in_comment:
                if text[i] == "{":
                    depth += 1
                elif text[i] == "}":
                    depth -= 1
                    if depth < 0:
                        fail(f"CSS brace underflow: {path}")
            i += 1
        if in_comment or depth:
            fail(f"CSS structural error: {path}")
        count += 1
    return count


def validate_runtime(staged: Path, php_bins: list[str]) -> tuple[list[str], int, int, int]:
    runtimes = [php_runtime(binary) for binary in php_bins]
    minors = {(major, minor) for major, minor, _version in runtimes}
    missing = REQUIRED_PHP_MINORS - minors
    if missing:
        fail(f"release validation requires actual PHP minors: {sorted(missing)}")

    php_files = list(staged.rglob("*.php"))
    for binary in php_bins:
        for path in php_files:
            run([binary, "-l", str(path)], capture=True)

    js_files = list(staged.joinpath("assets/js").rglob("*.js"))
    if js_files and shutil.which("node") is None:
        fail("Node.js is required for JavaScript syntax validation")
    for path in js_files:
        run(["node", "--check", str(path)], capture=True)

    css_count = check_css(staged)
    return [version for _major, _minor, version in runtimes], len(php_files), len(js_files), css_count


def excluded_from_release(name: str) -> bool:
    parts = Path(name).parts
    return any(part in DEV_PATH_PARTS for part in parts) or Path(name).name in JUNK_NAMES


def build_zip(staged: Path, target: Path) -> None:
    if target.exists():
        target.unlink()
    target.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(target, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted(staged.rglob("*")):
            if not path.is_file():
                continue
            rel = path.relative_to(staged).as_posix()
            name = f"{ROOT_NAME}/{rel}"
            entry = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            entry.external_attr = 0o100644 << 16
            entry.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(entry, path.read_bytes(), compresslevel=9)


def validate_zip(target: Path) -> None:
    with zipfile.ZipFile(target) as archive:
        names = [name for name in archive.namelist() if name]
        if archive.testzip() is not None:
            fail("release ZIP CRC verification failed")
        roots = {name.split("/", 1)[0] for name in names}
        if roots != {ROOT_NAME}:
            fail(f"archive root must be exactly {ROOT_NAME}/")
        if f"{ROOT_NAME}/{MAIN_FILE}" not in names:
            fail("release ZIP is missing the plugin bootstrap")
        for name in names:
            rel = name[len(ROOT_NAME) + 1 :] if name.startswith(ROOT_NAME + "/") else name
            if excluded_from_release(rel):
                fail(f"development path entered release ZIP: {name}")
        for locale in EXPECTED_LOCALES:
            po = f"{ROOT_NAME}/languages/{ROOT_NAME}-{locale}.po"
            mo = f"{ROOT_NAME}/languages/{ROOT_NAME}-{locale}.mo"
            if po not in names or mo not in names:
                fail(f"release ZIP is missing locale artifacts for {locale}")


def write_checksum(target: Path) -> Path:
    digest = hashlib.sha256(target.read_bytes()).hexdigest()
    checksum = target.with_name(target.name + ".sha256")
    checksum.write_text(f"{digest}  {target.name}\n", encoding="utf-8")
    return checksum


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True, help="Directory for release outputs")
    parser.add_argument("--php-bin", action="append", dest="php_bins", default=[])
    args = parser.parse_args()

    source_contract(ROOT)
    run_i18n_check()
    if not args.php_bins:
        fail("pass both PHP 8.4 and PHP 8.5 CLI binaries with --php-bin")

    with tempfile.TemporaryDirectory(prefix="cb-backups-release-") as tmp:
        staged = Path(tmp) / ROOT_NAME
        copy_runtime(ROOT, staged)
        compile_translations(staged)
        runtimes, php_count, js_count, css_count = validate_runtime(staged, args.php_bins)

        target = args.output / f"{ROOT_NAME}-{EXPECTED_VERSION}.zip"
        build_zip(staged, target)
        validate_zip(target)
        checksum = write_checksum(target)

    print(
        "PASS: "
        f"version={EXPECTED_VERSION} api={EXPECTED_API} php={','.join(runtimes)} "
        f"php_files={php_count} js_files={js_count} css_files={css_count} "
        f"zip={target.resolve()} sha256={checksum.resolve()}"
    )


if __name__ == "__main__":
    main()
