import { apiRequest } from '../lib/http';
import {
    type OperationsActivity,
    type OperationsOverview,
    type QueueJobsPage,
    type TaskRunsPage,
    parseOperationsActivity,
    parseOperationsOverview,
    parseQueueJobsPage,
    parseTaskRunsPage,
} from '../types/operations';

/**
 * `GET /api/v1/operations/activity` — the live picture behind the topbar's queue-activity indicator:
 * the queues and their waiting counts, the tasks running now, and whether the queue is moving. Polled
 * while on screen, and asked again on each `operations.activity.changed` nudge.
 */
export async function getOperationsActivity(): Promise<OperationsActivity> {
    return parseOperationsActivity(JSON.parse(await apiRequest('/v1/operations/activity')));
}

function pageQuery(page: number | undefined, perPage: number | undefined): URLSearchParams {
    const params = new URLSearchParams();

    if (page !== undefined && page > 1) {
        params.set('page', String(page));
    }

    if (perPage !== undefined) {
        params.set('per_page', String(perPage));
    }

    return params;
}

/**
 * `GET /api/v1/operations` — the scheduler's heartbeat and the six tasks.
 *
 * One request rather than two because they only mean something together: "last run succeeded" is
 * worthless next to a heartbeat that went stale four hours ago.
 */
export async function getOperationsOverview(): Promise<OperationsOverview> {
    return parseOperationsOverview(JSON.parse(await apiRequest('/v1/operations')));
}

/**
 * `GET /api/v1/operations/jobs` — a page of the queue, read from Horizon.
 *
 * `status` picks which of Horizon's lists is read (`all`, `pending`, `completed`, `failed`), and is
 * not a filter across a merged one — a Redis sorted set cannot answer a filtered page, so each
 * status has its own pre-built index. Everything except `failed` is a 60-minute window; failures
 * keep 7 days. The counters come back with the page, so the tiles never lag the table.
 */
export async function listQueueJobs(
    filters: { status?: string; page?: number; perPage?: number } = {},
): Promise<QueueJobsPage> {
    const params = pageQuery(filters.page, filters.perPage);

    if (filters.status !== undefined && filters.status !== '') {
        params.set('status', filters.status);
    }

    const query = params.toString();

    return parseQueueJobsPage(JSON.parse(await apiRequest(`/v1/operations/jobs${query === '' ? '' : `?${query}`}`)));
}

/**
 * `GET /api/v1/operations/tasks/{task}/runs` — one task's run history, newest first.
 *
 * Per task rather than a combined ledger: the task list already answers "did the last one work",
 * and this answers the only question left, which is about one task at a time.
 */
export async function listTaskRuns(
    task: string,
    filters: { page?: number; perPage?: number } = {},
): Promise<TaskRunsPage> {
    const query = pageQuery(filters.page, filters.perPage).toString();

    return parseTaskRunsPage(
        JSON.parse(
            await apiRequest(`/v1/operations/tasks/${encodeURIComponent(task)}/runs${query === '' ? '' : `?${query}`}`),
        ),
    );
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
 * have taken effect at all. It is read through `getPlatformData` in `settingsApi`, and rendered
 * read-only. See `ReadSourceAllowlist`.
 */
