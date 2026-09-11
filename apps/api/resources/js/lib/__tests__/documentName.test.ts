import { describe, expect, it } from 'vitest';
import { suggestDocumentName } from '../documentName';

describe('suggestDocumentName', () => {
    it('builds a name from the type, the merchant and the date', () => {
        expect(
            suggestDocumentName('Bon fiscal', { merchant: 'KAUFLAND ROMANIA SCS', date: '2026-03-14' }, 'a1b2c3.png'),
        ).toBe('bon-fiscal-kaufland-romania-scs-2026-03-14.png');
    });

    it('keeps Romanian diacritics readable instead of stripping them away', () => {
        // Naively removing non-ASCII would turn "Chirie Măgurele" into "chirie-gurele".
        expect(suggestDocumentName('Contract', { counterparty: 'Chirie Măgurele', date: '2026-01-05' }, 'x.pdf')).toBe(
            'contract-chirie-magurele-2026-01-05.pdf',
        );
    });

    it('falls back through the fields it knows about', () => {
        expect(suggestDocumentName('Factură', { issuer: 'eMAG', issue_date: '2026-07-09' }, 'x.pdf')).toBe(
            'factura-emag-2026-07-09.pdf',
        );
    });

    it('suggests nothing when nothing identifying was extracted', () => {
        // "bon-fiscal.png" on every receipt distinguishes no better than the hash it would replace.
        expect(suggestDocumentName('Bon fiscal', { amount: '44.89' }, 'a1b2c3.png')).toBeNull();
    });

    it('keeps the extension of the name it replaces', () => {
        expect(suggestDocumentName('Bon', { merchant: 'Lidl' }, 'deadbeefdeadbeef.jpg')).toBe('bon-lidl.jpg');
    });

    it('never ends a name with a separator when it has to truncate', () => {
        const name = suggestDocumentName('Contract', { counterparty: 'A'.repeat(200) }, 'x.pdf');

        expect(name).not.toBeNull();
        expect(name).not.toMatch(/-\.pdf$/);
    });
});
