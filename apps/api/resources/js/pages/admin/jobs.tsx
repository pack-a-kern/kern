import { useCallback, useEffect, useState, type JSX } from 'react';
import { Check, Eye, Filter, ListChecks, RotateCcw, Trash2, X } from 'lucide-react';
import { toast } from 'sonner';

import { forgetFailedJob, listQueueJobs, retryFailedJob } from '../../api/operationsApi';
import {
    DataTable,
    Detail,
    DetailList,
    Page,
    Panel,
    PanelButton,
    PanelDialog,
    PanelSelect,
    Pill,
    Stat,
    StatGrid,
    Status,
    type StatusTone,
} from '../../components/panel';
import AppShell from '../../components/AppShell';
import Pagination from '../../components/Pagination';
import { useRememberedPaging, useRememberedState } from '../../hooks/useRememberedState';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { ApiError } from '../../lib/http';
import type { QueueJob, QueueJobsPage } from '../../types/operations';

/** The four lists Horizon maintains. Each is its own Redis index; see `ReadQueueJobs`. */
const STATUSES = ['all', 'pending', 'completed', 'failed'] as const;

/**
 * The work queue.
 *
 * ## Where these rows come from, and why not from a table
 *
 * There is no table to read. `failed_jobs` holds failures only, and nothing in Postgres records a
 * job that succeeded or is still waiting — the queue lives in Redis and Horizon is the only thing
 * that indexes it. Horizon's dashboard is closed on a deployed installation (no `viewHorizon` gate
 * is defined, so it defaults to local-only), which is why this screen exists at all.
 *
 * ## Why the status is a list to choose, not a filter
 *
 * Horizon keeps four separate sorted sets, and a sorted set has no `WHERE`: the status lives in a
 * per-job hash Redis only reads *after* the ids are chosen. Filtering a page of "everything" down to
 * failures would show three rows on a page claiming twenty-five, and a meaningless total. So each
 * selection reads its own pre-built index and pages honestly.
 *
 * ## What is deliberately absent
 *
 * The serialized payload. It carries document and tax-subject ids and, through `SerializesModels`,
 * whatever re-hydrating those would surface — rendering it would make this screen a bypass around
 * every access control the rest of the application enforces. The class name says what broke.
 *
 * Maintenance-task runs are also absent, and that is not an omission: a task reaches the queue
 * wrapped in `RunOperationTask`, which catches its own exception and records the outcome in
 * `task_runs`. It therefore completes from the queue's point of view even when the import failed,
 * and its history lives on the Tasks tab.
 */
function AdminJobsBody(): JSX.Element {
    const { t } = useTranslation();

    const [page, setPage] = useState<QueueJobsPage | null>(null);
    const [forbidden, setForbidden] = useState(false);
    const [busy, setBusy] = useState(false);

    /*
     * Opens on every list, and on whichever one was chosen last.
     *
     * Horizon trims recent, pending and completed jobs at 60 minutes, so on a quiet installation "all"
     * can be an empty table under a tile reporting real failures — the failures list is one click away,
     * and once chosen it is what this tab opens on next time.
     */
    const [status, setStatus] = useRememberedState<string>('admin.jobs.status', 'all', {
        isValid: (value) => (STATUSES as readonly string[]).includes(value),
    });
    const { page: pageNumber, pageSize, setPage: setPageNumber, setPageSize } = useRememberedPaging('admin.jobs');

    const [selected, setSelected] = useState<QueueJob | null>(null);
    const [toDiscard, setToDiscard] = useState<QueueJob | null>(null);
    // Bumped after a retry or a discard, to refetch without duplicating the effect's body.
    const [reloadToken, setReloadToken] = useState(0);

    const describeFailure = useCallback((cause: unknown): string => describeApiFailure(cause, t), [t]);

    // The previous page stays on screen while the next one loads, rather than being blanked: only
    // the very first load has nothing to show, and that is the one `page === null` covers. Clearing
    // it here would also be a synchronous setState inside an effect, which cascades a render.
    useEffect(() => {
        let active = true;

        listQueueJobs({ status, page: pageNumber, perPage: pageSize }).then(
            (loaded) => {
                if (!active) {
                    return;
                }

                setPage(loaded);
                // The server clamps a page past the end, so a list that shrank lands on the last
                // page that still has rows.
                setPageNumber(loaded.page);
                setForbidden(false);
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
                    t((d) => d.pages.adminJobs.loadFailed),
                    { description: describeFailure(cause) },
                );
            },
        );

        return () => {
            active = false;
        };
    }, [status, pageNumber, pageSize, setPageNumber, reloadToken, describeFailure, t]);

    const reload = (): void => {
        setReloadToken((token) => token + 1);
    };

    const retry = async (job: QueueJob): Promise<void> => {
        setBusy(true);

        try {
            await retryFailedJob(job.id);
            toast.success(t((d) => d.pages.adminJobs.retrySucceeded));
            reload();
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.adminJobs.actionFailed),
                { description: describeFailure(cause) },
            );
        } finally {
            setBusy(false);
        }
    };

    const confirmDiscard = async (): Promise<void> => {
        if (toDiscard === null) {
            return;
        }

        setBusy(true);

        try {
            await forgetFailedJob(toDiscard.id);
            toast.success(t((d) => d.pages.adminJobs.discarded));
            setToDiscard(null);
            reload();
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.adminJobs.actionFailed),
                { description: describeFailure(cause) },
            );
        } finally {
            setBusy(false);
        }
    };

    if (forbidden) {
        // Just the body: the frame around it comes from the default export, so a refusal still
        // arrives with the heading that says what was refused and the navigation to leave by.
        return <p className="note p-4">{t((d) => d.pages.adminJobs.forbidden)}</p>;
    }

    const queue = page?.queue ?? null;

    /** Horizon unreachable means unknown, so a missing count is an em dash and never a zero. */
    const count = (value: number | null | undefined): string =>
        typeof value === 'number' ? value.toLocaleString() : '—';

    /** A job's one meaningful timestamp, whichever stage it reached. */
    const occurredAt = (job: QueueJob): string | null => job.failedAt ?? job.completedAt ?? job.queuedAt;

    return (
        <>
            <div className="page-stack">
                <StatGrid>
                    <Stat
                        label={t((d) => d.pages.adminJobs.queue.pending)}
                        value={count(queue?.pending)}
                        sub={
                            queue !== null && !queue.available
                                ? t((d) => d.pages.adminJobs.unavailable)
                                : `${t((d) => d.pages.adminJobs.queue.workers)}: ${count(queue?.supervisors)}`
                        }
                    />
                    {/* The count of failures that can actually be opened, matching the table below
                        — see `ReadQueueJobs::retrievableTotal()`. The sub-line states the window it
                        covers rather than repeating the figure. */}
                    <Stat
                        label={t((d) => d.pages.adminJobs.queue.failed)}
                        value={count(queue?.failed)}
                        sub={t((d) => d.pages.adminJobs.queue.failedWindow)}
                    />
                    <Stat
                        label={t((d) => d.pages.adminJobs.queue.longestWait)}
                        value={
                            typeof queue?.maxWaitSeconds === 'number'
                                ? queue.maxWaitSeconds.toLocaleString() + t((d) => d.pages.adminJobs.queue.seconds)
                                : '—'
                        }
                        sub={`${t((d) => d.pages.adminJobs.queue.completed)}: ${count(queue?.completed)}`}
                    />
                    {/* Whether the queue is *moving*, which neither a depth nor a wait answers on
                        its own: zero pending is equally consistent with "caught up" and "nothing
                        was dispatched all day", and those call for opposite reactions. */}
                    <Stat
                        label={t((d) => d.pages.adminJobs.queue.throughput)}
                        value={
                            typeof queue?.throughput === 'number'
                                ? queue.throughput.toLocaleString(undefined, { maximumFractionDigits: 1 })
                                : '—'
                        }
                        sub={t((d) => d.pages.adminJobs.queue.throughputSub)}
                    />
                </StatGrid>

                <Panel
                    title={t((d) => d.pages.adminJobs.listTitle)}
                    description={t((d) => d.pages.adminJobs.listDescription)}
                    icon={<ListChecks className="size-4" />}
                    help={t((d) => d.pages.adminJobs.retentionNotice)}
                    flush
                    aside={
                        <PanelSelect
                            className="filter-select"
                            icon={<Filter className="size-3.5" aria-hidden="true" />}
                            value={status}
                            onValueChange={(next) => {
                                // A different Horizon list entirely, so the page number belongs to
                                // the one being left behind.
                                setStatus(next === '' ? 'all' : next);
                                setPageNumber(1);
                            }}
                            options={STATUSES.map((key) => ({
                                value: key,
                                label: t((d) => d.pages.adminJobs.statusFilter[key] ?? key),
                            }))}
                        />
                    }
                    foot={
                        <Pagination
                            page={pageNumber}
                            pageSize={pageSize}
                            total={page?.total ?? 0}
                            unit={t((d) => d.pages.adminJobs.unit)}
                            onPageChange={setPageNumber}
                            onPageSizeChange={(size) => {
                                setPageSize(size);
                                setPageNumber(1);
                            }}
                        />
                    }
                >
                    {page === null ? (
                        <div className="space-y-2 p-3">
                            {[0, 1, 2, 3].map((index) => (
                                <Skeleton key={index} className="tile h-9 w-full" />
                            ))}
                        </div>
                    ) : !page.available ? (
                        <p className="note p-4">{t((d) => d.pages.adminJobs.unavailable)}</p>
                    ) : page.jobs.length === 0 ? (
                        <p className="note p-4">{t((d) => d.pages.adminJobs.empty)}</p>
                    ) : (
                        <DataTable
                            // Stacked on a phone: the class name takes the first line and the queue,
                            // status and actions flow under it rather than scrolling out of reach.
                            stacked
                            head={
                                <>
                                    <th>{t((d) => d.pages.adminJobs.columns.job)}</th>
                                    <th>{t((d) => d.pages.adminJobs.columns.queue)}</th>
                                    <th>{t((d) => d.pages.adminJobs.columns.status)}</th>
                                    <th>{t((d) => d.pages.adminJobs.columns.when)}</th>
                                    <th />
                                </>
                            }
                        >
                            {page.jobs.map((job) => (
                                <tr key={job.id}>
                                    {/* The class name, untranslated: it is a class, and a friendly
                                        name invented for it here would be a name nothing else uses. */}
                                    <td className="col-primary">
                                        <div className="font-semibold break-all">{shortClass(job.name)}</div>
                                        {job.retried ? <Pill>{t((d) => d.pages.adminJobs.retriedPill)}</Pill> : null}
                                    </td>
                                    <td className="col-hide">
                                        <span className="mono">{job.queue}</span>
                                    </td>
                                    <td data-label={t((d) => d.pages.adminJobs.columns.status)}>
                                        <Status tone={jobTone(job.status)}>
                                            {t((d) => d.pages.adminJobs.statuses[job.status] ?? job.status)}
                                        </Status>
                                    </td>
                                    <td
                                        className="whitespace-nowrap tabular-nums"
                                        data-label={t((d) => d.pages.adminJobs.columns.when)}
                                    >
                                        {occurredAt(job) === null
                                            ? '—'
                                            : new Date(occurredAt(job) ?? '').toLocaleString()}
                                    </td>
                                    {/* Not pinned to the corner: a failed job carries three buttons, and
                                        `.col-end` reserves 80px for them. They take the card's last
                                        line on a phone and sit at the row's right on a table. */}
                                    <td className="col-actions text-right">
                                        <div className="flex flex-wrap justify-end gap-1.5">
                                            {job.status === 'failed' ? (
                                                <>
                                                    <PanelButton
                                                        disabled={busy}
                                                        onClick={() => {
                                                            void retry(job);
                                                        }}
                                                    >
                                                        <RotateCcw className="size-3.5" aria-hidden="true" />
                                                        {t((d) => d.pages.adminJobs.retry)}
                                                    </PanelButton>
                                                    <PanelButton
                                                        disabled={busy}
                                                        onClick={() => {
                                                            setToDiscard(job);
                                                        }}
                                                    >
                                                        <Trash2 className="size-3.5" aria-hidden="true" />
                                                        {t((d) => d.pages.adminJobs.discard)}
                                                    </PanelButton>
                                                </>
                                            ) : null}
                                            <PanelButton
                                                onClick={() => {
                                                    setSelected(job);
                                                }}
                                            >
                                                <Eye className="size-3.5" aria-hidden="true" />
                                                {t((d) => d.pages.adminJobs.details)}
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
                open={selected !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelected(null);
                    }
                }}
                title={t((d) => d.pages.adminJobs.detailsTitle)}
                actions={
                    <PanelButton
                        onClick={() => {
                            setSelected(null);
                        }}
                    >
                        <X className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.adminJobs.cancel)}
                    </PanelButton>
                }
            >
                {selected === null ? null : (
                    <div className="space-y-3">
                        <DetailList>
                            <Detail label={t((d) => d.pages.adminJobs.columns.job)}>
                                <span className="mono break-all">{selected.name}</span>
                            </Detail>
                            <Detail label={t((d) => d.pages.adminJobs.columns.status)}>
                                <Status tone={jobTone(selected.status)}>
                                    {t((d) => d.pages.adminJobs.statuses[selected.status] ?? selected.status)}
                                </Status>
                            </Detail>
                            <Detail label={t((d) => d.pages.adminJobs.columns.queue)}>
                                <span className="mono">{selected.queue}</span>
                            </Detail>
                            <Detail label={t((d) => d.pages.adminJobs.connection)}>
                                <span className="mono">{selected.connection}</span>
                            </Detail>
                            {selected.queuedAt === null ? null : (
                                <Detail label={t((d) => d.pages.adminJobs.queuedAt)}>
                                    {new Date(selected.queuedAt).toLocaleString()}
                                </Detail>
                            )}
                            {selected.completedAt === null ? null : (
                                <Detail label={t((d) => d.pages.adminJobs.completedAt)}>
                                    {new Date(selected.completedAt).toLocaleString()}
                                </Detail>
                            )}
                            {selected.failedAt === null ? null : (
                                <Detail label={t((d) => d.pages.adminJobs.failedAt)}>
                                    {new Date(selected.failedAt).toLocaleString()}
                                </Detail>
                            )}
                        </DetailList>

                        {selected.exception === null ? null : (
                            <div>
                                <p className="eyebrow">{t((d) => d.pages.adminJobs.error)}</p>
                                <p className="note mt-1 break-words">{selected.exception}</p>
                            </div>
                        )}

                        <p className="note">{t((d) => d.pages.adminJobs.noPayloadNotice)}</p>
                    </div>
                )}
            </PanelDialog>

            <PanelDialog
                open={toDiscard !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setToDiscard(null);
                    }
                }}
                size="sm"
                title={t((d) => d.pages.adminJobs.discardTitle)}
                description={t((d) => d.pages.adminJobs.discardConfirm)}
                actions={
                    <>
                        <PanelButton
                            onClick={() => {
                                setToDiscard(null);
                            }}
                        >
                            <X className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.adminJobs.cancel)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={busy}
                            onClick={() => {
                                void confirmDiscard();
                            }}
                        >
                            <Check className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.adminJobs.confirm)}
                        </PanelButton>
                    </>
                }
            >
                {toDiscard === null ? null : <p className="mono text-xs break-all">{toDiscard.name}</p>}
            </PanelDialog>
        </>
    );
}

/**
 * The class without its namespace.
 *
 * `Kern\Documents\Jobs\ProcessScannedDocument` is unreadable in a table cell and the namespace
 * is the same for almost every row; the full name is one click away in the details.
 */
function shortClass(name: string): string {
    const parts = name.split('\\');

    return parts[parts.length - 1] ?? name;
}

/** Horizon's own status words, mapped to the four tones `Status` draws. */
function jobTone(status: string): StatusTone {
    if (status === 'completed') {
        return 'ok';
    }

    if (status === 'failed') {
        return 'bad';
    }

    return status === 'reserved' ? 'warn' : 'idle';
}

/**
 * The screen, framed.
 *
 * Its own page rather than one of three tabs: these three records were kept together because the
 * question "what happened" does not say which table holds the answer, but two of them answered it
 * by *doing* something — retrying a job, running a task — and one is a read-only chain of evidence.
 * They are in the navigation now, where a person can see all three without opening any.
 */
export default function AdminJobs(): JSX.Element {
    const { t } = useTranslation();

    return (
        <AppShell>
            <Page title={t((d) => d.pages.adminJobs.title)} lede={t((d) => d.pages.adminJobs.description)}>
                <AdminJobsBody />
            </Page>
        </AppShell>
    );
}
