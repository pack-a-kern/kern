import { bool, nullableNum, nullableStr, num, obj, str } from '../lib/json';

/** What a security is, as `kern/investments` files it. */
export const INSTRUMENT_KINDS = ['GOVERNMENT_BOND', 'MUNICIPAL_BOND', 'CORPORATE_BOND', 'SHARE'] as const;
export type InstrumentKind = (typeof INSTRUMENT_KINDS)[number];

export const PROGRAMMES = ['FIDELIS', 'TEZAUR'] as const;
export type Programme = (typeof PROGRAMMES)[number];

export const INSTRUMENT_STATUSES = ['ON_OFFER', 'ACTIVE', 'MATURED', 'DELISTED'] as const;
export type InstrumentStatus = (typeof INSTRUMENT_STATUSES)[number];

/** Where a holding is kept: the Treasury (Tezaur), a bank, a broker. */
export const HELD_AT = ['TREASURY', 'BANK', 'BROKER'] as const;
export type HeldAt = (typeof HELD_AT)[number];

export interface Instrument {
    id: string;
    kind: InstrumentKind;
    programme: Programme | null;
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
    /** How far off a maturity still to come is, as the server counts it; null for none, or one past. */
    maturesIn: Span | null;
}

/** A stretch of time the server counted: 3 years, 5 months. */
export interface Span {
    value: number;
    unit: 'year' | 'month';
}

export interface Payout {
    id: string;
    instrumentId: string;
    kind: 'COUPON' | 'PRINCIPAL' | 'DIVIDEND';
    /** The days a coupon pays for; null for a repayment or a dividend. */
    periodStart: string | null;
    periodEnd: string | null;
    exDate: string | null;
    recordDate: string | null;
    paymentDate: string;
    amountPerUnit: string | null;
    ratePercent: string | null;
    status: 'ANNOUNCED' | 'PAID';
}

/** A payment as its instrument's list shows it: a bond's coupons each with their number, a dividend with none. */
export interface PayoutRow extends Payout {
    number: number | null;
}

export interface InstrumentDetail extends Instrument {
    /** Where it is traded — `BVB` — or null for a bond sold only by the Treasury. */
    market: string | null;
    /** How the latest price moved on its day: its percent, signed, and which way. */
    latestChange: { percent: string; trend: 'UP' | 'DOWN' | 'FLAT' } | null;
    /** The order a government bond's terms were read from: a law on the legislation page. */
    termsDocumentId: string | null;
    /** How many payments its list holds, the next still to come and the last paid — the list itself is its own call. */
    payoutsCount: number;
    nextPayout: Payout | null;
    lastPayout: Payout | null;
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
    programme: Programme | null;
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
        maturesIn: row.maturesIn === null || row.maturesIn === undefined ? null : parseSpan(row.maturesIn),
    };
}

function parseSpan(payload: unknown): Span {
    const row = obj(payload);

    return { value: num(row, 'value'), unit: oneOf(['year', 'month'] as const, row.unit, 'unit of time') };
}

export function parsePayoutRow(payload: unknown): PayoutRow {
    return { ...parsePayout(payload), number: nullableNum(obj(payload), 'number') };
}

export function parsePayout(payload: unknown): Payout {
    const row = obj(payload);

    return {
        id: str(row, 'id'),
        instrumentId: str(row, 'instrumentId'),
        kind: oneOf(['COUPON', 'PRINCIPAL', 'DIVIDEND'] as const, row.kind, 'kind of payout'),
        periodStart: nullableStr(row, 'periodStart'),
        periodEnd: nullableStr(row, 'periodEnd'),
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
    const change = row.latestChange === null || row.latestChange === undefined ? null : obj(row.latestChange);
    const payout = (key: string): Payout | null =>
        row[key] === null || row[key] === undefined ? null : parsePayout(row[key]);

    return {
        ...parseInstrument(payload),
        market: nullableStr(row, 'market'),
        latestChange:
            change === null
                ? null
                : {
                      percent: str(change, 'percent'),
                      trend: oneOf(['UP', 'DOWN', 'FLAT'] as const, change.trend, 'trend'),
                  },
        termsDocumentId: nullableStr(row, 'termsDocumentId'),
        payoutsCount: num(row, 'payoutsCount'),
        nextPayout: payout('nextPayout'),
        lastPayout: payout('lastPayout'),
    };
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

/**
 * One instrument held in one account: what its purchases add up to, and the purchases themselves — each a
 * row of its own, because the law counts each. Every figure is the server's.
 */
export interface HoldingPosition {
    accountId: string;
    instrumentId: string;
    instrumentName: string;
    instrumentKind: InstrumentKind;
    programme: Programme | null;
    symbol: string | null;
    isin: string | null;
    issueCode: string | null;
    maturityDate: string | null;
    currency: string;
    heldAt: HeldAt;
    units: string;
    costMinor: number;
    /** The weighted average price paid per unit, fees in. */
    averagePrice: string;
    firstBoughtOn: string;
    lastSoldOn: string | null;
    valueMinor: number | null;
    valuationBasis: 'MARKET_PRICE' | 'FACE_VALUE' | null;
    valuedOn: string | null;
    proceedsMinor: number | null;
    /** Unrealised while held, realised once sold. */
    resultMinor: number | null;
    resultPercent: string | null;
    /** Coupons and dividends paid into the account, net. */
    receivedMinor: number;
    lots: HoldingLine[];
}

/** One page of positions, still held or sold, and how many there are in all. */
export interface HoldingPage {
    positions: HoldingPosition[];
    total: number;
}

export function parseHoldingPage(payload: unknown): HoldingPage {
    const page = obj(payload);

    return {
        total: num(page, 'total'),
        positions: (Array.isArray(page.positions) ? page.positions : []).map((entry: unknown) => {
            const row = obj(entry);
            const basis = row.valuationBasis;

            return {
                accountId: str(row, 'accountId'),
                instrumentId: str(row, 'instrumentId'),
                instrumentName: str(row, 'instrumentName'),
                instrumentKind: oneOf(INSTRUMENT_KINDS, row.instrumentKind, 'kind of instrument'),
                programme: programme(row.programme),
                symbol: nullableStr(row, 'symbol'),
                isin: nullableStr(row, 'isin'),
                issueCode: nullableStr(row, 'issueCode'),
                maturityDate: nullableStr(row, 'maturityDate'),
                currency: str(row, 'currency'),
                heldAt: oneOf(HELD_AT, row.heldAt, 'place a holding is kept'),
                units: str(row, 'units'),
                costMinor: num(row, 'costMinor'),
                averagePrice: str(row, 'averagePrice'),
                firstBoughtOn: str(row, 'firstBoughtOn'),
                lastSoldOn: nullableStr(row, 'lastSoldOn'),
                valueMinor: nullableNum(row, 'valueMinor'),
                valuationBasis: basis === 'MARKET_PRICE' || basis === 'FACE_VALUE' ? basis : null,
                valuedOn: nullableStr(row, 'valuedOn'),
                proceedsMinor: nullableNum(row, 'proceedsMinor'),
                resultMinor: nullableNum(row, 'resultMinor'),
                resultPercent: nullableStr(row, 'resultPercent'),
                receivedMinor: num(row, 'receivedMinor'),
                lots: (Array.isArray(row.lots) ? row.lots : []).map(parseHoldingLine),
            };
        }),
    };
}

/** One page of the market, how many instruments the filters keep in all, and how many of each kind. */
export interface InstrumentPage {
    data: Instrument[];
    total: number;
    /** By kind, what the status, programme and search keep — whatever kind is chosen — and every kind together. */
    counts: Record<InstrumentKind, number>;
    countAll: number;
}

export function parseInstrumentPage(payload: unknown): InstrumentPage {
    const row = obj(payload);
    const counts = obj(row.counts);

    return {
        data: (Array.isArray(row.data) ? row.data : []).map(parseInstrument),
        total: typeof row.total === 'number' ? row.total : 0,
        counts: Object.fromEntries(
            INSTRUMENT_KINDS.map((kind) => [kind, typeof counts[kind] === 'number' ? counts[kind] : 0]),
        ) as Record<InstrumentKind, number>,
        countAll: num(row, 'countAll'),
    };
}

/**
 * The figures a share's BVB page shows beside its issuer, each null where the page leaves it out — or for a
 * profile read before they were kept. Decimals as plain text.
 */
export interface IssuerFigures {
    marketCap: string | null;
    priceEarnings: string | null;
    priceBook: string | null;
    earningsPerShare: string | null;
    dividendYield: string | null;
    lastDividend: string | null;
    lastDividendYear: number | null;
    week52High: string | null;
    week52Low: string | null;
    sharesTotal: number | null;
    faceValue: string | null;
    shareCapital: string | null;
    tradingSince: string | null;
    segment: string | null;
    category: string | null;
    /** The best bid and ask, as BVB last showed them. */
    bid: string | null;
    ask: string | null;
    /** A bond's: its current coupon and yield to maturity, in percent, how many were issued and for how much. */
    couponRate: string | null;
    yieldToMaturity: string | null;
    unitsIssued: number | null;
    issueValue: string | null;
    /** Whether the coupon BVB shows is not the rate the bond's terms give. */
    couponDiffers: boolean;
}

/**
 * A listed instrument's profile as BVB last showed it: a company's main shareholders and its shareholders'
 * meetings, the financial calendar, and its figures — a bond's none of the first two.
 */
export interface IssuerProfile {
    shareholders: { holder: string; shares: number; percent: string }[];
    /** The day the shareholder structure is as of, as BVB states it. */
    shareholdersAsOf: string | null;
    /** Latest first, each said to be still to come or not, as the server dates them. */
    meetings: { date: string; kind: string; upcoming: boolean }[];
    financialCalendar: { date: string; what: string; upcoming: boolean }[];
    figures: IssuerFigures;
    readAt: string;
}

export function parseIssuerProfile(payload: unknown): IssuerProfile {
    const row = obj(payload);
    const figures = obj(row.figures);
    const list = (key: string): Record<string, unknown>[] =>
        (Array.isArray(row[key]) ? (row[key] as unknown[]) : []).map((entry) => obj(entry));

    return {
        shareholders: list('shareholders').map((entry) => ({
            holder: str(entry, 'holder'),
            shares: typeof entry.shares === 'number' ? entry.shares : 0,
            percent: str(entry, 'percent'),
        })),
        shareholdersAsOf: nullableStr(row, 'shareholdersAsOf'),
        meetings: list('meetings').map((entry) => ({
            date: str(entry, 'date'),
            kind: str(entry, 'kind'),
            upcoming: bool(entry, 'upcoming'),
        })),
        financialCalendar: list('financialCalendar').map((entry) => ({
            date: str(entry, 'date'),
            what: str(entry, 'what'),
            upcoming: bool(entry, 'upcoming'),
        })),
        figures: {
            marketCap: nullableStr(figures, 'marketCap'),
            priceEarnings: nullableStr(figures, 'priceEarnings'),
            priceBook: nullableStr(figures, 'priceBook'),
            earningsPerShare: nullableStr(figures, 'earningsPerShare'),
            dividendYield: nullableStr(figures, 'dividendYield'),
            lastDividend: nullableStr(figures, 'lastDividend'),
            lastDividendYear: nullableNum(figures, 'lastDividendYear'),
            week52High: nullableStr(figures, 'week52High'),
            week52Low: nullableStr(figures, 'week52Low'),
            sharesTotal: nullableNum(figures, 'sharesTotal'),
            faceValue: nullableStr(figures, 'faceValue'),
            shareCapital: nullableStr(figures, 'shareCapital'),
            tradingSince: nullableStr(figures, 'tradingSince'),
            segment: nullableStr(figures, 'segment'),
            category: nullableStr(figures, 'category'),
            bid: nullableStr(figures, 'bid'),
            ask: nullableStr(figures, 'ask'),
            couponRate: nullableStr(figures, 'couponRate'),
            yieldToMaturity: nullableStr(figures, 'yieldToMaturity'),
            unitsIssued: nullableNum(figures, 'unitsIssued'),
            issueValue: nullableStr(figures, 'issueValue'),
            couponDiffers: bool(figures, 'couponDiffers'),
        },
        readAt: str(row, 'readAt'),
    };
}
