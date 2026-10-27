import test, { beforeEach } from 'node:test';
import assert from 'node:assert/strict';

globalThis.window = new EventTarget();
globalThis.location = { href: 'https://store.example/pos' };
Object.defineProperty(globalThis, 'navigator', { value: {}, configurable: true });
let clicks;
globalThis.document = { createElement: () => ({ click() { clicks++; } }) };
const { preparePdf, resetPdf, sharePdf, downloadPdf, nativePdf, shareRemote } = await import('../../resources/js/pdf-sharing.js');
const state = () => ({ sharePreparing: false, sharePdfFile: null, sharePdfUrl: null });

beforeEach(() => { clicks = 0; window.ImranAndroid = undefined; navigator.share = undefined; navigator.canShare = undefined; });

test('prepares one PDF with session credentials and releases it on close', async () => {
    const s = state(); let calls = 0;
    globalThis.fetch = async (url, options) => {
        calls++; assert.equal(options.credentials, 'same-origin'); assert.equal(options.headers.Accept, 'application/pdf');
        return new Response('%PDF-1.7', { headers: { 'Content-Type': 'application/pdf' } });
    };
    await preparePdf(s, '/sales/1/pdf', 'INV/1');
    await preparePdf(s, '/sales/1/pdf', 'INV/1');
    assert.equal(calls, 1); assert.equal(s.sharePdfFile.name, 'INV-1.pdf');
    assert.equal(s.shareSourceUrl, 'https://store.example/sales/1/pdf');
    assert.equal(s.sharePreparing, false);
    resetPdf(s); assert.equal(s.sharePdfFile, null); assert.equal(s.sharePdfUrl, null);
});

test('expired login HTML produces a retryable error instead of a fake PDF', async () => {
    const s = state(); globalThis.fetch = async () => new Response('<html>Login</html>', { headers: { 'Content-Type': 'text/html' } });
    await preparePdf(s, '/sales/1/pdf', 'Invoice');
    assert.equal(s.sharePdfError, true); assert.equal(s.sharePreparing, false); assert.equal(s.sharePdfFile, null);
});

test('closing and reopening a document cannot install a stale PDF or clear a newer request', async () => {
    const s = state(); let completeOld, completeNew;
    globalThis.fetch = () => new Promise(resolve => { completeOld = resolve; });
    const oldJob = preparePdf(s, '/sales/1/pdf', 'Old');
    resetPdf(s);
    globalThis.fetch = () => new Promise(resolve => { completeNew = resolve; });
    const newJob = preparePdf(s, '/sales/2/pdf', 'New');
    completeOld(new Response('%PDF-old', { headers: { 'Content-Type': 'application/pdf' } }));
    await oldJob; assert.equal(s.sharePdfFile, null); assert.equal(s.sharePreparing, true);
    completeNew(new Response('%PDF-new', { headers: { 'Content-Type': 'application/pdf' } }));
    await newJob; assert.equal(s.sharePdfFile.name, 'New.pdf'); resetPdf(s);
});

test('cancelled share sheets do not mark the PDF as shared or trigger a download', async () => {
    const s = state(); s.sharePdfFile = new File(['%PDF'], 'Invoice.pdf', { type: 'application/pdf' });
    navigator.canShare = () => true; navigator.share = async () => { throw new DOMException('Cancelled', 'AbortError'); };
    await sharePdf(s); assert.notEqual(s.shareCopied, true); assert.notEqual(s.sharePdfError, true); assert.equal(clicks, 0);
});

test('browser download fallback downloads the actual prepared PDF', async () => {
    const s = state(); s.sharePdfFile = new File(['%PDF'], 'Invoice.pdf'); s.sharePdfUrl = 'blob:test';
    await sharePdf(s); assert.equal(clicks, 1); assert.equal(s.shareCopied, true);
});

test('Android receives an authenticated server PDF address and result callback', async () => {
    let call;
    window.ImranAndroid = { sharePdfUrl(url, name, id) {
        call = { url, name };
        window.dispatchEvent(new CustomEvent('twinsofte:pdf-result', { detail: { id, ok: true } }));
    } };
    const s = state(); await shareRemote(s, '/sales/1/pdf');
    assert.equal(call.url, 'https://store.example/sales/1/pdf'); assert.equal(s.shareCopied, true); assert.equal(s.sharePreparing, false);
});

test('native failure releases busy state and allows retry', async () => {
    window.ImranAndroid = { sharePdfUrl(url, name, id) {
        window.dispatchEvent(new CustomEvent('twinsofte:pdf-result', { detail: { id, ok: false, error: 'Offline' } }));
    } };
    const s = state(); await shareRemote(s, '/sales/1/pdf');
    assert.equal(s.sharePdfError, true); assert.equal(s.sharePreparing, false); assert.notEqual(s.shareCopied, true);
});
