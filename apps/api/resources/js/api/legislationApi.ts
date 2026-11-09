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
    type PortalSearchState,
    type ProvisionVersion,
    parseLawDetail,
    parseLawOutline,
    parseLaws,
    parseLawText,
    parseLegislationSearch,
    parsePortalSearch,
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
 * `POST /api/v1/legislation/portal-searches` — starts a search of legislatie.just.ro by words of the title
 * (and a year, if given) or by number and year, of one type if given. Answers at once with the search;
 * it is read a page at a time on the server, its progress announced on the person's own channel.
 */
export async function startPortalSearch(
    query: { title: string; year: number | null } | { number: number; year: number },
    type: string | null = null,
): Promise<PortalSearchState> {
    return parsePortalSearch(
        JSON.parse(
            await apiRequest('/v1/legislation/portal-searches', {
                method: 'POST',
                body: {
                    ...('title' in query ? { title: query.title } : { number: query.number }),
                    ...(query.year === null ? {} : { year: query.year }),
                    ...(type === null ? {} : { type }),
                },
            }),
        ),
    );
}

/** A portal search as it stands, with the acts found so far. */
export async function getPortalSearch(searchId: string, signal?: AbortSignal): Promise<PortalSearchState> {
    return parsePortalSearch(
        JSON.parse(
            await apiRequest(
                `/v1/legislation/portal-searches/${encodeURIComponent(searchId)}`,
                signal === undefined ? {} : { signal },
            ),
        ),
    );
}

/** Stops a running portal search; it keeps what it found. */
export async function cancelPortalSearch(searchId: string): Promise<PortalSearchState> {
    return parsePortalSearch(
        JSON.parse(
            await apiRequest(`/v1/legislation/portal-searches/${encodeURIComponent(searchId)}`, { method: 'DELETE' }),
        ),
    );
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

/**
 * Removes a law read in from the portal, with every consolidation and provision it brought.
 *
 * Refused with 409 while any fiscal value rests on one of its paragraphs: a value's whole claim is
 * the paragraph it was read in, and a law the values stand on is not something to lose by a click.
 */
export async function deleteLaw(lawId: string): Promise<void> {
    await apiRequest(`/v1/legislation/laws/${encodeURIComponent(lawId)}`, { method: 'DELETE' });
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
