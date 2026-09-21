/**
 * What changed between two versions of a provision, word by word.
 *
 * A longest-common-subsequence over words, so an inserted "sau în natură" shows as three added words
 * rather than the rest of the paragraph shifted. Whitespace is kept with the word before it, so the
 * pieces join back into the text as written. Two very long texts are not diffed — the table would be
 * tens of millions of cells — and come back as one removal and one addition, which is still true.
 */

export type DiffPart = { kind: 'same' | 'added' | 'removed'; text: string };

/** Beyond this many word pairs the comparison is not attempted. */
const MAX_CELLS = 4_000_000;

function words(text: string): string[] {
    return text.match(/\S+\s*/gu) ?? [];
}

function push(parts: DiffPart[], kind: DiffPart['kind'], text: string): void {
    const last = parts[parts.length - 1];

    if (last !== undefined && last.kind === kind) {
        last.text += text;
    } else {
        parts.push({ kind, text });
    }
}

export function wordDiff(before: string, after: string): DiffPart[] {
    const a = words(before);
    const b = words(after);

    if (a.length * b.length > MAX_CELLS) {
        return [
            ...(before === '' ? [] : [{ kind: 'removed' as const, text: before }]),
            ...(after === '' ? [] : [{ kind: 'added' as const, text: after }]),
        ];
    }

    const key = (word: string): string => word.trim();
    const width = b.length + 1;
    // lengths[i * width + j]: the common run of a[i..] and b[j..], filled from the end.
    const lengths = new Uint32Array((a.length + 1) * width);
    const at = (i: number, j: number): number => lengths[i * width + j] ?? 0;
    const same = (i: number, j: number): boolean => key(a[i] ?? '') === key(b[j] ?? '');

    for (let i = a.length - 1; i >= 0; i--) {
        for (let j = b.length - 1; j >= 0; j--) {
            lengths[i * width + j] = same(i, j) ? at(i + 1, j + 1) + 1 : Math.max(at(i + 1, j), at(i, j + 1));
        }
    }

    const parts: DiffPart[] = [];
    let i = 0;
    let j = 0;

    while (i < a.length && j < b.length) {
        if (same(i, j)) {
            push(parts, 'same', b[j] ?? '');
            i++;
            j++;
        } else if (at(i + 1, j) >= at(i, j + 1)) {
            push(parts, 'removed', a[i] ?? '');
            i++;
        } else {
            push(parts, 'added', b[j] ?? '');
            j++;
        }
    }

    for (; i < a.length; i++) {
        push(parts, 'removed', a[i] ?? '');
    }

    for (; j < b.length; j++) {
        push(parts, 'added', b[j] ?? '');
    }

    return parts;
}
