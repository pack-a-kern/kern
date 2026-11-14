import { apiRequest } from '../lib/http';
import {
    type HeldAt,
    type HoldingPage,
    type InstrumentDetail,
    type InstrumentKind,
    type InstrumentPage,
    type IssuerProfile,
    type InstrumentStatus,
    type PayoutRow,
    type PricePoint,
    type Programme,
    parseHoldingPage,
    parseInstrumentDetail,
    parseInstrumentPage,
    parseIssuerProfile,
    parsePayoutRow,
    parsePricePoint,
} from '../types/investments';
import { obj } from '../lib/json';

/** One page of the market: active by default, filtered and searched as asked, with what the filters keep. */
export async function listInstruments(filters: {
    kind?: InstrumentKind;
    status?: InstrumentStatus;
    programme?: Programme;
    q?: string;
    page?: number;
    perPage?: number;
}): Promise<InstrumentPage> {
    const { perPage, ...rest } = filters;
    const query = new URLSearchParams();

    for (const [key, value] of Object.entries({ ...rest, per_page: perPage })) {
        if ((typeof value === 'string' && value !== '') || typeof value === 'number') {
            query.set(key, String(value));
        }
    }

    const suffix = query.size === 0 ? '' : `?${query.toString()}`;

    return parseInstrumentPage(JSON.parse(await apiRequest(`/v1/investments/instruments${suffix}`)));
}

export async function getInstrument(id: string): Promise<InstrumentDetail> {
    return parseInstrumentDetail(JSON.parse(await apiRequest(`/v1/investments/instruments/${encodeURIComponent(id)}`)));
}

/** The payments an instrument's list shows, in date order — asked for when the list is opened. */
export async function listInstrumentPayouts(id: string): Promise<PayoutRow[]> {
    const answer = obj(JSON.parse(await apiRequest(`/v1/investments/instruments/${encodeURIComponent(id)}/payouts`)));

    return (Array.isArray(answer.data) ? answer.data : []).map(parsePayoutRow);
}

/** A listed instrument's profile as last read from BVB, by its symbol — or null when it has never been read. Never reaches out. */
export async function getIssuerProfile(symbol: string): Promise<IssuerProfile | null> {
    const answer: unknown = JSON.parse(await apiRequest(`/v1/investments/issuers/${encodeURIComponent(symbol)}`));

    return answer === null ? null : parseIssuerProfile(answer);
}

/** Reads the company's profile from BVB now — the stored one while it is still fresh. */
export async function refreshIssuerProfile(symbol: string): Promise<IssuerProfile> {
    return parseIssuerProfile(
        JSON.parse(
            await apiRequest(`/v1/investments/issuers/${encodeURIComponent(symbol)}/refresh`, { method: 'POST' }),
        ),
    );
}

/** The price each trading day from `from` to `to`, oldest first. */
/** The price on each trading day of the last so many months, oldest first — the server counts the span. */
export async function getPrices(id: string, months: number): Promise<PricePoint[]> {
    const answer = obj(
        JSON.parse(
            await apiRequest(
                `/v1/investments/instruments/${encodeURIComponent(id)}/prices?${new URLSearchParams({ months: String(months) }).toString()}`,
            ),
        ),
    );

    return (Array.isArray(answer.data) ? answer.data : []).map(parsePricePoint);
}

const holdingsPath = (subjectId: string): string => `/v1/subjects/${encodeURIComponent(subjectId)}/wallet/holdings`;

/** One page of positions still held (`open`) or sold, each with its purchases, as the server computes them. */
export async function listHoldings(
    subjectId: string,
    state: 'open' | 'sold',
    page: number,
    perPage: number,
): Promise<HoldingPage> {
    const query = new URLSearchParams({ state, page: String(page), per_page: String(perPage) });

    return parseHoldingPage(JSON.parse(await apiRequest(`${holdingsPath(subjectId)}?${query.toString()}`)));
}

/** A purchase: the instrument's terms are its own, the rest is what was paid. The server finds or opens the money account for where it is held. */
export async function addHolding(
    subjectId: string,
    holding: {
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
