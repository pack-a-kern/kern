import { describe, expect, it } from 'vitest';
import {
    addCaptureItem,
    deleteCaptureItem,
    generateLocalId,
    listCaptureItems,
    listKnownDocuments,
    updateCaptureItem,
    upsertKnownDocument,
} from './db';
import type { CaptureItem, KnownDocument } from './db';

// `fake-indexeddb/auto` (loaded in resources/js/test/setup.ts) provides a real, in-memory
// IndexedDB implementation — this exercises the actual `indexedDB.open`/transaction/index code
// paths in `db.ts`, not a mock of them. Each test uses its own generated subject id so tests never
// see each other's rows in the same (module-singleton) database connection.

function makeItem(subjectId: string, overrides: Partial<CaptureItem> = {}): CaptureItem {
    return {
        localId: generateLocalId(),
        subjectId,
        files: [{ fileName: 'bon.jpg', mimeType: 'image/jpeg', sizeBytes: 1024, blob: new Blob(['x']) }],
        status: 'queued',
        attempts: 0,
        errorMessage: null,
        rejectionReason: null,
        remoteDocumentId: null,
        createdAt: Date.now(),
        ...overrides,
    };
}

describe('capture queue store', () => {
    it('adds and lists an item scoped to its subject', async () => {
        const subjectId = generateLocalId();
        const item = makeItem(subjectId);
        await addCaptureItem(item);

        const items = await listCaptureItems(subjectId);
        expect(items).toHaveLength(1);
        expect(items[0]?.localId).toBe(item.localId);

        expect(await listCaptureItems(generateLocalId())).toHaveLength(0);
    });

    it('updates fields on an existing item without clobbering the rest', async () => {
        const subjectId = generateLocalId();
        const item = makeItem(subjectId);
        await addCaptureItem(item);

        await updateCaptureItem(item.localId, { status: 'failed', errorMessage: 'boom' });

        const [updated] = await listCaptureItems(subjectId);
        expect(updated?.status).toBe('failed');
        expect(updated?.errorMessage).toBe('boom');
        expect(updated?.files[0]?.fileName).toBe('bon.jpg');
    });

    it('deletes an item', async () => {
        const subjectId = generateLocalId();
        const item = makeItem(subjectId);
        await addCaptureItem(item);
        await deleteCaptureItem(item.localId);

        expect(await listCaptureItems(subjectId)).toHaveLength(0);
    });

    it('orders items oldest-first', async () => {
        const subjectId = generateLocalId();
        const first = makeItem(subjectId, { createdAt: 100 });
        const second = makeItem(subjectId, { createdAt: 200 });
        await addCaptureItem(second);
        await addCaptureItem(first);

        const items = await listCaptureItems(subjectId);
        expect(items.map((entry) => entry.createdAt)).toEqual([100, 200]);
    });
});

describe('known documents store', () => {
    it('upserts and lists documents newest-first', async () => {
        const subjectId = generateLocalId();
        const older: KnownDocument = {
            id: generateLocalId(),
            subjectId,
            type: 'RECEIPT',
            status: 'READY',
            rejectionReason: null,
            mimeType: 'application/pdf',
            sizeBytes: 2048,
            receivedAt: '2026-01-01T00:00:00Z',
            lastSyncedAt: Date.now(),
        };
        const newer: KnownDocument = { ...older, id: generateLocalId(), receivedAt: '2026-02-01T00:00:00Z' };

        await upsertKnownDocument(older);
        await upsertKnownDocument(newer);

        const documents = await listKnownDocuments(subjectId);
        expect(documents.map((doc) => doc.id)).toEqual([newer.id, older.id]);
    });
});

describe('generateLocalId', () => {
    it('produces unique ids', () => {
        const ids = new Set(Array.from({ length: 20 }, () => generateLocalId()));
        expect(ids.size).toBe(20);
    });
});
