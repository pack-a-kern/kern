import type { DepositRequest } from '../api/walletApi';
import type { useTranslation } from '../i18n';
import { maturityFor } from './depositTerm';
import type { DepositCsvRow } from '../types/wallet';

/** What every deposit in the file shares — the add-deposit form's own fields, answered once. */
export interface DepositCsvShared {
    bankId: string;
    currencyCode: string;
    fundingAccountId: string;
    rateType: string;
    interestMethod: string;
    paymentFrequency: string;
}

/**
 * One line of the file as the deposit the form would send, or the first reason it is not one yet.
 *
 * Only what reading can tell: a cell that is not a date, a figure or a bank on the list. Whether the
 * deposit is acceptable — a maturity already past, a rate the form would refuse — is the server's
 * answer, given by the same rules as for a single deposit.
 */
export function line(
    row: DepositCsvRow,
    shared: DepositCsvShared,
    t: ReturnType<typeof useTranslation>['t'],
): { line: number; deposit: DepositRequest | null; problem: string | undefined } {
    const cells = [row.startsAt, row.principalMinor, row.annualRate, row.termMonths, row.bankId, row.currencyCode];
    const unreadable = cells.find((cell) => cell !== null && cell.value === null);

    if (unreadable !== undefined && unreadable !== null) {
        return {
            line: row.line,
            deposit: null,
            problem: t((d) => d.pages.walletDeposits.csv.unreadable).replace('{cell}', unreadable.cell),
        };
    }

    const startsAt = row.startsAt?.value ?? null;
    const principal = row.principalMinor?.value ?? null;
    const rate = row.annualRate?.value ?? null;
    const months = row.termMonths?.value ?? null;
    const bankId = row.bankId?.value ?? shared.bankId;
    const currency = row.currencyCode?.value ?? shared.currencyCode;

    if (
        startsAt === null ||
        principal === null ||
        rate === null ||
        months === null ||
        bankId === '' ||
        currency === ''
    ) {
        return { line: row.line, deposit: null, problem: t((d) => d.pages.walletDeposits.csv.missing) };
    }

    return {
        line: row.line,
        deposit: {
            bank_id: bankId,
            currency_code: currency,
            starts_at: startsAt,
            principal_minor: principal,
            // From the term, exactly as the one-deposit form derives it.
            maturity_at: maturityFor(startsAt, months),
            annual_rate: rate,
            rate_type: shared.rateType,
            interest_method: shared.interestMethod,
            payment_frequency: shared.paymentFrequency,
            funding_account_id: shared.fundingAccountId === '' ? null : shared.fundingAccountId,
        },
        problem: undefined,
    };
}
