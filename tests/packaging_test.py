#!/usr/bin/env python3
"""Offline integration tests for the verified Element download and app release."""
import hashlib
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile
import unittest

REPO = Path(__file__).resolve().parents[1]


class PackagingTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'checkout with spaces'
        self.root.mkdir()
        self.archive = self.root / 'element.tar.gz'
        self.files = {
            'index.html': '<html>Element</html>',
            'version': '1.12.29',
            'config.sample.json': '{}',
            'bundles/hash/bundle.js': 'console.log("Element")',
            'bundles/hash/bundle.js.LICENSE.txt': 'upstream notice',
            'widgets/element-call/index.html': '<html>Call</html>',
            'decoderWorker.min.wasm': 'wasm',
            '.well-known/security.txt': 'Contact: security@example.org',
        }
        self.write_archive()
        license_file = self.root / 'build/downloads/LICENSE-AGPL-3.0'
        license_file.parent.mkdir(parents=True)
        license_file.write_text('AGPL fixture license')
        self.manifest = {
            'version': '1.12.29',
            'tag': 'v1.12.29',
            'url': 'https://example.invalid/element-v1.12.29.tar.gz',
            'sha256': hashlib.sha256(self.archive.read_bytes()).hexdigest(),
            'source': 'https://github.com/element-hq/element-web/tree/v1.12.29',
            'source_archive': 'https://github.com/element-hq/element-web/archive/refs/tags/v1.12.29.tar.gz',
            'licenses': [{
                'name': 'LICENSE-AGPL-3.0',
                'url': 'https://example.invalid/LICENSE-AGPL-3.0',
                'sha256': hashlib.sha256(license_file.read_bytes()).hexdigest(),
            }],
        }
        self.write_manifest()

    def write_archive(self, extra=None):
        with tarfile.open(self.archive, 'w:gz') as archive:
            for name, content in dict(self.files, **(extra or {})).items():
                data = content.encode()
                entry = tarfile.TarInfo('element-v1.12.29/' + name)
                entry.size = len(data)
                archive.addfile(entry, io.BytesIO(data))

    def write_manifest(self):
        (self.root / 'element-release.json').write_text(json.dumps(self.manifest))

    def fetch(self):
        return subprocess.run([
            sys.executable, str(REPO / 'scripts/fetch-element.py'),
            '--root', str(self.root), '--archive', str(self.archive),
        ], capture_output=True, text=True)

    def test_verified_bundle_preserves_all_assets_and_replaces_previous_files(self):
        result = self.fetch()
        self.assertEqual(result.returncode, 0, result.stderr)
        target = self.root / '3rdparty/riot'
        for name, content in self.files.items():
            self.assertEqual((target / name).read_text(), content)
        self.assertEqual((target / 'LICENSE-AGPL-3.0').read_text(), 'AGPL fixture license')
        (target / 'stale.js').write_text('old asset')
        result = self.fetch()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse((target / 'stale.js').exists())

    def test_wrong_checksum_leaves_previous_bundle_untouched(self):
        target = self.root / '3rdparty/riot'
        target.mkdir(parents=True)
        (target / 'index.html').write_text('previous release')
        self.manifest['sha256'] = '0' * 64
        self.write_manifest()
        result = self.fetch()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('SHA-256 mismatch', result.stderr)
        self.assertEqual((target / 'index.html').read_text(), 'previous release')

    def test_failed_download_does_not_install_or_cache_a_partial_archive(self):
        self.manifest['url'] = 'https://127.0.0.1:1/element.tar.gz'
        self.write_manifest()
        result = subprocess.run([
            sys.executable, str(REPO / 'scripts/fetch-element.py'),
            '--root', str(self.root),
        ], capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Element fetch failed', result.stderr)
        self.assertFalse((self.root / '3rdparty/riot').exists())
        self.assertFalse((self.root / 'build/downloads/element-v1.12.29.tar.gz').exists())

    def test_archive_path_traversal_is_rejected_before_installation(self):
        self.write_archive({'../../escaped': 'unsafe'})
        self.manifest['sha256'] = hashlib.sha256(self.archive.read_bytes()).hexdigest()
        self.write_manifest()
        result = self.fetch()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Unsafe archive member', result.stderr)
        self.assertFalse((self.root / 'escaped').exists())
        self.assertFalse((self.root / '3rdparty/riot').exists())

    def prepare_release(self):
        (self.root / 'scripts').mkdir()
        for name in ['release.sh', 'fetch-element.py']:
            shutil.copy2(REPO / 'scripts' / name, self.root / 'scripts' / name)
        shutil.copy2(REPO / 'Makefile', self.root / 'Makefile')
        cache = self.root / 'build/downloads/element-v1.12.29.tar.gz'
        shutil.copy2(self.archive, cache)
        for name, content in {
            'appinfo/info.xml': '<info><id>riotchat</id><version>0.22.0</version></info>',
            'package.json': '{"name":"riotchat","version":"0.22.0"}',
            'lib/Controller/StaticController.php': '<?php',
            'templates/index.php': '<?php',
            'css/main.css': 'body {}',
            'img/app.svg': '<svg/>',
            'l10n/fr.js': 'translations',
            'LICENSE': 'adapter AGPL',
            'README.md': 'source information',
            'CHANGELOG.md': 'release notes',
            'node_modules/secret': 'excluded',
            '.git/config': 'excluded',
            'src/main.js': 'source, excluded from runtime',
        }.items():
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content)
        bin_dir = self.root / 'bin'
        bin_dir.mkdir()
        npm = bin_dir / 'npm'
        npm.write_text('#!/bin/sh\nset -eu\n[ "$1 $2" = "run build" ]\nmkdir -p js\nfor entry in main adminSettings logout; do echo "compiled" > "js/$entry.js"; done\n')
        npm.chmod(0o755)
        self.env = dict(os.environ, PATH=str(bin_dir) + os.pathsep + os.environ['PATH'])

    def release(self):
        return subprocess.run(['bash', 'scripts/release.sh'], cwd=self.root,
                              env=self.env, capture_output=True, text=True)

    def test_release_archive_has_runtime_assets_and_no_development_checkout(self):
        self.prepare_release()
        result = self.release()
        self.assertEqual(result.returncode, 0, result.stderr)
        artifact = self.root / 'build/artifacts/appstore/riotchat-0.22.0.tar.gz'
        checksum = artifact.with_suffix(artifact.suffix + '.sha256').read_text().strip().split()
        self.assertEqual(checksum[0], hashlib.sha256(artifact.read_bytes()).hexdigest())
        self.assertEqual(checksum[1], 'riotchat-0.22.0.tar.gz')
        with tarfile.open(artifact) as archive:
            names = archive.getnames()
            for name in ['appinfo/info.xml', 'lib/Controller/StaticController.php',
                         'templates/index.php', 'css/main.css', 'img/app.svg',
                         'js/main.js', 'js/adminSettings.js', 'js/logout.js',
                         'l10n/fr.js', 'LICENSE', 'element-release.json']:
                self.assertIn('riotchat/' + name, names)
            for name in self.files:
                self.assertIn('riotchat/3rdparty/riot/' + name, names)
            self.assertIn('riotchat/3rdparty/riot/LICENSE-AGPL-3.0', names)
            self.assertTrue(all(name == 'riotchat' or name.startswith('riotchat/') for name in names))
            self.assertFalse(any('/node_modules/' in name or '/.git/' in name or '/src/' in name for name in names))
        self.assertTrue((self.root / 'build/artifacts/riotchat/appinfo/info.xml').is_file())

    def test_failed_adapter_build_does_not_publish_a_package(self):
        self.prepare_release()
        (self.root / 'bin/npm').write_text('#!/bin/sh\nexit 7\n')
        result = self.release()
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((self.root / 'build/artifacts/riotchat').exists())
        self.assertFalse((self.root / 'build/artifacts/appstore/riotchat-0.22.0.tar.gz').exists())


if __name__ == '__main__':
    unittest.main()
