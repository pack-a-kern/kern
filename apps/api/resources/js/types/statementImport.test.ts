import { describe, expect, it } from 'vitest';

import { parseStatementImportDetail } from './statementImport';

/**
 * The mapping a person confirmed, read back.
 *
 * Every field of the form has travelled the same road three times — validated on the way in, stored,
 * and parsed on the way out — and a key missing from any one of the three is a choice that looks
 * like it was never made. The reference's second place was dropped twice that way, once by the
 * request validator and once here, so it is the one asserted.
 */
describe('parseStatementImportDetail', () => {
    const body = (mapping: unknown): string =>
        JSON.stringify({
            import: { id: 'imp-1', account_id: 'acc-1', status: 'MAPPING', created_at: '2026-03-05T10:00:00+00:00' },
            preview: {},
            mapping,
            proposals: { ai: 0, rule: 0 },
            rows: [],
            total: 0,
            page: 1,
            per_page: 25,
            categories: [],
        });

    const form = {
        layout: 'row',
        date: { type: 'index', value: 0, format: 'DD.MM.YYYY' },
        amounts: {
            mode: 'signed',
            amount: { type: 'index', value: 2 },
            decimal_separator: ',',
            thousands_separator: '.',
        },
        counterparty: { debit: { type: 'index', value: 4 }, credit: null },
        reference: {
            // One place stays an object, several become a list: both shapes are read back.
            debit: [
                { type: 'index', value: 4 },
                { type: 'index', value: 5 },
            ],
            credit: { type: 'label', value: 'Numar autorizare' },
        },
    };

    it('keeps every place of a field, in the order they are tried', () => {
        const parsed = parseStatementImportDetail(JSON.parse(body(form)));

        expect(parsed.mapping?.reference.debit).toEqual([
            { type: 'index', value: 4 },
            { type: 'index', value: 5 },
        ]);
        expect(parsed.mapping?.reference.credit).toEqual([{ type: 'label', value: 'Numar autorizare' }]);
    });

    it('reads a field that names nowhere as no places at all', () => {
        const parsed = parseStatementImportDetail(
            JSON.parse(body({ ...form, reference: { debit: { type: 'index', value: 4 }, credit: null } })),
        );

        expect(parsed.mapping?.reference.debit).toEqual([{ type: 'index', value: 4 }]);
        expect(parsed.mapping?.reference.credit).toEqual([]);
    });
});
