import { Link } from '@inertiajs/react';
import { Activity, ArrowRight, ChevronDown, Inbox, Layers, Loader2, TriangleAlert } from 'lucide-react';
import { useEffect, useState, type JSX } from 'react';

import { getOperationsActivity } from '../api/operationsApi';
import { useTranslation } from '../i18n';
import { subscribeToOperationsActivity } from '../lib/echo';
import type { OperationsActivity } from '../types/operations';
import { Popover, PopoverContent, PopoverTrigger } from './ui/popover';

/** A compact "5m" / "2h" since an ISO instant, language-neutral for a tight row. */
function since(iso: string): string {
    const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);

    if (seconds < 90) {
        return `${String(Math.round(seconds))}s`;
    }

    if (seconds < 5400) {
        return `${String(Math.round(seconds / 60))}m`;
    }

    return `${String(Math.round(seconds / 3600))}h`;
}

/**
 * The topbar's queue-activity indicator: a card that is always on show — a dot, the task running now,
 * and the number waiting — with the per-queue breakdown and the failure total one click away. The
 * card never shows the failure count itself: failures are a standing backlog, not current activity, so
 * they read as a small red dot here and a figure in the panel, where there is room to act on them.
 *
 * It reads `GET /v1/operations/activity`, which only `operations.view` may, so a person without that
 * permission gets nothing back: the first read fails quietly, the card stays hidden, and no socket
 * subscription is made. For everyone else the socket nudges it when a task starts or ends, and it
 * polls on the cadence the endpoint hands back for the backlog draining in between.
 */
export default function QueueActivity(): JSX.Element | null {
    const { t } = useTranslation();
    const words = t((d) => d.shell.queueActivity);
    const taskNames = t((d) => d.pages.adminOperations.tasks.names);
    const [data, setData] = useState<OperationsActivity | null>(null);
    // Controlled so the panel can close itself on navigation: the "open jobs" link is an Inertia
    // soft navigation that never unmounts this card, so an uncontrolled popover would stay open on
    // the page it sent you to. A DropdownMenu (the bell) closes on select for free; a Popover does not.
    const [open, setOpen] = useState(false);

    useEffect(() => {
        let active = true;
        let unsubscribe = (): void => {};

        const reload = (): void => {
            getOperationsActivity().then(
                (activity) => {
                    if (active) {
                        setData(activity);
                    }
                },
                () => {},
            );
        };

        // The socket is only subscribed to after a first read succeeds, so a person refused the
        // operations permission (a 403) never joins the channel and never shows the card.
        getOperationsActivity().then(
            (activity) => {
                if (!active) {
                    return;
                }

                setData(activity);
                unsubscribe = subscribeToOperationsActivity(reload);
            },
            () => {},
        );

        return () => {
            active = false;
            unsubscribe();
        };
    }, []);

    const pollAfterMs = data?.pollAfterMs;

    useEffect(() => {
        // Null or absent means the queue is idle: no interval at all, so the indicator rests on the
        // socket nudge until the next task starts (ADR 0024). Only a positive cadence polls.
        if (pollAfterMs === undefined || pollAfterMs === null || pollAfterMs <= 0) {
            return;
        }

        const id = window.setInterval(
            () => {
                getOperationsActivity().then(
                    (activity) => {
                        setData(activity);
                    },
                    () => {},
                );
            },
            Math.max(3000, pollAfterMs),
        );

        return () => {
            window.clearInterval(id);
        };
    }, [pollAfterMs]);

    if (data === null) {
        return null;
    }

    const taskName = (key: string): string => taskNames[key] ?? key;
    const { running } = data;
    const first = running[0];
    // "Working" covers a job a worker has in hand (active) as well as a named task and a backlog —
    // a year being read is active with nothing waiting and no ledger run, and must still read as busy.
    const busy = running.length > 0 || data.pending > 0 || data.active > 0;
    const lead =
        first !== undefined ? taskName(first.key) : data.pending > 0 || data.active > 0 ? words.processing : words.idle;
    const more = running.length - 1;

    // The card's dot is about activity, never failures: emerald while working, dim while merely up,
    // grey when the queue is not being worked at all.
    const dot = busy ? 'bg-emerald-500' : data.moving ? 'bg-emerald-500/50' : 'bg-muted-foreground/40';

    const status = !data.available
        ? { text: words.unavailable, chip: 'bg-muted text-muted-foreground', chipDot: 'bg-muted-foreground/50' }
        : busy
          ? {
                text: words.working,
                chip: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
                chipDot: 'bg-emerald-500',
            }
          : data.moving
            ? { text: words.idle, chip: 'bg-muted text-muted-foreground', chipDot: 'bg-emerald-500/60' }
            : { text: words.paused, chip: 'bg-muted text-muted-foreground', chipDot: 'bg-muted-foreground/50' };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    aria-label={words.label}
                    className="shell-control bar-icon relative flex items-center justify-center text-xs lg:h-8 lg:w-auto lg:justify-start lg:gap-2 lg:rounded-md lg:border lg:border-border lg:bg-white lg:px-2 lg:dark:bg-[#202225]"
                >
                    {/* On a phone an icon button, the size and shape of the bell and theme toggle beside it. */}
                    <Activity className="lg:hidden" aria-hidden="true" />

                    {/* From lg a card that reads at a glance: a dot, the task running now, the number waiting. */}
                    <span className={`hidden size-2 shrink-0 rounded-full lg:block ${dot}`} aria-hidden="true" />
                    <span className="hidden max-w-[150px] truncate font-medium lg:inline">
                        {lead}
                        {more > 0 ? <span className="text-muted-foreground"> +{more}</span> : null}
                    </span>
                    {data.pending > 0 && (
                        <span className="text-2xs hidden items-center rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-700 tabular-nums lg:inline-flex dark:bg-amber-500/15 dark:text-amber-400">
                            {data.pending}
                        </span>
                    )}

                    <ChevronDown
                        className="text-muted-foreground hidden size-3.5 shrink-0 lg:block"
                        aria-hidden="true"
                    />

                    {/* A corner dot: on a phone it carries waiting (amber) or failures (red), since the
                        chips are hidden there; from lg only failures show here, waiting being the chip above. */}
                    {data.failed > 0 ? (
                        <span
                            className="ring-card absolute -top-0.5 -right-0.5 size-2 rounded-full bg-red-500 ring-2"
                            aria-hidden="true"
                        />
                    ) : data.pending > 0 ? (
                        <span
                            className="ring-card absolute -top-0.5 -right-0.5 size-2 rounded-full bg-amber-500 ring-2 lg:hidden"
                            aria-hidden="true"
                        />
                    ) : null}
                </button>
            </PopoverTrigger>
            <PopoverContent align="end" collisionPadding={12} className="shell-sheet-menu w-96 p-0">
                {/* Header: what this is, and the overall state as a pill. */}
                <div className="flex items-start justify-between gap-3 px-4 pt-3.5 pb-3">
                    <div className="min-w-0">
                        <p className="text-sm font-semibold">{words.label}</p>
                        <p className="text-2xs text-muted-foreground mt-0.5">{words.subtitle}</p>
                    </div>
                    <span
                        className={`text-2xs inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-1 font-semibold ${status.chip}`}
                    >
                        <span className={`size-1.5 rounded-full ${status.chipDot}`} aria-hidden="true" />
                        {status.text}
                    </span>
                </div>

                {/* The thing in flight — or the statement that nothing is. */}
                <div className="px-3 pb-3">
                    {running.length === 0 && data.active === 0 ? (
                        <div className="bg-muted/60 flex items-center gap-3 rounded-xl p-3">
                            <span className="border-border bg-card text-muted-foreground grid size-8 shrink-0 place-items-center rounded-lg border">
                                <Inbox className="size-4" aria-hidden="true" />
                            </span>
                            <div className="min-w-0">
                                <p className="text-xs font-semibold">{words.idleTitle}</p>
                                <p className="text-2xs text-muted-foreground mt-0.5">{words.idleSub}</p>
                            </div>
                        </div>
                    ) : running.length === 0 ? (
                        <div className="bg-muted/60 flex items-center gap-3 rounded-xl p-3">
                            <span className="border-border bg-card grid size-8 shrink-0 place-items-center rounded-lg border text-emerald-600 dark:text-emerald-400">
                                <Loader2 className="size-4 animate-spin" aria-hidden="true" />
                            </span>
                            <div className="min-w-0">
                                <p className="text-xs font-semibold">{words.processing}</p>
                                <p className="text-2xs text-muted-foreground mt-0.5">
                                    {words.inProgress.replace('{count}', String(data.active))}
                                </p>
                            </div>
                        </div>
                    ) : (
                        <ul className="space-y-1.5">
                            {running.map((run) => (
                                <li key={run.key} className="bg-muted/60 flex items-center gap-3 rounded-xl p-3">
                                    <span className="border-border bg-card grid size-8 shrink-0 place-items-center rounded-lg border text-emerald-600 dark:text-emerald-400">
                                        <Loader2 className="size-4 animate-spin" aria-hidden="true" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-xs font-semibold">{taskName(run.key)}</p>
                                        <p className="text-2xs text-muted-foreground mt-0.5">
                                            {words.runningFor.replace('{time}', since(run.since))}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                {/* Queues — one row each, with what each is holding. */}
                {data.queues.length > 0 && (
                    <div className="border-border border-t px-3 pt-3 pb-2">
                        <div className="flex items-center justify-between px-1 pb-1.5">
                            <span className="text-2xs text-muted-foreground font-bold tracking-wider uppercase">
                                {words.queues}
                            </span>
                            <span className="text-2xs text-muted-foreground tabular-nums">
                                {words.queuesCount.replace('{count}', String(data.queues.length))}
                            </span>
                        </div>
                        <ul className="space-y-0.5">
                            {data.queues.map((queue) => (
                                <li
                                    key={queue.name}
                                    className="hover:bg-muted flex items-center justify-between gap-2 rounded-lg px-2 py-1.5"
                                >
                                    <span className="flex min-w-0 items-center gap-2">
                                        <span className="bg-muted text-muted-foreground grid size-6 shrink-0 place-items-center rounded-md">
                                            <Layers className="size-3" aria-hidden="true" />
                                        </span>
                                        <span className="text-2xs truncate font-mono">{queue.name}</span>
                                    </span>
                                    {queue.waiting > 0 ? (
                                        <span className="text-2xs shrink-0 rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-700 tabular-nums dark:bg-amber-500/15 dark:text-amber-400">
                                            {queue.waiting} {words.waiting}
                                        </span>
                                    ) : (
                                        <span className="text-2xs border-border text-muted-foreground shrink-0 rounded-full border px-2 py-0.5">
                                            {words.queueIdle}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* Failures — a panel that invites a look, not a bare count. */}
                {data.failed > 0 && (
                    <div className="border-border border-t p-3">
                        <div className="flex items-center gap-2.5 rounded-xl border border-red-200 bg-red-50 p-3 dark:border-red-500/25 dark:bg-red-500/10">
                            <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-400">
                                <TriangleAlert className="size-4" aria-hidden="true" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="text-xs font-bold text-red-700 tabular-nums dark:text-red-400">
                                    {words.failedTitle.replace('{count}', String(data.failed))}
                                </p>
                                <p className="text-2xs mt-0.5 text-red-600/80 dark:text-red-400/70">
                                    {words.failedSub}
                                </p>
                            </div>
                            <Link
                                href="/admin/jobs"
                                onClick={() => {
                                    setOpen(false);
                                }}
                                className="shrink-0 rounded-lg px-2.5 py-1.5 text-xs font-bold text-red-700 hover:bg-red-100 dark:text-red-400 dark:hover:bg-red-500/15"
                            >
                                {words.review} →
                            </Link>
                        </div>
                    </div>
                )}

                {/* Footer: the refresh note, and the way to the full jobs screen. */}
                <div className="border-border bg-muted/30 flex items-center justify-between gap-2 border-t px-4 py-2.5">
                    <span className="text-2xs text-muted-foreground">{words.refreshNote}</span>
                    <Link
                        href="/admin/jobs"
                        onClick={() => {
                            setOpen(false);
                        }}
                        className="text-accent-700 dark:text-accent-400 inline-flex items-center gap-1 text-xs font-semibold hover:underline"
                    >
                        {words.openAllJobs}
                        <ArrowRight className="size-3.5" aria-hidden="true" />
                    </Link>
                </div>
            </PopoverContent>
        </Popover>
    );
}
