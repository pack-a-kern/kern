import { describe, expect, it } from 'vitest';

import { takeSelectedFiles } from '../fileInput';

/**
 * A file input that behaves the way a real browser's does: assigning `value` empties the live
 * `files` list. jsdom does not model that, so it is modelled here — without it the test would pass
 * against the very ordering that shipped the bug.
 */
function inputWithLiveFileList(files: File[]): HTMLInputElement {
    const live = [...files];

    return {
        get files() {
            return live as unknown as FileList;
        },
        set value(_next: string) {
            live.length = 0;
        },
        get value() {
            return '';
        },
    } as unknown as HTMLInputElement;
}

describe('takeSelectedFiles', () => {
    it('returns the selection even though resetting the input empties the live list', () => {
        const file = new File(['%PDF-1.4'], 'bon.pdf', { type: 'application/pdf' });
        const input = inputWithLiveFileList([file]);

        const taken = takeSelectedFiles(input);

        // Reading after the reset — the original bug — would return an empty array here, and the
        // page would silently queue nothing.
        expect(taken).toHaveLength(1);
        expect(taken[0]?.name).toBe('bon.pdf');
    });

    it('keeps every file of a multi-file selection', () => {
        const input = inputWithLiveFileList([
            new File(['a'], 'a.jpg', { type: 'image/jpeg' }),
            new File(['b'], 'b.jpg', { type: 'image/jpeg' }),
            new File(['c'], 'c.jpg', { type: 'image/jpeg' }),
        ]);

        expect(takeSelectedFiles(input).map((file) => file.name)).toEqual(['a.jpg', 'b.jpg', 'c.jpg']);
    });

    it('resets the input, so picking the same file again still fires a change event', () => {
        const input = inputWithLiveFileList([new File(['a'], 'a.jpg', { type: 'image/jpeg' })]);

        takeSelectedFiles(input);

        expect(input.files).toHaveLength(0);
    });

    it('is an empty array when the picker was dismissed', () => {
        expect(takeSelectedFiles(inputWithLiveFileList([]))).toEqual([]);
    });
});
