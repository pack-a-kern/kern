import { nullableNum, nullableStr, num, obj, str } from '../lib/json';

/** What a security is, as `kern/investments` files it. */
export const INSTRUMENT_KINDS = ['GOVERNMENT_BOND', 'MUNICIPAL_BOND', 'CORPORATE_BOND', 'SHARE'] as const;
export type InstrumentKind = (typeof INSTRUMENT_KINDS)[number];

export const INSTRUMENT_STATUSES = ['ON_OFFER', 'ACTIVE', 'MATURED', 'DELISTED'] as const;
export type InstrumentStatus = (typeof INSTRUMENT_STATUSES)[number];

/** Where a holding is kept: the Treasury (Tezaur), a bank, a broker. */
export const HELD_AT = ['TREASURY', 'BANK', 'BROKER'] as const;
export type HeldAt = (typeof HELD_AT)[number];

export interface Instrument {
    id: string;
    kind: InstrumentKind;
    programme: 'FIDELIS' | 'TEZAUR' | null;
    isin: string | null;
    symbol: string | null;
    issueCode: string | null;
    name: string;
    issuer: string;
    currency: string;
    faceValue: string | null;
    ratePercent: string | null;
    issueDate: string | null;
    maturityDate: string | null;
    status: InstrumentStatus;
    subscribedValueMinor: number | null;
    subscriptions: number | null;
    /** A share's price per share; a bond's in percent of its face value. */
    latestPrice: { price: string; tradingDate: string | null } | null;
}

export interface Payout {
    id: string;
    instrumentId: string;
    kind: 'COUPON' | 'PRINCIPAL' | 'DIVIDEND';
    exDate: string | null;
    recordDate: string | null;
    paymentDate: string;
    amountPerUnit: string | null;
    ratePercent: string | null;
    status: 'ANNOUNCED' | 'PAID';
}

export interface InstrumentDetail extends Instrument {
    payouts: Payout[];
}

export interface PricePoint {
    tradingDate: string;
    price: string;
    changePercent: string | null;
}

/** One purchase of a holding, open or sold, with what it is worth and what it made — computed by the server. */
export interface HoldingLine {
    id: string;
    accountId: string;
    instrumentId: string;
    instrumentName: string;
    instrumentKind: InstrumentKind;
    programme: 'FIDELIS' | 'TEZAUR' | null;
    symbol: string | null;
    isin: string | null;
    issueCode: string | null;
    currency: string;
    heldAt: HeldAt;
    units: string;
    pricePerUnit: string;
    boughtOn: string;
    feesMinor: number;
    costMinor: number;
    soldOn: string | null;
    proceedsMinor: number | null;
    resultMinor: number | null;
    saleTaxWithheldMinor: number | null;
    valueMinor: number | null;
    valuationBasis: 'MARKET_PRICE' | 'FACE_VALUE' | null;
    valuedOn: string | null;
}

function oneOf<T extends string>(known: readonly T[], value: unknown, what: string): T {
    const found = known.find((candidate) => candidate === value);

    if (found === undefined) {
        throw new Error(`The server answered a ${what} this screen does not know.`);
    }

    return found;
}

function programme(value: unknown): 'FIDELIS' | 'TEZAUR' | null {
    return value === 'FIDELIS' || value === 'TEZAUR' ? value : null;
}

export function parseInstrument(payload: unknown): Instrument {
    const row = obj(payload);
    const latest = row.latestPrice === null || row.latestPrice === undefined ? null : obj(row.latestPrice);

    return {
        id: str(row, 'id'),
        kind: oneOf(INSTRUMENT_KINDS, row.kind, 'kind of instrument'),
        programme: programme(row.programme),
        isin: nullableStr(row, 'isin'),
        symbol: nullableStr(row, 'symbol'),
        issueCode: nullableStr(row, 'issueCode'),
        name: str(row, 'name'),
        issuer: str(row, 'issuer'),
        currency: str(row, 'currency'),
        faceValue: nullableStr(row, 'faceValue'),
        ratePercent: nullableStr(row, 'ratePercent'),
        issueDate: nullableStr(row, 'issueDate'),
        maturityDate: nullableStr(row, 'maturityDate'),
        status: oneOf(INSTRUMENT_STATUSES, row.status, 'status'),
        subscribedValueMinor: nullableNum(row, 'subscribedValueMinor'),
        subscriptions: nullableNum(row, 'subscriptions'),
        latestPrice:
            latest === null ? null : { price: str(latest, 'price'), tradingDate: nullableStr(latest, 'tradingDate') },
    };
}

export function parsePayout(payload: unknown): Payout {
    const row = obj(payload);

    return {
        id: str(row, 'id'),
        instrumentId: str(row, 'instrumentId'),
        kind: oneOf(['COUPON', 'PRINCIPAL', 'DIVIDEND'] as const, row.kind, 'kind of payout'),
        exDate: nullableStr(row, 'exDate'),
        recordDate: nullableStr(row, 'recordDate'),
        paymentDate: str(row, 'paymentDate'),
        amountPerUnit: nullableStr(row, 'amountPerUnit'),
        ratePercent: nullableStr(row, 'ratePercent'),
        status: oneOf(['ANNOUNCED', 'PAID'] as const, row.status, 'payout status'),
    };
}

export function parseInstrumentDetail(payload: unknown): InstrumentDetail {
    const row = obj(payload);

    return { ...parseInstrument(payload), payouts: (Array.isArray(row.payouts) ? row.payouts : []).map(parsePayout) };
}

export function parsePricePoint(payload: unknown): PricePoint {
    const row = obj(payload);

    return {
        tradingDate: str(row, 'tradingDate'),
        price: str(row, 'price'),
        changePercent: nullableStr(row, 'changePercent'),
    };
}

export function parseHoldingLine(payload: unknown): HoldingLine {
    const row = obj(payload);
    const basis = row.valuationBasis;

    return {
        id: str(row, 'id'),
        accountId: str(row, 'accountId'),
        instrumentId: str(row, 'instrumentId'),
        instrumentName: str(row, 'instrumentName'),
        instrumentKind: oneOf(INSTRUMENT_KINDS, row.instrumentKind, 'kind of instrument'),
        programme: programme(row.programme),
        symbol: nullableStr(row, 'symbol'),
        isin: nullableStr(row, 'isin'),
        issueCode: nullableStr(row, 'issueCode'),
        currency: str(row, 'currency'),
        heldAt: oneOf(HELD_AT, row.heldAt, 'place a holding is kept'),
        units: str(row, 'units'),
        pricePerUnit: str(row, 'pricePerUnit'),
        boughtOn: str(row, 'boughtOn'),
        feesMinor: num(row, 'feesMinor'),
        costMinor: num(row, 'costMinor'),
        soldOn: nullableStr(row, 'soldOn'),
        proceedsMinor: nullableNum(row, 'proceedsMinor'),
        resultMinor: nullableNum(row, 'resultMinor'),
        saleTaxWithheldMinor: nullableNum(row, 'saleTaxWithheldMinor'),
        valueMinor: nullableNum(row, 'valueMinor'),
        valuationBasis: basis === 'MARKET_PRICE' || basis === 'FACE_VALUE' ? basis : null,
        valuedOn: nullableStr(row, 'valuedOn'),
    };
}

/** A page of a list read by cursor: its rows, and the cursor to the next page, null at the last. */
export function parsePage<T>(payload: unknown, parse: (row: unknown) => T): { data: T[]; nextCursor: string | null } {
    const row = obj(payload);

    return { data: (Array.isArray(row.data) ? row.data : []).map(parse), nextCursor: nullableStr(row, 'nextCursor') };
}
