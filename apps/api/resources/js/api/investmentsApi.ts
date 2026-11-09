import { apiRequest } from '../lib/http';
import {
    type HeldAt,
    type HoldingLine,
    type Instrument,
    type InstrumentDetail,
    type InstrumentKind,
    type InstrumentStatus,
    type PricePoint,
    parseHoldingLine,
    parseInstrument,
    parseInstrumentDetail,
    parsePage,
    parsePricePoint,
} from '../types/investments';
import { obj } from '../lib/json';

/** The market, a page at a time: active by default, filtered and searched as asked. */
export async function listInstruments(filters: {
    kind?: InstrumentKind;
    status?: InstrumentStatus;
    q?: string;
    cursor?: string | null;
}): Promise<{ data: Instrument[]; nextCursor: string | null }> {
    const query = new URLSearchParams();

    for (const [key, value] of Object.entries(filters)) {
        if (typeof value === 'string' && value !== '') {
            query.set(key, value);
        }
    }

    const suffix = query.size === 0 ? '' : `?${query.toString()}`;

    return parsePage(JSON.parse(await apiRequest(`/v1/investments/instruments${suffix}`)), parseInstrument);
}

export async function getInstrument(id: string): Promise<InstrumentDetail> {
    return parseInstrumentDetail(JSON.parse(await apiRequest(`/v1/investments/instruments/${encodeURIComponent(id)}`)));
}

/** The price each trading day from `from` to `to`, oldest first. */
export async function getPrices(id: string, from: string, to: string): Promise<PricePoint[]> {
    const answer = obj(
        JSON.parse(
            await apiRequest(
                `/v1/investments/instruments/${encodeURIComponent(id)}/prices?${new URLSearchParams({ from, to }).toString()}`,
            ),
        ),
    );

    return (Array.isArray(answer.data) ? answer.data : []).map(parsePricePoint);
}

const holdingsPath = (subjectId: string): string => `/v1/subjects/${encodeURIComponent(subjectId)}/wallet/holdings`;

/** Every purchase held or sold, with its value and its result, as the server computes them. */
export async function listHoldings(subjectId: string): Promise<HoldingLine[]> {
    const answer = obj(JSON.parse(await apiRequest(holdingsPath(subjectId))));

    return (Array.isArray(answer.holdings) ? answer.holdings : []).map(parseHoldingLine);
}

/** A purchase added like a deposit: the instrument's terms are its own; the rest is what was paid. */
export async function addHolding(
    subjectId: string,
    holding: {
        accountId: string;
        instrumentId: string;
        units: string;
        pricePerUnit: string;
        boughtOn: string;
        feesMinor: number;
        heldAt: HeldAt;
    },
): Promise<void> {
    await apiRequest(holdingsPath(subjectId), {
        method: 'POST',
        body: {
            account_id: holding.accountId,
            instrument_id: holding.instrumentId,
            units: holding.units,
            price_per_unit: holding.pricePerUnit,
            bought_on: holding.boughtOn,
            fees_minor: holding.feesMinor,
            held_at: holding.heldAt,
        },
    });
}

/** Units sold early, first in, first out: the server computes the proceeds, the gain and what was withheld. */
export async function sellHolding(
    subjectId: string,
    sale: {
        accountId: string;
        instrumentId: string;
        units: string;
        pricePerUnit: string;
        soldOn: string;
        feesMinor: number;
    },
): Promise<void> {
    await apiRequest(`${holdingsPath(subjectId)}/sales`, {
        method: 'POST',
        body: {
            account_id: sale.accountId,
            instrument_id: sale.instrumentId,
            units: sale.units,
            price_per_unit: sale.pricePerUnit,
            sold_on: sale.soldOn,
            fees_minor: sale.feesMinor,
        },
    });
}
