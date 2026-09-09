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
    recentlyFailed: number | null;
    supervisors: number | null;
    masterStatus: string | null;
    maxWaitSeconds: number | null;
}

/**
 * An argument the run dialog renders. Every type is constrained — a bounded range, a closed set, or
 * a URL the server checks against the chosen source's allowlist before it dispatches anything.
 * There is deliberately no free-text type.
 */
export interface TaskArgument {
    name: string;
    type: 'integer' | 'choice' | 'url';
    min: number | null;
    max: number | null;
    options: string[];
    required: boolean;
}

export interface JobRun {
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
 * One line of the merged background-activity feed.
 *
 * `kind` is not decoration: it says which record the row came from, and therefore what can be done
 * with it. Running a task again and re-queueing a failed job are different operations against
 * different endpoints, so a row that lost its origin would also lose its action. Fields only one
 * source has stay `null` on the other rather than being flattened away.
 */
export type ActivityKind = 'task_run' | 'failed_job';

export interface ActivityEntry {
    kind: ActivityKind;
    id: string;
    /** An `OperationTask` key (translated on screen) or a failed job's class name. */
    label: string;
    occurredAt: string;
    status: string;
    trigger: string | null;
    durationSeconds: number | null;
    exitCode: number | null;
    output: string | null;
    queue: string | null;
    connection: string | null;
    exception: string | null;
}

export interface ActivityPage {
    activity: ActivityEntry[];
    total: number;
    page: number;
    pages: number;
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
    lastRun: JobRun | null;
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
    /**
     * Whether an `official_sources` row exists yet. The table is filled lazily, on first fetch, so
     * `false` means declared and allowed but never used — which is what an entire empty table looks
     * like after a restore, and is very different from "nothing is allowlisted".
     */
    mirrored: boolean;
}

export interface OperationsOverview {
    scheduler: SchedulerStatus;
    queue: QueueSnapshot;
    tasks: OperationTaskView[];
    freshness: FreshnessItem[];
    health: ComponentHealth[];
    sources: SourceRegistryEntry[];
}

function obj(value: unknown): Record<string, unknown> {
    return typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {};
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

function nullableNum(row: Record<string, unknown>, key: string): number | null {
    const value = row[key];

    return typeof value === 'number' ? value : null;
}

function bool(row: Record<string, unknown>, key: string): boolean {
    return row[key] === true;
}

/** Anything the server did not send one of the four known tones for renders as `idle`, never as `ok`. */
function state(row: Record<string, unknown>, key: string): OperationState {
    const value = row[key];

    return value === 'ok' || value === 'warn' || value === 'bad' ? value : 'idle';
}

function parseJobRun(payload: unknown): JobRun {
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
    const queue = obj(body.queue);

    return {
        scheduler: {
            alive: bool(scheduler, 'alive'),
            lastBeatAt: nullableStr(scheduler, 'last_beat_at'),
            ageSeconds: nullableNum(scheduler, 'age_seconds'),
            maxAgeSeconds: num(scheduler, 'max_age_seconds'),
        },
        queue: {
            available: bool(queue, 'available'),
            pending: nullableNum(queue, 'pending'),
            completed: nullableNum(queue, 'completed'),
            failed: nullableNum(queue, 'failed'),
            recentlyFailed: nullableNum(queue, 'recently_failed'),
            supervisors: nullableNum(queue, 'supervisors'),
            masterStatus: nullableStr(queue, 'master_status'),
            maxWaitSeconds: nullableNum(queue, 'max_wait_seconds'),
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
                        type: type === 'integer' || type === 'url' ? type : 'choice',
                        min: nullableNum(spec, 'min'),
                        max: nullableNum(spec, 'max'),
                        options: Array.isArray(spec.options)
                            ? spec.options.filter((option): option is string => typeof option === 'string')
                            : [],
                        required: spec.required !== false,
                    };
                }),
                lastRun: row.last_run === null || row.last_run === undefined ? null : parseJobRun(row.last_run),
            };
        }),
        freshness: (Array.isArray(body.freshness) ? body.freshness : []).map((entry): FreshnessItem => {
            const row = obj(entry);

            return {
                key: str(row, 'key'),
                state: state(row, 'state'),
                value: nullableStr(row, 'value'),
                detail: nullableStr(row, 'detail'),
            };
        }),
        health: (Array.isArray(body.health) ? body.health : []).map((entry): ComponentHealth => {
            const row = obj(entry);

            return { key: str(row, 'key'), state: str(row, 'state'), detail: nullableStr(row, 'detail') };
        }),
        sources: (Array.isArray(body.sources) ? body.sources : []).map((entry): SourceRegistryEntry => {
            const row = obj(entry);

            return {
                code: str(row, 'code'),
                trustTier: str(row, 'trust_tier'),
                baseUrl: str(row, 'base_url'),
                enabled: bool(row, 'enabled'),
                mirrored: bool(row, 'mirrored'),
            };
        }),
    };
}

export function parseActivityPage(payload: unknown): ActivityPage {
    const body = obj(payload);

    return {
        activity: (Array.isArray(body.activity) ? body.activity : []).map((entry): ActivityEntry => {
            const row = obj(entry);

            return {
                // Anything the server did not label as a known kind is treated as a failed job:
                // that is the row with the least assumed about it, and it renders read-only.
                kind: row.kind === 'task_run' ? 'task_run' : 'failed_job',
                id: str(row, 'id'),
                label: str(row, 'label'),
                occurredAt: str(row, 'occurred_at'),
                status: str(row, 'status'),
                trigger: nullableStr(row, 'trigger'),
                durationSeconds: nullableNum(row, 'duration_seconds'),
                exitCode: nullableNum(row, 'exit_code'),
                output: nullableStr(row, 'output'),
                queue: nullableStr(row, 'queue'),
                connection: nullableStr(row, 'connection'),
                exception: nullableStr(row, 'exception'),
            };
        }),
        total: num(body, 'total'),
        page: num(body, 'page'),
        pages: num(body, 'pages'),
    };
}
