const jobs = new WeakMap();

export function resetPdf(state) {
    jobs.get(state)?.abort();
    jobs.delete(state);
    if (state.sharePdfUrl) URL.revokeObjectURL(state.sharePdfUrl);
    Object.assign(state, { shareCopied: false, sharePreparing: false, sharePdfError: false, sharePdfFile: null, sharePdfUrl: null, shareSourceUrl: null });
}

export async function preparePdf(state, url, filename) {
    if (state.sharePreparing || state.sharePdfFile) return;
    const job = new AbortController();
    jobs.set(state, job);
    state.sharePreparing = true;
    state.sharePdfError = false;
    try {
        if (!url) throw new Error('No saved document selected.');
        const response = await fetch(url, { credentials: 'same-origin', signal: job.signal, headers: { Accept: 'application/pdf' } });
        if (!response.ok || !response.headers.get('Content-Type')?.includes('application/pdf')) {
            throw new Error('PDF unavailable. Check your connection or sign in again.');
        }
        const blob = await response.blob();
        if (job.signal.aborted) return;
        state.sharePdfFile = new File([blob], `${filename.replace(/[\\/:*?"<>|]/g, '-').slice(0, 100)}.pdf`, { type: 'application/pdf' });
        state.sharePdfUrl = URL.createObjectURL(blob);
        state.shareSourceUrl = new URL(url, location.href).href;
    } catch (error) {
        if (error.name !== 'AbortError') state.sharePdfError = true;
    } finally {
        if (jobs.get(state) === job) {
            jobs.delete(state);
            state.sharePreparing = false;
        }
    }
}

export function nativePdf(method, url, filename) {
    return new Promise((resolve, reject) => {
        const id = crypto.randomUUID?.() || `${Date.now()}-${Math.random()}`;
        const timer = setTimeout(() => finish(new Error('PDF request timed out. Please retry.')), 65000);
        function finish(error) {
            clearTimeout(timer);
            window.removeEventListener('twinsofte:pdf-result', listener);
            error ? reject(error) : resolve();
        }
        function listener(event) {
            if (event.detail.id === id) finish(event.detail.ok ? null : new Error(event.detail.error || 'PDF request failed.'));
        }
        window.addEventListener('twinsofte:pdf-result', listener);
        try {
            window.ImranAndroid[method](new URL(url, location.href).href, filename || 'Invoice.pdf', id);
        } catch (error) { finish(error); }
    });
}

export async function shareRemote(state, url) {
    if (state.sharePreparing) return;
    state.sharePreparing = true;
    state.sharePdfError = false;
    try {
        await nativePdf('sharePdfUrl', url, state.sharePdfFile?.name);
        state.shareCopied = true;
    } catch { state.sharePdfError = true; }
    finally { state.sharePreparing = false; }
}

export async function sharePdf(state) {
    if (!state.sharePdfFile) return;
    if (window.ImranAndroid?.sharePdfUrl) return shareRemote(state, state.shareSourceUrl);
    try {
        if (navigator.share && navigator.canShare?.({ files: [state.sharePdfFile] })) {
            await navigator.share({ files: [state.sharePdfFile], title: state.sharePdfFile.name });
        } else {
            await downloadPdf(state);
        }
        state.shareCopied = true;
    } catch (error) {
        if (error.name !== 'AbortError') state.sharePdfError = true;
    }
}

export async function downloadPdf(state) {
    if (!state.sharePdfFile) return;
    if (window.ImranAndroid?.downloadPdfUrl) {
        try { await nativePdf('downloadPdfUrl', state.shareSourceUrl, state.sharePdfFile.name); }
        catch { state.sharePdfError = true; }
        return;
    }
    const link = document.createElement('a');
    link.href = state.sharePdfUrl;
    link.download = state.sharePdfFile.name;
    link.click();
}

window.PosPdf = { prepare: preparePdf, reset: resetPdf, share: sharePdf, shareRemote, download: downloadPdf };
