#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd -P)"
MODE="${1:-appstore}"
if [[ "$MODE" != "appstore" && "$MODE" != "--source" ]]; then
    echo "Usage: $0 [--source]" >&2
    exit 2
fi
cd "$ROOT"

if [[ "$MODE" == "appstore" ]]; then
    make build
fi

python3 - "$ROOT" "$MODE" <<'PY'
import hashlib
import json
from pathlib import Path
import re
import shutil
import sys
import tarfile
import tempfile
import xml.etree.ElementTree as ET

root = Path(sys.argv[1])
source_mode = sys.argv[2] == '--source'
info = ET.parse(root / 'appinfo/info.xml').getroot()
version = info.findtext('version')
if info.findtext('id') != 'riotchat' or not re.fullmatch(r'\d+\.\d+\.\d+(?:[-+][a-zA-Z0-9.-]+)?', version or ''):
    raise SystemExit('Expected riotchat app metadata with a valid version')
package = json.loads((root / 'package.json').read_text())
if package['version'] != version:
    raise SystemExit('package.json and appinfo/info.xml versions do not match')

artifacts = root / 'build/artifacts'
artifacts.mkdir(parents=True, exist_ok=True)
kind = 'source' if source_mode else 'appstore'
output = artifacts / kind
output.mkdir(exist_ok=True)
archive_name = 'riotchat-' + version + ('-source' if source_mode else '') + '.tar.gz'
with tempfile.TemporaryDirectory(prefix='.release-', dir=artifacts) as temporary:
    temporary = Path(temporary)
    staged = temporary / 'riotchat'
    staged.mkdir()
    if source_mode:
        # Ship the adapter's preferred source and the manifest linking upstream source.
        excluded = {'.git', 'build', 'node_modules', 'vendor', 'js', '3rdparty'}
        for entry in root.iterdir():
            if entry.name in excluded:
                continue
            if entry.is_dir():
                shutil.copytree(entry, staged / entry.name, ignore=shutil.ignore_patterns('__pycache__', '*.pyc'))
            elif entry.is_file():
                shutil.copy2(entry, staged / entry.name)
    else:
        manifest = json.loads((root / 'element-release.json').read_text())
        required = ['js/main.js', 'js/adminSettings.js', 'js/logout.js',
                    '3rdparty/riot/index.html', '3rdparty/riot/version']
        required.extend('3rdparty/riot/' + entry['name'] for entry in manifest['licenses'])
        for name in required:
            path = root / name
            if not path.is_file() or not path.stat().st_size:
                raise SystemExit('Cannot package: missing or empty ' + name)
        if json.loads((root / '3rdparty/riot/element-release.json').read_text()) != manifest:
            raise SystemExit('Cannot package: installed Element does not match the release manifest')
        for name in ['appinfo', 'css', 'img', 'js', 'l10n', 'lib', 'templates', '3rdparty/riot']:
            shutil.copytree(root / name, staged / name)
        for name in ['LICENSE', 'README.md', 'CHANGELOG.md', 'element-release.json']:
            shutil.copy2(root / name, staged / name)

    pending = temporary / archive_name
    with tarfile.open(pending, 'w:gz') as archive:
        archive.add(staged, arcname='riotchat')
    if not source_mode:
        installed = artifacts / 'riotchat'
        previous = temporary / 'previous'
        if installed.exists():
            installed.rename(previous)
        try:
            staged.rename(installed)
        except OSError:
            if previous.exists():
                previous.rename(installed)
            raise
    digest = hashlib.sha256()
    with pending.open('rb') as archive_file:
        for block in iter(lambda: archive_file.read(1024 * 1024), b''):
            digest.update(block)
    checksum = temporary / (archive_name + '.sha256')
    checksum.write_text(digest.hexdigest() + '  ' + archive_name + '\n')
    pending.replace(output / archive_name)
    checksum.replace(output / checksum.name)
print(output / archive_name)
PY
