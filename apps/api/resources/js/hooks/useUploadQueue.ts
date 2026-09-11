import { useCallback, useEffect, useRef, useState } from 'react';
import { DocumentDuplicateError, DocumentRejectedError, uploadDocument } from '../api/documentsApi';
import { ApiError } from '../lib/http';
import { compressImageIfNeeded } from '../lib/imageCompression';
import {
    addCaptureItem,
    deleteCaptureItem,
    generateLocalId,
    listCaptureItems,
    updateCaptureItem,
    upsertKnownDocument,
    type CaptureItem,
} from '../lib/db';
import { REJECTION_REASONS, type DocumentType, type RejectionReason } from '../types/documents';
import { useOnlineStatus } from './useOnlineStatus';

function isKnownRejectionReason(value: string): value is RejectionReason {
    return (REJECTION_REASONS as readonly string[]).includes(value);
}

/**
 * The offline capture queue (`BLUEPRINT.md` §1.4: "resume upload", "offline capture queue",
 * "background synchronization when the browser permite"). Every picked/photographed file is
 * written to IndexedDB immediately (`resources/js/lib/db.ts`) — this hook only drives *uploading*
 * what is already durably queued, so an app close, a tab crash, or a lost network connection mid
 * upload never loses the file: the next time this hook mounts with the same subject, the item is
 * still there with `status: 'queued'` or `'uploading'` (an interrupted upload is retried from
 * scratch — see `docs/handoff/030-pwa-frontend.md`'s "What is NOT done" for why this is a
 * whole-file retry, not a byte-range resume: the upload endpoint is a single multipart `POST` with
 * no `Content-Range`/chunking support on the server).
 *
 * Retries automatically when the browser comes back online, and once right after enqueueing.
 * `App\Domain\Documents\Enums\RejectionReason` failures (a `422` naming exactly why this file is
 * bad) are never retried automatically — see `resources/js/lib/db.ts`'s `CaptureStatus` docblock.
 */
export function useUploadQueue(
    subjectId: string | null,
    /**
     * Called once per document that reaches the server.
     *
     * The list behind this queue needs to know, and the `DocumentUploaded` broadcast is not a
     * reliable substitute: this is the one refresh signal that still arrives when Reverb is
     * unreachable, which is exactly when a person most needs the screen to be right.
     */
    onUploaded?: () => void,
): {
    items: CaptureItem[];
    enqueue: (type: DocumentType, file: File) => Promise<void>;
    retry: (localId: string) => Promise<void>;
    remove: (localId: string) => Promise<void>;
    processing: boolean;
    /** Upload fraction in `[0, 1]` per `localId`, present only while that item is uploading. */
    progress: Record<string, number>;
} {
    const [items, setItems] = useState<CaptureItem[]>([]);
    const [processing, setProcessing] = useState(false);
    /** Upload fraction per queued item, in memory only — see the `onProgress` note below. */
    const [progress, setProgress] = useState<Record<string, number>>({});
    const online = useOnlineStatus();
    const processingRef = useRef(false);
    // Held in a ref so a caller may pass an inline function without rebuilding `processQueue` — and
    // with it the effect that drives the queue — on every render.
    const onUploadedRef = useRef(onUploaded);

    useEffect(() => {
        onUploadedRef.current = onUploaded;
    }, [onUploaded]);

    const refresh = useCallback(async () => {
        if (!subjectId) {
            setItems([]);

            return;
        }

        setItems(await listCaptureItems(subjectId));
    }, [subjectId]);

    useEffect(() => {
        void (async () => {
            await refresh();
        })();
    }, [refresh]);

    const processQueue = useCallback(async () => {
        if (!subjectId || processingRef.current) {
            return;
        }

        processingRef.current = true;
        setProcessing(true);

        // Attempted in *this* run, so a fresh pass never re-tries something that just failed —
        // which would turn the drain loop below into an unbounded retry against a failing server.
        const attempted = new Set<string>();
        let wentOffline = false;

        try {
            // Drains until there is nothing left, rather than working from a single snapshot.
            //
            // Picking several files calls `enqueue` once per file, and the first call starts this
            // function; the rest were being written to IndexedDB *after* the list above had already
            // been read, and the `processingRef` guard turned their own `processQueue()` calls into
            // no-ops. So file one uploaded and the rest sat at `queued` forever — until the dialog
            // was reopened, which remounted the hook and started a run that could see them.
            for (;;) {
                const pending = (await listCaptureItems(subjectId)).filter(
                    (item) =>
                        (item.status === 'queued' || item.status === 'failed') && !attempted.has(item.localId),
                );

                if (pending.length === 0) {
                    break;
                }

                for (const item of pending) {
                    if (!navigator.onLine) {
                        wentOffline = true;

                        break;
                    }

                    attempted.add(item.localId);

                    await updateCaptureItem(item.localId, { status: 'uploading' });
                    await refresh();

                    try {
                        const uploaded = await uploadDocument(
                            subjectId,
                            item.type,
                            item.blob,
                            item.fileName,
                            (fraction) => {
                                // Kept in memory, never in IndexedDB: a write per progress event
                                // would be hundreds of transactions for one file, to persist a
                                // number that is meaningless the moment the upload ends.
                                setProgress((current) => ({ ...current, [item.localId]: fraction }));
                            },
                        );

                        await upsertKnownDocument({
                            id: uploaded.id,
                            subjectId,
                            type: item.type,
                            status: uploaded.status,
                            rejectionReason: null,
                            mimeType: item.mimeType,
                            sizeBytes: item.sizeBytes,
                            receivedAt: new Date().toISOString(),
                            lastSyncedAt: Date.now(),
                        });
                        await deleteCaptureItem(item.localId);
                        onUploadedRef.current?.();
                    } catch (error) {
                        if (error instanceof DocumentRejectedError) {
                            const reason = isKnownRejectionReason(error.reason) ? error.reason : null;

                            await updateCaptureItem(item.localId, {
                                status: 'rejected',
                                rejectionReason: reason,
                                errorMessage: reason ? null : error.message,
                            });
                        } else if (error instanceof DocumentDuplicateError) {
                            // Already held, byte for byte. Never retried — retrying is what causes
                            // it — and the message is the screen's own, so it stays translated.
                            await updateCaptureItem(item.localId, {
                                status: 'duplicate',
                                errorMessage: null,
                            });
                        } else {
                            // Never persist user-facing copy here (BLUEPRINT.md §19.1): an `ApiError`
                            // from the server carries the server's own localised message, but a
                            // transport failure (`status === 0`) carries only a developer string. That
                            // one is dropped so the screen renders its own translated fallback — which
                            // also keeps a queued item correct if the locale changes before it retries.
                            const message = error instanceof ApiError && error.status !== 0 ? error.message : null;

                            await updateCaptureItem(item.localId, {
                                status: 'failed',
                                attempts: item.attempts + 1,
                                errorMessage: message,
                            });
                        }
                    } finally {
                        setProgress((current) =>
                            item.localId in current
                                ? Object.fromEntries(
                                      Object.entries(current).filter(([key]) => key !== item.localId),
                                  )
                                : current,
                        );
                    }

                    await refresh();
                }

                if (wentOffline) {
                    break;
                }
            }
        } finally {
            processingRef.current = false;
            setProcessing(false);
        }
    }, [subjectId, refresh]);

    useEffect(() => {
        if (online) {
            void processQueue();
        }
    }, [online, processQueue]);

    const enqueue = useCallback(
        async (type: DocumentType, file: File) => {
            if (!subjectId) {
                // A developer message, deliberately not Romanian copy: no screen renders this
                // string — every caller already refuses to show the picker without an active
                // subject, so reaching here is a bug, not a state a person can get into.
                throw new Error('useUploadQueue.enqueue called with no active subject.');
            }

            const prepared = await compressImageIfNeeded(file);

            const item: CaptureItem = {
                localId: generateLocalId(),
                subjectId,
                type,
                fileName: prepared.name,
                mimeType: prepared.type,
                sizeBytes: prepared.size,
                blob: prepared,
                status: 'queued',
                attempts: 0,
                errorMessage: null,
                rejectionReason: null,
                remoteDocumentId: null,
                createdAt: Date.now(),
            };

            await addCaptureItem(item);
            await refresh();
            void processQueue();
        },
        [subjectId, refresh, processQueue],
    );

    const retry = useCallback(
        async (localId: string) => {
            await updateCaptureItem(localId, { status: 'queued', errorMessage: null });
            await refresh();
            void processQueue();
        },
        [refresh, processQueue],
    );

    const remove = useCallback(
        async (localId: string) => {
            await deleteCaptureItem(localId);
            await refresh();
        },
        [refresh],
    );

    return { items, enqueue, retry, remove, processing, progress };
}
