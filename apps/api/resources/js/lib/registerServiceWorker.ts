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
        navigator.serviceWorker.register('/sw.js').catch(async () => {
            // Registration failures (e.g. served over plain HTTP in a dev tunnel) are non-fatal —
            // see module docblock. But a worker installed earlier stays in charge of every page
            // after a failure like this, and if the failure is a certificate the browser no longer
            // accepts, that worker can never fetch its own update either. Remove it: no worker is
            // better than one that cannot be replaced.
            const registrations = await navigator.serviceWorker.getRegistrations();
            await Promise.all(registrations.map((registration) => registration.unregister()));
        });
    });
}
