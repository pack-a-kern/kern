import { apiRequest } from '../lib/http';
import { type AuditPage, type AuditSummary, parseAuditPage, parseAuditSummary } from '../types/audit';

export interface AuditFilters {
    q?: string;
    eventType?: string;
    since?: string;
    cursor?: number;
}

/**
 * `GET /api/v1/audit/events` — a page of the trail, newest first.
 *
 * Paged by cursor on the chain sequence rather than by offset: the log is append-only and grows
 * while it is being read, which is exactly the case `OFFSET` gets wrong (rows shift between pages).
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

    if (filters.cursor !== undefined) {
        params.set('cursor', String(filters.cursor));
    }

    const query = params.toString();

    return parseAuditPage(JSON.parse(await apiRequest(`/v1/audit/events${query === '' ? '' : `?${query}`}`)));
}

/** `GET /api/v1/audit/summary` — counts, the event types that actually occur, and live chain verification. */
export async function getAuditSummary(): Promise<AuditSummary> {
    return parseAuditSummary(JSON.parse(await apiRequest('/v1/audit/summary')));
}
