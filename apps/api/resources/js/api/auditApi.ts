import { apiRequest } from '../lib/http';
import { type AuditPage, type AuditSummary, parseAuditPage, parseAuditSummary } from '../types/audit';

export interface AuditFilters {
    q?: string;
    eventType?: string;
    since?: string;
    page?: number;
    perPage?: number;
    /** Pins the page to the window the first request returned — see `AuditEventQuery`. */
    anchor?: string;
}

/**
 * `GET /api/v1/audit/events` — a page of the trail, newest first.
 *
 * Paged by number, over a window frozen at `anchor`: the log is append-only and grows while it is
 * being read, so without that bound a row appended between two requests would shift every later
 * page. The server returns the anchor with the first page; pass it back for the rest, and drop it
 * when a filter changes.
 */
export async function listAuditEvents(filters: AuditFilters = {}): Promise<AuditPage> {
    const params = new URLSearchParams();

    if (filters.q !== undefined && filters.q !== '') {
        params.set('q', filters.q);
    }

    if (filters.eventType !== undefined && filters.eventType !== '') {
        params.set('event_type', filters.eventType);
    }

    if (filters.since !== undefined && filters.since !== '') {
        params.set('since', filters.since);
    }

    if (filters.anchor !== undefined && filters.anchor !== '') {
        params.set('anchor', filters.anchor);
    }

    if (filters.page !== undefined && filters.page > 1) {
        params.set('page', String(filters.page));
    }

    if (filters.perPage !== undefined) {
        params.set('per_page', String(filters.perPage));
    }

    const query = params.toString();

    return parseAuditPage(JSON.parse(await apiRequest(`/v1/audit/events${query === '' ? '' : `?${query}`}`)));
}

/** `GET /api/v1/audit/summary` — counts, the event types that actually occur, and live chain verification. */
export async function getAuditSummary(): Promise<AuditSummary> {
    return parseAuditSummary(JSON.parse(await apiRequest('/v1/audit/summary')));
}
