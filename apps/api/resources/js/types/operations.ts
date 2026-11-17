import { bool, nullableNum, nullableStr, num, obj, str } from '../lib/json';

/**
 * Shapes returned by `/api/v1/operations/*`.
 *
 * Two absences are deliberate rather than unfinished. A failed job carries no payload: the stored
 * payload holds the job's constructor arguments, which in this application reach document and tax
 * subject ids, so rendering it in a browser would make the operations screen a bypass around every
 * access control the rest of the application enforces. And every count in {@link QueueSnapshot} is
 * nullable: when Horizon cannot be reached the counts are `null`, never `0` — a zero would read as
 * "nothing is queued" when the truth is that nobody can tell.
 */

/** The four tones the shared `Status` component renders. Decided by the server, beside the threshold. */
export type OperationState = 'ok' | 'warn' | 'bad' | 'idle';

export interface SchedulerStatus {
    alive: boolean;
    lastBeatAt: string | null;
    ageSeconds: number | null;
    maxAgeSeconds: number;
}

export interface QueueSnapshot {
    available: boolean;
    pending: number | null;
    completed: number | null;
    failed: number | null;
    supervisors: number | null;
    masterStatus: string | null;
    maxWaitSeconds: number | null;
    /** Jobs processed per minute over Horizon's measurement window. */
    throughput: number | null;
}

/**
 * An argument the run dialog renders. Every type is constrained — a bounded range, a closed set, or
 * a URL the server checks against the chosen source's allowlist before it dispatches anything.
 * There is deliberately no free-text type.
 */
export interface TaskArgument {
    name: string;
    type: 'integer' | 'choice' | 'date';
    min: number | null;
    max: number | null;
    options: string[];
    required: boolean;
}

export interface TaskRun {
    id: string;
    taskKey: string;
    trigger: string;
    status: string;
    triggeredBy: string | null;
    arguments: Record<string, number> | null;
    startedAt: string;
    finishedAt: string | null;
    durationSeconds: number | null;
    exitCode: number | null;
    output: string | null;
}

/**
 * One queued job, as Horizon records it.
 *
 * Not the same population as a {@link TaskRun}, and the two are never mixed: an operation task
 * reaches the queue wrapped in a single job class and writes its own outcome to `task_runs`, so it
 * never appears among these failures. The payload is deliberately absent — it carries document and
 * tax-subject ids.
 */
export interface QueueJob {
    id: string;
    /** The job's class name. Not translated: it is a class, and a fake friendly name would lie. */
    name: string;
    queue: string;
    connection: string;
    status: string;
    queuedAt: string | null;
    completedAt: string | null;
    failedAt: string | null;
    exception: string | null;
    retried: boolean;
}

export interface QueueJobsPage {
    jobs: QueueJob[];
    total: number;
    page: number;
    perPage: number;
    /** False when Horizon could not be reached — an empty list then means "nobody can tell". */
    available: boolean;
    queue: QueueSnapshot;
}

/** `GET /v1/operations/tasks/{task}/runs` — one task's history, newest first. */
export interface TaskRunsPage {
    runs: TaskRun[];
    total: number;
    page: number;
    perPage: number;
}

/** `GET /v1/settings/platform-data` — the source allowlist, how fresh each feed is, and the
 *  scheduler's heartbeat, which the settings screen shows beside the other services. */
export interface PlatformData {
    scheduler: SchedulerStatus;
    freshness: FreshnessItem[];
    sources: SourceRegistryEntry[];
}

export interface OperationTaskView {
    key: string;
    command: string;
    /** On the schedule right now: declared schedulable, configured with a timing, and not paused. */
    scheduled: boolean;
    /** Declared schedulable in code. A task that is not can still be run on demand. */
    schedulable: boolean;
    /** Running it writes nothing — a verification or a catalogue sync. */
    readOnly: boolean;
    cronExpression: string | null;
    timezone: string;
    enabled: boolean;
    running: boolean;
    arguments: TaskArgument[];
    lastRun: TaskRun | null;
}

export interface FreshnessItem {
    key: string;
    state: OperationState;
    value: string | null;
    detail: string | null;
}

export interface ComponentHealth {
    key: string;
    state: string;
    detail: string | null;
}

export interface SourceRegistryEntry {
    code: string;
    trustTier: string;
    baseUrl: string;
    enabled: boolean;
    /** Which source is preferred when two publish the same data: 1 before 2. Null if the entry declares none. */
    priority: number | null;
    /**
     * Whether an `ext_official_sources` row exists yet. The table is filled lazily, on first fetch, so
     * `false` means declared and allowed but never used — which is what an entire empty table looks
     * like after a restore, and is very different from "nothing is allowlisted".
     */
    mirrored: boolean;
}

export interface OperationsOverview {
    scheduler: SchedulerStatus;
    tasks: OperationTaskView[];
}

/** Anything the server did not send one of the four known tones for renders as `idle`, never as `ok`. */
function state(row: Record<string, unknown>, key: string): OperationState {
    const value = row[key];

    return value === 'ok' || value === 'warn' || value === 'bad' ? value : 'idle';
}

function parseTaskRun(payload: unknown): TaskRun {
    const row = obj(payload);
    const args = row.arguments;

    return {
        id: str(row, 'id'),
        taskKey: str(row, 'task_key'),
        trigger: str(row, 'trigger'),
        status: str(row, 'status'),
        triggeredBy: nullableStr(row, 'triggered_by'),
        arguments:
            typeof args === 'object' && args !== null
                ? Object.fromEntries(
                      Object.entries(args as Record<string, unknown>).filter(
                          (entry): entry is [string, number] => typeof entry[1] === 'number',
                      ),
                  )
                : null,
        startedAt: str(row, 'started_at'),
        finishedAt: nullableStr(row, 'finished_at'),
        durationSeconds: nullableNum(row, 'duration_seconds'),
        exitCode: nullableNum(row, 'exit_code'),
        output: nullableStr(row, 'output'),
    };
}

export function parseOperationsOverview(payload: unknown): OperationsOverview {
    const body = obj(payload);
    const scheduler = obj(body.scheduler);

    return {
        scheduler: {
            alive: bool(scheduler, 'alive'),
            lastBeatAt: nullableStr(scheduler, 'last_beat_at'),
            ageSeconds: nullableNum(scheduler, 'age_seconds'),
            maxAgeSeconds: num(scheduler, 'max_age_seconds'),
        },
        tasks: (Array.isArray(body.tasks) ? body.tasks : []).map((entry): OperationTaskView => {
            const row = obj(entry);

            return {
                key: str(row, 'key'),
                command: str(row, 'command'),
                scheduled: bool(row, 'scheduled'),
                schedulable: bool(row, 'schedulable'),
                readOnly: bool(row, 'read_only'),
                cronExpression: nullableStr(row, 'cron_expression'),
                timezone: str(row, 'timezone'),
                enabled: bool(row, 'enabled'),
                running: bool(row, 'running'),
                arguments: (Array.isArray(row.arguments) ? row.arguments : []).map((argument): TaskArgument => {
                    const spec = obj(argument);

                    const type = str(spec, 'type');

                    return {
                        name: str(spec, 'name'),
                        // Anything unrecognised renders as a closed set with no options, so it
                        // cannot become an unconstrained text box by accident.
                        type: type === 'integer' || type === 'date' ? type : 'choice',
                        min: nullableNum(spec, 'min'),
                        max: nullableNum(spec, 'max'),
                        options: Array.isArray(spec.options)
                            ? spec.options.filter((option): option is string => typeof option === 'string')
                            : [],
                        required: spec.required !== false,
                    };
                }),
                lastRun: row.last_run === null || row.last_run === undefined ? null : parseTaskRun(row.last_run),
            };
        }),
    };
}

function parseQueueSnapshot(payload: unknown): QueueSnapshot {
    const queue = obj(payload);

    return {
        available: bool(queue, 'available'),
        pending: nullableNum(queue, 'pending'),
        completed: nullableNum(queue, 'completed'),
        failed: nullableNum(queue, 'failed'),
        supervisors: nullableNum(queue, 'supervisors'),
        masterStatus: nullableStr(queue, 'master_status'),
        maxWaitSeconds: nullableNum(queue, 'max_wait_seconds'),
        throughput: nullableNum(queue, 'throughput'),
    };
}

export function parseQueueJobsPage(payload: unknown): QueueJobsPage {
    const body = obj(payload);

    return {
        jobs: (Array.isArray(body.jobs) ? body.jobs : []).map((entry): QueueJob => {
            const row = obj(entry);

            return {
                id: str(row, 'id'),
                name: str(row, 'name'),
                queue: str(row, 'queue'),
                connection: str(row, 'connection'),
                status: str(row, 'status'),
                queuedAt: nullableStr(row, 'queued_at'),
                completedAt: nullableStr(row, 'completed_at'),
                failedAt: nullableStr(row, 'failed_at'),
                exception: nullableStr(row, 'exception'),
                retried: bool(row, 'retried'),
            };
        }),
        total: num(body, 'total'),
        page: num(body, 'page'),
        perPage: num(body, 'per_page'),
        available: bool(body, 'available'),
        queue: parseQueueSnapshot(body.queue),
    };
}

/** One queue as the activity indicator shows it — the name Horizon knows it by, and its counts. */
export interface QueueActivityEntry {
    name: string;
    waiting: number;
    processes: number;
}

/** A named task open in the run ledger right now. */
export interface RunningTask {
    key: string;
    since: string;
}

/** The live picture behind the topbar's queue-activity indicator (`GET /v1/operations/activity`). */
export interface OperationsActivity {
    available: boolean;
    moving: boolean;
    pending: number;
    failed: number;
    /** How long to wait before asking again when no socket nudge came, in milliseconds. */
    pollAfterMs: number;
    queues: QueueActivityEntry[];
    running: RunningTask[];
}

export function parseOperationsActivity(payload: unknown): OperationsActivity {
    const body = obj(payload);

    return {
        available: bool(body, 'available'),
        moving: bool(body, 'moving'),
        pending: num(body, 'pending'),
        failed: num(body, 'failed'),
        pollAfterMs: num(body, 'poll_after_ms'),
        queues: (Array.isArray(body.queues) ? body.queues : []).map((entry): QueueActivityEntry => {
            const row = obj(entry);

            return { name: str(row, 'name'), waiting: num(row, 'waiting'), processes: num(row, 'processes') };
        }),
        running: (Array.isArray(body.running) ? body.running : []).map((entry): RunningTask => {
            const row = obj(entry);

            return { key: str(row, 'key'), since: str(row, 'since') };
        }),
    };
}

export function parseTaskRunsPage(payload: unknown): TaskRunsPage {
    const body = obj(payload);

    return {
        runs: (Array.isArray(body.runs) ? body.runs : []).map(parseTaskRun),
        total: num(body, 'total'),
        page: num(body, 'page'),
        perPage: num(body, 'per_page'),
    };
}

export function parsePlatformData(payload: unknown): PlatformData {
    const body = obj(payload);
    const scheduler = obj(body.scheduler);

    return {
        scheduler: {
            alive: bool(scheduler, 'alive'),
            lastBeatAt: nullableStr(scheduler, 'last_beat_at'),
            ageSeconds: nullableNum(scheduler, 'age_seconds'),
            maxAgeSeconds: num(scheduler, 'max_age_seconds'),
        },
        freshness: (Array.isArray(body.freshness) ? body.freshness : []).map((entry): FreshnessItem => {
            const row = obj(entry);

            return {
                key: str(row, 'key'),
                state: state(row, 'state'),
                value: nullableStr(row, 'value'),
                detail: nullableStr(row, 'detail'),
            };
        }),
        sources: (Array.isArray(body.sources) ? body.sources : []).map((entry): SourceRegistryEntry => {
            const row = obj(entry);

            return {
                code: str(row, 'code'),
                trustTier: str(row, 'trust_tier'),
                baseUrl: str(row, 'base_url'),
                enabled: bool(row, 'enabled'),
                priority: typeof row.priority === 'number' ? row.priority : null,
                mirrored: bool(row, 'mirrored'),
            };
        }),
    };
}
