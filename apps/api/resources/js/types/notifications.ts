import { obj } from '../lib/json';
import { ValidationError, vEnum, vNullable, vObject, vString } from '../lib/validate';

/**
 * The bell's unread notices, as `kern/notifications` writes them (`GET v1/notifications`).
 *
 * Already in words: each kind of notice writes its own title, text and link on the server, in the
 * page's language, so this screen has no list of kinds and a kind a package adds is shown with nothing
 * to change here. A notice that does not have this shape is left out, not drawn blank.
 */
export interface Notice {
    id: string;
    /** The kind's key — for telling notices apart, never for choosing what they say. */
    type: string;
    title: string;
    body: string;
    /** A path inside this application. */
    url: string;
    /** `error` marks one that reports something that went wrong. */
    level: 'info' | 'error';
    createdAt: string | null;
}

/** The unread notices — the bell lists nothing once they are read. */
export interface NotificationFeed {
    notices: Notice[];
    unread: number;
}

const notice = vObject({
    id: vString,
    type: vString,
    title: vString,
    body: vString,
    url: vString,
    level: vEnum(['info', 'error'] as const),
    created_at: vNullable(vString),
});

function parseNotice(raw: unknown): Notice | null {
    try {
        const parsed = notice(raw);

        return {
            id: parsed.id,
            type: parsed.type,
            title: parsed.title,
            body: parsed.body,
            url: parsed.url,
            level: parsed.level,
            createdAt: parsed.created_at,
        };
    } catch (cause: unknown) {
        if (cause instanceof ValidationError) {
            return null;
        }

        throw cause;
    }
}

export function parseNotificationFeed(raw: unknown): NotificationFeed {
    const body = obj(raw);
    const rows = Array.isArray(body.notifications) ? body.notifications : [];

    return {
        notices: rows.map(parseNotice).filter((entry): entry is Notice => entry !== null),
        unread: typeof body.unread === 'number' && Number.isFinite(body.unread) ? body.unread : 0,
    };
}

/**
 * The notice's id from the person's private channel. The socket carries the id and the kind only —
 * never what the notice says — so the words are fetched with the list.
 */
export function noticeIdFrom(raw: unknown): string | null {
    const id = obj(raw).id;

    return typeof id === 'string' && id !== '' ? id : null;
}
