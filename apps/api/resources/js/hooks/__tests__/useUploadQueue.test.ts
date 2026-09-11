import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const uploadDocument = vi.fn();

vi.mock('../../api/documentsApi', () => ({
    uploadDocument: (...args: unknown[]) => uploadDocument(...args) as unknown,
    DocumentRejectedError: class DocumentRejectedError extends Error {},
    DocumentDuplicateError: class DocumentDuplicateError extends Error {},
}));

// Image compression is a browser-canvas path with nothing to say about queue draining, and jsdom
// has no canvas — pass the file through untouched.
vi.mock('../../lib/imageCompression', () => ({
    compressImageIfNeeded: (file: File) => Promise.resolve(file),
}));

const { useUploadQueue } = await import('../useUploadQueue');
const { listCaptureItems } = await import('../../lib/db');

function file(name: string): File {
    return new File(['content-of-' + name], name, { type: 'image/jpeg' });
}

describe('useUploadQueue', () => {
    beforeEach(() => {
        uploadDocument.mockReset();
        uploadDocument.mockImplementation(() =>
            // A real upload does not resolve in the same tick, which is the whole point: the queue
            // must still be running when the later files are written to IndexedDB.
            new Promise((resolve) => {
                setTimeout(() => {
                    resolve({ id: crypto.randomUUID(), status: 'QUARANTINE' });
                }, 5);
            }),
        );
    });

    it('uploads every file of a multi-file pick, not just the first', async () => {
        const subjectId = crypto.randomUUID();
        const { result } = renderHook(() => useUploadQueue(subjectId));

        // Exactly what `AddDocumentDialog.handleFiles` does: enqueue one after another. The first
        // call starts the queue; the rest land while it is already running.
        await act(async () => {
            for (const name of ['a.jpg', 'b.jpg', 'c.jpg']) {
                await result.current.enqueue('RECEIPT', file(name));
            }
        });

        await waitFor(() => {
            expect(uploadDocument).toHaveBeenCalledTimes(3);
        });

        // Uploaded items are deleted from IndexedDB, so an empty queue is the success condition.
        await waitFor(async () => {
            expect(await listCaptureItems(subjectId)).toHaveLength(0);
        });
    });

    it('does not retry a failing upload over and over within one run', async () => {
        const subjectId = crypto.randomUUID();
        uploadDocument.mockRejectedValue(new Error('network down'));

        const { result } = renderHook(() => useUploadQueue(subjectId));

        await act(async () => {
            await result.current.enqueue('RECEIPT', file('a.jpg'));
        });

        // The drain loop re-reads the queue until it is empty. A failed item stays in it, so
        // without the per-run guard this would spin against the server forever.
        await waitFor(() => {
            expect(uploadDocument).toHaveBeenCalledTimes(1);
        });

        const items = await listCaptureItems(subjectId);
        expect(items).toHaveLength(1);
        expect(items[0]?.status).toBe('failed');
    });
});
