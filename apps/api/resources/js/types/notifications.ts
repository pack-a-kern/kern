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
    type: 'statement-import';
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

/**
 * A term deposit nearing maturity (`Kern\TaxRomania\Wallet\Notifications\DepositMaturityNotice`). The bell
 * keeps no amount and no bank — its table is not encrypted — only which deposit, how many days, and when.
 */
export interface DepositMaturityNotice {
    id: string;
    type: 'deposit-maturity';
    subjectId: string;
    accountId: string;
    /** Days before maturity: 0 is the day itself. */
    daysBefore: number;
    maturityAt: string | null;
    read: boolean;
    createdAt: string | null;
}

/** Every notice the bell shows. A type this build does not know is left out, not drawn as something else. */
export type Notice = ImportNotice | DepositMaturityNotice;

export interface NotificationFeed {
    notices: Notice[];
    unread: number;
}

const IMPORT_NOTICE_TYPE = 'statement-import';
const DEPOSIT_NOTICE_TYPE = 'deposit-maturity';

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
function parseImportNotice(raw: unknown): ImportNotice | null {
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
        type: IMPORT_NOTICE_TYPE,
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

function parseDepositNotice(raw: unknown): DepositMaturityNotice | null {
    const row = obj(raw);
    const data = 'data' in row ? obj(row.data) : row;
    const id = asString(row.id);
    const subjectId = asString(data.subject_id);
    const accountId = asString(data.account_id);

    if (row.type !== DEPOSIT_NOTICE_TYPE || id === null || subjectId === null || accountId === null) {
        return null;
    }

    return {
        id,
        type: DEPOSIT_NOTICE_TYPE,
        subjectId,
        accountId,
        daysBefore: asCount(data.days_before),
        maturityAt: asString(data.maturity_at),
        read: asString(row.read_at) !== null,
        createdAt: asString(row.created_at),
    };
}

/** One notice from the feed or the person's channel, whichever kind it is — or null for one this build cannot read. */
export function parseNotice(raw: unknown): Notice | null {
    return parseImportNotice(raw) ?? parseDepositNotice(raw);
}

export function parseNotificationFeed(raw: unknown): NotificationFeed {
    const body = obj(raw);
    const rows = Array.isArray(body.notifications) ? body.notifications : [];

    return {
        notices: rows.map(parseNotice).filter((notice): notice is Notice => notice !== null),
        unread: asCount(body.unread),
    };
}
