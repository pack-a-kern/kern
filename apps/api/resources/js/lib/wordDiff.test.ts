import { describe, expect, it } from 'vitest';
import { wordDiff } from './wordDiff';

describe('wordDiff', () => {
    it('marks only the words that changed', () => {
        expect(wordDiff('baza este de 6 salarii minime', 'baza este de 12 salarii minime')).toEqual([
            { kind: 'same', text: 'baza este de ' },
            { kind: 'removed', text: '6 ' },
            { kind: 'added', text: '12 ' },
            { kind: 'same', text: 'salarii minime' },
        ]);
    });

    it('shows an insertion as the inserted words alone', () => {
        const parts = wordDiff('venituri în bani', 'venituri în bani sau în natură');

        expect(parts.filter((part) => part.kind !== 'same').map((part) => part.text.trim())).toEqual(['sau în natură']);
    });

    it('joins back into the texts it compared', () => {
        const before = 'Art. 170 (1) text vechi';
        const after = 'Art. 170 (1) text nou, modificat';
        const parts = wordDiff(before, after);

        expect(
            parts
                .filter((part) => part.kind !== 'added')
                .map((part) => part.text)
                .join(''),
        ).toBe(before);
        expect(
            parts
                .filter((part) => part.kind !== 'removed')
                .map((part) => part.text)
                .join(''),
        ).toBe(after);
    });

    it('reports nothing changed for identical texts', () => {
        expect(wordDiff('același text', 'același text')).toEqual([{ kind: 'same', text: 'același text' }]);
    });
});
