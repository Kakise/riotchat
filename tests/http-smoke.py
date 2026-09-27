#!/usr/bin/env python3
"""Read-only smoke checks against a disposable Nextcloud installation."""
import base64
import json
import os
import re
import time
import urllib.error
import urllib.request

base = os.environ['NEXTCLOUD_URL'].rstrip('/')
auth = base64.b64encode((os.environ['NEXTCLOUD_USER'] + ':' + os.environ['NEXTCLOUD_PASSWORD']).encode()).decode()


def get(path, authenticated=True):
    headers = {'OCS-APIRequest': 'true'}
    if authenticated:
        headers['Authorization'] = 'Basic ' + auth
    return urllib.request.urlopen(urllib.request.Request(base + path, headers=headers), timeout=20)


for attempt in range(30):
    try:
        with get('/status.php', False) as response:
            assert json.load(response)['versionstring'] == '35.0.0'
        break
    except (OSError, AssertionError):
        if attempt == 29:
            raise
        time.sleep(1)

app = '/index.php/apps/riotchat'
with get(app + '/') as response:
    assert 'id="riot-iframe"' in response.read().decode()
with get(app + '/riot/config.json') as response:
    config = json.load(response)
    assert isinstance(config['setting_defaults'], dict)
    assert isinstance(config['show_labs_settings'], bool)
    assert config['default_server_config']['m.homeserver']['base_url'].startswith('https://')
with get(app + '/riot/') as response:
    html = response.read().decode()
    assert '<script nonce="' in html
    policy = response.headers['Content-Security-Policy']
    assert "'wasm-unsafe-eval'" in policy and 'worker-src' in policy and 'blob:' in policy
    assert not response.headers.get('Last-Modified'), 'Nonce-bearing HTML must not return conditional 304'
    bundle = re.search(r'bundles/([a-zA-Z0-9_-]+)/', html).group(1)
with get(app + '/riot/version') as response:
    assert response.read().decode().strip().removeprefix('v') == '1.12.29'
with get(app + '/riot/sw.js') as response:
    assert 'javascript' in response.headers['Content-Type']
    assert 'no-cache' in response.headers['Cache-Control'] or 'no-store' in response.headers['Cache-Control']
    assert len(response.read()) > 100
with get(app + '/riot/bundles/' + bundle + '/usercontent.js', False) as response:
    assert 'javascript' in response.headers['Content-Type']
    assert len(response.read()) > 100
with get('/index.php/settings/admin/riotchat') as response:
    assert 'riot-chat-settings' in response.read().decode()
try:
    with get(app + '/riot/missing-smoke-test.js') as response:
        raise AssertionError('Missing assets must return 404')
except urllib.error.HTTPError as error:
    assert error.code == 404
# Anonymous requests must never receive the authenticated Element document.
try:
    with get(app + '/riot/', False) as response:
        assert 'nonce="' not in response.read().decode() or '/login' in response.url
except urllib.error.HTTPError as error:
    assert error.code in (401, 403)
print('HTTP smoke checks passed: app, config, Element, version, service worker, public attachment script, admin settings, 404 and authentication.')
