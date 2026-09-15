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
const DB_VERSION = 2;
const CAPTURE_STORE = 'captureQueue';
const DOCUMENTS_STORE = 'knownDocuments';

/**
 * `failed` is transient (network error, server unavailable) and is retried automatically the next
 * time the queue processes. `rejected` is permanent — `App\Domain\Documents\FileSecurity\
 * FileSecurityValidator` returned a `422` with a closed `RejectionReason` for *this exact file*,
 * which will fail identically on every retry — so it is never retried automatically, only shown to
 * the user with the reason, to be removed or replaced with a different file.
 *
 * `duplicate` is permanent too, but for the opposite reason: nothing is wrong with the file — the
 * subject simply already holds these exact bytes (`documents.sha256`). Retrying is precisely what
 * caused it, so it is never retried; `remoteDocumentId` points at the copy already stored.
 */
export type CaptureStatus = 'queued' | 'uploading' | 'uploaded' | 'failed' | 'rejected' | 'duplicate';

/** One file of a queued document, held here until it reaches the server. */
export interface CaptureFile {
    fileName: string;
    mimeType: string;
    sizeBytes: number;
    blob: Blob;
}

export interface CaptureItem {
    localId: string;
    subjectId: string;
    /**
     * The files this document is made of, in page order — one for almost every document, several
     * when an invoice was photographed page by page.
     *
     * A queued *document*, not a queued file, because that is what gets uploaded: sending four
     * photographs as four documents and merging them afterwards would mean four extractions to
     * throw away, and a window in which the person sees four rows appear and disappear.
     */
    files: CaptureFile[];
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
    /** Null until something has read the document and said what it is. */
    type: DocumentType | null;
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

        request.onupgradeneeded = (event: IDBVersionChangeEvent): void => {
            const db = request.result;

            if (!db.objectStoreNames.contains(CAPTURE_STORE)) {
                const store = db.createObjectStore(CAPTURE_STORE, { keyPath: 'localId' });
                store.createIndex('bySubject', 'subjectId');
                store.createIndex('byStatus', 'status');
            } else if (event.oldVersion < 2) {
                migrateCaptureItemsToFiles(request.transaction);
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

/**
 * v1 queued one file per item; v2 queues a document made of files.
 *
 * Rewritten in place rather than dropped, because the whole point of this store is that a file
 * photographed on a train survives the app being closed — discarding the queue on upgrade would
 * lose exactly the documents it exists to protect. An item whose shape is already v2, or is too
 * damaged to read, is left alone: the queue screen shows it and the person can remove it.
 */
function migrateCaptureItemsToFiles(transaction: IDBTransaction | null): void {
    if (transaction === null) {
        return;
    }

    const store = transaction.objectStore(CAPTURE_STORE);
    const cursorRequest = store.openCursor();

    cursorRequest.onsuccess = (): void => {
        const cursor = cursorRequest.result;

        if (!cursor) {
            return;
        }

        const legacy = cursor.value as Partial<CaptureItem> & Partial<CaptureFile>;

        if (!Array.isArray(legacy.files) && legacy.blob instanceof Blob) {
            const { fileName, mimeType, sizeBytes, blob, ...rest } = legacy;

            cursor.update({
                ...rest,
                files: [
                    {
                        fileName: fileName ?? '',
                        mimeType: mimeType ?? blob.type,
                        sizeBytes: sizeBytes ?? blob.size,
                        blob,
                    },
                ],
            });
        }

        cursor.continue();
    };
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

/**
 * Replace this subject's cached documents with what the server just said.
 *
 * The cache only ever grew: an upload added a row and nothing removed it, so a document deleted on
 * the server stayed on the device forever — and the moment the list request failed, the inbox fell
 * back to the cache and showed documents that no longer exist, indistinguishable from ones that do.
 *
 * Replacing rather than merging is the point. The server's list *is* the answer; anything this
 * device still holds that is not in it is gone, and keeping it would be the device insisting on a
 * past the server has moved on from.
 *
 * Scoped to one subject, and only called with a *complete* list — a filtered or paginated view must
 * never be written here, or it would delete every document the current filter happens to exclude.
 */
export async function replaceKnownDocuments(subjectId: string, documents: KnownDocument[]): Promise<void> {
    const db = await openDb();
    const tx = db.transaction(DOCUMENTS_STORE, 'readwrite');
    const store = tx.objectStore(DOCUMENTS_STORE);
    const existing = await promisifyRequest(store.index('bySubject').getAllKeys(subjectId));
    const keep = new Set(documents.map((document) => document.id));

    for (const key of existing) {
        if (typeof key === 'string' && ! keep.has(key)) {
            store.delete(key);
        }
    }

    for (const document of documents) {
        store.put(document);
    }
}

/** Forget one document, when it is deleted. */
export async function deleteKnownDocument(id: string): Promise<void> {
    await withStore(DOCUMENTS_STORE, 'readwrite', (store) => store.delete(id));
}

export function generateLocalId(): string {
    return typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : `local-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
}
