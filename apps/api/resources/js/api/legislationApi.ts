import { apiRequest } from '../lib/http';
import {
    type LawDetail,
    type LawSummary,
    type LawText,
    type LegislationSearchResult,
    type ProvisionVersion,
    parseLawDetail,
    parseLaws,
    parseLawText,
    parseLegislationSearch,
    parseProvisionHistory,
} from '../types/legislation';

export interface LegislationSearchFilters {
    source?: string;
    tier?: string;
    asOf?: string;
    limit?: number;
}

/**
 * `GET /api/v1/legislation/search` — hybrid lexical + semantic search over ingested legislation.
 *
 * `asOf` decides which versions can match, so it is sent explicitly rather than left to the server's
 * clock: the same query run tomorrow would otherwise silently search different law.
 */
export async function searchLegislation(
    query: string,
    filters: LegislationSearchFilters = {},
): Promise<LegislationSearchResult> {
    const params = new URLSearchParams({ q: query });

    if (filters.source !== undefined && filters.source !== '') {
        params.set('source', filters.source);
    }

    if (filters.tier !== undefined && filters.tier !== '') {
        params.set('tier', filters.tier);
    }

    if (filters.asOf !== undefined && filters.asOf !== '') {
        params.set('as_of', filters.asOf);
    }

    if (filters.limit !== undefined) {
        params.set('limit', String(filters.limit));
    }

    return parseLegislationSearch(JSON.parse(await apiRequest(`/v1/legislation/search?${params.toString()}`)));
}

/** Every law read from legislatie.just.ro. */
export async function listLaws(): Promise<LawSummary[]> {
    return parseLaws(JSON.parse(await apiRequest('/v1/legislation/laws')));
}

export async function getLaw(lawId: string): Promise<LawDetail> {
    return parseLawDetail(JSON.parse(await apiRequest(`/v1/legislation/laws/${encodeURIComponent(lawId)}`)));
}

/** The law as it stood on `asOf`, a page of articles at a time. 404 when no consolidation covers the date. */
export async function getLawText(lawId: string, asOf: string, page: number): Promise<LawText> {
    const params = new URLSearchParams({ as_of: asOf, page: String(page) });

    return parseLawText(
        JSON.parse(await apiRequest(`/v1/legislation/laws/${encodeURIComponent(lawId)}/text?${params.toString()}`)),
    );
}

/** One provision's wording over time, newest first. */
export async function getProvisionHistory(lawId: string, citation: string): Promise<ProvisionVersion[]> {
    const params = new URLSearchParams({ citation });

    return parseProvisionHistory(
        JSON.parse(await apiRequest(`/v1/legislation/laws/${encodeURIComponent(lawId)}/history?${params.toString()}`)),
    );
}
