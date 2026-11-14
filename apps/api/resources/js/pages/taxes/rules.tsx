import {
    CalendarPlus,
    CalendarRange,
    Check,
    Hourglass,
    ListChecks,
    Scale,
    Trash2,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useState, type JSX, type ReactNode } from 'react';
import { toast } from 'sonner';

import {
    approveFiscalParameter,
    dropFiscalParameterValues,
    getFiscalParameterBoard,
    readFiscalYear,
} from '../../api/fiscalParametersApi';
import ConfirmDialog from '../../components/ConfirmDialog';
import {
    ActionGroup,
    EmptyState,
    Field,
    Page,
    Panel,
    PanelButton,
    PanelDialog,
    PanelSelect,
    Status,
} from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { Tooltip, TooltipContent, TooltipTrigger } from '../../components/ui/tooltip';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { describeFiscalValue, describePeriod } from '../../lib/fiscalValue';
import { formatDateTime, formatNumber } from '../../lib/format';
import { ApiError } from '../../lib/http';
import { useSharedAuth } from '../../lib/sharedProps';
import { cn } from '../../lib/utils';
import type {
    FiscalParameter,
    FiscalParameterBoard,
    ParameterRead,
    ParameterVersion,
} from '../../types/fiscalParameters';
import FiscalParameterDialog from './FiscalParameterDialog';

/**
 * The values every tax is computed from, year by year — each read from an official text, shown with
 * the paragraph of the law it rests on, and counted only once a person has approved it.
 *
 * Read from the minimum-wage decisions and ANAF's D212 files ("Read values"); a year no form states
 * yet is proposed from the year before ("Read a year"). Nothing here is typed in: a value that is wrong
 * is rejected, and the source corrected.
 */
export default function AdminParameters(): JSX.Element {
    const { t } = useTranslation();
    const permissions = useSharedAuth()?.user.permissions ?? [];
    const canApprove = permissions.includes('fiscal.parameters.approve');
    // Opens a rule's waiting years together — offered only to someone who may approve them.
    const approveFor = (rule: FiscalParameter): (() => void) | undefined =>
        canApprove
            ? () => {
                  setApproving(rule);
              }
            : undefined;
    const [board, setBoard] = useState<FiscalParameterBoard | null>(null);
    const [failed, setFailed] = useState<'forbidden' | 'error' | null>(null);
    const [open, setOpen] = useState<{ parameter: FiscalParameter; version: ParameterVersion } | null>(null);
    const [startingYear, setStartingYear] = useState(false);
    const [dropping, setDropping] = useState(false);
    const [approving, setApproving] = useState<FiscalParameter | null>(null);

    const load = useCallback((): void => {
        getFiscalParameterBoard().then(
            (loaded) => {
                setBoard(loaded);
                setFailed(null);
            },
            (cause: unknown) => {
                setFailed(cause instanceof ApiError && cause.status === 403 ? 'forbidden' : 'error');
            },
        );
    }, []);

    useEffect(load, [load]);

    const name = (code: string): string => t((d) => d.pages.taxRules.names)[code] ?? code;

    return (
        <>
            <Page title={t((d) => d.pages.taxRules.title)} lede={t((d) => d.pages.taxRules.description)}>
                {failed !== null ? (
                    <Panel title={t((d) => d.pages.taxRules.valuesTitle)} icon={<Scale className="size-4" />}>
                        <p className="note">
                            {t((d) => (failed === 'forbidden' ? d.pages.taxRules.forbidden : d.pages.taxRules.failed))}
                        </p>
                    </Panel>
                ) : board === null ? (
                    <Skeleton className="tile h-48" />
                ) : board.parameters.every((parameter) => parameter.versions.length === 0) ? (
                    <Panel title={t((d) => d.pages.taxRules.valuesTitle)} icon={<Scale className="size-4" />}>
                        {/* Reading starts here too: after everything was dropped, this is the screen a
                            person is left on, and it must not be a dead end. */}
                        <EmptyState
                            icon={<Scale className="size-5" />}
                            title={t((d) => d.pages.taxRules.empty)}
                            action={
                                canApprove ? (
                                    <PanelButton
                                        onClick={() => {
                                            setStartingYear(true);
                                        }}
                                    >
                                        <CalendarPlus className="size-3.5" />
                                        {t((d) => d.pages.taxRules.newYear)}
                                    </PanelButton>
                                ) : undefined
                            }
                        />
                    </Panel>
                ) : (
                    <div className="flex flex-col gap-3">
                        <Overview
                            board={board}
                            canApprove={canApprove}
                            onNewYear={() => {
                                setStartingYear(true);
                            }}
                            onDropAll={() => {
                                setDropping(true);
                            }}
                            onOpen={(parameter, version) => {
                                setOpen({ parameter, version });
                            }}
                        />
                        {taxesOf(board)
                            .filter(([tax]) => tax !== wageOf(board)?.code)
                            .map(([tax, rules]) => (
                                <Panel
                                    key={tax}
                                    title={t((d) => d.pages.taxRules.taxes)[tax] ?? tax}
                                    description={t((d) => d.pages.taxRules.rulesCount).replace(
                                        '{count}',
                                        String(rules.length),
                                    )}
                                    icon={<Scale className="size-4" />}
                                    flush
                                >
                                    {/* A table of years by rules runs off a phone's side, so a phone gets each
                                        rule as a block of its own instead: its name, then a line per
                                        year — the year as a tag, tinted where a value waits, and the
                                        value at the right. Same cells, same ways to open them. */}
                                    <div className="hidden sm:block">
                                        <div className="table-wrap">
                                            <table className="data-table">
                                                <thead>
                                                    <tr>
                                                        <th className="w-20">{t((d) => d.pages.taxRules.year)}</th>
                                                        {rules.map((rule) => (
                                                            <th key={rule.code}>
                                                                <RuleName
                                                                    name={name(rule.code)}
                                                                    waiting={waitingOn(rule).length}
                                                                    onApprove={approveFor(rule)}
                                                                />
                                                            </th>
                                                        ))}
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {(board.summary.yearsByTax[tax] ?? []).map((year) => (
                                                        <tr key={year}>
                                                            <th
                                                                scope="row"
                                                                className="text-left font-semibold tabular-nums"
                                                            >
                                                                {year}
                                                            </th>
                                                            {rules.map((rule) => (
                                                                <td key={rule.code} className="align-top">
                                                                    <Cell
                                                                        parameter={rule}
                                                                        year={year}
                                                                        onOpen={(version) => {
                                                                            setOpen({ parameter: rule, version });
                                                                        }}
                                                                    />
                                                                </td>
                                                            ))}
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div className="divide-border divide-y sm:hidden">
                                        {rules.map((rule) => (
                                            <section key={rule.code} className="px-3.5 py-3">
                                                <h3 className="mb-1.5 text-sm font-semibold">
                                                    <RuleName
                                                        name={name(rule.code)}
                                                        waiting={waitingOn(rule).length}
                                                        onApprove={approveFor(rule)}
                                                    />
                                                </h3>
                                                <ul>
                                                    {(board.summary.yearsByTax[tax] ?? []).map((year) => {
                                                        const waiting = rule.versions.some(
                                                            (version) =>
                                                                version.status === 'CANDIDATE' &&
                                                                version.validFrom.startsWith(String(year)),
                                                        );

                                                        return (
                                                            <li
                                                                key={year}
                                                                className={cn(
                                                                    'border-border flex items-center justify-between gap-3 rounded-md border-b border-dashed px-1.5 py-1.5 last:border-b-0',
                                                                    waiting && 'bg-info/5',
                                                                )}
                                                            >
                                                                <span
                                                                    className={cn(
                                                                        'rounded-md px-1.5 py-0.5 text-xs font-semibold tabular-nums',
                                                                        waiting
                                                                            ? 'bg-info/15 text-info'
                                                                            : 'bg-muted text-muted-foreground',
                                                                    )}
                                                                >
                                                                    {year}
                                                                </span>
                                                                <span className="min-w-0 text-right text-sm">
                                                                    <Cell
                                                                        parameter={rule}
                                                                        year={year}
                                                                        onOpen={(version) => {
                                                                            setOpen({ parameter: rule, version });
                                                                        }}
                                                                    />
                                                                </span>
                                                            </li>
                                                        );
                                                    })}
                                                </ul>
                                            </section>
                                        ))}
                                    </div>
                                </Panel>
                            ))}
                        <p className="note px-1">{t((d) => d.pages.taxRules.legend)}</p>
                    </div>
                )}
            </Page>

            {open === null ? null : (
                <FiscalParameterDialog
                    parameter={open.parameter}
                    version={open.version}
                    canApprove={canApprove}
                    onClose={() => {
                        setOpen(null);
                    }}
                    onDecided={() => {
                        setOpen(null);
                        load();
                    }}
                />
            )}

            {approving === null ? null : (
                <ApproveRuleDialog
                    parameter={approving}
                    name={name(approving.code)}
                    onClose={() => {
                        setApproving(null);
                    }}
                    onDecided={load}
                />
            )}

            <ConfirmDialog
                open={dropping}
                title={t((d) => d.pages.taxRules.dropAllTitle)}
                description={t((d) => d.pages.taxRules.dropAllHint)}
                confirmLabel={t((d) => d.pages.taxRules.dropAll)}
                cancelLabel={t((d) => d.common.cancel)}
                onOpenChange={setDropping}
                onConfirm={() =>
                    dropFiscalParameterValues().then(
                        () => {
                            setDropping(false);
                            toast.success(t((d) => d.pages.taxRules.dropped));
                            load();
                        },
                        (cause: unknown) => {
                            toast.error(describeApiFailure(cause, t));
                        },
                    )
                }
            />

            {startingYear ? (
                <StartYearDialog
                    years={board?.years ?? []}
                    onClose={() => {
                        setStartingYear(false);
                    }}
                    onStarted={() => {
                        setStartingYear(false);
                        load();
                    }}
                />
            ) : null}
        </>
    );
}

/**
 * What this page is about, above the rules themselves: the wage every threshold counts in, as it
 * has stood since the first year read, and what there is to do.
 *
 * The wage is a step, not a curve — it holds until a decision replaces it — so it is drawn as bars
 * over the days each held, and a period that runs across a year end is simply a wider bar rather
 * than a value in two cells.
 */
function Overview({
    board,
    canApprove,
    onNewYear,
    onDropAll,
    onOpen,
}: {
    board: FiscalParameterBoard;
    canApprove: boolean;
    onNewYear: () => void;
    onDropAll: () => void;
    onOpen: (parameter: FiscalParameter, version: ParameterVersion) => void;
}): JSX.Element | null {
    const { t, locale } = useTranslation();
    const wage = wageOf(board);
    // In date order, with the one in force named and each bar measured — all by the server.
    const periods = wage?.versions ?? [];
    const inForce = periods.find((version) => version.id === board.summary.wageInForce);
    const waiting = board.summary.waiting;
    // Every year something was read for, newest first — the same years the table has rows for.
    const years = board.summary.years;
    const yearsRange = years.length === 0 ? '—' : `${String(years.at(-1))}–${String(years[0])}`;

    return (
        <Panel
            title={t((d) => d.pages.taxRules.overviewTitle)}
            description={t((d) => d.pages.taxRules.overviewHint)}
            icon={<Scale className="size-4" />}
            aside={
                canApprove ? (
                    <ActionGroup
                        collapse
                        actions={[
                            {
                                key: 'read-year',
                                label: t((d) => d.pages.taxRules.newYear),
                                icon: <CalendarPlus className="size-3.5" />,
                                onClick: onNewYear,
                            },
                            {
                                key: 'drop-all',
                                label: t((d) => d.pages.taxRules.dropAll),
                                icon: <Trash2 className="size-3.5" />,
                                tone: 'destructive',
                                onClick: onDropAll,
                            },
                        ]}
                    />
                ) : null
            }
        >
            {/* A phone puts the counts beside the wage as icon and figure, and the years read on a
                line of their own under both; from `sm` they are three tiles. */}
            <div className="flex items-start justify-between gap-4 sm:grid sm:grid-cols-[minmax(0,1fr)_auto]">
                <div className="min-w-0">
                    <p className="eyebrow">{t((d) => d.pages.taxRules.wageInForce)}</p>
                    <p className="mt-1 text-2xl font-semibold tabular-nums">
                        {inForce === undefined ? '—' : describeFiscalValue(inForce.display, t, locale)}
                    </p>
                    {inForce === undefined ? null : <p className="note mt-0.5">{describePeriod(inForce, t, locale)}</p>}
                </div>

                <dl className="grid shrink-0 gap-2 self-start sm:min-w-72 sm:grid-cols-3">
                    <Fact
                        icon={<ListChecks className="size-4" aria-hidden="true" />}
                        label={t((d) => d.pages.taxRules.rulesRead)}
                        value={String(board.summary.rules)}
                    />
                    <Fact className="hidden sm:block" label={t((d) => d.pages.taxRules.yearsRead)} value={yearsRange} />
                    <Fact
                        icon={<Hourglass className="size-4" aria-hidden="true" />}
                        label={t((d) => d.pages.taxRules.waiting)}
                        value={String(waiting)}
                        marked={waiting > 0}
                    />
                </dl>
            </div>

            <div className="border-border mt-4 flex items-center justify-between gap-3 border-t pt-3 sm:hidden">
                <p className="flex items-center gap-2.5">
                    <span className="bg-success/10 text-success grid size-8 shrink-0 place-items-center rounded-lg">
                        <CalendarRange className="size-4" aria-hidden="true" />
                    </span>
                    <span className="eyebrow">{t((d) => d.pages.taxRules.yearsRead)}</span>
                </p>
                <p className="bg-success/10 text-success rounded-full px-2.5 py-0.5 text-sm font-semibold tabular-nums">
                    {yearsRange}
                </p>
            </div>

            {periods.length === 0 || wage === undefined ? null : (
                <WageHistory
                    periods={periods}
                    bars={board.summary.wageHistory}
                    onOpen={(version) => {
                        onOpen(wage, version);
                    }}
                />
            )}
        </Panel>
    );
}

/**
 * A rule's name in the table's head, and how many of its years are waiting.
 *
 * A value is approved one at a time in its own dialog, where the paragraph it rests on is chosen —
 * which is right for one and tiring for eight identical years of the same rate. The name opens those
 * years together, each on the paragraph it was read in.
 */
function RuleName({
    name,
    waiting,
    onApprove,
}: {
    name: string;
    waiting: number;
    onApprove: (() => void) | undefined;
}): JSX.Element {
    const { t } = useTranslation();

    if (waiting === 0 || onApprove === undefined) {
        return <>{name}</>;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    className="hover:bg-muted inline-flex w-fit items-center gap-1.5 rounded px-1 py-0.5 text-left"
                    onClick={onApprove}
                >
                    {name}
                    <span className="bg-info/15 text-info rounded-full px-1.5 tabular-nums">{waiting}</span>
                </button>
            </TooltipTrigger>
            <TooltipContent side="top">
                {t((d) => d.pages.taxRules.approveAllHint).replace('{count}', String(waiting))}
            </TooltipContent>
        </Tooltip>
    );
}

/**
 * Every year of one rule that is waiting, approved in order, oldest first.
 *
 * Each on the paragraph it was read in — the same one its own dialog would open on. A year that
 * cannot be approved stops the run where it stands, so what went wrong is in front of the person
 * rather than behind them, and what was approved before it stays approved.
 */
function ApproveRuleDialog({
    parameter,
    name,
    onClose,
    onDecided,
}: {
    parameter: FiscalParameter;
    name: string;
    onClose: () => void;
    onDecided: () => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const waiting = waitingOn(parameter);
    const [done, setDone] = useState<string[]>([]);
    const [failedAt, setFailedAt] = useState<{ id: string; said: string } | null>(null);
    const [busy, setBusy] = useState(false);

    const approveAll = async (): Promise<void> => {
        setBusy(true);
        setFailedAt(null);

        for (const version of waiting) {
            if (done.includes(version.id)) {
                continue;
            }

            const on = version.source?.sectionId ?? version.matches[0]?.sectionId;

            try {
                if (on === undefined) {
                    throw new Error(t((d) => d.pages.taxRules.approveAllNoParagraph));
                }

                await approveFiscalParameter(version.id, on);
                setDone((approved) => [...approved, version.id]);
            } catch (cause) {
                setFailedAt({ id: version.id, said: describeApiFailure(cause, t) });
                break;
            }
        }

        setBusy(false);
        onDecided();
    };

    return (
        <PanelDialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
            title={t((d) => d.pages.taxRules.approveAll).replace('{rule}', name)}
            description={
                done.length === 0
                    ? t((d) => d.pages.taxRules.approveAllHint).replace('{count}', String(waiting.length))
                    : t((d) => d.pages.taxRules.approveAllProgress)
                          .replace('{done}', String(done.length))
                          .replace('{count}', String(waiting.length))
            }
            actions={
                <>
                    <PanelButton onClick={onClose}>
                        <X className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.taxRules.close)}
                    </PanelButton>
                    <PanelButton
                        primary
                        disabled={busy || done.length === waiting.length}
                        onClick={() => void approveAll()}
                    >
                        <Check className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.taxRules.approveAllAction).replace(
                            '{count}',
                            String(waiting.length - done.length),
                        )}
                    </PanelButton>
                </>
            }
        >
            <div className="table-wrap">
                <table className="data-table">
                    <thead>
                        <tr>
                            <th className="w-14">{t((d) => d.pages.taxRules.year)}</th>
                            <th>{t((d) => d.pages.taxRules.period)}</th>
                            <th className="text-right">{t((d) => d.pages.taxRules.parameter)}</th>
                            <th className="w-10" />
                        </tr>
                    </thead>
                    <tbody>
                        {waiting.map((version) => (
                            <tr key={version.id}>
                                <td className="text-foreground font-semibold tabular-nums">
                                    {version.validFrom.slice(0, 4)}
                                </td>
                                <td className="whitespace-nowrap">
                                    {describeDays(
                                        version,
                                        t((d) => d.pages.taxRules.periodOpen),
                                        locale,
                                    )}
                                </td>
                                <td className="text-foreground text-right font-semibold tabular-nums">
                                    {describeFiscalValue(version.display, t, locale)}
                                </td>
                                <td className="text-right">
                                    <Decided
                                        done={done.includes(version.id)}
                                        said={failedAt?.id === version.id ? failedAt.said : null}
                                        warn={version.checks.some((check) => check.tone === 'warn')}
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </PanelDialog>
    );
}

/** Where one year of the run stands: waiting, approved, or stopped on what went wrong. */
function Decided({ done, said, warn }: { done: boolean; said: string | null; warn: boolean }): JSX.Element {
    const { t } = useTranslation();

    if (said !== null) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span className="inline-flex">
                        <TriangleAlert className="text-destructive size-3.5" />
                    </span>
                </TooltipTrigger>
                <TooltipContent side="top">{said}</TooltipContent>
            </Tooltip>
        );
    }

    if (done) {
        return <Check className="text-success ml-auto size-4" />;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span className="inline-flex">
                    {warn ? (
                        <TriangleAlert className="text-warning size-3.5" />
                    ) : (
                        <span className="bg-info size-1.5 rounded-full" />
                    )}
                </span>
            </TooltipTrigger>
            <TooltipContent side="top">
                {t((d) => (warn ? d.pages.taxRules.look : d.pages.taxRules.statuses.CANDIDATE))}
            </TooltipContent>
        </Tooltip>
    );
}

/** A period inside one year, as short as the year column lets it be: "1 ian – 31 dec". */
function describeDays(version: ParameterVersion, open: string, locale: string): string {
    return version.validUntil === null
        ? open.replace('{from}', describeFrom(version, locale))
        : `${describeFrom(version, locale)} – ${new Date(version.validUntil).toLocaleDateString(locale, { day: 'numeric', month: 'short' })}`;
}

/** The values of one rule waiting for a person, oldest first: the order they would be approved in. */
function waitingOn(parameter: FiscalParameter): ParameterVersion[] {
    return parameter.versions
        .filter((version) => version.status === 'CANDIDATE')
        .sort((a, b) => a.validFrom.localeCompare(b.validFrom));
}

/** One of the three facts about the values, as a tile: a number and what it counts. */
/**
 * A count beside the wage. On a phone an icon tile with the figure over what it counts, on one line;
 * from `sm` a tile of its own, the term over the figure.
 */
function Fact({
    icon,
    label,
    value,
    marked = false,
    className,
}: {
    icon?: ReactNode;
    label: string;
    value: string;
    marked?: boolean;
    className?: string;
}): JSX.Element {
    return (
        <div
            className={cn(
                'flex items-center gap-2.5 sm:block sm:rounded-lg sm:border sm:px-3 sm:py-2',
                marked ? 'sm:border-info/40 sm:bg-info/10' : 'sm:bg-muted/40',
                className,
            )}
        >
            {icon === undefined ? null : (
                <span
                    className={cn(
                        'grid size-8 shrink-0 place-items-center rounded-lg sm:hidden',
                        marked ? 'bg-info/10 text-info' : 'bg-muted text-muted-foreground',
                    )}
                >
                    {icon}
                </span>
            )}
            {/* The term before its value, as a list of terms needs it; a phone shows the figure first. */}
            <div className="flex min-w-0 flex-col-reverse sm:flex-col">
                <dt className="eyebrow">{label}</dt>
                <dd className={cn('text-sm leading-tight font-semibold tabular-nums sm:mt-0.5', marked && 'text-info')}>
                    {value}
                </dd>
            </div>
        </div>
    );
}

/**
 * Every minimum wage since the first year read: one bar each, all the same width, because what is
 * being compared is the amounts and not how long each held — the one in force stands out, and the
 * days it covers are in its tooltip. Click a bar to open the decision it was read from.
 */
function WageHistory({
    periods,
    bars,
    onOpen,
}: {
    periods: ParameterVersion[];
    /** Each period's bar against the largest, as the server measured it. */
    bars: { id: string; bar: string }[];
    onOpen: (version: ParameterVersion) => void;
}): JSX.Element {
    const { t, locale } = useTranslation();

    return (
        <div className="border-border mt-5 border-t pt-4">
            <p className="eyebrow">{t((d) => d.pages.taxRules.wageHistory)}</p>
            <div className="mt-4 flex h-28 items-stretch gap-1.5">
                {periods.map((period, index) => (
                    <button
                        key={period.id}
                        type="button"
                        className="group flex flex-1 flex-col justify-end gap-1"
                        onClick={() => {
                            onOpen(period);
                        }}
                    >
                        <span className="note text-center tabular-nums">
                            {period.display.kind === 'money'
                                ? formatNumber(period.display.amountMinor / 100, locale)
                                : ''}
                        </span>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <span
                                    style={{
                                        height: `calc(${bars.find((entry) => entry.id === period.id)?.bar ?? '0'} * 100%)`,
                                    }}
                                    className={cn(
                                        'group-hover:bg-info/60 group-focus-visible:bg-info/60 w-full rounded-t-sm',
                                        index === periods.length - 1 ? 'bg-info/45' : 'bg-info/20',
                                    )}
                                />
                            </TooltipTrigger>
                            <TooltipContent side="top">
                                {describeFiscalValue(period.display, t, locale)} · {describePeriod(period, t, locale)}
                            </TooltipContent>
                        </Tooltip>
                    </button>
                ))}
            </div>
            <div className="mt-1 flex gap-1.5">
                {periods.map((period, index) => (
                    <span key={period.id} className="note flex-1 text-center tabular-nums">
                        {period.validFrom.slice(0, 4) === periods[index - 1]?.validFrom.slice(0, 4)
                            ? describeFrom(period, locale)
                            : period.validFrom.slice(0, 4)}
                    </span>
                ))}
            </div>
        </div>
    );
}

/** The minimum wage among the values: its own thing, since every threshold is counted in it. */
/** The minimum wage, as the server named it. */
function wageOf(board: FiscalParameterBoard): FiscalParameter | undefined {
    return board.parameters.find((parameter) => parameter.code === board.summary.wage);
}

/**
 * One value of one year: what it is, and only what asks something of the reader — a value approved
 * is quiet, one waiting is marked, one the law does not state is a dash, and a year never read is
 * blank. A year can hold two, where the law changed during it.
 */
function Cell({
    parameter,
    year,
    onOpen,
}: {
    parameter: FiscalParameter;
    year: number;
    onOpen: (version: ParameterVersion) => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const versions = parameter.versions.filter((version) => version.validFrom.startsWith(String(year)));
    const read = parameter.reads.find((entry) => entry.year === year);

    if (versions.length === 0) {
        return <ReadOutcome read={read} />;
    }

    return (
        <div className="flex flex-col items-start gap-0.5">
            {versions.map((version) => (
                <Tooltip key={version.id}>
                    <TooltipTrigger asChild>
                        <button
                            type="button"
                            className={cn(
                                'hover:bg-muted inline-flex w-fit items-baseline gap-1.5 rounded px-1 py-0.5 text-left',
                                version.status === 'REJECTED' && 'text-muted-foreground line-through',
                            )}
                            onClick={() => {
                                onOpen(version);
                            }}
                        >
                            <span className="font-medium tabular-nums">
                                {describeFiscalValue(version.display, t, locale)}
                            </span>
                            {version.status === 'CANDIDATE' ? (
                                <span className="bg-info size-1.5 shrink-0 rounded-full" />
                            ) : null}
                            {version.checks.some((check) => check.tone === 'warn') ? (
                                <TriangleAlert className="text-warning size-3 shrink-0" />
                            ) : null}
                            {versions.length > 1 ? (
                                <span className="note whitespace-nowrap">{describeFrom(version, locale)}</span>
                            ) : null}
                        </button>
                    </TooltipTrigger>
                    <TooltipContent side="top">
                        {describePeriod(version, t, locale)}
                        {version.status === 'CANDIDATE' ? ` · ${t((d) => d.pages.taxRules.statuses.CANDIDATE)}` : ''}
                    </TooltipContent>
                </Tooltip>
            ))}
        </div>
    );
}

/** The day a value starts, for a year that holds more than one. */
function describeFrom(version: ParameterVersion, locale: string): string {
    return new Date(version.validFrom).toLocaleDateString(locale, { day: 'numeric', month: 'short' });
}

/**
 * Why a year holds no value: the law of that year was read and does not state it, or nothing could
 * be asked. A year never read shows nothing at all, which is the third thing an empty cell meant.
 */
function ReadOutcome({ read }: { read: ParameterRead | undefined }): JSX.Element {
    const { t, locale } = useTranslation();

    if (read === undefined) {
        return <span className="text-muted-foreground/40 px-1">·</span>;
    }

    const said = (t((d) => d.pages.taxRules.reads)[read.outcome] ?? '').replace(
        '{date}',
        formatDateTime(read.readAt, locale),
    );

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span className="inline-flex px-1 text-xs">
                    {read.outcome === 'UNREAD' ? (
                        <Status tone="warn">{t((d) => d.pages.taxRules.unread)}</Status>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    )}
                </span>
            </TooltipTrigger>
            <TooltipContent side="top">{said}</TooltipContent>
        </Tooltip>
    );
}

/**
 * The rules of each tax, in the order the catalogue lists them: one table per tax reads across four
 * columns rather than a dozen, and a person looking for what a pension carries looks at one.
 *
 * @return every tax with its rules
 */
function taxesOf(board: FiscalParameterBoard): [string, FiscalParameter[]][] {
    const taxes = new Map<string, FiscalParameter[]>();

    for (const parameter of board.parameters) {
        taxes.set(parameter.tax, [...(taxes.get(parameter.tax) ?? []), parameter]);
    }

    return [...taxes.entries()];
}

/** Reads one year's law: pick the year, and every value its law states comes to review. */
function StartYearDialog({
    years,
    onClose,
    onStarted,
}: {
    /** The years the law can be read for, oldest first. */
    years: number[];
    onClose: () => void;
    onStarted: () => void;
}): JSX.Element {
    const { t } = useTranslation();
    const options = [...years].sort((a, b) => b - a).map(String);
    const [year, setYear] = useState(options[0] ?? String(new Date().getFullYear()));
    const [busy, setBusy] = useState(false);

    const start = async (): Promise<void> => {
        setBusy(true);

        try {
            await readFiscalYear(Number(year));
            toast.success(t((d) => d.pages.taxRules.yearQueued).replace('{year}', year));
            onStarted();
        } catch (cause) {
            toast.error(describeApiFailure(cause, t));
        } finally {
            setBusy(false);
        }
    };

    return (
        <PanelDialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
            size="sm"
            title={t((d) => d.pages.taxRules.newYear)}
            description={t((d) => d.pages.taxRules.newYearHint)}
            actions={
                <>
                    <PanelButton onClick={onClose}>
                        <X className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.taxRules.close)}
                    </PanelButton>
                    <PanelButton primary disabled={busy} onClick={() => void start()}>
                        <CalendarPlus className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.taxRules.propose)}
                    </PanelButton>
                </>
            }
        >
            <Field label={t((d) => d.pages.taxRules.year)} htmlFor="new-fiscal-year">
                <PanelSelect
                    id="new-fiscal-year"
                    value={year}
                    onValueChange={setYear}
                    options={options.map((value) => ({ value, label: value }))}
                />
            </Field>
        </PanelDialog>
    );
}
