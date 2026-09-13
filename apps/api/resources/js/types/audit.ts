/**
 * Shapes returned by `/api/v1/audit/*`.
 *
 * There is no payload and no IP address here, and that is not an omission: `audit_events` stores
 * `payload_hash` rather than the payload (`BLUEPRINT.md` §4.8), so the trail can prove a change
 * happened, and that the record of it is untampered, without becoming a second unencrypted copy of
 * the data it describes.
 */
export interface AuditEvent {
    id: string;
    chainKey: string;
    sequence: number;
    subjectId: string | null;
    actorId: string | null;
    eventType: string;
    resourceType: string;
    resourceId: string | null;
    occurredAt: string;
    payloadHash: string;
    previousHash: string;
    eventHash: string;
    signatureKeyVersion: number;
}

export interface AuditPage {
    events: AuditEvent[];
    /** Rows in the whole frozen window, not on this page — what the pager counts. */
    total: number;
    page: number;
    perPage: number;
    /** The window this run of paging is frozen to; echoed back with each page. */
    anchor: string | null;
}

export interface AuditSummary {
    eventsTotal: number;
    events24h: number;
    actors24h: number;
    eventTypes: string[];
    integrity: {
        ok: boolean;
        chainsChecked: number;
        eventsVerified: number;
        broken: { chainKey: string; sequence: number | null; reason: string | null } | null;
    };
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

export function parseAuditPage(payload: unknown): AuditPage {
    if (typeof payload !== 'object' || payload === null) {
        throw new TypeError('Audit page response is not an object.');
    }

    const body = payload as Record<string, unknown>;

    if (!Array.isArray(body.events)) {
        throw new TypeError('Audit page response has no events array.');
    }

    return {
        events: body.events.map((entry): AuditEvent => {
            if (typeof entry !== 'object' || entry === null) {
                throw new TypeError('Audit event is not an object.');
            }

            const row = entry as Record<string, unknown>;

            return {
                id: str(row, 'id'),
                chainKey: str(row, 'chain_key'),
                sequence: num(row, 'sequence'),
                subjectId: nullableStr(row, 'subject_id'),
                actorId: nullableStr(row, 'actor_id'),
                eventType: str(row, 'event_type'),
                resourceType: str(row, 'resource_type'),
                resourceId: nullableStr(row, 'resource_id'),
                occurredAt: str(row, 'occurred_at'),
                payloadHash: str(row, 'payload_hash'),
                previousHash: str(row, 'previous_hash'),
                eventHash: str(row, 'event_hash'),
                signatureKeyVersion: num(row, 'signature_key_version'),
            };
        }),
        total: num(body, 'total'),
        page: num(body, 'page'),
        perPage: num(body, 'per_page'),
        anchor: nullableStr(body, 'anchor'),
    };
}

export function parseAuditSummary(payload: unknown): AuditSummary {
    if (typeof payload !== 'object' || payload === null) {
        throw new TypeError('Audit summary response is not an object.');
    }

    const body = payload as Record<string, unknown>;
    const integrity = (typeof body.integrity === 'object' && body.integrity !== null ? body.integrity : {}) as Record<
        string,
        unknown
    >;
    const broken = (
        typeof integrity.broken === 'object' && integrity.broken !== null ? integrity.broken : null
    ) as Record<string, unknown> | null;

    return {
        eventsTotal: num(body, 'events_total'),
        events24h: num(body, 'events_24h'),
        actors24h: num(body, 'actors_24h'),
        eventTypes: Array.isArray(body.event_types)
            ? body.event_types.filter((entry): entry is string => typeof entry === 'string')
            : [],
        integrity: {
            ok: integrity.ok === true,
            chainsChecked: num(integrity, 'chains_checked'),
            eventsVerified: num(integrity, 'events_verified'),
            broken:
                broken === null
                    ? null
                    : {
                          chainKey: str(broken, 'chain_key'),
                          sequence: typeof broken.sequence === 'number' ? broken.sequence : null,
                          reason: nullableStr(broken, 'reason'),
                      },
        },
    };
}
