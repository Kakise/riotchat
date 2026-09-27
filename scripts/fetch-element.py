#!/usr/bin/env python3
"""Install the official Element release only after verifying its pinned digest."""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile


def verify(path, digest):
    if not re.fullmatch(r'[a-f0-9]{64}', digest):
        raise ValueError('Expected a SHA-256 digest in element-release.json')
    checksum = hashlib.sha256()
    with path.open('rb') as source:
        for block in iter(lambda: source.read(1024 * 1024), b''):
            checksum.update(block)
    if checksum.hexdigest() != digest:
        raise ValueError('SHA-256 mismatch for ' + str(path))


def download(asset, path):
    if path.exists():
        verify(path, asset['sha256'])
        return path
    if not asset['url'].startswith('https://'):
        raise ValueError('Element downloads must use HTTPS')
    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='.download-', dir=path.parent) as temporary:
        pending = Path(temporary) / 'download'
        subprocess.run([
            'curl', '--fail', '--location', '--show-error', '--silent',
            '--retry', '3', '--proto', '=https', '--proto-redir', '=https',
            '--tlsv1.2', '--output', str(pending), asset['url'],
        ], check=True)
        verify(pending, asset['sha256'])
        pending.replace(path)
    return path


def install(root, archive_override=None):
    manifest = json.loads((root / 'element-release.json').read_text())
    if not re.fullmatch(r'\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?', manifest['version']):
        raise ValueError('Invalid Element version')
    if manifest['tag'] != 'v' + manifest['version']:
        raise ValueError('Element tag and version do not match')
    if not manifest.get('licenses'):
        raise ValueError('Element license files must be pinned')
    cache = root / 'build/downloads'
    if archive_override:
        archive = archive_override
        verify(archive, manifest['sha256'])
    else:
        archive = download(manifest, cache / ('element-' + manifest['tag'] + '.tar.gz'))
    licenses = []
    for license_asset in manifest['licenses']:
        name = license_asset['name']
        if not re.fullmatch(r'LICENSE-[A-Z0-9.-]+', name):
            raise ValueError('Invalid Element license filename')
        licenses.append(download(license_asset, cache / name))

    destination = root / '3rdparty/riot'
    destination.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='.element-', dir=destination.parent) as temporary:
        staged = Path(temporary) / 'riot'
        staged.mkdir()
        prefix = 'element-' + manifest['tag']
        with tarfile.open(archive, 'r:gz') as bundle:
            # Validate the whole archive before writing anything; never follow links.
            members = bundle.getmembers()
            for member in members:
                parts = PurePosixPath(member.name).parts
                if (not parts or parts[0] != prefix or '..' in parts
                        or PurePosixPath(member.name).is_absolute()
                        or not (member.isdir() or member.isfile())):
                    raise ValueError('Unsafe archive member: ' + member.name)
            for member in members:
                relative = PurePosixPath(member.name).parts[1:]
                if not relative:
                    continue
                target = staged.joinpath(*relative)
                if member.isdir():
                    target.mkdir(parents=True, exist_ok=True)
                else:
                    target.parent.mkdir(parents=True, exist_ok=True)
                    with bundle.extractfile(member) as source, target.open('wb') as output:
                        shutil.copyfileobj(source, output)
                    target.chmod(0o644)
        for name in ['index.html', 'version', 'config.sample.json']:
            if not (staged / name).is_file() or not (staged / name).stat().st_size:
                raise ValueError('Element release is missing ' + name)
        if (staged / 'version').read_text().strip().removeprefix('v') != manifest['version']:
            raise ValueError('Element archive version does not match the manifest')
        for license_file in licenses:
            shutil.copyfile(license_file, staged / license_file.name)
        shutil.copyfile(root / 'element-release.json', staged / 'element-release.json')

        # Only replace the previous installation after every download and check passes.
        previous = Path(temporary) / 'previous'
        if destination.exists():
            destination.rename(previous)
        try:
            staged.rename(destination)
        except OSError:
            if previous.exists():
                previous.rename(destination)
            raise
    print('Installed verified Element ' + manifest['tag'] + ' in ' + str(destination))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--archive', type=Path, help='Use a local archive, still requiring the pinned checksum')
    arguments = parser.parse_args()
    try:
        install(arguments.root.resolve(), arguments.archive)
    except (OSError, ValueError, KeyError, tarfile.TarError, subprocess.CalledProcessError) as error:
        print('Element fetch failed: ' + str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
