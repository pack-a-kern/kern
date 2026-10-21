import { obj } from '../lib/json';

/**
 * The bell's notices (`Kern\Platform\Http\Controllers\Notifications\ListNotificationsController`).
 *
 * Only statement imports raise one today. A notice of a type this screen does not know is dropped
 * rather than drawn blank: the server can learn a new kind before the bundle in an open tab does.
 */

export type ImportNoticeKind = 'READY_FOR_MAPPING' | 'READY_FOR_REVIEW' | 'IMPORTED' | 'FAILED';

const KINDS: readonly ImportNoticeKind[] = ['READY_FOR_MAPPING', 'READY_FOR_REVIEW', 'IMPORTED', 'FAILED'];

/** Ids, counts and closed codes only — `StatementImportNotice::toArray()`. */
export interface ImportNotice {
    id: string;
    kind: ImportNoticeKind;
    subjectId: string;
    importId: string;
    rowCount: number;
    importedCount: number;
    rowsSuggested: number;
    failureReason: string | null;
    read: boolean;
    createdAt: string | null;
}

export interface NotificationFeed {
    notices: ImportNotice[];
    unread: number;
}

const IMPORT_NOTICE_TYPE = 'statement-import';

function asCount(value: unknown): number {
    return typeof value === 'number' && Number.isFinite(value) ? value : 0;
}

function asString(value: unknown): string | null {
    return typeof value === 'string' && value !== '' ? value : null;
}

/**
 * One notice, from either shape it arrives in: a stored row keeps its fields under `data`, a
 * broadcast carries them at the top level beside `id` and `type`.
 */
export function parseImportNotice(raw: unknown): ImportNotice | null {
    const row = obj(raw);
    const data = 'data' in row ? obj(row.data) : row;
    const id = asString(row.id);
    const kind = KINDS.find((known) => known === data.kind);
    const subjectId = asString(data.subject_id);
    const importId = asString(data.import_id);

    if (
        row.type !== IMPORT_NOTICE_TYPE ||
        id === null ||
        kind === undefined ||
        subjectId === null ||
        importId === null
    ) {
        return null;
    }

    return {
        id,
        kind,
        subjectId,
        importId,
        rowCount: asCount(data.row_count),
        importedCount: asCount(data.imported_count),
        rowsSuggested: asCount(data.rows_suggested),
        failureReason: asString(data.failure_reason),
        read: asString(row.read_at) !== null,
        createdAt: asString(row.created_at),
    };
}

export function parseNotificationFeed(raw: unknown): NotificationFeed {
    const body = obj(raw);
    const rows = Array.isArray(body.notifications) ? body.notifications : [];

    return {
        notices: rows.map(parseImportNotice).filter((notice): notice is ImportNotice => notice !== null),
        unread: asCount(body.unread),
    };
}
