import { apiRequest } from '../lib/http';
import { type LegislationSearchResult, parseLegislationSearch } from '../types/legislation';

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
