import { apiRequest } from '../lib/http';
import { runOperationTask } from './operationsApi';
import {
    type IgnoredAct,
    type LawDetail,
    type LawsPage,
    type LawOutline,
    type LawPart,
    type LawText,
    type LawWatch,
    type LegislationSearchResult,
    type PortalActResult,
    type PortalSearchState,
    type ProvisionVersion,
    parseLawDetail,
    parseLawOutline,
    parseLaws,
    parseLawText,
    parseLawWatch,
    parseLawWatches,
    parseLegislationSearch,
    parsePortalSearch,
    parseProvisionHistory,
} from '../types/legislation';

/** Which laws the laws screen shows, and which its search looks in: of one type, year or watch — each, or any. */
export interface LawFilter {
    type: string | null;
    year: number | null;
    watch: string | null;
}

/** The filter as the server asks for it; what is "any" is left out. */
function lawFilterParams(filter: LawFilter): Record<string, string> {
    return {
        ...(filter.type === null ? {} : { type: filter.type }),
        ...(filter.year === null ? {} : { year: String(filter.year) }),
        ...(filter.watch === null ? {} : { watch: filter.watch }),
    };
}

/**
 * `GET /api/v1/legislation/search` — the provisions of the laws the filter keeps, by words and, where the
 * installation searches by meaning, by meaning.
 *
 * `asOf` decides which versions can match, so it is sent explicitly rather than left to the server's
 * clock: the same query run tomorrow would otherwise silently search different law.
 */
export async function searchLegislation(
    query: string,
    asOf: string,
    laws: LawFilter,
): Promise<LegislationSearchResult> {
    const params = new URLSearchParams({ q: query, as_of: asOf, ...lawFilterParams(laws) });

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
 * Starts reading an act from the portal — one a search found, or a law read again as it was first
 * read — through the ingest task, so it is locked, audited and in the run history. The portal id picks
 * it out where several acts share its type and number.
 */
export async function readPortalAct(
    act: Pick<PortalActResult, 'type' | 'number' | 'year' | 'portalId'>,
): Promise<void> {
    await runOperationTask('legislation.ingest', {
        act_type: act.type,
        act_number: Number(act.number),
        act_year: act.year ?? 0,
        ...(act.portalId === null ? {} : { portal_id: Number(act.portalId) }),
    });
}

/**
 * `POST /api/v1/legislation/imports` — several acts a search found, each queued to be read one after
 * another; those already read are passed over. How each goes is said over the socket (`subscribeToPortalActs` in `lib/echo`).
 */
export async function importPortalActs(
    acts: Pick<PortalActResult, 'type' | 'number' | 'year' | 'portalId'>[],
): Promise<{ queued: number; alreadyRead: number }> {
    const answer = JSON.parse(
        await apiRequest('/v1/legislation/imports', {
            method: 'POST',
            body: {
                acts: acts.map((act) => ({
                    type: act.type,
                    number: Number(act.number),
                    year: act.year ?? 0,
                    portal_id: Number(act.portalId),
                })),
            },
        }),
    ) as { queued?: unknown; already_read?: unknown };

    return {
        queued: typeof answer.queued === 'number' ? answer.queued : 0,
        alreadyRead: typeof answer.already_read === 'number' ? answer.already_read : 0,
    };
}

/**
 * One page of the laws read from legislatie.just.ro of one type, year or watch — each, or any — how many
 * there are in all, and what may be filtered by.
 */
export async function listLaws(filter: LawFilter, page: number, pageSize: number): Promise<LawsPage> {
    const query = new URLSearchParams({
        ...lawFilterParams(filter),
        page: String(page),
        per_page: String(pageSize),
    }).toString();

    return parseLaws(JSON.parse(await apiRequest(`/v1/legislation/laws?${query}`)));
}

/**
 * Removes a law read in from the portal, with every consolidation and provision it brought.
 *
 * Refused with 409 while any fiscal value rests on one of its paragraphs: a value's whole claim is
 * the paragraph it was read in, and a law the values stand on is not something to lose by a click.
 */
export async function deleteLaw(lawId: string, ignore: boolean): Promise<void> {
    await apiRequest(`/v1/legislation/laws/${encodeURIComponent(lawId)}`, { method: 'DELETE', body: { ignore } });
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

/** Every watched search, the intervals a person may choose, and the acts the watches ignore. */
export async function listLawWatches(): Promise<{ watches: LawWatch[]; intervals: number[]; ignored: IgnoredAct[] }> {
    return parseLawWatches(JSON.parse(await apiRequest('/v1/legislation/watches')));
}

/** Keeps a search to be run again every `everyHours`, every year from `firstYear`. */
export async function keepLawWatch(watch: {
    searchWords: string;
    actType: string;
    firstYear: number;
    everyHours: number;
}): Promise<LawWatch> {
    return parseLawWatch(
        JSON.parse(
            await apiRequest('/v1/legislation/watches', {
                method: 'POST',
                body: {
                    search_words: watch.searchWords,
                    act_type: watch.actType,
                    first_year: watch.firstYear,
                    every_hours: watch.everyHours,
                },
            }),
        ),
    );
}

/** Pauses or resumes a watch; a person's own also runs more or less often. */
export async function changeLawWatch(
    id: string,
    change: { enabled?: boolean; everyHours?: number },
): Promise<LawWatch> {
    return parseLawWatch(
        JSON.parse(
            await apiRequest(`/v1/legislation/watches/${encodeURIComponent(id)}`, {
                method: 'PATCH',
                body: {
                    ...(change.enabled === undefined ? {} : { enabled: change.enabled }),
                    ...(change.everyHours === undefined ? {} : { every_hours: change.everyHours }),
                },
            }),
        ),
    );
}

/** Stops watching a search a person kept; the laws it found stay. A package's is refused. */
export async function deleteLawWatch(id: string): Promise<void> {
    await apiRequest(`/v1/legislation/watches/${encodeURIComponent(id)}`, { method: 'DELETE' });
}

/** Runs a watch now, on the queue; it answers at once, and says over the socket when the run is done. */
export async function runLawWatch(id: string): Promise<LawWatch> {
    return parseLawWatch(
        JSON.parse(await apiRequest(`/v1/legislation/watches/${encodeURIComponent(id)}/run`, { method: 'POST' })),
    );
}

/** Takes an act off the ignored list: the next run that finds it reads it in. */
export async function allowIgnoredAct(id: string): Promise<void> {
    await apiRequest(`/v1/legislation/watches/ignored/${encodeURIComponent(id)}`, { method: 'DELETE' });
}
