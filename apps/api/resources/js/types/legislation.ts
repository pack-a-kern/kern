/**
 * Shapes returned by `/api/v1/legislation/search`.
 *
 * The two result groups stay separate rather than being merged and sorted by score, because they
 * make different claims. A `confirmed` hit has effective dates covering the searched date. A
 * `dateUnknown` hit has no effective dates at all — which is most of this corpus, because
 * legislatie.just.ro publishes no machine-readable effective date and every ingested version
 * therefore lands `REQUIRES_REVIEW`.
 *
 * Showing them as one list would be the app asserting a text was in force on a date nobody has
 * established. A third case never arrives: a section whose known dates exclude the searched date is
 * dropped server-side, because that is a confirmed non-match rather than an unknown.
 */
export interface LegislationHit {
    chunkId: string;
    sectionId: string;
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
