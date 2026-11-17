import { Link } from '@inertiajs/react';
import { Activity } from 'lucide-react';
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
        if (pollAfterMs === undefined) {
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
    const busy = running.length > 0 || data.pending > 0;
    const lead = first !== undefined ? taskName(first.key) : data.pending > 0 ? words.processing : words.idle;
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
        <Popover>
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
            <PopoverContent align="end" collisionPadding={12} className="shell-sheet-menu w-80 p-0">
                <div className="border-border flex items-center justify-between border-b px-3 py-2.5">
                    <span className="text-xs font-semibold">{words.label}</span>
                    <span
                        className={`text-2xs inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 font-semibold ${status.chip}`}
                    >
                        <span className={`size-1.5 rounded-full ${status.chipDot}`} aria-hidden="true" />
                        {status.text}
                    </span>
                </div>

                <div className="border-border border-b px-3 py-2.5">
                    <p className="text-2xs text-muted-foreground mb-2 font-semibold tracking-wide uppercase">
                        {words.runningNow}
                    </p>
                    {running.length === 0 ? (
                        <p className="text-muted-foreground text-xs">{words.noneRunning}</p>
                    ) : (
                        <ul className="space-y-1.5">
                            {running.map((run) => (
                                <li key={run.key} className="flex items-center gap-2 text-xs">
                                    <span className="relative flex size-2 shrink-0" aria-hidden="true">
                                        <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-500/60" />
                                        <span className="relative inline-flex size-2 rounded-full bg-emerald-500" />
                                    </span>
                                    <span className="truncate font-medium">{taskName(run.key)}</span>
                                    <span className="text-2xs text-muted-foreground ml-auto tabular-nums">
                                        {since(run.since)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="px-3 py-2.5">
                    <p className="text-2xs text-muted-foreground mb-2 font-semibold tracking-wide uppercase">
                        {words.queues}
                    </p>
                    <ul className="space-y-1.5">
                        {data.queues.map((queue) => (
                            <li key={queue.name} className="flex items-center justify-between gap-2 text-xs">
                                <span className="text-2xs font-mono">{queue.name}</span>
                                {queue.waiting > 0 ? (
                                    <span className="text-2xs rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-700 tabular-nums dark:bg-amber-500/15 dark:text-amber-400">
                                        {queue.waiting} {words.waiting}
                                    </span>
                                ) : (
                                    <span className="text-2xs text-muted-foreground rounded-full px-2 py-0.5">
                                        {words.queueIdle}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>

                {data.failed > 0 && (
                    <Link
                        href="/admin/jobs"
                        className="border-border bg-muted/40 text-muted-foreground hover:text-foreground flex items-center gap-2 border-t px-3 py-2 text-xs"
                    >
                        <span className="size-1.5 shrink-0 rounded-full bg-red-500" aria-hidden="true" />
                        <span className="font-semibold text-red-600 tabular-nums dark:text-red-400">{data.failed}</span>
                        <span>{words.failed}</span>
                        <span className="text-accent-600 dark:text-accent-400 ml-auto font-semibold">
                            {words.openJobs}
                        </span>
                    </Link>
                )}
            </PopoverContent>
        </Popover>
    );
}
