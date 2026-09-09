import { apiRequest } from '../lib/http';
import {
    type ActivityPage,
    type OperationsOverview,
    parseActivityPage,
    parseOperationsOverview,
} from '../types/operations';

/**
 * `GET /api/v1/operations` — scheduler, queue, tasks, data freshness, service health and the
 * official-source registry in one response.
 *
 * One request rather than six because these panels only mean something together: an empty queue is
 * reassuring right up until you notice the scheduler stopped beating four hours ago.
 */
export async function getOperationsOverview(): Promise<OperationsOverview> {
    return parseOperationsOverview(JSON.parse(await apiRequest('/v1/operations')));
}

/**
 * `GET /api/v1/operations/activity` — task runs and failed queue jobs as one feed, newest first.
 *
 * Refetched on its own after a retry or a run, without re-probing every service on the installation
 * just to refresh a list.
 */
export async function listActivity(
    filters: { status?: string; kind?: string; page?: number } = {},
): Promise<ActivityPage> {
    const params = new URLSearchParams();

    if (filters.status !== undefined && filters.status !== '') {
        params.set('status', filters.status);
    }

    if (filters.kind !== undefined && filters.kind !== '') {
        params.set('kind', filters.kind);
    }

    if (filters.page !== undefined) {
        params.set('page', String(filters.page));
    }

    const query = params.toString();

    return parseActivityPage(JSON.parse(await apiRequest(`/v1/operations/activity${query === '' ? '' : `?${query}`}`)));
}

/**
 * `POST /api/v1/operations/tasks/{task}/run` — dispatch a task onto the queue.
 *
 * Answers 202, not 200: the work has been accepted, not done. Whether it succeeded appears in the
 * run ledger minutes later, which is the entire reason that ledger exists. A 409 means a run of the
 * same task is already in flight.
 */
export async function runOperationTask(task: string, args: Record<string, number | string> = {}): Promise<void> {
    await apiRequest(`/v1/operations/tasks/${encodeURIComponent(task)}/run`, { method: 'POST', body: args });
}

/** `PUT /api/v1/operations/tasks/{task}` — pause or resume. Takes effect on the next scheduler tick. */
export async function setScheduledTaskEnabled(task: string, enabled: boolean): Promise<void> {
    await apiRequest(`/v1/operations/tasks/${encodeURIComponent(task)}`, { method: 'PUT', body: { enabled } });
}

/** `POST /api/v1/operations/failed-jobs/{uuid}/retry` — re-queue one failure. */
export async function retryFailedJob(uuid: string): Promise<void> {
    await apiRequest(`/v1/operations/failed-jobs/${encodeURIComponent(uuid)}/retry`, { method: 'POST' });
}

/** `DELETE /api/v1/operations/failed-jobs/{uuid}` — discard it. The payload is the only record of what it would have done. */
export async function forgetFailedJob(uuid: string): Promise<void> {
    await apiRequest(`/v1/operations/failed-jobs/${encodeURIComponent(uuid)}`, { method: 'DELETE' });
}

/*
 * There is no `setSourceEnabled`. The official-source allowlist is declared in `config/sources.php`
 * and mirrored into `official_sources` on every fetch, so a write here would be overwritten by the
 * next import — and the fetcher checks the config-derived definition, not the row, so it would never
 * have taken effect at all. The screen renders it read-only. See `ReadSourceAllowlist`.
 */
