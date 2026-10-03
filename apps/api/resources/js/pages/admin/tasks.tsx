import { useCallback, useEffect, useState, type JSX } from 'react';
import AppShell from '../../components/AppShell';
import { CalendarClock, Play, X } from 'lucide-react';
import { toast } from 'sonner';

import {
    getOperationsOverview,
    listTaskRuns,
    runOperationTask,
    setScheduledTaskEnabled,
} from '../../api/operationsApi';
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
    Status,
    type StatusTone,
} from '../../components/panel';
import Pagination, { PAGE_SIZES } from '../../components/Pagination';
import { PanelDate } from '../../components/PanelDatePicker';
import { Skeleton } from '../../components/ui/skeleton';
import { Switch } from '../../components/ui/switch';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { ApiError } from '../../lib/http';
import type { OperationTaskView, OperationsOverview, TaskRun } from '../../types/operations';

/**
 * The scheduled-task console.
 *
 * ## What a task is, and what this screen therefore contains
 *
 * A *task* is one of six artisan commands named by `Kern\TaxRomania\Operations\Enums\OperationTask`. It
 * is not the same thing as a queue *job*, even though it travels on the queue: `RunOperationTask`
 * wraps it, runs it with a buffered output, and writes the outcome to `task_runs`. That is why a
 * failed import never appears among the failed jobs — and why the queue lives on its own screen
 * (the Jobs tab) rather than here.
 *
 * Three sources define a task, and the separation is the security property: *which* tasks exist is a
 * closed PHP enum, so no database row can ever name a command to execute; *when* they run is
 * `config/operations.php`; *whether* they run is the one database-held bit, and the only thing the
 * switch below touches. There is deliberately no schedule editor: a form that could change the first
 * would be remote code execution, and one that could change the second is the standard way a job
 * silently stops running forever.
 *
 * ## Why the history is per task
 *
 * `task_runs` keeps every execution, forever. A single flat table of all of them would repeat the
 * status column the task row already shows, and bury six tasks in each other's history — so the
 * ledger is opened one task at a time, where the exit code and the console output are the answer to
 * a question actually being asked.
 */
function AdminOperationsBody(): JSX.Element {
    const { t } = useTranslation();

    const [overview, setOverview] = useState<OperationsOverview | null>(null);
    const [forbidden, setForbidden] = useState(false);
    const [busy, setBusy] = useState(false);

    const [taskToRun, setTaskToRun] = useState<OperationTaskView | null>(null);
    const [taskArguments, setTaskArguments] = useState<Record<string, string>>({});
    const [history, setHistory] = useState<OperationTaskView | null>(null);

    const describeFailure = useCallback((cause: unknown): string => describeApiFailure(cause, t), [t]);

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

    // The first load resolves inside promise callbacks rather than by awaiting in the effect body:
    // `react-hooks/set-state-in-effect` cannot see through an async function, and the guard is worth
    // keeping honest. `loadOverview` above is for event handlers, where awaiting is the clearer shape.
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

    const refresh = useCallback((): void => {
        setOverview(null);
        void loadOverview();
    }, [loadOverview]);

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

    const confirmRun = async (): Promise<void> => {
        if (taskToRun === null) {
            return;
        }

        const args: Record<string, number | string> = {};

        for (const argument of taskToRun.arguments) {
            const raw = taskArguments[argument.name] ?? '';

            // An optional field left empty is left out, so the command's own default applies.
            if (!argument.required && raw === '') {
                continue;
            }

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
            refresh();
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

    if (forbidden) {
        // Just the body: the frame around it comes from the default export, so a refusal still
        // arrives with the heading that says what was refused and the navigation to leave by.
        return <p className="note p-4">{t((d) => d.pages.adminOperations.forbidden)}</p>;
    }

    const taskName = (key: string): string => t((d) => d.pages.adminOperations.tasks.names[key] ?? key);

    return (
        <>
            <div className="page-stack">
                <Panel
                    title={t((d) => d.pages.adminOperations.tasks.title)}
                    description={t((d) => d.pages.adminOperations.tasks.description)}
                    icon={<CalendarClock className="size-4" />}
                    flush
                >
                    {overview === null ? (
                        <TableSkeleton />
                    ) : (
                        <DataTable
                            // Stacked on a phone: six columns of cron expressions and timestamps
                            // would otherwise scroll the switch and the run button out of reach.
                            stacked
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
                                    <td className="col-primary">
                                        <div className="font-semibold">{taskName(task.key)}</div>
                                        <div className="note">
                                            {t(
                                                (d) =>
                                                    d.pages.adminOperations.tasks.descriptions[task.key] ??
                                                    task.command,
                                            )}
                                        </div>
                                    </td>
                                    <td
                                        className="whitespace-nowrap"
                                        data-label={t((d) => d.pages.adminOperations.tasks.columns.schedule)}
                                    >
                                        {task.schedulable ? (
                                            <>
                                                <span className="mono">{task.cronExpression}</span>
                                                <div className="note">{task.timezone}</div>
                                            </>
                                        ) : (
                                            <Pill>{t((d) => d.pages.adminOperations.tasks.manualOnly)}</Pill>
                                        )}
                                    </td>
                                    <td
                                        className="whitespace-nowrap"
                                        data-label={t((d) => d.pages.adminOperations.tasks.columns.lastRun)}
                                    >
                                        {task.lastRun === null ? (
                                            <span className="note">
                                                {t((d) => d.pages.adminOperations.tasks.never)}
                                            </span>
                                        ) : (
                                            <>
                                                <Status tone={runTone(task.lastRun.status)}>
                                                    {t(
                                                        (d) =>
                                                            d.pages.adminOperations.tasks.statuses[
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
                                    <td data-label={t((d) => d.pages.adminOperations.tasks.columns.state)}>
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
                                    <td className="col-end">
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
                                                onClick={() => {
                                                    setHistory(task);
                                                }}
                                            >
                                                {t((d) => d.pages.adminOperations.tasks.details)}
                                            </PanelButton>
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
            </div>

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
                            <X className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.adminOperations.cancel)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={busy}
                            onClick={() => {
                                void confirmRun();
                            }}
                        >
                            <Play className="size-3.5" aria-hidden="true" />
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
                                        : argument.type === 'date'
                                          ? t((d) => d.pages.adminOperations.tasks.argumentDateHint)
                                          : undefined
                                }
                            >
                                {/* One control per constrained type. No branch renders an unconstrained
                                    text box: a number is bounded, a choice is a closed set, a date is a
                                    date. */}
                                {argument.type === 'date' ? (
                                    <PanelDate
                                        block
                                        value={
                                            (taskArguments[argument.name] ?? '') === ''
                                                ? null
                                                : (taskArguments[argument.name] ?? null)
                                        }
                                        onChange={(next) => {
                                            setTaskArguments((current) => ({
                                                ...current,
                                                [argument.name]: next ?? '',
                                            }));
                                        }}
                                    />
                                ) : argument.type === 'choice' ? (
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
                                        type="number"
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

            {/* Keyed by task: opening a different one remounts the dialog, which resets its page
                and its expanded row without an effect that writes state during render. */}
            <TaskHistoryDialog
                key={history?.key ?? 'none'}
                task={history}
                onClose={() => {
                    setHistory(null);
                }}
                describeFailure={describeFailure}
            />
        </>
    );
}

/**
 * One task's run history.
 *
 * A dialog rather than a drawer, for the same reason the user edit moved: the screens here open
 * dialogs, and one panel sliding in from the right among them reads as a different application.
 */
function TaskHistoryDialog(props: {
    task: OperationTaskView | null;
    onClose: () => void;
    describeFailure: (cause: unknown) => string;
}): JSX.Element {
    const { t } = useTranslation();
    const [runs, setRuns] = useState<TaskRun[] | null>(null);
    const [total, setTotal] = useState(0);
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState<number>(PAGE_SIZES[0]);
    const [expanded, setExpanded] = useState<string | null>(null);

    const taskKey = props.task?.key ?? null;

    // The rows already on screen stay while the next page loads; `runs === null` is the first load
    // only, and the component is remounted per task so that is also per task.
    useEffect(() => {
        if (taskKey === null) {
            return;
        }

        let active = true;

        listTaskRuns(taskKey, { page, perPage: pageSize }).then(
            (loaded) => {
                if (active) {
                    setRuns(loaded.runs);
                    setTotal(loaded.total);
                    setPage(loaded.page);
                }
            },
            (cause: unknown) => {
                if (active) {
                    setRuns([]);
                    setTotal(0);
                    toast.error(
                        t((d) => d.pages.adminOperations.tasks.historyFailed),
                        { description: props.describeFailure(cause) },
                    );
                }
            },
        );

        return () => {
            active = false;
        };
        // `describeFailure` is stable per language and would otherwise refetch on every render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [taskKey, page, pageSize, t]);

    return (
        <PanelDialog
            open={props.task !== null}
            onOpenChange={(open) => {
                if (!open) {
                    props.onClose();
                }
            }}
            size="lg"
            title={t((d) => d.pages.adminOperations.tasks.detailsTitle)}
            description={t((d) => d.pages.adminOperations.tasks.historyDescription)}
            actions={
                <PanelButton onClick={props.onClose}>
                    <X className="size-3.5" aria-hidden="true" />
                    {t((d) => d.pages.adminOperations.cancel)}
                </PanelButton>
            }
        >
            {props.task === null ? null : (
                <div className="space-y-3">
                    <DetailList>
                        <Detail label={t((d) => d.pages.adminOperations.tasks.columns.task)}>
                            <span className="font-semibold">
                                {t((d) => d.pages.adminOperations.tasks.names[props.task?.key ?? ''] ?? '')}
                            </span>
                        </Detail>
                        <Detail label={t((d) => d.pages.adminOperations.tasks.runCommand)}>
                            <span className="mono">{props.task.command}</span>
                        </Detail>
                    </DetailList>

                    {runs === null ? (
                        <div className="space-y-2">
                            {[0, 1, 2].map((index) => (
                                <Skeleton key={index} className="tile h-9 w-full" />
                            ))}
                        </div>
                    ) : runs.length === 0 ? (
                        <p className="note">{t((d) => d.pages.adminOperations.tasks.historyEmpty)}</p>
                    ) : (
                        <>
                            <DataTable
                                stacked
                                head={
                                    <>
                                        <th>{t((d) => d.pages.adminOperations.tasks.startedAt)}</th>
                                        <th>{t((d) => d.pages.adminOperations.tasks.trigger)}</th>
                                        <th>{t((d) => d.pages.adminOperations.tasks.columns.state)}</th>
                                        <th>{t((d) => d.pages.adminOperations.tasks.duration)}</th>
                                        <th />
                                    </>
                                }
                            >
                                {runs.map((run) => (
                                    <tr key={run.id}>
                                        <td className="col-primary whitespace-nowrap tabular-nums">
                                            {new Date(run.startedAt).toLocaleString()}
                                        </td>
                                        <td data-label={t((d) => d.pages.adminOperations.tasks.trigger)}>
                                            <Pill>
                                                {t(
                                                    (d) =>
                                                        d.pages.adminOperations.tasks.triggers[run.trigger] ??
                                                        run.trigger,
                                                )}
                                            </Pill>
                                        </td>
                                        <td data-label={t((d) => d.pages.adminOperations.tasks.columns.state)}>
                                            <Status tone={runTone(run.status)}>
                                                {t(
                                                    (d) =>
                                                        d.pages.adminOperations.tasks.statuses[run.status] ??
                                                        run.status,
                                                )}
                                            </Status>
                                        </td>
                                        <td
                                            className="tabular-nums"
                                            data-label={t((d) => d.pages.adminOperations.tasks.duration)}
                                        >
                                            {run.durationSeconds === null ? '—' : `${String(run.durationSeconds)}s`}
                                        </td>
                                        <td className="col-end text-right">
                                            <PanelButton
                                                onClick={() => {
                                                    setExpanded(expanded === run.id ? null : run.id);
                                                }}
                                            >
                                                {t((d) => d.pages.adminOperations.tasks.output)}
                                            </PanelButton>
                                        </td>
                                    </tr>
                                ))}
                            </DataTable>

                            {/* The console output is opened under the table rather than in a third
                                layer of dialog: it is often hundreds of lines, and a run is only
                                interesting next to the rows around it. */}
                            {expanded === null ? null : (
                                <RunOutput run={runs.find((candidate) => candidate.id === expanded) ?? null} />
                            )}

                            <Pagination
                                page={page}
                                pageSize={pageSize}
                                total={total}
                                unit={t((d) => d.pages.adminOperations.tasks.unit)}
                                onPageChange={setPage}
                                onPageSizeChange={(size) => {
                                    setPageSize(size);
                                    setPage(1);
                                }}
                            />
                        </>
                    )}
                </div>
            )}
        </PanelDialog>
    );
}

function RunOutput({ run }: { run: TaskRun | null }): JSX.Element | null {
    const { t } = useTranslation();

    if (run === null) {
        return null;
    }

    return (
        <div className="tile border p-3">
            <DetailList>
                <Detail label={t((d) => d.pages.adminOperations.tasks.exitCode)}>
                    <span className="mono">{run.exitCode ?? '—'}</span>
                </Detail>
            </DetailList>

            <p className="eyebrow mt-3">{t((d) => d.pages.adminOperations.tasks.output)}</p>
            {run.output === null || run.output === '' ? (
                <p className="note">{t((d) => d.pages.adminOperations.tasks.noOutput)}</p>
            ) : (
                <pre className="ctl-code mt-1 max-h-64 overflow-auto whitespace-pre-wrap">{run.output}</pre>
            )}
        </div>
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

function TableSkeleton(): JSX.Element {
    return (
        <div className="space-y-2 p-3">
            {[0, 1, 2, 3].map((index) => (
                <Skeleton key={index} className="tile h-9 w-full" />
            ))}
        </div>
    );
}

/**
 * The screen, framed.
 *
 * Its own page rather than one of three tabs: these three records were kept together because the
 * question "what happened" does not say which table holds the answer, but two of them answered it
 * by *doing* something — retrying a job, running a task — and one is a read-only chain of evidence.
 * They are in the navigation now, where a person can see all three without opening any.
 */
export default function AdminTasks(): JSX.Element {
    const { t } = useTranslation();

    return (
        <AppShell>
            <Page title={t((d) => d.pages.adminOperations.title)} lede={t((d) => d.pages.adminOperations.description)}>
                <AdminOperationsBody />
            </Page>
        </AppShell>
    );
}
