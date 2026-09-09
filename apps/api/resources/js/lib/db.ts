import type { DocumentStatus, DocumentType, RejectionReason } from '../types/documents';

/**
 * The device-local IndexedDB store backing the offline capture queue and the document inbox
 * (`BLUEPRINT.md` §17: offline capture queue and non-sensitive draft metadata are the only things
 * permitted client-side; CNP, SPV credentials, bank transactions, OCR text and decrypted documents
 * are not — and nothing here stores any of those).
 *
 * Two object stores:
 *
 * - `captureQueue` — a document picked/photographed on this device but not yet confirmed uploaded.
 *   Holds the actual file bytes (`Blob`) only until the upload succeeds, at which point the row is
 *   deleted — this is a transient upload buffer, not a document archive.
 * - `knownDocuments` — the exact fields `App\Http\Controllers\Api\V1\Documents\
 *   ShowDocumentController` returns (id/type/status/rejection_reason/mime_type/size_bytes/
 *   received_at), cached so the inbox has something to show instantly and offline. No original
 *   filename, no OCR text, no extracted content is ever written here — see
 *   `docs/handoff/030-pwa-frontend.md` for why the inbox is scoped to "documents this device
 *   knows about" (no list-documents endpoint exists yet).
 */

const DB_NAME = 'taxos-pwa';
const DB_VERSION = 1;
const CAPTURE_STORE = 'captureQueue';
const DOCUMENTS_STORE = 'knownDocuments';

/**
 * `failed` is transient (network error, server unavailable) and is retried automatically the next
 * time the queue processes. `rejected` is permanent — `App\Domain\Documents\FileSecurity\
 * FileSecurityValidator` returned a `422` with a closed `RejectionReason` for *this exact file*,
 * which will fail identically on every retry — so it is never retried automatically, only shown to
 * the user with the reason, to be removed or replaced with a different file.
 */
export type CaptureStatus = 'queued' | 'uploading' | 'uploaded' | 'failed' | 'rejected';

export interface CaptureItem {
    localId: string;
    subjectId: string;
    type: DocumentType;
    fileName: string;
    mimeType: string;
    sizeBytes: number;
    blob: Blob;
    status: CaptureStatus;
    attempts: number;
    errorMessage: string | null;
    rejectionReason: RejectionReason | null;
    remoteDocumentId: string | null;
    createdAt: number;
}

export interface KnownDocument {
    id: string;
    subjectId: string;
    type: DocumentType;
    status: DocumentStatus;
    rejectionReason: RejectionReason | null;
    mimeType: string;
    sizeBytes: number;
    receivedAt: string;
    lastSyncedAt: number;
}

let dbPromise: Promise<IDBDatabase> | null = null;

function openDb(): Promise<IDBDatabase> {
    dbPromise ??= new Promise((resolve, reject) => {
        const request = window.indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = (): void => {
            const db = request.result;

            if (!db.objectStoreNames.contains(CAPTURE_STORE)) {
                const store = db.createObjectStore(CAPTURE_STORE, { keyPath: 'localId' });
                store.createIndex('bySubject', 'subjectId');
                store.createIndex('byStatus', 'status');
            }

            if (!db.objectStoreNames.contains(DOCUMENTS_STORE)) {
                const store = db.createObjectStore(DOCUMENTS_STORE, { keyPath: 'id' });
                store.createIndex('bySubject', 'subjectId');
            }
        };

        request.onsuccess = (): void => {
            resolve(request.result);
        };
        request.onerror = (): void => {
            reject(new Error(request.error?.message ?? 'Failed to open IndexedDB'));
        };
    });

    return dbPromise;
}

function promisifyRequest<T>(request: IDBRequest<T>): Promise<T> {
    return new Promise((resolve, reject) => {
        request.onsuccess = (): void => {
            resolve(request.result);
        };
        request.onerror = (): void => {
            reject(new Error(request.error?.message ?? 'IndexedDB request failed'));
        };
    });
}

async function withStore<T>(
    storeName: string,
    mode: IDBTransactionMode,
    fn: (store: IDBObjectStore) => IDBRequest<T>,
): Promise<T> {
    const db = await openDb();
    const tx = db.transaction(storeName, mode);
    const store = tx.objectStore(storeName);

    return promisifyRequest(fn(store));
}

export async function addCaptureItem(item: CaptureItem): Promise<void> {
    await withStore(CAPTURE_STORE, 'readwrite', (store) => store.put(item));
}

export async function updateCaptureItem(localId: string, patch: Partial<CaptureItem>): Promise<void> {
    const db = await openDb();
    const tx = db.transaction(CAPTURE_STORE, 'readwrite');
    const store = tx.objectStore(CAPTURE_STORE);
    const existing = await promisifyRequest(store.get(localId) as IDBRequest<CaptureItem | undefined>);

    if (!existing) {
        return;
    }

    await promisifyRequest(store.put({ ...existing, ...patch }));
}

export async function deleteCaptureItem(localId: string): Promise<void> {
    await withStore(CAPTURE_STORE, 'readwrite', (store) => store.delete(localId));
}

export async function listCaptureItems(subjectId: string): Promise<CaptureItem[]> {
    const db = await openDb();
    const tx = db.transaction(CAPTURE_STORE, 'readonly');
    const index = tx.objectStore(CAPTURE_STORE).index('bySubject');
    const items = await promisifyRequest(index.getAll(subjectId) as IDBRequest<CaptureItem[]>);

    return items.sort((a, b) => a.createdAt - b.createdAt);
}

export async function upsertKnownDocument(document: KnownDocument): Promise<void> {
    await withStore(DOCUMENTS_STORE, 'readwrite', (store) => store.put(document));
}

export async function listKnownDocuments(subjectId: string): Promise<KnownDocument[]> {
    const db = await openDb();
    const tx = db.transaction(DOCUMENTS_STORE, 'readonly');
    const index = tx.objectStore(DOCUMENTS_STORE).index('bySubject');
    const items = await promisifyRequest(index.getAll(subjectId) as IDBRequest<KnownDocument[]>);

    return items.sort((a, b) => (a.receivedAt < b.receivedAt ? 1 : -1));
}

export function generateLocalId(): string {
    return typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : `local-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
}
