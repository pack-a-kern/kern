import { bool, nullableNum, nullableStr, num, obj, str } from '../lib/json';

/**
 * Shapes returned by `/api/v1/legislation/search`.
 *
 * The two result groups stay separate rather than being merged and sorted by score, because they
 * make different claims. A `confirmed` hit has effective dates covering the searched date — every
 * consolidation read from the portal is dated by its list of consolidations. A `dateUnknown` hit has
 * no effective dates at all: a version reached some other way.
 *
 * Showing them as one list would be the app asserting a text was in force on a date nobody has
 * established. A third case never arrives: a section whose known dates exclude the searched date is
 * dropped server-side, because that is a confirmed non-match rather than an unknown.
 */
export interface LegislationHit {
    chunkId: string;
    sectionId: string;
    /** The law the hit is in — for opening it at the searched date. */
    sourceDocumentId: string;
    sourceCode: string;
    documentType: string;
    issuer: string | null;
    citation: string;
    effectiveFrom: string | null;
    effectiveUntil: string | null;
    publicationDate: string | null;
    text: string;
    chunkIndex: number;
    chunkCount: number;
    lexicalRank: number;
    /** `null` when no embedding provider answered — the search still ran, lexically. */
    semanticSimilarity: number | null;
    score: number;
}

export interface LegislationSearchResult {
    asOf: string;
    confirmed: LegislationHit[];
    dateUnknown: LegislationHit[];
}

function parseHit(payload: unknown): LegislationHit {
    const row = obj(payload);

    return {
        chunkId: str(row, 'chunk_id'),
        sectionId: str(row, 'section_id'),
        sourceDocumentId: str(row, 'source_document_id'),
        sourceCode: str(row, 'source_code'),
        documentType: str(row, 'document_type'),
        issuer: nullableStr(row, 'issuer'),
        citation: str(row, 'citation'),
        effectiveFrom: nullableStr(row, 'effective_from'),
        effectiveUntil: nullableStr(row, 'effective_until'),
        publicationDate: nullableStr(row, 'publication_date'),
        text: str(row, 'text'),
        chunkIndex: num(row, 'chunk_index'),
        chunkCount: num(row, 'chunk_count'),
        lexicalRank: num(row, 'lexical_rank'),
        semanticSimilarity: nullableNum(row, 'semantic_similarity'),
        score: num(row, 'score'),
    };
}

export function parseLegislationSearch(payload: unknown): LegislationSearchResult {
    const body = obj(payload);

    return {
        asOf: str(body, 'as_of'),
        confirmed: (Array.isArray(body.confirmed) ? body.confirmed : []).map(parseHit),
        dateUnknown: (Array.isArray(body.date_unknown) ? body.date_unknown : []).map(parseHit),
    };
}

/** A law read from legislatie.just.ro, as the portal describes it. */
export interface Law {
    id: string;
    title: string | null;
    /** The filing key, without diacritics — `HOTARARE`. */
    type: string;
    /** The type as the portal writes it — `HOTĂRÂRE`. */
    typeLabel: string | null;
    number: string | null;
    issuer: string | null;
    actDate: string | null;
    officialJournal: string | null;
    officialJournalNumber: string | null;
    publishedOn: string | null;
    inForceFrom: string | null;
    url: string;
    lastSyncedAt: string | null;
    /** The portal's id of the act, and its year: what reading it again asks for. */
    portalId: string | null;
    year: number | null;
    /** Why the last reading stopped on this installation's side — `storage_unavailable` — or null. */
    readingStopped: string | null;
    readingStoppedAt: string | null;
}

export interface LawSummary extends Law {
    consolidationsListed: number;
    consolidationsRead: number;
    consolidationsFailed: number;
    firstConsolidation: string | null;
    lastConsolidation: string | null;
    /** What another part of the app flags beside this law — `bonds_skipped` and how many. */
    flags: LawFlag[];
    /** The watch that read it in; null when read by hand, or its watch was removed. */
    watchId: string | null;
}

/** What the laws list may be filtered by, read by the server from every law here. */
export interface LawFilters {
    types: { value: string; label: string }[];
    years: number[];
    /** Only for those who may see the watches; empty for everyone else. */
    watches: { id: string; words: string }[];
}

export interface LawFlag {
    code: string;
    count: number;
}

export interface LawConsolidation {
    /** The date the portal gives it: the law as it stood from this day. */
    date: string;
    ref: string;
    /** Its page on the portal. */
    url: string;
    failed: boolean;
    /** Null until it has been read. */
    versionId: string | null;
    effectiveUntil: string | null;
    sectionCount: number | null;
}

export interface LawDetail extends Law {
    /** Newest first. */
    consolidations: LawConsolidation[];
}

/** A cell spanning more than one column or row: its place among the table's rows, as they list it. */
export interface LawSpan {
    row: number;
    cell: number;
    colspan: number;
    rowspan: number;
}

/** One table: its rows of cells, and the cells it merges — "Cod emisiune 0105" across three columns. */
export interface LawTable {
    rows: string[][];
    spans: LawSpan[];
}

/** A heading of the table of contents: "Titlul IV" "Impozitul pe venit". */
export interface LawHeading {
    id: string;
    parentId: string | null;
    level: 'TITLU' | 'CAPITOL' | 'SECTIUNE';
    label: string;
    name: string;
    path: string;
}

export interface LawOutlineHeading extends LawHeading {
    /** The first article under it; null for a heading with no article under it. */
    firstCitation: string | null;
    lastCitation: string | null;
}

export interface LawOutline {
    asOf: string;
    effectiveFrom: string;
    headings: LawOutlineHeading[];
}

/** An act a note quotes — the one whose words the note copies. */
export interface NoteAct {
    /** As the note writes it: `ORDONANȚA DE URGENȚĂ`. */
    type: string;
    number: string;
    date: string | null;
    journalNumber: string;
    journalDate: string | null;
}

export type NoteApplicabilityKind =
    | 'income_month_after_publication'
    | 'income_month'
    | 'income_year'
    | 'fiscal_year'
    | 'modified_fiscal_year'
    | 'quarter'
    | 'in_force'
    | 'in_force_on_publication'
    | 'from_date'
    | 'until_date'
    | 'on_date';

/** A sentence of a note that says when the provision applies, and the dates it gives. */
export interface NoteApplies {
    kind: NoteApplicabilityKind;
    /** Null when the note gives no day: a company's own fiscal year, or publication by an act not named. */
    from: string | null;
    until: string | null;
    year: number | null;
    /** Index into the note's `acts`: the act named last before the sentence. */
    act: number | null;
    /** The words the dates were read from. */
    quote: string;
}

/** One of the portal's notes on a provision — never part of the provision's own text. */
export interface LawNote {
    text: string;
    acts: NoteAct[];
    applies: NoteApplies[];
}

const NOTE_KINDS: readonly NoteApplicabilityKind[] = [
    'income_month_after_publication',
    'income_month',
    'income_year',
    'fiscal_year',
    'modified_fiscal_year',
    'quarter',
    'in_force',
    'in_force_on_publication',
    'from_date',
    'until_date',
    'on_date',
];

function notes(value: unknown): LawNote[] {
    return (Array.isArray(value) ? value : []).map((entry): LawNote => {
        const row = obj(entry);

        return {
            text: str(row, 'text'),
            acts: (Array.isArray(row.acts) ? row.acts : []).map((act): NoteAct => {
                const item = obj(act);

                return {
                    type: str(item, 'type'),
                    number: str(item, 'number'),
                    date: nullableStr(item, 'date'),
                    journalNumber: str(item, 'journal_number'),
                    journalDate: nullableStr(item, 'journal_date'),
                };
            }),
            applies: (Array.isArray(row.applies) ? row.applies : []).flatMap((applies): NoteApplies[] => {
                const item = obj(applies);
                const kind = NOTE_KINDS.find((known) => known === item.kind);

                return kind === undefined
                    ? []
                    : [
                          {
                              kind,
                              from: nullableStr(item, 'from'),
                              until: nullableStr(item, 'until'),
                              year: nullableNum(item, 'year'),
                              act: nullableNum(item, 'act'),
                              quote: str(item, 'quote'),
                          },
                      ];
            }),
        };
    });
}

export interface LawSection {
    id: string;
    type: string;
    citation: string;
    /** How it is headed in place: "Articolul 170", "(2)", "a)", "–". */
    label: string;
    depth: number;
    /** Lines, with a `[[table:N]]` marker line where the Nth table stands. */
    text: string;
    tables: LawTable[];
    amendedOn: string | null;
    notes: LawNote[];
    /** For an article or annex: the headings it stands under, outermost first. Empty for the rest. */
    headings: LawHeading[];
}

/** Which part of a law to show: a heading by its path, or the one a provision stands in by its citation. */
export interface LawPart {
    heading: string | null;
    provision: string | null;
}

export interface LawText {
    asOf: string;
    versionId: string;
    effectiveFrom: string;
    effectiveUntil: string | null;
    /** The part shown and those above it, outermost first; empty for the articles under no heading. */
    heading: LawHeading[];
    /** The parts of the same level on either side, to read on. */
    previous: LawHeading | null;
    next: LawHeading | null;
    /** The provision asked for, to open at. */
    focus: string | null;
    sections: LawSection[];
}

export interface ProvisionVersion {
    versionIndex: number;
    validFrom: string;
    validUntil: string | null;
    amendedOn: string | null;
    snapshotCount: number;
    /** False when a consolidation inside this stretch could not be read. */
    coverageComplete: boolean;
    /** Its start is the day the portal's own note gives — in a stretch with no consolidation — not a consolidation's. */
    datedByNote: boolean;
    text: string;
    tables: LawTable[];
    notes: LawNote[];
}

/** Each table's rows, and its merged cells from `table_spans`, the same table at the same index. */
function tables(value: unknown, spans: unknown): LawTable[] {
    const merged = Array.isArray(spans) ? spans : [];

    return (Array.isArray(value) ? value : []).map((table, index): LawTable => ({
        rows: (Array.isArray(table) ? table : []).map((row) =>
            (Array.isArray(row) ? row : []).map((cell) => (typeof cell === 'string' ? cell : '')),
        ),
        spans: (Array.isArray(merged[index]) ? (merged[index] as unknown[]) : []).map((entry): LawSpan => {
            const span = obj(entry);

            return {
                row: num(span, 'row'),
                cell: num(span, 'cell'),
                colspan: num(span, 'colspan'),
                rowspan: num(span, 'rowspan'),
            };
        }),
    }));
}

function parseHeading(entry: unknown): LawHeading {
    const row = obj(entry);
    const level = str(row, 'level');

    return {
        id: str(row, 'id'),
        parentId: nullableStr(row, 'parent_id'),
        level: level === 'TITLU' || level === 'CAPITOL' ? level : 'SECTIUNE',
        label: str(row, 'label'),
        name: str(row, 'name'),
        path: str(row, 'path'),
    };
}

export function parseLawOutline(payload: unknown): LawOutline {
    const body = obj(payload);

    return {
        asOf: str(body, 'as_of'),
        effectiveFrom: str(body, 'effective_from'),
        headings: (Array.isArray(body.headings) ? body.headings : []).map((entry): LawOutlineHeading => {
            const row = obj(entry);

            return {
                ...parseHeading(row),
                firstCitation: nullableStr(row, 'first_citation'),
                lastCitation: nullableStr(row, 'last_citation'),
            };
        }),
    };
}

function parseLaw(row: Record<string, unknown>): Law {
    return {
        id: str(row, 'id'),
        title: nullableStr(row, 'title'),
        type: str(row, 'type'),
        typeLabel: nullableStr(row, 'type_label'),
        number: nullableStr(row, 'number'),
        issuer: nullableStr(row, 'issuer'),
        actDate: nullableStr(row, 'act_date'),
        officialJournal: nullableStr(row, 'official_journal'),
        officialJournalNumber: nullableStr(row, 'official_journal_number'),
        publishedOn: nullableStr(row, 'published_on'),
        inForceFrom: nullableStr(row, 'in_force_from'),
        url: str(row, 'url'),
        lastSyncedAt: nullableStr(row, 'last_synced_at'),
        portalId: nullableStr(row, 'portal_id'),
        year: nullableNum(row, 'year'),
        readingStopped: nullableStr(row, 'reading_stopped'),
        readingStoppedAt: nullableStr(row, 'reading_stopped_at'),
    };
}

/** One page of the laws a filter keeps, how many it keeps in all, and what the list may be filtered by. */
export interface LawsPage {
    laws: LawSummary[];
    total: number;
    filters: LawFilters;
}

export function parseLaws(payload: unknown): LawsPage {
    const filters = obj(obj(payload).filters);
    const list = (key: string): unknown[] => (Array.isArray(filters[key]) ? (filters[key] as unknown[]) : []);

    return {
        laws: parseLawRows(payload),
        total: num(obj(payload), 'total'),
        filters: {
            types: list('types').map((entry) => ({ value: str(obj(entry), 'value'), label: str(obj(entry), 'label') })),
            years: list('years').filter((year): year is number => typeof year === 'number'),
            watches: list('watches').map((entry) => ({ id: str(obj(entry), 'id'), words: str(obj(entry), 'words') })),
        },
    };
}

function parseLawRows(payload: unknown): LawSummary[] {
    return (Array.isArray(obj(payload).laws) ? (obj(payload).laws as unknown[]) : []).map((entry) => {
        const row = obj(entry);

        return {
            ...parseLaw(row),
            consolidationsListed: num(row, 'consolidations_listed'),
            consolidationsRead: num(row, 'consolidations_read'),
            consolidationsFailed: num(row, 'consolidations_failed'),
            firstConsolidation: nullableStr(row, 'first_consolidation'),
            lastConsolidation: nullableStr(row, 'last_consolidation'),
            flags: (Array.isArray(row.flags) ? (row.flags as unknown[]) : []).map((flag) => {
                const said = obj(flag);

                return { code: str(said, 'code'), count: num(said, 'count') };
            }),
            watchId: nullableStr(row, 'watch_id'),
        };
    });
}

export function parseLawDetail(payload: unknown): LawDetail {
    const row = obj(obj(payload).law);

    return {
        ...parseLaw(row),
        consolidations: (Array.isArray(row.consolidations) ? row.consolidations : []).map((entry): LawConsolidation => {
            const item = obj(entry);

            return {
                date: str(item, 'date'),
                ref: str(item, 'ref'),
                url: str(item, 'url'),
                failed: item.failed === true,
                versionId: nullableStr(item, 'version_id'),
                effectiveUntil: nullableStr(item, 'effective_until'),
                sectionCount: nullableNum(item, 'section_count'),
            };
        }),
    };
}

export function parseLawText(payload: unknown): LawText {
    const body = obj(payload);

    return {
        asOf: str(body, 'as_of'),
        versionId: str(body, 'version_id'),
        effectiveFrom: str(body, 'effective_from'),
        effectiveUntil: nullableStr(body, 'effective_until'),
        heading: (Array.isArray(body.heading) ? body.heading : []).map(parseHeading),
        previous: body.previous === null || body.previous === undefined ? null : parseHeading(body.previous),
        next: body.next === null || body.next === undefined ? null : parseHeading(body.next),
        focus: nullableStr(body, 'focus'),
        sections: (Array.isArray(body.sections) ? body.sections : []).map((entry): LawSection => {
            const row = obj(entry);

            return {
                id: str(row, 'id'),
                type: str(row, 'type'),
                citation: str(row, 'citation'),
                label: str(row, 'label'),
                depth: num(row, 'depth'),
                text: str(row, 'text'),
                tables: tables(row.tables, row.table_spans),
                amendedOn: nullableStr(row, 'amended_on'),
                notes: notes(row.notes),
                headings: (Array.isArray(row.headings) ? row.headings : []).map(parseHeading),
            };
        }),
    };
}

export function parseProvisionHistory(payload: unknown): ProvisionVersion[] {
    return (Array.isArray(obj(payload).history) ? (obj(payload).history as unknown[]) : []).map((entry) => {
        const row = obj(entry);

        return {
            versionIndex: num(row, 'version_index'),
            validFrom: str(row, 'valid_from'),
            validUntil: nullableStr(row, 'valid_until'),
            amendedOn: nullableStr(row, 'amended_on'),
            snapshotCount: num(row, 'snapshot_count'),
            coverageComplete: row.coverage_complete !== false,
            datedByNote: row.dated_by_note === true,
            text: str(row, 'text'),
            tables: tables(row.tables, row.table_spans),
            notes: notes(row.notes),
        };
    });
}

/** An act on legislatie.just.ro, as a search there finds it — to be read, or already read. */
export interface PortalActResult {
    /** The filing key, without diacritics — `HOTARARE`. */
    type: string;
    typeLabel: string;
    number: string;
    year: number | null;
    title: string;
    issuer: string | null;
    inForceFrom: string | null;
    portalId: string | null;
    /** Its page on the portal. */
    url: string;
    /** Whether it can be read here: its type is one the ingest task takes. */
    importable: boolean;
    /** Not importable for its year: older than the laws this installation reads. */
    beforeMinYear: boolean;
    /** The law it already is, once read. */
    lawId: string | null;
}

export function parsePortalActs(payload: unknown): PortalActResult[] {
    return (Array.isArray(obj(payload).acts) ? (obj(payload).acts as unknown[]) : []).map((entry): PortalActResult => {
        const row = obj(entry);

        return {
            type: str(row, 'type'),
            typeLabel: str(row, 'type_label'),
            number: str(row, 'number'),
            year: nullableNum(row, 'year'),
            title: str(row, 'title'),
            issuer: nullableStr(row, 'issuer'),
            inForceFrom: nullableStr(row, 'in_force_from'),
            portalId: nullableStr(row, 'portal_id'),
            url: str(row, 'url'),
            importable: row.importable === true,
            beforeMinYear: row.before_min_year === true,
            lawId: nullableStr(row, 'law_id'),
        };
    });
}

/**
 * Where a search of legislatie.just.ro is (`Kern\Legislation\Enums\PortalSearchStatus`): still reading,
 * every page read, stopped short at a page that would not answer, failed at the first, or cancelled.
 */
export type PortalSearchStatus = 'running' | 'done' | 'incomplete' | 'failed' | 'cancelled';

const PORTAL_SEARCH_STATUSES: readonly PortalSearchStatus[] = ['running', 'done', 'incomplete', 'failed', 'cancelled'];

/** A search of the portal, read a page at a time on the server (ADR 0024), as it stands. */
export interface PortalSearchState {
    id: string;
    status: PortalSearchStatus;
    pagesRead: number;
    /** How many times the portal was asked again before it answered, over every page read. */
    retries: number;
    /** How many of the acts found are already laws here. */
    inLibrary: number;
    /** Why it stopped short — `portal_search_refused`, `portal_search_down`… — when it did. */
    failure: string | null;
    /** While it runs: how soon to ask again if no message comes over the socket. */
    pollAfterMs: number | null;
    /** The acts found so far, each already shaped by the server. */
    acts: PortalActResult[];
}

export function parsePortalSearch(payload: unknown): PortalSearchState {
    const row = obj(payload);
    const status = PORTAL_SEARCH_STATUSES.find((known) => known === row.status);

    if (status === undefined) {
        throw new Error('A portal search answered without a status this screen knows.');
    }

    return {
        id: str(row, 'id'),
        status,
        pagesRead: num(row, 'pages_read'),
        retries: num(row, 'retries'),
        inLibrary: num(row, 'in_library'),
        failure: nullableStr(row, 'failure'),
        pollAfterMs: nullableNum(row, 'poll_after_ms'),
        acts: parsePortalActs(payload),
    };
}

/** A search of the portal kept and run again — a package's, or one a person keeps (`ext_law_watches`). */
export interface LawWatch {
    id: string;
    /** The package that declared it; null for one a person keeps. */
    declaredBy: string | null;
    mine: boolean;
    searchWords: string;
    actType: string;
    firstYear: number;
    everyHours: number;
    enabled: boolean;
    nextRunAt: string;
    lastRunAt: string | null;
    /** What its last run did; null before its first. */
    lastSummary: WatchRunSummary | null;
    /** A run a person asked for is on its way. */
    running: boolean;
    /** How many laws it read in. */
    laws: number;
}

/** One run of a watch, as counts — `Kern\Legislation\Watches\WatchRun`. */
export interface WatchRunSummary {
    years: number[];
    found: number;
    started: number;
    known: number;
    ignored: number;
    waiting: number;
    failed: number;
    retries: number;
    /** Why the search stopped — `portal_search_down`… — when it did. */
    failure: string | null;
}

/** An act a person asked the watches to pass over. */
export interface IgnoredAct {
    id: string;
    title: string;
    portalId: string;
    ignoredAt: string;
}

export function parseLawWatch(payload: unknown): LawWatch {
    const row = obj(payload);

    return {
        id: str(row, 'id'),
        declaredBy: nullableStr(row, 'declaredBy'),
        mine: bool(row, 'mine'),
        searchWords: str(row, 'searchWords'),
        actType: str(row, 'actType'),
        firstYear: num(row, 'firstYear'),
        everyHours: num(row, 'everyHours'),
        enabled: bool(row, 'enabled'),
        nextRunAt: str(row, 'nextRunAt'),
        lastRunAt: nullableStr(row, 'lastRunAt'),
        lastSummary: row.lastSummary === null || row.lastSummary === undefined ? null : parseWatchRun(row.lastSummary),
        running: bool(row, 'running'),
        laws: num(row, 'laws'),
    };
}

function parseWatchRun(payload: unknown): WatchRunSummary {
    const row = obj(payload);

    return {
        years: (Array.isArray(row.years) ? row.years : []).filter((year): year is number => typeof year === 'number'),
        found: num(row, 'found'),
        started: num(row, 'started'),
        known: num(row, 'known'),
        ignored: num(row, 'ignored'),
        waiting: num(row, 'waiting'),
        failed: num(row, 'failed'),
        retries: num(row, 'retries'),
        failure: nullableStr(row, 'failure'),
    };
}

/** Every watch, the intervals — in hours — a person may choose for one of their own, and the acts ignored. */
export function parseLawWatches(payload: unknown): { watches: LawWatch[]; intervals: number[]; ignored: IgnoredAct[] } {
    const row = obj(payload);
    const watches = Array.isArray(row.watches) ? row.watches : [];
    const intervals = Array.isArray(row.intervals) ? row.intervals : [];
    const ignored = Array.isArray(row.ignored) ? row.ignored : [];

    return {
        watches: watches.map(parseLawWatch),
        ignored: ignored.map((entry) => {
            const act = obj(entry);

            return {
                id: str(act, 'id'),
                title: str(act, 'title'),
                portalId: str(act, 'portalId'),
                ignoredAt: str(act, 'ignoredAt'),
            };
        }),
        intervals: intervals.filter(
            (hours): hours is number => typeof hours === 'number' && Number.isInteger(hours) && hours > 0,
        ),
    };
}
