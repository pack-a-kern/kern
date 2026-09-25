import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { getCsrfToken } from './csrf';
import { type DocumentLifecycleEvent, parseDocumentLifecycleEvent } from '../types/documents';
import { type ImportNotice, parseImportNotice } from '../types/notifications';
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

    echo ??= new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY ?? '',
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
 * (`App\Domain\StatementImport\Events\StatementImportChanged`, authorized in
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

/**
 * Subscribes to the signed-in person's own private `users.{userId}` channel (authorized in
 * `routes/channels.php`) and calls `onNotice` for each notice Laravel broadcasts there — the same
 * notice the bell lists, pushed the moment it is stored.
 */
export function subscribeToUserNotifications(userId: string, onNotice: (notice: ImportNotice) => void): () => void {
    const channelName = `users.${userId}`;

    getEcho()
        .private(channelName)
        .notification((raw: unknown): void => {
            const notice = parseImportNotice(raw);

            // A kind this bundle does not know yet is left to the next fetch of the list.
            if (notice !== null) {
                onNotice(notice);
            }
        });

    return () => {
        getEcho().leave(channelName);
    };
}

export function disconnectEcho(): void {
    echo?.disconnect();
    echo = null;
}
