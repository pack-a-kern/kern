import { describe, expect, it } from 'vitest';

import { parseInstrumentDetail, parsePayoutRow } from '../investments';

const bond = {
    id: '01900000-0000-7000-8000-000000000001',
    kind: 'GOVERNMENT_BOND',
    programme: 'FIDELIS',
    isin: 'RO0000EXMPL1',
    symbol: 'R2899A',
    issueCode: null,
    name: 'FIDELIS R2899A',
    issuer: 'Example ministry',
    currency: 'RON',
    faceValue: '100',
    ratePercent: '7.50',
    issueDate: '2026-08-19',
    maturityDate: '2029-08-19',
    market: 'BVB',
    status: 'ACTIVE',
    subscribedValueMinor: null,
    subscriptions: null,
    latestPrice: { price: '99.10', tradingDate: '2026-11-13' },
    maturesIn: { value: 3, unit: 'year' },
    latestChange: { percent: '+0.35', trend: 'UP' },
    termsDocumentId: null,
    payoutsCount: 3,
    nextPayout: {
        id: 'p1',
        instrumentId: '01900000-0000-7000-8000-000000000001',
        kind: 'COUPON',
        periodStart: '2026-08-19',
        periodEnd: '2027-08-18',
        exDate: null,
        recordDate: '2027-08-10',
        paymentDate: '2027-08-19',
        amountPerUnit: '7.500000',
        ratePercent: '7.50',
        status: 'ANNOUNCED',
    },
    lastPayout: null,
};

describe('parseInstrumentDetail', () => {
    it('reads what the server worked out: the move, how far off it matures, its payments counted', () => {
        const detail = parseInstrumentDetail(bond);

        expect(detail).toMatchObject({
            maturesIn: { value: 3, unit: 'year' },
            latestChange: { percent: '+0.35', trend: 'UP' },
            payoutsCount: 3,
            nextPayout: { paymentDate: '2027-08-19', periodStart: '2026-08-19' },
            lastPayout: null,
        });
    });

    it('reads no move, no maturity and no payment as none', () => {
        expect(parseInstrumentDetail({ ...bond, latestChange: null, maturesIn: null, nextPayout: null })).toMatchObject(
            {
                latestChange: null,
                maturesIn: null,
                nextPayout: null,
            },
        );
    });

    it('refuses a trend this screen does not know', () => {
        expect(() => parseInstrumentDetail({ ...bond, latestChange: { percent: '1', trend: 'SIDEWAYS' } })).toThrow();
    });
});

describe('parsePayoutRow', () => {
    it('reads a coupon with its number, and a dividend with none', () => {
        expect(parsePayoutRow({ ...bond.nextPayout, number: 1 }).number).toBe(1);
        expect(parsePayoutRow({ ...bond.nextPayout, kind: 'DIVIDEND', number: null }).number).toBeNull();
    });
});
