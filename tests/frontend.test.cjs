const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');

function harness(frameWindow) {
    const iframe = { contentWindow: frameWindow };
    const document = {
        title: 'Nextcloud',
        addEventListener: () => {},
        getElementById: () => iframe,
    };
    const context = vm.createContext({
        document,
        window: { location: { hash: '#/login' }, localStorage: { getItem: () => null } },
        loadState: () => 'true',
        generateUrl: path => path,
        MutationObserver: class {
            observe(node) { assert.ok(node, 'Cannot observe an absent title'); }
            disconnect() {}
        },
    });
    const source = readFileSync('src/main.js', 'utf8').replace(/^import .*;\n/gm, '');
    vm.runInContext(source, context);
    vm.runInContext('main()', context);
    return { context, iframe };
}

test('an initial blank iframe does not break app initialization', () => {
    const { iframe } = harness({ document: { querySelector: () => null } });
    assert.doesNotThrow(() => iframe.onload());
});

test('a cross-origin SSO page can load without a parent frame exception', () => {
    const frameWindow = {};
    Object.defineProperty(frameWindow, 'document', { get() { throw new Error('SecurityError'); } });
    const { iframe } = harness(frameWindow);
    assert.doesNotThrow(() => iframe.onload());
});

test('same-origin Element navigation still synchronizes the URL and title', () => {
    const frameWindow = {
        document: { querySelector: () => ({}), title: 'Element' },
        location: { hash: '#/login' },
    };
    const { context, iframe } = harness(frameWindow);
    iframe.onload();
    frameWindow.location.hash = '#/room/test';
    frameWindow.onhashchange();
    assert.equal(context.window.location.hash, '#/room/test');
    vm.runInContext('setTitle()', context);
    assert.equal(context.document.title, 'Element - Nextcloud');
});
