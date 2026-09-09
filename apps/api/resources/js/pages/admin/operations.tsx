import { useCallback, useEffect, useState, type JSX } from 'react';
import { toast } from 'sonner';

import {
    forgetFailedJob,
    getOperationsOverview,
    listActivity,
    retryFailedJob,
    runOperationTask,
    setScheduledTaskEnabled,
} from '../../api/operationsApi';
import AppShell from '../../components/AppShell';
import {
    DataTable,
    Detail,
    DetailList,
    Field,
    Page,
    Panel,
    PanelButton,
    PanelDialog,
    PanelInput,
    PanelSelect,
    Pill,
    Row,
    RowList,
    Stat,
    StatGrid,
    Status,
    Toolbar,
    type StatusTone,
} from '../../components/panel';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { Skeleton } from '../../components/ui/skeleton';
import { Switch } from '../../components/ui/switch';
import { useTranslation } from '../../i18n';
import { ApiError } from '../../lib/http';
import type { ActivityEntry, ActivityPage, OperationTaskView, OperationsOverview } from '../../types/operations';

/**
 * The background-operations console.
 *
 * What this screen shows is bounded by what the server can honestly answer, and two of those bounds
 * are worth stating because they look like gaps:
 *
 *  - Queue counts render as `—`, not `0`, when Horizon cannot be reached. A zero would read as
 *    "nothing is queued" during the exact incident where the truth is that nobody can tell.
 *  - A failed job shows its class name and the first line of its exception, never its payload. The
 *    payload carries document and tax subject ids, so rendering it here would turn this screen into
 *    a bypass around every access control the rest of the application enforces.
 *
 * The task list has a pause switch and a "Run now" button, and deliberately no schedule editor.
 * Which tasks exist is a PHP enum, and when they run is configuration; a form that could change
 * either would be remote code execution in the first case and, in the second, the standard way a
 * job silently stops running forever.
 */
export default function AdminOperations(): JSX.Element {
    const { t } = useTranslation();

    const [overview, setOverview] = useState<OperationsOverview | null>(null);
    const [activity, setActivity] = useState<ActivityPage | null>(null);
    const [forbidden, setForbidden] = useState(false);
    const [busy, setBusy] = useState(false);

    const [statusFilter, setStatusFilter] = useState('all');
    const [kindFilter, setKindFilter] = useState('all');
    const [activityPage, setActivityPage] = useState(1);

    const [selected, setSelected] = useState<ActivityEntry | null>(null);
    const [taskToRun, setTaskToRun] = useState<OperationTaskView | null>(null);
    const [taskArguments, setTaskArguments] = useState<Record<string, string>>({});
    const [jobToDiscard, setJobToDiscard] = useState<ActivityEntry | null>(null);

    const describeFailure = useCallback(
        (cause: unknown): string => {
            if (cause instanceof ApiError && cause.reason === 'network') {
                return t((d) => d.errors.network);
            }

            return t((d) => d.errors.unexpected);
        },
        [t],
    );

    const loadOverview = useCallback(async (): Promise<void> => {
        try {
            setOverview(await getOperationsOverview());
            setForbidden(false);
        } catch (cause: unknown) {
            if (cause instanceof ApiError && cause.status === 403) {
                setForbidden(true);

                return;
            }

            toast.error(
                t((d) => d.pages.adminOperations.loadFailed),
                { description: describeFailure(cause) },
            );
        }
    }, [describeFailure, t]);

    const loadActivity = useCallback(async (): Promise<void> => {
        try {
            setActivity(await listActivity({ status: statusFilter, kind: kindFilter, page: activityPage }));
        } catch {
            // The overview already reports a lost session or a lost network; a second toast for the
            // same cause is noise.
            setActivity(null);
        }
    }, [statusFilter, kindFilter, activityPage]);

    // The first load resolves inside promise callbacks rather than by awaiting in the effect body:
    // `react-hooks/set-state-in-effect` cannot see through an async function, and the guard is
    // worth keeping honest. `loadOverview`/`loadActivity` above are for event handlers, where
    // awaiting is the clearer shape.
    useEffect(() => {
        let active = true;

        getOperationsOverview().then(
            (loaded) => {
                if (active) {
                    setOverview(loaded);
                    setForbidden(false);
                }
            },
            (cause: unknown) => {
                if (!active) {
                    return;
                }

                if (cause instanceof ApiError && cause.status === 403) {
                    setForbidden(true);

                    return;
                }

                toast.error(
                    t((d) => d.pages.adminOperations.loadFailed),
                    { description: describeFailure(cause) },
                );
            },
        );

        return () => {
            active = false;
        };
    }, [describeFailure, t]);

    useEffect(() => {
        let active = true;

        listActivity({ status: statusFilter, kind: kindFilter, page: activityPage }).then(
            (page) => {
                if (active) {
                    setActivity(page);
                }
            },
            () => {
                if (active) {
                    setActivity(null);
                }
            },
        );

        return () => {
            active = false;
        };
    }, [statusFilter, kindFilter, activityPage]);

    const refreshAll = useCallback((): void => {
        setOverview(null);
        setActivity(null);
        void loadOverview();
        void loadActivity();
    }, [loadOverview, loadActivity]);

    const toggleTask = async (task: OperationTaskView, enabled: boolean): Promise<void> => {
        try {
            await setScheduledTaskEnabled(task.key, enabled);
            await loadOverview();
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.adminOperations.tasks.enableFailed),
                { description: describeFailure(cause) },
            );
        }
    };

    /** A failed task run is re-run through the same confirm dialog the task list uses. */
    const runAgain = (taskKey: string): void => {
        const task = overview?.tasks.find((candidate) => candidate.key === taskKey);

        if (task === undefined) {
            return;
        }

        setTaskArguments({});
        setTaskToRun(task);
    };

    const confirmRun = async (): Promise<void> => {
        if (taskToRun === null) {
            return;
        }

        const args: Record<string, number | string> = {};

        for (const argument of taskToRun.arguments) {
            const raw = taskArguments[argument.name] ?? '';
            // Only the numeric argument is coerced. Coercing them all sent `Number('LEGISLATIE')`,
            // which is `NaN`, which `JSON.stringify` writes as `null` — so the server saw three
            // empty fields and reported them as missing.
            args[argument.name] = argument.type === 'integer' ? Number(raw) : raw;
        }

        setBusy(true);

        try {
            await runOperationTask(taskToRun.key, args);
            toast.success(t((d) => d.pages.adminOperations.tasks.started));
            setTaskToRun(null);
            refreshAll();
        } catch (cause: unknown) {
            // 409 is not an error the operator caused — a run is already in flight — so it gets its
            // own message rather than the generic failure text.
            const conflict = cause instanceof ApiError && cause.status === 409;

            toast.error(
                conflict
                    ? t((d) => d.pages.adminOperations.tasks.alreadyRunning)
                    : t((d) => d.pages.adminOperations.tasks.runFailed),
                conflict ? undefined : { description: describeFailure(cause) },
            );
        } finally {
            setBusy(false);
        }
    };

    const retryJob = async (entry: ActivityEntry): Promise<void> => {
        setBusy(true);

        try {
            await retryFailedJob(entry.id);
            toast.success(t((d) => d.pages.adminOperations.activity.retried));
            await loadActivity();
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.adminOperations.activity.actionFailed),
                { description: describeFailure(cause) },
            );
        } finally {
            setBusy(false);
        }
    };

    const confirmDiscard = async (): Promise<void> => {
        if (jobToDiscard === null) {
            return;
        }

        setBusy(true);

        try {
            await forgetFailedJob(jobToDiscard.id);
            toast.success(t((d) => d.pages.adminOperations.activity.discarded));
            setJobToDiscard(null);
            await loadActivity();
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.adminOperations.activity.actionFailed),
                { description: describeFailure(cause) },
            );
        } finally {
            setBusy(false);
        }
    };

    if (forbidden) {
        return (
            <AppShell>
                <div className="mx-auto w-full max-w-lg pt-16 text-center">
                    <h1 className="text-lg font-semibold">{t((d) => d.pages.adminOperations.title)}</h1>
                    <p className="note mt-2">{t((d) => d.pages.adminOperations.forbidden)}</p>
                </div>
            </AppShell>
        );
    }

    const scheduler = overview?.scheduler ?? null;
    const queue = overview?.queue ?? null;

    /** Horizon unreachable means unknown, so a missing count is an em dash and never a zero. */
    const count = (value: number | null | undefined): string =>
        typeof value === 'number' ? value.toLocaleString() : '—';

    const taskName = (key: string): string => t((d) => d.pages.adminOperations.tasks.names[key] ?? key);

    return (
        <AppShell>
            <Page
                title={t((d) => d.pages.adminOperations.title)}
                lede={t((d) => d.pages.adminOperations.description)}
                actions={<PanelButton onClick={refreshAll}>{t((d) => d.pages.adminOperations.refresh)}</PanelButton>}
            >
                <StatGrid>
                    <Stat
                        label={t((d) => d.pages.adminOperations.scheduler.title)}
                        value={
                            scheduler === null ? (
                                '—'
                            ) : (
                                <Status tone={scheduler.alive ? 'ok' : 'bad'}>
                                    {scheduler.alive
                                        ? t((d) => d.pages.adminOperations.scheduler.alive)
                                        : t((d) => d.pages.adminOperations.scheduler.stalled)}
                                </Status>
                            )
                        }
                        sub={
                            scheduler === null || scheduler.ageSeconds === null
                                ? t((d) => d.pages.adminOperations.scheduler.never)
                                : t((d) => d.pages.adminOperations.scheduler.age)
                                      .replace('{seconds}', String(scheduler.ageSeconds))
                                      .replace('{limit}', String(scheduler.maxAgeSeconds))
                        }
                    />
                    <Stat
                        label={t((d) => d.pages.adminOperations.queue.pending)}
                        value={count(queue?.pending)}
                        sub={
                            queue !== null && !queue.available
                                ? t((d) => d.pages.adminOperations.queue.unavailable)
                                : t((d) => d.pages.adminOperations.queue.workers) + ': ' + count(queue?.supervisors)
                        }
                    />
                    <Stat
                        label={t((d) => d.pages.adminOperations.queue.failed)}
                        value={count(queue?.failed)}
                        sub={
                            t((d) => d.pages.adminOperations.queue.recentlyFailed) + ': ' + count(queue?.recentlyFailed)
                        }
                    />
                    <Stat
                        label={t((d) => d.pages.adminOperations.queue.longestWait)}
                        value={
                            typeof queue?.maxWaitSeconds === 'number'
                                ? queue.maxWaitSeconds.toLocaleString() +
                                  t((d) => d.pages.adminOperations.queue.seconds)
                                : '—'
                        }
                        sub={t((d) => d.pages.adminOperations.queue.completed) + ': ' + count(queue?.completed)}
                    />
                </StatGrid>

                <Panel
                    title={t((d) => d.pages.adminOperations.tasks.title)}
                    description={t((d) => d.pages.adminOperations.tasks.description)}
                    flush
                >
                    {overview === null ? (
                        <TableSkeleton />
                    ) : (
                        <DataTable
                            head={
                                <>
                                    <th>{t((d) => d.pages.adminOperations.tasks.columns.task)}</th>
                                    <th>{t((d) => d.pages.adminOperations.tasks.columns.schedule)}</th>
                                    <th>{t((d) => d.pages.adminOperations.tasks.columns.lastRun)}</th>
                                    <th>{t((d) => d.pages.adminOperations.tasks.columns.state)}</th>
                                    <th />
                                </>
                            }
                        >
                            {overview.tasks.map((task) => (
                                <tr key={task.key}>
                                    <td>
                                        <div className="font-semibold">{taskName(task.key)}</div>
                                        <div className="note">
                                            {t(
                                                (d) =>
                                                    d.pages.adminOperations.tasks.descriptions[task.key] ??
                                                    task.command,
                                            )}
                                        </div>
                                    </td>
                                    <td className="whitespace-nowrap">
                                        {task.schedulable ? (
                                            <>
                                                <span className="mono">{task.cronExpression}</span>
                                                <div className="note">{task.timezone}</div>
                                            </>
                                        ) : (
                                            <Pill>{t((d) => d.pages.adminOperations.tasks.manualOnly)}</Pill>
                                        )}
                                    </td>
                                    <td className="whitespace-nowrap">
                                        {task.lastRun === null ? (
                                            <span className="note">
                                                {t((d) => d.pages.adminOperations.tasks.never)}
                                            </span>
                                        ) : (
                                            <>
                                                <Status tone={runTone(task.lastRun.status)}>
                                                    {t(
                                                        (d) =>
                                                            d.pages.adminOperations.activity.statuses[
                                                                task.lastRun?.status ?? ''
                                                            ] ??
                                                            task.lastRun?.status ??
                                                            '',
                                                    )}
                                                </Status>
                                                <div className="note">
                                                    {new Date(task.lastRun.startedAt).toLocaleString()}
                                                </div>
                                            </>
                                        )}
                                    </td>
                                    <td>
                                        {task.running ? (
                                            <Status tone="warn">
                                                {t((d) => d.pages.adminOperations.tasks.running)}
                                            </Status>
                                        ) : task.schedulable && !task.enabled ? (
                                            <Status tone="idle">
                                                {t((d) => d.pages.adminOperations.tasks.paused)}
                                            </Status>
                                        ) : (
                                            <Pill tone={task.readOnly ? 'neutral' : 'accent'}>
                                                {task.readOnly
                                                    ? t((d) => d.pages.adminOperations.tasks.readOnly)
                                                    : t((d) => d.pages.adminOperations.tasks.writes)}
                                            </Pill>
                                        )}
                                    </td>
                                    <td>
                                        <div className="flex items-center justify-end gap-2">
                                            {task.schedulable ? (
                                                <Switch
                                                    aria-label={t((d) => d.pages.adminOperations.tasks.enabled)}
                                                    checked={task.enabled}
                                                    onCheckedChange={(checked) => {
                                                        void toggleTask(task, checked);
                                                    }}
                                                />
                                            ) : null}
                                            <PanelButton
                                                disabled={task.running}
                                                onClick={() => {
                                                    setTaskArguments({});
                                                    setTaskToRun(task);
                                                }}
                                            >
                                                {t((d) => d.pages.adminOperations.tasks.run)}
                                            </PanelButton>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    )}
                </Panel>

                <Panel
                    title={t((d) => d.pages.adminOperations.freshness.title)}
                    description={t((d) => d.pages.adminOperations.freshness.description)}
                >
                    {overview === null ? (
                        <RowSkeleton />
                    ) : (
                        <RowList>
                            {overview.freshness.map((item) => (
                                <Row
                                    key={item.key}
                                    title={t((d) => d.pages.adminOperations.freshness.labels[item.key] ?? item.key)}
                                    {...(item.detail === null
                                        ? {}
                                        : {
                                              subtitle: t(
                                                  (d) =>
                                                      d.pages.adminOperations.freshness.details[item.key] ?? '{detail}',
                                              ).replace('{detail}', item.detail),
                                          })}
                                >
                                    <div className="flex items-center gap-2">
                                        <span className="tabular-nums">
                                            {item.value ?? t((d) => d.pages.adminOperations.freshness.unknown)}
                                        </span>
                                        <Status tone={item.state}>
                                            {t((d) => d.pages.adminOperations.states[item.state])}
                                        </Status>
                                    </div>
                                </Row>
                            ))}
                        </RowList>
                    )}
                </Panel>

                <Panel
                    title={t((d) => d.pages.adminOperations.health.title)}
                    description={t((d) => d.pages.adminOperations.health.description)}
                >
                    {overview === null ? (
                        <RowSkeleton />
                    ) : (
                        <RowList>
                            {overview.health.map((component) => (
                                <Row
                                    key={component.key}
                                    title={t(
                                        (d) =>
                                            d.pages.adminOperations.health.components[component.key] ?? component.key,
                                    )}
                                    {...(component.detail === null ? {} : { subtitle: component.detail })}
                                >
                                    <Status tone={healthTone(component.state)}>{component.state}</Status>
                                </Row>
                            ))}
                        </RowList>
                    )}
                </Panel>

                <Panel
                    title={t((d) => d.pages.adminOperations.sources.title)}
                    description={t((d) => d.pages.adminOperations.sources.description)}
                    footNote={t((d) => d.pages.adminOperations.sources.readOnlyNotice)}
                    flush
                >
                    {overview === null ? (
                        <TableSkeleton />
                    ) : (
                        <DataTable
                            head={
                                <>
                                    <th>{t((d) => d.pages.adminOperations.sources.columns.code)}</th>
                                    <th>{t((d) => d.pages.adminOperations.sources.columns.tier)}</th>
                                    <th>{t((d) => d.pages.adminOperations.sources.columns.url)}</th>
                                    <th>{t((d) => d.pages.adminOperations.sources.columns.enabled)}</th>
                                    <th>{t((d) => d.pages.adminOperations.sources.columns.state)}</th>
                                </>
                            }
                        >
                            {overview.sources.map((source) => (
                                <tr key={source.code}>
                                    <td className="font-semibold">{source.code}</td>
                                    <td>
                                        <Pill>{source.trustTier}</Pill>
                                    </td>
                                    <td className="mono truncate">{source.baseUrl}</td>
                                    <td>
                                        {/* Read-only, and deliberately so: this value is declared in
                                            config/sources.php and re-mirrored onto the row at every
                                            fetch, so a switch here would report a state it cannot
                                            hold. */}
                                        <Status tone={source.enabled ? 'ok' : 'idle'}>
                                            {source.enabled
                                                ? t((d) => d.pages.adminOperations.sources.allowed)
                                                : t((d) => d.pages.adminOperations.sources.blocked)}
                                        </Status>
                                    </td>
                                    <td>
                                        {source.mirrored ? (
                                            <Pill>{t((d) => d.pages.adminOperations.sources.inUse)}</Pill>
                                        ) : (
                                            <Pill tone="locked">
                                                {t((d) => d.pages.adminOperations.sources.neverFetched)}
                                            </Pill>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    )}
                </Panel>

                <Panel
                    title={t((d) => d.pages.adminOperations.activity.title)}
                    description={t((d) => d.pages.adminOperations.activity.description)}
                    footNote={`${t((d) => d.pages.adminOperations.activity.windowNotice)} ${t(
                        (d) => d.pages.adminOperations.activity.noPayloadNotice,
                    )}`}
                    flush
                >
                    <Toolbar>
                        <PanelSelect
                            className="filter-select"
                            value={statusFilter}
                            onValueChange={(value) => {
                                setStatusFilter(value === '' ? 'all' : value);
                                setActivityPage(1);
                            }}
                            options={['all', 'failed', 'succeeded', 'running'].map((key) => ({
                                value: key,
                                label: t((d) => d.pages.adminOperations.activity.statusFilter[key] ?? key),
                            }))}
                        />
                        <PanelSelect
                            className="filter-select"
                            value={kindFilter}
                            onValueChange={(value) => {
                                setKindFilter(value === '' ? 'all' : value);
                                setActivityPage(1);
                            }}
                            options={['all', 'task_run', 'failed_job'].map((key) => ({
                                value: key,
                                label: t((d) => d.pages.adminOperations.activity.kindFilter[key] ?? key),
                            }))}
                        />
                    </Toolbar>

                    {activity === null ? (
                        <TableSkeleton />
                    ) : activity.activity.length === 0 ? (
                        <p className="note p-4">{t((d) => d.pages.adminOperations.activity.empty)}</p>
                    ) : (
                        <DataTable
                            stacked
                            head={
                                <>
                                    <th>{t((d) => d.pages.adminOperations.activity.columns.what)}</th>
                                    <th>{t((d) => d.pages.adminOperations.activity.columns.when)}</th>
                                    <th>{t((d) => d.pages.adminOperations.activity.columns.status)}</th>
                                    <th />
                                </>
                            }
                        >
                            {activity.activity.map((entry) => (
                                <tr key={`${entry.kind}:${entry.id}`}>
                                    {/* A task run shows its translated task name; a queue job shows its
                                        class, which has no translation and should not gain a fake one. */}
                                    <td className="col-primary">
                                        <div className="font-semibold">
                                            {entry.kind === 'task_run' ? taskName(entry.label) : entry.label}
                                        </div>
                                        <div className="note">
                                            {t((d) => d.pages.adminOperations.activity.kinds[entry.kind] ?? entry.kind)}
                                        </div>
                                    </td>
                                    <td className="whitespace-nowrap tabular-nums">
                                        {entry.occurredAt === '' ? '—' : new Date(entry.occurredAt).toLocaleString()}
                                    </td>
                                    <td>
                                        <Status tone={runTone(entry.status)}>
                                            {t(
                                                (d) =>
                                                    d.pages.adminOperations.activity.statuses[entry.status] ??
                                                    entry.status,
                                            )}
                                        </Status>
                                    </td>
                                    <td className="col-end text-right">
                                        <div className="flex justify-end gap-1.5">
                                            {/* The row's origin decides its action: re-running a task and
                                                re-queueing a job are different operations. */}
                                            {entry.kind === 'failed_job' ? (
                                                <>
                                                    <PanelButton
                                                        disabled={busy}
                                                        onClick={() => {
                                                            void retryJob(entry);
                                                        }}
                                                    >
                                                        {t((d) => d.pages.adminOperations.activity.retry)}
                                                    </PanelButton>
                                                    <PanelButton
                                                        disabled={busy}
                                                        onClick={() => {
                                                            setJobToDiscard(entry);
                                                        }}
                                                    >
                                                        {t((d) => d.pages.adminOperations.activity.discard)}
                                                    </PanelButton>
                                                </>
                                            ) : entry.status === 'FAILED' ? (
                                                <PanelButton
                                                    onClick={() => {
                                                        runAgain(entry.label);
                                                    }}
                                                >
                                                    {t((d) => d.pages.adminOperations.activity.runAgain)}
                                                </PanelButton>
                                            ) : null}
                                            <PanelButton
                                                onClick={() => {
                                                    setSelected(entry);
                                                }}
                                            >
                                                {t((d) => d.pages.adminOperations.activity.details)}
                                            </PanelButton>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    )}

                    {activity !== null && activity.pages > 1 ? (
                        <div className="pager">
                            <span className="note">
                                {t((d) => d.pages.adminOperations.activity.page)
                                    .replace('{page}', String(activity.page))
                                    .replace('{pages}', String(activity.pages))}
                            </span>
                            <div className="flex gap-1.5">
                                <PanelButton
                                    disabled={activity.page <= 1}
                                    onClick={() => {
                                        setActivityPage(activity.page - 1);
                                    }}
                                >
                                    {t((d) => d.pages.adminOperations.activity.previous)}
                                </PanelButton>
                                <PanelButton
                                    disabled={activity.page >= activity.pages}
                                    onClick={() => {
                                        setActivityPage(activity.page + 1);
                                    }}
                                >
                                    {t((d) => d.pages.adminOperations.activity.next)}
                                </PanelButton>
                            </div>
                        </div>
                    ) : null}
                </Panel>
            </Page>

            <PanelDialog
                open={taskToRun !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setTaskToRun(null);
                    }
                }}
                title={t((d) => d.pages.adminOperations.tasks.runTitle)}
                description={t((d) => d.pages.adminOperations.tasks.runConfirm)}
                actions={
                    <>
                        <PanelButton
                            onClick={() => {
                                setTaskToRun(null);
                            }}
                        >
                            {t((d) => d.pages.adminOperations.cancel)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={busy}
                            onClick={() => {
                                void confirmRun();
                            }}
                        >
                            {t((d) => d.pages.adminOperations.tasks.run)}
                        </PanelButton>
                    </>
                }
            >
                {taskToRun === null ? null : (
                    <div className="space-y-3">
                        <DetailList>
                            <Detail label={t((d) => d.pages.adminOperations.tasks.columns.task)}>
                                <span className="font-semibold">{taskName(taskToRun.key)}</span>
                            </Detail>
                            <Detail label={t((d) => d.pages.adminOperations.tasks.runCommand)}>
                                <span className="mono">{taskToRun.command}</span>
                            </Detail>
                        </DetailList>

                        {taskToRun.arguments.map((argument) => (
                            <Field
                                key={argument.name}
                                label={t(
                                    (d) => d.pages.adminOperations.tasks.argumentLabel[argument.name] ?? argument.name,
                                )}
                                htmlFor={`argument-${argument.name}`}
                                hint={
                                    argument.type === 'integer'
                                        ? t((d) => d.pages.adminOperations.tasks.argumentRange)
                                              .replace('{min}', String(argument.min))
                                              .replace('{max}', String(argument.max))
                                        : argument.type === 'url'
                                          ? t((d) => d.pages.adminOperations.tasks.argumentUrlHint)
                                          : undefined
                                }
                            >
                                {/* One control per constrained type. No branch renders an unconstrained
                                    text box: a URL is checked against the chosen source's allowlist on
                                    the server before anything is dispatched. */}
                                {argument.type === 'choice' ? (
                                    <PanelSelect
                                        id={`argument-${argument.name}`}
                                        value={taskArguments[argument.name] ?? ''}
                                        onValueChange={(value) => {
                                            setTaskArguments((current) => ({ ...current, [argument.name]: value }));
                                        }}
                                        placeholder={t((d) => d.pages.adminOperations.tasks.argumentChoose)}
                                        options={argument.options.map((option) => ({ value: option, label: option }))}
                                    />
                                ) : (
                                    <PanelInput
                                        id={`argument-${argument.name}`}
                                        type={argument.type === 'integer' ? 'number' : 'url'}
                                        {...(argument.min === null ? {} : { min: argument.min })}
                                        {...(argument.max === null ? {} : { max: argument.max })}
                                        value={taskArguments[argument.name] ?? ''}
                                        onChange={(event) => {
                                            setTaskArguments((current) => ({
                                                ...current,
                                                [argument.name]: event.target.value,
                                            }));
                                        }}
                                    />
                                )}
                            </Field>
                        ))}
                    </div>
                )}
            </PanelDialog>

            <PanelDialog
                open={jobToDiscard !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setJobToDiscard(null);
                    }
                }}
                title={t((d) => d.pages.adminOperations.activity.discardTitle)}
                description={t((d) => d.pages.adminOperations.activity.discardConfirm)}
                size="sm"
                actions={
                    <>
                        <PanelButton
                            onClick={() => {
                                setJobToDiscard(null);
                            }}
                        >
                            {t((d) => d.pages.adminOperations.cancel)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={busy}
                            onClick={() => {
                                void confirmDiscard();
                            }}
                        >
                            {t((d) => d.pages.adminOperations.confirm)}
                        </PanelButton>
                    </>
                }
            >
                {jobToDiscard === null ? null : <p className="mono break-all">{jobToDiscard.label}</p>}
            </PanelDialog>

            <Sheet
                open={selected !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelected(null);
                    }
                }}
            >
                <SheetContent side="right" className="drawer gap-0 p-0">
                    <SheetHeader className="border-b p-4">
                        <SheetTitle className="dialog-title">
                            {t((d) => d.pages.adminOperations.activity.detailsTitle)}
                        </SheetTitle>
                    </SheetHeader>

                    {selected === null ? null : (
                        <div className="space-y-4 overflow-auto p-4">
                            <DetailList>
                                <Detail label={t((d) => d.pages.adminOperations.activity.columns.what)}>
                                    <span className="font-semibold">
                                        {selected.kind === 'task_run' ? taskName(selected.label) : selected.label}
                                    </span>
                                </Detail>
                                <Detail label={t((d) => d.pages.adminOperations.activity.columns.status)}>
                                    <Status tone={runTone(selected.status)}>
                                        {t(
                                            (d) =>
                                                d.pages.adminOperations.activity.statuses[selected.status] ??
                                                selected.status,
                                        )}
                                    </Status>
                                </Detail>
                                <Detail label={t((d) => d.pages.adminOperations.activity.columns.when)}>
                                    {selected.occurredAt === '' ? '—' : new Date(selected.occurredAt).toLocaleString()}
                                </Detail>

                                {/* Only the fields the row's own source actually has. A task run has a
                                    trigger and an exit code; a queue job has a queue and an exception. */}
                                {selected.trigger !== null ? (
                                    <Detail label={t((d) => d.pages.adminOperations.activity.trigger)}>
                                        {t(
                                            (d) =>
                                                d.pages.adminOperations.activity.triggers[selected.trigger ?? ''] ??
                                                selected.trigger ??
                                                '',
                                        )}
                                    </Detail>
                                ) : null}
                                {selected.durationSeconds !== null ? (
                                    <Detail label={t((d) => d.pages.adminOperations.activity.duration)}>
                                        {selected.durationSeconds.toLocaleString() +
                                            t((d) => d.pages.adminOperations.queue.seconds)}
                                    </Detail>
                                ) : null}
                                {selected.exitCode !== null ? (
                                    <Detail label={t((d) => d.pages.adminOperations.activity.exitCode)}>
                                        <span className="mono">{selected.exitCode}</span>
                                    </Detail>
                                ) : null}
                                {selected.queue !== null ? (
                                    <Detail label={t((d) => d.pages.adminOperations.activity.queue)}>
                                        <span className="mono">{selected.queue}</span>
                                    </Detail>
                                ) : null}
                                {selected.connection !== null ? (
                                    <Detail label={t((d) => d.pages.adminOperations.activity.connection)}>
                                        <span className="mono">{selected.connection}</span>
                                    </Detail>
                                ) : null}
                            </DetailList>

                            {selected.exception !== null ? (
                                <div>
                                    <p className="eyebrow">{t((d) => d.pages.adminOperations.activity.error)}</p>
                                    <p className="note mt-1 break-words">{selected.exception}</p>
                                </div>
                            ) : null}

                            {selected.kind === 'task_run' ? (
                                <div>
                                    <p className="eyebrow">{t((d) => d.pages.adminOperations.activity.output)}</p>
                                    {selected.output === null ? (
                                        <p className="note">{t((d) => d.pages.adminOperations.activity.noOutput)}</p>
                                    ) : (
                                        <pre className="ctl-code mt-1 overflow-auto whitespace-pre-wrap">
                                            {selected.output}
                                        </pre>
                                    )}
                                </div>
                            ) : null}
                        </div>
                    )}
                </SheetContent>
            </Sheet>
        </AppShell>
    );
}

/** `SUCCEEDED`/`FAILED`/`RUNNING` mapped to the four tones the shared `Status` component draws. */
function runTone(status: string): StatusTone {
    if (status === 'SUCCEEDED') {
        return 'ok';
    }

    if (status === 'FAILED') {
        return 'bad';
    }

    return status === 'RUNNING' ? 'warn' : 'idle';
}

/** `ProbeSystemHealth` reports `healthy`/`degraded`; anything else is not claimed to be fine. */
function healthTone(state: string): StatusTone {
    if (state === 'healthy') {
        return 'ok';
    }

    return state === 'degraded' ? 'bad' : 'idle';
}

function TableSkeleton(): JSX.Element {
    return (
        <div className="space-y-2 p-3">
            {[0, 1, 2, 3].map((index) => (
                <Skeleton key={index} className="tile h-9 w-full" />
            ))}
        </div>
    );
}

function RowSkeleton(): JSX.Element {
    return (
        <div className="space-y-2">
            {[0, 1, 2].map((index) => (
                <Skeleton key={index} className="tile h-8 w-full" />
            ))}
        </div>
    );
}
