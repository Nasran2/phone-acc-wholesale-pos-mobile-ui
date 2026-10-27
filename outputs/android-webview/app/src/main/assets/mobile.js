let gesture = null;
let refreshing = false;
let indicator;

function refreshIndicator() {
    if (indicator?.isConnected) return indicator;
    indicator = document.createElement('div');
    indicator.className = 'pull-refresh-indicator';
    indicator.setAttribute('role', 'status');
    indicator.setAttribute('aria-live', 'polite');
    document.body.appendChild(indicator);
    return indicator;
}

function canPullRefresh(target) {
    if (refreshing || document.querySelector('[data-loading]') || target.closest('input, textarea, select, button, [contenteditable], [role="dialog"], [aria-modal="true"]')) return false;
    for (let node = target; node && node !== document.documentElement; node = node.parentElement) {
        if (node.scrollTop > 0) return false;
        if (node.hasAttribute('x-show') && getComputedStyle(node).position === 'fixed') return false;
    }
    return window.scrollY <= 0;
}

async function refreshPage() {
    if (refreshing) return;
    const component = document.querySelector('main [wire\\:id], [data-flux-main] [wire\\:id], [wire\\:id]');
    const wire = component && window.Livewire?.find(component.getAttribute('wire:id'));
    // Refresh server data while preserving the cart and other Livewire state.
    if (!wire) return;
    refreshing = true;
    const status = refreshIndicator();
    status.textContent = 'Refreshing…';
    status.dataset.visible = 'true';
    try {
        await wire.$refresh();
        status.textContent = 'Up to date';
    } catch {
        status.textContent = 'Could not refresh. Check your connection.';
    } finally {
        refreshing = false;
        setTimeout(() => { status.dataset.visible = 'false'; }, 1200);
    }
}

document.addEventListener('touchstart', (event) => {
    gesture = event.touches.length === 1 && canPullRefresh(event.target)
        ? { x: event.touches[0].clientX, y: event.touches[0].clientY, distance: 0 } : null;
}, { passive: true });

document.addEventListener('touchmove', (event) => {
    if (!gesture) return;
    const dx = Math.abs(event.touches[0].clientX - gesture.x);
    const dy = event.touches[0].clientY - gesture.y;
    if (dx > 35 || dy < 0 || !canPullRefresh(event.target)) { gesture = null; return; }
    gesture.distance = dy;
    if (dy > 35) {
        if (event.cancelable) event.preventDefault();
        const status = refreshIndicator();
        status.textContent = dy >= 100 ? 'Release to refresh' : 'Pull down to refresh';
        status.dataset.visible = 'true';
    }
}, { passive: false });

document.addEventListener('touchend', () => {
    const shouldRefresh = gesture?.distance >= 100;
    gesture = null;
    if (shouldRefresh) refreshPage();
    else if (indicator) indicator.dataset.visible = 'false';
}, { passive: true });
document.addEventListener('touchcancel', () => { gesture = null; if (indicator) indicator.dataset.visible = 'false'; }, { passive: true });
window.TwinsofteMobile = { refresh: refreshPage };
