import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { getCsrfToken } from './csrf';
import { downServicesFrom } from './sharedProps';
import { type DocumentLifecycleEvent, parseDocumentLifecycleEvent } from '../types/documents';
import { noticeIdFrom } from '../types/notifications';
import { type StatementImportEvent, parseStatementImportEvent } from '../types/statementImport';

// `laravel-echo`'s `reverb` broadcaster resolves `window.Pusher` at construction time — this is
// the documented Laravel/Reverb wiring (`BLUEPRINT.md` §1.3: "WebSockets prin Laravel Echo + Reverb"),
// not a workaround.
declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

window.Pusher = Pusher;

let echo: Echo<'reverb'> | null = null;

/**
 * Lazily creates the single `Echo` connection this app ever opens, using the exact
 * `VITE_REVERB_*` env vars `apps/api/.env.example` already defines (`docs/handoff/002-laravel-
 * scaffold.md` — these existed before this task; nothing new was added to `.env.example`, which is
 * outside this task's file scope). The Reverb app key is public by design (it identifies the
 * application to the WebSocket server, the same role a Pusher app key plays) — channel-level
 * authorization is what actually protects a subject's events (`routes/channels.php`'s
 * `SubjectMembership` check), not secrecy of this key.
 */
function getEcho(): Echo<'reverb'> {
    // Same-origin by default. Reverb is reached through nginx (`location /app/` in
    // docker/nginx/conf.d/app.conf), so the socket goes to whatever host and scheme served this
    // page — which is the only value that can be right for every way the site is reached (domain,
    // LAN IP, localhost). Baking a host in at build time gave `localhost`, and every browser that
    // was not on the server itself silently failed to connect. The `VITE_REVERB_*` vars remain as
    // an override for a deployment that puts Reverb on its own hostname.
    const secure = import.meta.env.VITE_REVERB_SCHEME
        ? import.meta.env.VITE_REVERB_SCHEME === 'https'
        : window.location.protocol === 'https:';
    const port = Number(import.meta.env.VITE_REVERB_PORT) || (secure ? 443 : 80);

    // The key the server rendered into the page (app.blade.php) before the one the build baked in:
    // assets built by CI, which is every installation that pulls its images, were built with none.
    const key =
        document.querySelector('meta[name="reverb-key"]')?.getAttribute('content') ||
        import.meta.env.VITE_REVERB_APP_KEY ||
        '';

    echo ??= new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: secure,
        enabledTransports: ['ws', 'wss'],
        // Registered by `withRouting(channels: ...)` → `withBroadcasting()`
        // (`docs/handoff/022-document-pipeline.md` "Key decisions" #4) under the `web` middleware
        // group, so it shares this page's session cookie — no bearer token needed here.
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-CSRF-TOKEN': getCsrfToken(),
                Accept: 'application/json',
            },
        },
    });

    return echo;
}

export interface DocumentsChannelHandlers {
    onEvent: (event: DocumentLifecycleEvent) => void;
}

/**
 * Subscribes to the private `tax-subjects.{subjectId}.documents` channel
 * (`Kern\Documents\Events\Concerns\BroadcastsOnDocumentsChannel`, authorized in
 * `routes/channels.php`) and calls `onEvent` for each of the five lifecycle broadcasts
 * (`DocumentUploaded`, `DocumentScanned`, `DocumentTextExtracted`, `DocumentTextExtractionRejected`,
 * `DocumentExtractionProposed`). Returns an unsubscribe function — callers must invoke it on
 * unmount (`BLUEPRINT.md` §16: one private channel per subject, not a growing set of stale listeners).
 *
 * Per `BLUEPRINT.md` §16, the payload is status/progress/ids only; this app never treats it as more
 * than "something changed, go re-fetch" — every handler using this re-fetches
 * `Kern\Documents\Http\Controllers\ShowDocumentController` for the authoritative state.
 */
export function subscribeToDocumentsChannel(subjectId: string, handlers: DocumentsChannelHandlers): () => void {
    const channelName = `tax-subjects.${subjectId}.documents`;
    const channel = getEcho().private(channelName);

    const eventNames = [
        'DocumentUploaded',
        'DocumentScanned',
        'DocumentTextExtracted',
        'DocumentTextExtractionRejected',
        'DocumentExtractionProposed',
        'DocumentAiExtractionFinished',
    ];

    const listener = (raw: unknown): void => {
        try {
            handlers.onEvent(parseDocumentLifecycleEvent(raw));
        } catch {
            // A broadcast payload that doesn't match the documented shape is dropped, not
            // crashed on — the client falls back to whatever state it last fetched over HTTPS.
        }
    };

    for (const eventName of eventNames) {
        channel.listen(`.${eventName}`, listener);
    }

    return () => {
        getEcho().leave(channelName);
    };
}

/**
 * Subscribes to the private `tax-subjects.{subjectId}.imports` channel
 * (`Kern\TaxRomania\StatementImport\Events\StatementImportChanged`, authorized in
 * `routes/channels.php`) and calls `onEvent` whenever an import moves — read, mapped, reviewed,
 * written — or its counterparty proposals arrive.
 *
 * Same contract as the documents channel: the payload is ids and a status, never a row, and every
 * handler treats it as "something changed, go re-fetch" rather than as data.
 */
export function subscribeToImportsChannel(
    subjectId: string,
    onEvent: (event: StatementImportEvent) => void,
): () => void {
    const channelName = `tax-subjects.${subjectId}.imports`;

    getEcho()
        .private(channelName)
        .listen('.StatementImportChanged', (raw: unknown): void => {
            try {
                onEvent(parseStatementImportEvent(raw));
            } catch {
                // A payload that is not the documented shape is dropped, not crashed on — the screen
                // keeps whatever it last fetched over HTTPS.
            }
        });

    return () => {
        getEcho().leave(channelName);
    };
}

/*
 * The signed-in person's own private channel, `users.{userId}` (authorized in `routes/channels.php`),
 * which more than one part of the frame listens on: the bell for notices, the switcher for taxpayers, the
 * legislation dialog for its portal search.
 *
 * Joined once and shared. Each listener used to join and leave it itself — and leaving a channel in
 * Echo leaves it for everyone on it, so the first listener to go silenced the others.
 */
const userListeners = {
    notices: new Set<(noticeId: string) => void>(),
    taxpayers: new Set<() => void>(),
    portalSearches: new Set<(searchId: string) => void>(),
};
let userChannel: string | null = null;

function joinUserChannel(userId: string): void {
    const channelName = `users.${userId}`;

    if (userChannel === channelName) {
        return;
    }

    if (userChannel !== null) {
        getEcho().leave(userChannel);
    }

    userChannel = channelName;
    getEcho()
        .private(channelName)
        .notification((raw: unknown): void => {
            const id = noticeIdFrom(raw);

            if (id !== null) {
                userListeners.notices.forEach((listener) => {
                    listener(id);
                });
            }
        })
        .listen('.taxpayers.changed', (): void => {
            userListeners.taxpayers.forEach((listener) => {
                listener();
            });
        })
        .listen('.legislation.portal-search.changed', (raw: unknown): void => {
            const id =
                typeof raw === 'object' && raw !== null && 'id' in raw && typeof raw.id === 'string' ? raw.id : null;

            if (id !== null) {
                userListeners.portalSearches.forEach((listener) => {
                    listener(id);
                });
            }
        });
}

function leaveUserChannelIfUnheard(): void {
    if (userChannel !== null && Object.values(userListeners).every((listeners) => listeners.size === 0)) {
        getEcho().leave(userChannel);
        userChannel = null;
    }
}

/**
 * Calls `onNotice` with the id of each notice stored for the signed-in person. The socket carries the
 * id and the kind only, never what the notice says: the caller fetches the words over HTTPS, as the
 * bell does.
 */
export function subscribeToUserNotifications(userId: string, onNotice: (noticeId: string) => void): () => void {
    joinUserChannel(userId);
    userListeners.notices.add(onNotice);

    return () => {
        userListeners.notices.delete(onNotice);
        leaveUserChannelIfUnheard();
    };
}

/**
 * Calls `onChange` when the taxpayers this person works in changed — added, renamed, pictured, closed,
 * shared or unshared (`Kern\TaxRomania\Subjects\Events\TaxpayersChanged`). Says nothing of which:
 * the caller asks for the list again.
 */
export function subscribeToTaxpayerChanges(userId: string, onChange: () => void): () => void {
    joinUserChannel(userId);
    userListeners.taxpayers.add(onChange);

    return () => {
        userListeners.taxpayers.delete(onChange);
        leaveUserChannelIfUnheard();
    };
}

/**
 * Calls `onChange` with a portal search's id each time one of this person's moves — a page read, finished,
 * cancelled (`Kern\Legislation\Events\PortalSearchChanged`). The socket carries the id and counts only:
 * the caller fetches the search over HTTPS.
 */
export function subscribeToPortalSearches(userId: string, onChange: (searchId: string) => void): () => void {
    joinUserChannel(userId);
    userListeners.portalSearches.add(onChange);

    return () => {
        userListeners.portalSearches.delete(onChange);
        leaveUserChannelIfUnheard();
    };
}

/**
 * Subscribes to `system.health` (`SystemHealthChanged`, authorized for `system.settings.view` in
 * `routes/channels.php`) and calls `onChange` with the services now down — the whole list each time,
 * so a missed message is corrected by the next one.
 */
export function subscribeToSystemHealth(onChange: (down: string[]) => void): () => void {
    const channelName = 'system.health';

    getEcho()
        .private(channelName)
        .listen('.SystemHealthChanged', (raw: unknown): void => {
            if (typeof raw === 'object' && raw !== null && 'down' in raw) {
                onChange(downServicesFrom(raw.down));
            }
        });

    return () => {
        getEcho().leave(channelName);
    };
}
