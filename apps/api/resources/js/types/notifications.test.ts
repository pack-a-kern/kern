import { describe, expect, it } from 'vitest';
import { parseNotice, parseNotificationFeed } from './notifications';

const data = {
    kind: 'IMPORTED',
    subject_id: 'subject-1',
    import_id: 'import-1',
    status: 'IMPORTED',
    row_count: 12,
    imported_count: 10,
    rows_suggested: 0,
    failure_reason: null,
};

describe('parseNotice', () => {
    it('reads a stored notice, whose fields sit under data', () => {
        const notice = parseNotice({
            id: 'n-1',
            type: 'statement-import',
            data,
            read_at: null,
            created_at: '2026-09-21T10:00:00+00:00',
        });

        expect(notice).toMatchObject({
            id: 'n-1',
            kind: 'IMPORTED',
            importId: 'import-1',
            importedCount: 10,
            read: false,
        });
    });

    it('reads a broadcast notice, whose fields sit beside the id', () => {
        expect(parseNotice({ id: 'n-2', type: 'statement-import', ...data })).toMatchObject({
            id: 'n-2',
            subjectId: 'subject-1',
            createdAt: null,
        });
    });

    it('drops a notice of a kind or type it does not know', () => {
        expect(parseNotice({ id: 'n-3', type: 'statement-import', ...data, kind: 'SOMETHING_NEW' })).toBeNull();
        expect(parseNotice({ id: 'n-4', type: 'other', ...data })).toBeNull();
    });
});

describe('parseNotificationFeed', () => {
    it('keeps the server count of unread notices', () => {
        const feed = parseNotificationFeed({
            notifications: [{ id: 'n-1', type: 'statement-import', data, read_at: '2026-09-21T10:00:00+00:00' }],
            unread: 3,
        });

        expect(feed.unread).toBe(3);
        expect(feed.notices[0]?.read).toBe(true);
    });
});

describe('a deposit maturity notice', () => {
    it('is read with its deposit, its distance and its date — and nothing more', () => {
        expect(
            parseNotice({
                id: 'n-9',
                type: 'deposit-maturity',
                read_at: null,
                created_at: '2026-11-05T09:00:00Z',
                data: { subject_id: 's-1', account_id: 'a-1', days_before: 7, maturity_at: '2026-11-12' },
            }),
        ).toEqual({
            id: 'n-9',
            type: 'deposit-maturity',
            subjectId: 's-1',
            accountId: 'a-1',
            daysBefore: 7,
            maturityAt: '2026-11-12',
            read: false,
            createdAt: '2026-11-05T09:00:00Z',
        });
    });

    it('is left out when it names no deposit', () => {
        expect(parseNotice({ id: 'n-10', type: 'deposit-maturity', data: { subject_id: 's-1' } })).toBeNull();
    });
});
