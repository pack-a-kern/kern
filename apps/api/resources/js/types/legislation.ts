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
    trustTier: string;
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

function obj(value: unknown): Record<string, unknown> {
    return typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {};
}

function str(row: Record<string, unknown>, key: string): string {
    const value = row[key];

    return typeof value === 'string' ? value : '';
}

function nullableStr(row: Record<string, unknown>, key: string): string | null {
    const value = row[key];

    return typeof value === 'string' ? value : null;
}

function num(row: Record<string, unknown>, key: string): number {
    const value = row[key];

    return typeof value === 'number' ? value : 0;
}

function nullableNum(row: Record<string, unknown>, key: string): number | null {
    const value = row[key];

    return typeof value === 'number' ? value : null;
}

function parseHit(payload: unknown): LegislationHit {
    const row = obj(payload);

    return {
        chunkId: str(row, 'chunk_id'),
        sectionId: str(row, 'section_id'),
        sourceDocumentId: str(row, 'source_document_id'),
        sourceCode: str(row, 'source_code'),
        trustTier: str(row, 'trust_tier'),
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
}

export interface LawSummary extends Law {
    consolidationsListed: number;
    consolidationsRead: number;
    consolidationsFailed: number;
    firstConsolidation: string | null;
    lastConsolidation: string | null;
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

/** One table's rows of cells. */
export type LawTable = string[][];

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
    /** The page of the text it opens on; null for a heading with no article under it. */
    page: number | null;
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

export interface LawText {
    asOf: string;
    versionId: string;
    effectiveFrom: string;
    effectiveUntil: string | null;
    page: number;
    perPage: number;
    /** Top-level provisions — articles and annexes — in the whole law. */
    total: number;
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
    text: string;
    tables: LawTable[];
    notes: LawNote[];
}

function tables(value: unknown): LawTable[] {
    return (Array.isArray(value) ? value : []).map((table) =>
        (Array.isArray(table) ? table : []).map((row) =>
            (Array.isArray(row) ? row : []).map((cell) => (typeof cell === 'string' ? cell : '')),
        ),
    );
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
                page: nullableNum(row, 'page'),
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
    };
}

export function parseLaws(payload: unknown): LawSummary[] {
    return (Array.isArray(obj(payload).laws) ? (obj(payload).laws as unknown[]) : []).map((entry) => {
        const row = obj(entry);

        return {
            ...parseLaw(row),
            consolidationsListed: num(row, 'consolidations_listed'),
            consolidationsRead: num(row, 'consolidations_read'),
            consolidationsFailed: num(row, 'consolidations_failed'),
            firstConsolidation: nullableStr(row, 'first_consolidation'),
            lastConsolidation: nullableStr(row, 'last_consolidation'),
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
        page: num(body, 'page'),
        perPage: num(body, 'per_page'),
        total: num(body, 'total'),
        sections: (Array.isArray(body.sections) ? body.sections : []).map((entry): LawSection => {
            const row = obj(entry);

            return {
                id: str(row, 'id'),
                type: str(row, 'type'),
                citation: str(row, 'citation'),
                label: str(row, 'label'),
                depth: num(row, 'depth'),
                text: str(row, 'text'),
                tables: tables(row.tables),
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
            text: str(row, 'text'),
            tables: tables(row.tables),
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
            lawId: nullableStr(row, 'law_id'),
        };
    });
}
