#!/usr/bin/env python3
"""Build the canonical plugin ZIP from a validated checkout; no network or mutation."""
import argparse
from pathlib import Path
import re
import zipfile

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
main = (root / 'core-blueprint-backups.php').read_text()
header = re.search(r'Version:\s+(\S+)', main).group(1)
constant = re.search(r"define\( 'CB_BACKUPS_VERSION', '([^']+)' \)", main).group(1)
if header != constant:
    raise SystemExit('Plugin version declarations differ.')
files = [root / p for p in ['core-blueprint-backups.php', 'uninstall.php', 'README.md']]
for directory in ['src', 'assets', 'languages', 'docs']:
    files.extend(p for p in (root / directory).rglob('*') if p.is_file())
required = ['src/DB/SqlValueCodec.php', 'src/DB/ContentDigest.php', 'src/Restore/DatabaseContentVerifier.php']
if any(root / p not in files for p in required):
    raise SystemExit('Required runtime files are missing.')
args.output.mkdir(parents=True, exist_ok=True)
target = args.output / f'core-blueprint-backups-{header}.zip'
with zipfile.ZipFile(target, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for path in sorted(set(files)):
        if path.is_symlink():
            raise SystemExit(f'Symlink is not a release payload: {path}')
        name = 'core-blueprint-backups/' + path.relative_to(root).as_posix()
        entry = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
        entry.external_attr = 0o100644 << 16
        entry.compress_type = zipfile.ZIP_DEFLATED
        archive.writestr(entry, path.read_bytes(), compresslevel=9)
with zipfile.ZipFile(target) as archive:
    if archive.testzip() is not None:
        raise SystemExit('Release ZIP CRC verification failed.')
print(target.resolve())
