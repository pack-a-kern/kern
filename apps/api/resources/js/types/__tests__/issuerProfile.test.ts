import { describe, expect, it } from 'vitest';

import { parseIssuerProfile } from '../investments';

describe('parseIssuerProfile', () => {
    it('reads the shareholders and meetings BVB showed, and when', () => {
        const profile = parseIssuerProfile({
            issuerKey: 'ALFA',
            shareholders: [{ holder: 'Example Holding S.A.', shares: 1200, percent: '60.50' }],
            shareholdersAsOf: '2026-09-30',
            meetings: [{ date: '2027-04-20', kind: 'AGOA', upcoming: true }],
            financialCalendar: [{ date: '2026-11-16', what: 'Rezultate T3', upcoming: false }],
            figures: {
                marketCap: '1000000.00',
                priceEarnings: '12.50',
                lastDividendYear: 2025,
                sharesTotal: 1200,
                couponDiffers: true,
            },
            readAt: '2026-11-13T09:00:00+00:00',
        });

        expect(profile).toMatchObject({
            shareholders: [{ holder: 'Example Holding S.A.', shares: 1200, percent: '60.50' }],
            shareholdersAsOf: '2026-09-30',
            meetings: [{ date: '2027-04-20', kind: 'AGOA', upcoming: true }],
            financialCalendar: [{ date: '2026-11-16', what: 'Rezultate T3', upcoming: false }],
            readAt: '2026-11-13T09:00:00+00:00',
        });
        expect(profile.figures).toMatchObject({
            marketCap: '1000000.00',
            priceEarnings: '12.50',
            dividendYield: null,
            lastDividendYear: 2025,
            sharesTotal: 1200,
            couponDiffers: true,
        });
    });

    it('reads a profile with neither as empty lists, and one read before its figures as none', () => {
        expect(parseIssuerProfile({ readAt: '2026-11-13T09:00:00+00:00' })).toMatchObject({
            shareholders: [],
            meetings: [],
            financialCalendar: [],
            shareholdersAsOf: null,
            figures: { marketCap: null, category: null },
        });
    });
});
