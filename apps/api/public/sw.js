/* global self, caches, fetch, Response, Promise, URL */
/**
 * Hand-written service worker (`docs/DEPENDENCY-POLICY.md` §4 orders "standard library" above a
 * third-party package — the Cache/Fetch APIs used here cover this app's modest caching needs
 * without pulling in Workbox's transitive dependency tree, the same reasoning
 * `docs/handoff/022-document-pipeline.md` decision #3 applied to the ClamAV client).
 *
 * Two caches:
 * - `SHELL_CACHE`: the offline fallback page plus whatever Vite build assets were discoverable at
 *   install time (read from `/build/manifest.json`, never a hand-maintained filename list — Vite
 *   content-hashes every asset, so guessing names would silently go stale on the next build).
 * - `RUNTIME_CACHE`: build assets fetched after install, cached cache-first (safe: Vite's
 *   content-hashed filenames are immutable — the same hash never resolves to different bytes).
 *
 * Deliberately NOT cached, ever:
 * - `/api/**` and `/broadcasting/**` — always fresh, authorised data (`BLUEPRINT.md` §16/§17: no
 *   fiscal data, sessions, or documents cached by the service worker).
 * - Any non-GET request — an upload/mutation is never replayed from a cache.
 * - Inertia page navigations — these carry a per-session CSRF token
 *   (`resources/js/lib/csrf.ts`'s docblock); caching the HTML would serve a stale one. A navigation
 *   made while offline gets the static, session-free `/offline.html` instead of a stale app shell.
 */

const CACHE_VERSION = 'v3';
const SHELL_CACHE = `kern-shell-${CACHE_VERSION}`;
const RUNTIME_CACHE = `kern-runtime-${CACHE_VERSION}`;
const OFFLINE_URL = '/offline.html';
// What the offline page loads. Served from the cache, since by definition the network is not there.
const OFFLINE_ASSETS = ['/offline.css', '/offline.js'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const cache = await caches.open(SHELL_CACHE);
            const urls = new Set([OFFLINE_URL, ...OFFLINE_ASSETS, '/manifest.webmanifest']);

            try {
                const manifestResponse = await fetch('/build/manifest.json', { cache: 'no-store' });

                if (manifestResponse.ok) {
                    const manifest = await manifestResponse.json();

                    for (const entry of Object.values(manifest)) {
                        if (typeof entry.file === 'string') {
                            urls.add(`/build/${entry.file}`);
                        }

                        if (Array.isArray(entry.css)) {
                            for (const cssFile of entry.css) {
                                urls.add(`/build/${cssFile}`);
                            }
                        }
                    }
                }
            } catch {
                // No network at install time (or the manifest isn't built yet in dev mode) —
                // the offline page and runtime caching below still work without a precache list.
            }

            await Promise.allSettled([...urls].map((url) => cache.add(url)));
            await self.skipWaiting();
        })(),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const keys = await caches.keys();

            await Promise.all(keys.filter((key) => key !== SHELL_CACHE && key !== RUNTIME_CACHE).map((key) => caches.delete(key)));
            await self.clients.claim();
        })(),
    );
});

async function cacheFirst(request) {
    const cache = await caches.open(RUNTIME_CACHE);
    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (response.ok) {
        await cache.put(request, response.clone());
    }

    return response;
}

async function navigationWithOfflineFallback(request) {
    try {
        return await fetch(request);
    } catch {
        const cache = await caches.open(SHELL_CACHE);
        const offline = await cache.match(OFFLINE_URL);

        return offline ?? Response.error();
    }
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/broadcasting/')) {
        return;
    }

    if (OFFLINE_ASSETS.includes(url.pathname)) {
        event.respondWith(caches.match(request).then((cached) => cached ?? fetch(request)));

        return;
    }

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(request));

        return;
    }

    // Only while the device says it is offline. Online, the browser navigates on its own: a failure
    // there is not "no internet" but a certificate it has to ask about, a server that is down, a
    // proxy — and only the browser can show those. A worker that answered every failed navigation
    // with the offline page told a person on a working connection that they had none.
    if (request.mode === 'navigate' && !self.navigator.onLine) {
        event.respondWith(navigationWithOfflineFallback(request));
    }
});

/*
 * Device notifications (`Kern\Platform\Notifications`): the server sends a small JSON message,
 * encrypted to this browser, and this shows it. `userVisibleOnly` is what the page subscribed with, so
 * every push is shown — a browser may revoke the permission of a worker that receives one silently.
 *
 * What it says came from the server already in the device's language; nothing here is trusted beyond
 * being text: a title, a body, a tag, and a path inside this application.
 */
self.addEventListener('push', (event) => {
    let message = {};

    try {
        message = event.data ? event.data.json() : {};
    } catch {
        message = {};
    }

    const title = typeof message.title === 'string' && message.title !== '' ? message.title : 'Kern';

    event.waitUntil(
        self.registration.showNotification(title, {
            body: typeof message.body === 'string' ? message.body : '',
            icon: '/icons/icon-192.png',
            badge: '/icons/icon-192.png',
            tag: typeof message.tag === 'string' ? message.tag : undefined,
            data: { url: typeof message.url === 'string' ? message.url : '/' },
        }),
    );
});

/*
 * Tapping a notification opens what it is about — a window of this application already open is
 * brought forward and sent there, otherwise one is opened. Only a path of this origin: a message
 * naming another site is opened at the start page instead, never followed.
 */
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const requested = event.notification.data && typeof event.notification.data.url === 'string' ? event.notification.data.url : '/';
    const target = new URL(requested, self.location.origin);
    const url = target.origin === self.location.origin ? target.href : self.location.origin + '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const open = windows.find((client) => new URL(client.url).origin === self.location.origin);

            if (open) {
                return open.focus().then((client) => (client && 'navigate' in client ? client.navigate(url) : undefined));
            }

            return self.clients.openWindow(url);
        }),
    );
});
