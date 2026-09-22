import { apiRequest } from '../lib/http';
import { runOperationTask } from './operationsApi';
import {
    type LawDetail,
    type LawOutline,
    type LawPart,
    type LawSummary,
    type LawText,
    type LegislationSearchResult,
    type PortalActResult,
    type ProvisionVersion,
    parseLawDetail,
    parseLawOutline,
    parseLaws,
    parseLawText,
    parseLegislationSearch,
    parsePortalActs,
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

/**
 * `GET /api/v1/legislation/portal` — acts on legislatie.just.ro, by words of their title (and a year,
 * if given) or by number and year, of one type if given. Only a search: reading one is the ingest task.
 */
export async function searchPortal(
    query: { title: string; year: number | null } | { number: number; year: number },
    type: string | null = null,
): Promise<PortalActResult[]> {
    const params = new URLSearchParams(
        'title' in query ? { title: query.title } : { number: String(query.number) },
    );

    if (query.year !== null) {
        params.set('year', String(query.year));
    }

    if (type !== null) {
        params.set('type', type);
    }

    return parsePortalActs(JSON.parse(await apiRequest(`/v1/legislation/portal?${params.toString()}`)));
}

/**
 * Starts reading an act found on the portal — through the ingest task, so it is locked, audited and
 * in the run history. The portal id picks it out where several acts share its type and number.
 */
export async function readPortalAct(act: PortalActResult): Promise<void> {
    await runOperationTask('legislation.ingest', {
        act_type: act.type,
        act_number: Number(act.number),
        act_year: act.year ?? 0,
        ...(act.portalId === null ? {} : { portal_id: Number(act.portalId) }),
    });
}

/** Every law read from legislatie.just.ro. */
export async function listLaws(): Promise<LawSummary[]> {
    return parseLaws(JSON.parse(await apiRequest('/v1/legislation/laws')));
}

export async function getLaw(lawId: string): Promise<LawDetail> {
    return parseLawDetail(JSON.parse(await apiRequest(`/v1/legislation/laws/${encodeURIComponent(lawId)}`)));
}

/**
 * One part of the law as it stood on `asOf`: the heading at `heading`'s path, or the one `provision`
 * stands in, or the first. 404 when no consolidation covers the date.
 */
export async function getLawText(lawId: string, asOf: string, part: LawPart): Promise<LawText> {
    const params = new URLSearchParams({ as_of: asOf });

    if (part.provision !== null) {
        params.set('provision', part.provision);
    } else if (part.heading !== null) {
        params.set('heading', part.heading);
    }

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

/** The law's table of contents on `asOf`, each heading with the articles under it. */
export async function getLawOutline(lawId: string, asOf: string): Promise<LawOutline> {
    const params = new URLSearchParams({ as_of: asOf });

    return parseLawOutline(
        JSON.parse(await apiRequest(`/v1/legislation/laws/${encodeURIComponent(lawId)}/outline?${params.toString()}`)),
    );
}
