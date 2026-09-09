/**
 * Registers `public/sw.js` (served at `/sw.js`) — the installable-PWA requirement (`BLUEPRINT.md`
 * §1.4). No-ops outside a secure context / a browser without `serviceWorker` support (older Safari
 * versions, non-HTTPS dev tunnels) rather than throwing, since the app must remain fully usable
 * without a service worker — it only adds offline resilience and installability, never a
 * capability the rest of the app depends on.
 */
export function registerServiceWorker(): void {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Registration failures (e.g. served over plain HTTP in a dev tunnel) are non-fatal —
            // see module docblock.
        });
    });
}
