import test from 'node:test';
import assert from 'node:assert/strict';
const listeners = {};
const status = { isConnected: true, setAttribute() {}, dataset: {} };
let busy = false;
const component = { getAttribute: () => 'pos-component' };
globalThis.document = {
    documentElement: {},
    body: { appendChild() {} },
    addEventListener(name, callback) { listeners[name] = callback; },
    querySelector(selector) { return selector === '[data-loading]' ? (busy ? {} : null) : component; },
    createElement() { return status; },
};
globalThis.window = { scrollY: 0 };
globalThis.getComputedStyle = () => ({ position: 'static' });
const { canPullRefresh, refreshPage } = await import('../../resources/js/mobile.js');
const target = (scrollTop = 0) => ({ scrollTop, closest: () => null, hasAttribute: () => false, parentElement: document.documentElement });

test('pull refresh only starts at the top and ignores editable controls and busy saves', () => {
    assert.equal(canPullRefresh(target()), true);
    assert.equal(canPullRefresh(target(20)), false);
    assert.equal(canPullRefresh({ closest: () => ({}) }), false);
    busy = true; assert.equal(canPullRefresh(target()), false); busy = false;
    window.scrollY = 40; assert.equal(canPullRefresh(target()), false); window.scrollY = 0;
});

test('refresh calls Livewire refresh and never reloads the document or cart', async () => {
    let calls = 0;
    window.Livewire = { find(id) { assert.equal(id, 'pos-component'); return { $refresh: async () => { calls++; } }; } };
    await refreshPage();
    assert.equal(calls, 1); assert.equal(status.textContent, 'Up to date');
});

test('offline refresh reports failure and lets the cashier retry', async () => {
    window.Livewire = { find: () => ({ $refresh: async () => { throw new Error('Offline'); } }) };
    await refreshPage(); assert.match(status.textContent, /Check your connection/);
    window.Livewire = { find: () => ({ $refresh: async () => {} }) };
    await refreshPage(); assert.equal(status.textContent, 'Up to date');
});
