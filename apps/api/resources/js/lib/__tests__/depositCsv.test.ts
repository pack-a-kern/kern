import { describe, expect, it } from 'vitest';

import type { Dictionary } from '../../i18n/dictionary';
import { en } from '../../i18n/en';
import { maturityFor } from '../depositTerm';
import type { DepositCsvRow } from '../../types/wallet';
import { line, type DepositCsvShared } from '../depositCsv';

/*
 * A line of a deposit CSV becomes exactly the deposit the one-deposit form would send — its maturity
 * derived from the term the same way — or says why it cannot yet. Whether the deposit is acceptable is
 * the server's to answer; this only covers what reading can tell.
 */

const t = <T>(select: (dictionary: Dictionary) => T): T => select(en);

const shared: DepositCsvShared = {
    bankId: 'bank-for-the-file',
    currencyCode: 'RON',
    fundingAccountId: '',
    rateType: 'FIXED',
    interestMethod: 'SIMPLE',
    paymentFrequency: 'AT_MATURITY',
};

function row(overrides: Partial<DepositCsvRow> = {}): DepositCsvRow {
    return {
        line: 2,
        startsAt: { value: '2026-11-30', cell: '30.11.2026' },
        principalMinor: { value: 1_000_050, cell: '10.000,50' },
        annualRate: { value: '6.25', cell: '6,25' },
        termMonths: { value: 6, cell: '6' },
        bankId: null,
        currencyCode: null,
        ...overrides,
    };
}

describe('a deposit CSV line', () => {
    it('becomes the deposit the form would send, maturing where the form would put it', () => {
        expect(line(row(), shared, t)).toEqual({
            line: 2,
            problem: undefined,
            deposit: {
                bank_id: 'bank-for-the-file',
                currency_code: 'RON',
                starts_at: '2026-11-30',
                principal_minor: 1_000_050,
                maturity_at: maturityFor('2026-11-30', 6),
                annual_rate: '6.25',
                rate_type: 'FIXED',
                interest_method: 'SIMPLE',
                payment_frequency: 'AT_MATURITY',
                // No account chosen: declared, as on the form.
                funding_account_id: null,
            },
        });
    });

    it('takes the bank and the currency from their own columns over the answer for the file', () => {
        const deposit = line(
            row({ bankId: { value: 'bank-on-the-line', cell: 'BTRL' }, currencyCode: { value: 'EUR', cell: 'eur' } }),
            shared,
            t,
        ).deposit;

        expect(deposit?.bank_id).toBe('bank-on-the-line');
        expect(deposit?.currency_code).toBe('EUR');
    });

    it('names the cell it could not read, rather than sending a guess', () => {
        expect(line(row({ startsAt: { value: null, cell: '31.02.2026' } }), shared, t)).toEqual({
            line: 2,
            deposit: null,
            problem: 'Cannot read “31.02.2026”',
        });
    });

    it('is not a deposit while a field has no answer at all', () => {
        expect(line(row(), { ...shared, bankId: '' }, t).problem).toBe(en.pages.walletDeposits.csv.missing);
        expect(line(row({ termMonths: null }), shared, t).deposit).toBeNull();
    });
});
