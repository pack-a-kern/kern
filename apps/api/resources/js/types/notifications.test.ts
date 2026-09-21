import { describe, expect, it } from 'vitest';
import { parseImportNotice, parseNotificationFeed } from './notifications';

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

describe('parseImportNotice', () => {
    it('reads a stored notice, whose fields sit under data', () => {
        const notice = parseImportNotice({
            id: 'n-1',
            type: 'statement-import',
            data,
            read_at: null,
            created_at: '2026-09-21T10:00:00+00:00',
        });

        expect(notice).toMatchObject({ id: 'n-1', kind: 'IMPORTED', importId: 'import-1', importedCount: 10, read: false });
    });

    it('reads a broadcast notice, whose fields sit beside the id', () => {
        expect(parseImportNotice({ id: 'n-2', type: 'statement-import', ...data })).toMatchObject({
            id: 'n-2',
            subjectId: 'subject-1',
            createdAt: null,
        });
    });

    it('drops a notice of a kind or type it does not know', () => {
        expect(parseImportNotice({ id: 'n-3', type: 'statement-import', ...data, kind: 'SOMETHING_NEW' })).toBeNull();
        expect(parseImportNotice({ id: 'n-4', type: 'other', ...data })).toBeNull();
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
