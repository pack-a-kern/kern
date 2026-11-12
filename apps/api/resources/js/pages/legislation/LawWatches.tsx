import { CircleCheck, CircleX, Eye, EyeOff, Loader2, Play, RotateCcw, Trash2, TriangleAlert } from 'lucide-react';
import { useCallback, useEffect, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { allowIgnoredAct, changeLawWatch, deleteLawWatch, listLawWatches, runLawWatch } from '../../api/legislationApi';
import ConfirmDialog from '../../components/ConfirmDialog';
import { EmptyState, PanelButton, PanelDialog, PanelSelect, Pill } from '../../components/panel';
import { Switch } from '../../components/ui/switch';
import { useTranslation } from '../../i18n';
import type { Dictionary } from '../../i18n/dictionary';
import { describeApiFailure } from '../../lib/apiErrors';
import { subscribeToLawWatchRuns } from '../../lib/echo';
import { formatDate, formatDateTime } from '../../lib/format';
import { ApiError } from '../../lib/http';
import { useSharedAuth } from '../../lib/sharedProps';
import { intervalLabel } from '../../lib/watchInterval';
import type { IgnoredAct, LawWatch, WatchRunSummary } from '../../types/legislation';

type Words = Dictionary['pages']['legislation']['watches'];

/**
 * The searches of the portal kept and run again, each new act they find read as a law — behind a button
 * on the laws screen, in a dialog: the packages' — paused here at most, since their interval is their
 * configuration's — and those a person kept, which that person alone may also run more or less often, or
 * stop watching. Each says what its last run did, and may be run now; a run asked for here says over the
 * socket when it is done. Under them, the acts a person asked the watches to pass over, each allowed
 * again with one click. `reload` is bumped when "Add a law" keeps a watch; `onLawsChanged` is told when a
 * run may have read laws in.
 */
export default function LawWatches({
    reload,
    onLawsChanged,
}: {
    reload: number;
    onLawsChanged: () => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const words = t((d) => d.pages.legislation.watches);
    const userId = useSharedAuth()?.user.id ?? null;
    const [open, setOpen] = useState(false);
    const [watches, setWatches] = useState<LawWatch[] | null>(null);
    const [intervals, setIntervals] = useState<number[]>([]);
    const [ignored, setIgnored] = useState<IgnoredAct[]>([]);
    const [removing, setRemoving] = useState<LawWatch | null>(null);
    const [again, setAgain] = useState(0);

    useEffect(() => {
        let current = true;

        listLawWatches()
            .then((answer) => {
                if (current) {
                    setWatches(answer.watches);
                    setIntervals(answer.intervals);
                    setIgnored(answer.ignored);
                }
            })
            .catch(() => {
                if (current) {
                    toast.error(words.failed);
                }
            });

        return () => {
            current = false;
        };
    }, [reload, again, open, words.failed]);

    // A run asked for here has ended: what it did is fetched again, and the laws, which it may have added.
    useEffect(
        () =>
            userId === null
                ? undefined
                : subscribeToLawWatchRuns(userId, () => {
                      setAgain((run) => run + 1);
                      onLawsChanged();
                  }),
        [userId, onLawsChanged],
    );

    const replace = useCallback((saved: LawWatch): void => {
        setWatches((all) => all?.map((one) => (one.id === saved.id ? saved : one)) ?? null);
    }, []);

    const change = async (watch: LawWatch, changed: { enabled?: boolean; everyHours?: number }): Promise<void> => {
        try {
            replace(await changeLawWatch(watch.id, changed));
        } catch (cause) {
            toast.error(describeApiFailure(cause, t));
        }
    };

    const run = async (watch: LawWatch): Promise<void> => {
        try {
            replace(await runLawWatch(watch.id));
        } catch (cause) {
            toast.error(
                cause instanceof ApiError && cause.reason === 'WATCH_PAUSED'
                    ? words.paused
                    : describeApiFailure(cause, t),
            );
        }
    };

    const allow = async (act: IgnoredAct): Promise<void> => {
        try {
            await allowIgnoredAct(act.id);
            setIgnored((all) => all.filter((one) => one.id !== act.id));
            toast.success(words.allowed);
        } catch (cause) {
            toast.error(describeApiFailure(cause, t));
        }
    };

    return (
        <>
            <PanelButton
                aria-label={words.open.replace('{count}', String(watches?.length ?? 0))}
                onClick={() => {
                    setOpen(true);
                }}
            >
                <Eye className="size-3.5" aria-hidden="true" />
                {/* A phone's header has room for the mark and the count, not the words. */}
                <span className="hidden sm:inline">{words.open.replace('{count}', String(watches?.length ?? 0))}</span>
                <span className="sm:hidden">{watches?.length ?? 0}</span>
            </PanelButton>

            <PanelDialog
                open={open}
                onOpenChange={setOpen}
                title={words.title}
                description={words.hint}
                size="lg"
                dismissOnOutsideClick
                actions={
                    <PanelButton
                        onClick={() => {
                            setOpen(false);
                        }}
                    >
                        {words.close}
                    </PanelButton>
                }
            >
                <div className="space-y-5">
                    {watches === null ? null : watches.length === 0 ? (
                        <EmptyState icon={<Eye className="size-5" />} title={words.empty} />
                    ) : (
                        <ul className="watch-list">
                            {watches.map((watch) => (
                                <li key={watch.id} className="watch-card">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div className="min-w-0 space-y-1">
                                            <p className="text-sm font-medium break-words">{watch.searchWords}</p>
                                            <p className="note">
                                                {[
                                                    words.scope
                                                        .replace('{type}', watch.actType)
                                                        .replace('{year}', String(watch.firstYear)),
                                                    words.laws.replace('{count}', String(watch.laws)),
                                                ].join(' · ')}
                                            </p>
                                        </div>
                                        <Pill {...(watch.declaredBy === null ? {} : { tone: 'accent' as const })}>
                                            {watch.declaredBy !== null
                                                ? words.declaredBy.replace('{package}', watch.declaredBy)
                                                : watch.mine
                                                  ? words.yours
                                                  : words.someone}
                                        </Pill>
                                    </div>

                                    <RunState watch={watch} words={words} locale={locale} />

                                    <div className="flex flex-wrap items-center gap-2">
                                        <label className="flex items-center gap-2 text-xs">
                                            <Switch
                                                aria-label={words.active}
                                                checked={watch.enabled}
                                                disabled={watch.declaredBy === null && !watch.mine}
                                                onCheckedChange={(enabled) => void change(watch, { enabled })}
                                            />
                                            {words.active}
                                        </label>
                                        {watch.mine ? (
                                            <PanelSelect
                                                aria-label={words.interval}
                                                value={String(watch.everyHours)}
                                                onValueChange={(hours) =>
                                                    void change(watch, { everyHours: Number(hours) })
                                                }
                                                options={intervals.map((hours) => ({
                                                    value: String(hours),
                                                    label: intervalLabel(words, hours),
                                                }))}
                                            />
                                        ) : (
                                            <span className="note">{intervalLabel(words, watch.everyHours)}</span>
                                        )}
                                        <div className="ml-auto flex gap-1.5">
                                            {watch.declaredBy !== null || watch.mine ? (
                                                <PanelButton
                                                    disabled={!watch.enabled || watch.running}
                                                    onClick={() => void run(watch)}
                                                >
                                                    <Play className="size-3.5" aria-hidden="true" />
                                                    {words.runNow}
                                                </PanelButton>
                                            ) : null}
                                            {watch.mine ? (
                                                <PanelButton
                                                    onClick={() => {
                                                        setRemoving(watch);
                                                    }}
                                                >
                                                    <Trash2 className="size-3.5" aria-hidden="true" />
                                                    {words.remove}
                                                </PanelButton>
                                            ) : null}
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    {ignored.length === 0 ? null : (
                        <section className="space-y-2">
                            <div>
                                <h3 className="flex items-center gap-1.5 text-sm font-medium">
                                    <EyeOff className="size-3.5" aria-hidden="true" />
                                    {words.ignoredTitle} ({ignored.length})
                                </h3>
                                <p className="note">{words.ignoredHint}</p>
                            </div>
                            <ul className="watch-list">
                                {ignored.map((act) => (
                                    <li key={act.id} className="watch-card flex-row items-center gap-3">
                                        <div className="min-w-0 flex-1">
                                            <p className="law-title text-xs" title={act.title}>
                                                {act.title}
                                            </p>
                                            <p className="note">
                                                {words.ignoredOn.replace('{date}', formatDate(act.ignoredAt, locale))}
                                            </p>
                                        </div>
                                        <PanelButton onClick={() => void allow(act)}>
                                            <RotateCcw className="size-3.5" aria-hidden="true" />
                                            {words.allow}
                                        </PanelButton>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>
            </PanelDialog>

            {removing === null ? null : (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setRemoving(null);
                        }
                    }}
                    title={words.remove}
                    description={removing.searchWords}
                    detail={<p className="note">{words.removeConfirm}</p>}
                    confirmLabel={words.remove}
                    cancelLabel={words.cancel}
                    onConfirm={async () => {
                        try {
                            await deleteLawWatch(removing.id);
                            setWatches((all) => all?.filter((one) => one.id !== removing.id) ?? null);
                            toast.success(words.removed);
                            setRemoving(null);
                        } catch (cause) {
                            toast.error(describeApiFailure(cause, t));
                            throw cause;
                        }
                    }}
                />
            )}
        </>
    );
}

/**
 * Where a watch is: running now; or what its last run did — found, read in, and only where they are not
 * nothing, already here, ignored, left for later, failed, asked again — or why its search stopped; with
 * its last complete run and its next.
 */
function RunState({ watch, words, locale }: { watch: LawWatch; words: Words; locale: string }): JSX.Element {
    const summary = watch.lastSummary;
    const when = [
        watch.lastRunAt === null
            ? words.notRun
            : words.lastComplete.replace('{date}', formatDateTime(watch.lastRunAt, locale)),
        watch.enabled ? words.nextRun.replace('{date}', formatDateTime(watch.nextRunAt, locale)) : null,
    ]
        .filter((part) => part !== null)
        .join(' · ');

    if (watch.running) {
        return (
            <div className="watch-run" role="status">
                <Loader2 className="size-4 shrink-0 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                <span className="text-xs">{words.running}</span>
            </div>
        );
    }

    return (
        <div
            className={`watch-run ${summary === null ? '' : summary.failure !== null ? 'watch-run-bad' : outcome(summary)}`}
        >
            {summary === null ? null : summary.failure !== null ? (
                <CircleX className="size-4 shrink-0" aria-hidden="true" />
            ) : outcome(summary) === 'watch-run-warn' ? (
                <TriangleAlert className="size-4 shrink-0" aria-hidden="true" />
            ) : (
                <CircleCheck className="size-4 shrink-0" aria-hidden="true" />
            )}
            <div className="min-w-0 space-y-0.5">
                {summary === null ? null : <p className="text-xs">{said(summary, words)}</p>}
                <p className="note">{when}</p>
            </div>
        </div>
    );
}

/** A run that left something undone is a warning; one that did all it found, a success. */
function outcome(summary: WatchRunSummary): string {
    return summary.waiting > 0 || summary.failed > 0 ? 'watch-run-warn' : 'watch-run-ok';
}

/** A run in the reader's words: why its search stopped, or its counts, the first two always. */
function said(summary: WatchRunSummary, words: Words): string {
    if (summary.failure !== null) {
        return words.failure[summary.failure] ?? summary.failure;
    }

    const parts: [keyof Words['summary'], number, boolean][] = [
        ['found', summary.found, true],
        ['started', summary.started, true],
        ['known', summary.known, false],
        ['ignored', summary.ignored, false],
        ['waiting', summary.waiting, false],
        ['failed', summary.failed, false],
        ['retries', summary.retries, false],
    ];

    return parts
        .filter(([, count, always]) => always || count > 0)
        .map(([key, count]) => words.summary[key].replace('{count}', String(count)))
        .join(' · ');
}
