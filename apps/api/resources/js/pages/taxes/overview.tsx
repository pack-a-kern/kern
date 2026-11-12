import { useEffect, useMemo, useState, type JSX } from 'react';
import type { ChartConfiguration } from 'chart.js';
import {
    Calculator,
    CalendarDays,
    ChartPie,
    Download,
    EllipsisVertical,
    FileText,
    HeartPulse,
    Landmark,
    Lock,
    LockOpen,
    PiggyBank,
    Receipt,
    Tags,
    TriangleAlert,
    Wallet,
} from 'lucide-react';

import { Chart } from '../../components/Chart';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '../../components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipTrigger } from '../../components/ui/tooltip';
import { EmptyState, IconBadge, Kpi, Page, Panel, PanelButton, PanelSelect } from '../../components/panel';
import { useRememberedState } from '../../hooks/useRememberedState';
import { useTranslation } from '../../i18n';
import { reportApiFailure } from '../../lib/apiErrors';
import { formatAmount, formatCompact, formatDateTime, formatPercent } from '../../lib/format';
import { apiRequest, apiRequestBinary } from '../../lib/http';
import { useActiveSubjectId } from '../../hooks/useActiveSubjectId';
import { themeColor } from '../../lib/themeColor';
import { obj, str } from '../../lib/json';

/**
 * One line of a draft assessment: a figure and the reasoning behind it.
 *
 * `trace` is carried but not rendered here. It names the rule version, every step, and the source sections the
 * rule was built from — which is `/taxes/explain`'s subject, and showing a wall of it beside each figure would
 * bury the number somebody pressed a button for.
 */
interface Line {
    taxCode: string;
    categoryCode: string;
    taxableBaseMinor: number;
    rateDecimal: string | null;
    taxMinor: number;
    explanationKey: string;
    /**
     * What the rule summed before anything else — the income this figure came from.
     *
     * Shown because the first version of this screen displayed a base of 103,800 and nothing about the 475,690
     * behind it, so a contribution the rule describes as "a percentage of the threshold, not of what you
     * earned" appeared with no sign of what was earned.
     *
     * Minor units, formatted here like every other amount. It used to arrive pre-formatted by the engine's
     * `Money`, which writes a fixed machine form — so the row read `2177598.46 RON` next to `2.177.598,46 RON`,
     * the same currency written two ways on one line.
     */
    incomeMinor: number | null;
    /** How the rule got from that income to the base, as the step recorded it. */
    baseReached: Record<string, string> | null;
}

interface Assessment {
    fiscalYear: number;
    status: string;
    /** When it was computed. A draft read back a week later is only worth reading with its date on it. */
    computedAt: string | null;
    lines: Line[];
    /**
     * What each tax comes to, as the assessment recorded it.
     *
     * Read rather than added up here. A screen that sums money is a second place money is decided, and the two
     * drift; these are the figures `tax_assessments.totals_json` holds, which is what the assessment itself says
     * is owed.
     */
    totals: Record<string, number>;
    /** What the year comes to across every tax, added by the server from those totals. */
    totalOwedMinor: number;
    /** The year's income, from the facts — where each income exists exactly once. */
    incomeAssessedMinor: number;
}

/**
 * The taxes, in the order a person meets them, with the components of each.
 *
 * Grouping is the point of this screen rather than decoration. Six rules produce six rows, but a taxpayer owes
 * three taxes — and the same freelance income appears in three of those rows, which is what made an earlier
 * version read as though 2.1 million had been earned three times over. Grouped, each tax states its total once
 * and the rows beneath it are the parts, which is also how the obligation is actually paid.
 */
const TAX_ORDER = ['INCOME_TAX', 'CAS', 'CASS'] as const;

interface Group {
    taxCode: string;
    /** The engine's own total for this tax, in minor units. */
    totalMinor: number;
    components: Line[];
}

function grouped(assessment: Assessment): Group[] {
    const seen = new Map<string, Line[]>();

    for (const line of assessment.lines) {
        const components = seen.get(line.taxCode);

        if (components === undefined) {
            seen.set(line.taxCode, [line]);
        } else {
            components.push(line);
        }
    }

    return [...seen.keys()]
        .sort((left, right) => {
            // Known taxes in a chosen order, anything new after them alphabetically rather than dropped: a tax
            // this screen has not been taught about must still be shown, or a figure goes missing silently.
            const leftRank = TAX_ORDER.indexOf(left as (typeof TAX_ORDER)[number]);
            const rightRank = TAX_ORDER.indexOf(right as (typeof TAX_ORDER)[number]);

            if (leftRank === -1 && rightRank === -1) {
                return left.localeCompare(right);
            }

            return (leftRank === -1 ? TAX_ORDER.length : leftRank) - (rightRank === -1 ? TAX_ORDER.length : rightRank);
        })
        .map((taxCode) => ({
            taxCode,
            totalMinor: assessment.totals[taxCode] ?? 0,
            components: seen.get(taxCode) ?? [],
        }));
}

function int(row: Record<string, unknown>, key: string): number {
    return typeof row[key] === 'number' ? row[key] : 0;
}

function parse(payload: unknown): Assessment {
    const body = obj(payload);

    const totals: Record<string, number> = {};

    for (const [taxCode, total] of Object.entries(obj(body.totals))) {
        const minor = obj(total).minor_units;

        if (typeof minor === 'number') {
            totals[taxCode] = minor;
        }
    }

    return {
        fiscalYear: int(body, 'fiscal_year'),
        status: str(body, 'status'),
        computedAt: typeof body.computed_at === 'string' ? body.computed_at : null,
        totals,
        totalOwedMinor: int(body, 'total_owed_minor'),
        incomeAssessedMinor: int(body, 'income_assessed_minor'),
        lines: (Array.isArray(body.lines) ? body.lines : []).map((entry): Line => {
            const row = obj(entry);

            return {
                taxCode: str(row, 'tax_code'),
                categoryCode: str(row, 'category_code'),
                taxableBaseMinor: int(row, 'taxable_base_minor'),
                rateDecimal: typeof row.rate_decimal === 'string' ? row.rate_decimal : null,
                taxMinor: int(row, 'tax_minor'),
                explanationKey: str(row, 'explanation_key'),
                incomeMinor: typeof row.income_minor === 'number' ? row.income_minor : null,
                baseReached:
                    typeof row.base_reached === 'object' && row.base_reached !== null
                        ? (row.base_reached as Record<string, string>)
                        : null,
            };
        }),
    };
}

/**
 * A mark for each tax, so the three are told apart before the words are read.
 *
 * A tax this screen has not been taught about gets the generic one rather than none: an unmarked group beside
 * three marked ones reads as broken, where a neutral mark reads as "new".
 */
function taxIcon(taxCode: string): JSX.Element {
    switch (taxCode) {
        case 'CAS':
            return <PiggyBank className="size-4" aria-hidden="true" />;
        case 'CASS':
            return <HeartPulse className="size-4" aria-hidden="true" />;
        default:
            return <Landmark className="size-4" aria-hidden="true" />;
    }
}

/** A tax's name in the reader's language, falling back to its code so a new tax is never nameless. */
function taxName(taxCode: string, t: ReturnType<typeof useTranslation>['t']): string {
    switch (taxCode) {
        case 'CAS':
            return t((d) => d.pages.taxes.taxCasTitle);
        case 'CASS':
            return t((d) => d.pages.taxes.taxCassTitle);
        case 'INCOME_TAX':
            return t((d) => d.pages.taxes.taxIncomeTaxTitle);
        default:
            return taxCode;
    }
}

/**
 * Taxes — what a year's recorded income comes to.
 *
 * Replaces a placeholder that said the engine was blocked on a dataset. It no longer is: the parameters are
 * approved, three rules are registered, and this is the button that runs them.
 *
 * Every assessment it produces is a draft, which the status says plainly. The facts behind it are proposed —
 * they rest on how somebody categorised a bank line — and the rule versions are candidates until a person
 * approves them, so nothing here is fileable and the screen should not imply otherwise.
 */
/**
 * A colour per tax, in a fixed order.
 *
 * Identity, not magnitude: these are three different obligations, paid separately, and a reader picks one out
 * of the legend by its hue. Shades of a single colour said "parts of one quantity", which is true of the total
 * and useless for telling pension from health at a glance.
 *
 * Fixed per tax rather than by rank, so a year where health exceeds income tax does not repaint both. The
 * tokens are the app's own, and the pairs clear the separation the chart guidance asks for in both themes.
 */
const TAX_TINTS: Record<string, [string, number]> = {
    INCOME_TAX: ['--primary', 0.9],
    CASS: ['--info', 0.85],
    CAS: ['--success', 0.8],
};

/** A tax's share of the bill, for the bar's width and the percentage beside its name. */
function shareOf(minor: number, assessment: Assessment): number {
    const total = assessment.totalOwedMinor;

    return total === 0 ? 0 : minor / total;
}

/** A tax's colour for its mark's glyph. The ground comes from the stylesheet, as every other mark's does. */
function taxMarkStyle(taxCode: string): { color: string } {
    const [token] = TAX_TINTS[taxCode] ?? ['--muted-foreground', 0.35];

    return { color: themeColor(token) };
}

/** A tax's colour as a badge: its hue for the text, the same hue held well back for the ground. */
function taxBadgeStyle(taxCode: string): { background: string; color: string } {
    const [token] = TAX_TINTS[taxCode] ?? ['--muted-foreground', 0.35];

    return { background: themeColor(token, 0.12), color: themeColor(token) };
}

/** A tax's colour, with a neutral for one this screen has not been taught about. */
function sliceTint(taxCode: string): string {
    const [token, alpha] = TAX_TINTS[taxCode] ?? ['--muted-foreground', 0.35];

    return themeColor(token, alpha);
}

/**
 * What the bill is made of: one slice per tax, largest first.
 *
 * The taxes rather than the income categories, because this sits beside "total owed" and answers the question
 * that figure provokes — which of the three is most of it. The income breakdown is already on the rows.
 */
function compositionOf(assessment: Assessment | null): { taxCode: string; minor: number }[] {
    if (assessment === null) {
        return [];
    }

    return Object.entries(assessment.totals)
        .map(([taxCode, minor]) => ({ taxCode, minor }))
        .filter((slice) => slice.minor > 0)
        .sort((left, right) => right.minor - left.minor);
}

export default function TaxesOverview(): JSX.Element {
    const { t, locale } = useTranslation();
    const subjectId = useActiveSubjectId();
    /** Remembered per taxpayer, as the reports screen remembers its period: a year chosen is a year being read. */
    const [year, setYear] = useRememberedState<string>('taxes.assessment.year', String(new Date().getFullYear()), {
        perSubject: true,
        isValid: (value: string) => /^\d{4}$/.test(value),
    });
    /**
     * What was found for a year, with the year it was found for.
     *
     * The pair rather than two states: whether the screen is still looking is *derived* from whether what it
     * holds is for the year now selected. Setting a loading flag inside the effect is a synchronous set during
     * render, and it also leaves a window where last year's figures are on screen under this year's heading.
     */
    const [loaded, setLoaded] = useState<{
        year: string;
        assessment: Assessment | null;
        years: number[];
    } | null>(null);
    const [working, setWorking] = useState(false);

    const assessment = loaded !== null && loaded.year === year ? loaded.assessment : null;
    const loading = subjectId !== null && (loaded === null || loaded.year !== year);

    const composition = compositionOf(assessment);

    /*
     * The assessment already on file for this year.
     *
     * A draft is saved, so returning to the screen should show it rather than ask for it to be recomputed —
     * which would also replace it, making last week's figures unreachable by the act of looking for them.
     */
    useEffect(() => {
        if (subjectId === null) {
            return;
        }

        let current = true;

        void apiRequest(
            `/v1/subjects/${encodeURIComponent(subjectId)}/tax/assessments?fiscal_year=${encodeURIComponent(year)}`,
        )
            .then((text) => {
                if (current) {
                    // 204 and an empty body for a year never assessed: nothing is missing, it has not been
                    // done yet, and a screen that treats that as an error shows a failure instead of a button.
                    const body = obj(JSON.parse(text));

                    setLoaded({
                        year,
                        assessment: body.assessment === null ? null : parse(body.assessment),
                        years: (Array.isArray(body.years) ? body.years : []).filter(
                            (value): value is number => typeof value === 'number',
                        ),
                    });
                }
            })
            .catch(() => {
                if (current) {
                    setLoaded({ year, assessment: null, years: [] });
                }
            });

        return () => {
            current = false;
        };
    }, [subjectId, year]);

    /*
     * Rebuilt only when the figures change — Chart.js keeps the object it is handed, so a new one on every
     * render redraws continuously.
     */
    const compositionConfig = useMemo<ChartConfiguration>(
        () => ({
            type: 'doughnut',
            data: {
                labels: composition.map((slice) => slice.taxCode),
                datasets: [
                    composition.length === 0
                        ? {
                              // Nothing to divide: the track the slices would sit on, in the neutral ground,
                              // so the card reads as "empty" rather than as a ring that failed to draw.
                              data: [1],
                              backgroundColor: [themeColor('--muted')],
                              borderWidth: 0,
                          }
                        : {
                              data: composition.map((slice) => slice.minor / 100),
                              backgroundColor: composition.map((slice) => sliceTint(slice.taxCode)),
                              // A ring of the surface between slices, so two shares read as two rather than as
                              // one wide one where their edges meet.
                              borderColor: themeColor('--card'),
                              borderWidth: 2,
                          },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
            },
        }),
        [assessment],
    );

    /**
     * A year that has ended. 2026 cannot be final while income may still arrive in it. The server's list
     * leads with this year on Romania's calendar, so no clock here decides it.
     */
    const yearHasClosed = loaded?.years[0] !== undefined && Number(year) < loaded.years[0];

    /**
     * One request, one outcome, the same error handling.
     *
     * Three buttons do the same three things — ask the server, replace what is held, say so if it refused —
     * and three copies of that would be three places for a failure to be swallowed differently.
     */
    const run = async (path: string): Promise<void> => {
        if (subjectId === null) {
            return;
        }

        setWorking(true);

        try {
            // `apiRequest` already carries the step-up retry; wrapping it again would ask twice.
            const text = await apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/tax/assessments${path}`, {
                method: 'POST',
                body: { fiscal_year: Number(year) },
            });

            setLoaded({ year, assessment: parse(JSON.parse(text)), years: loaded?.years ?? [] });
        } catch (cause: unknown) {
            reportApiFailure(cause, t);
        } finally {
            setWorking(false);
        }
    };

    /** The figures as a file. A download, so it is fetched as bytes rather than parsed. */
    const exportCsv = async (part: 'figures' | 'evidence'): Promise<void> => {
        if (subjectId === null) {
            return;
        }

        try {
            const blob = await apiRequestBinary(
                `/v1/subjects/${encodeURIComponent(subjectId)}/tax/assessments/export` +
                    `?fiscal_year=${encodeURIComponent(year)}&part=${part}`,
            );
            const url = URL.createObjectURL(blob);
            const link = window.document.createElement('a');

            link.href = url;
            link.download = `kern-${year}-${part}.csv`;
            link.click();

            window.setTimeout(() => {
                URL.revokeObjectURL(url);
            }, 0);
        } catch (cause: unknown) {
            reportApiFailure(cause, t);
        }
    };

    const assess = async (): Promise<void> => {
        if (subjectId === null) {
            return;
        }

        setWorking(true);

        try {
            const text = await apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/tax/assessments`, {
                method: 'POST',
                body: { fiscal_year: Number(year) },
            });

            setLoaded({ year, assessment: parse(JSON.parse(text)), years: loaded?.years ?? [] });
        } catch (cause: unknown) {
            reportApiFailure(cause, t);
        } finally {
            setWorking(false);
        }
    };

    return (
        <>
            <Page
                title={t((d) => d.pages.taxes.title)}
                lede={t((d) => d.pages.taxes.description)}
                filters={
                    <>
                        <PanelSelect
                            id="assess-year"
                            /* A year is four characters; left at its own width the control takes the row and
                               strands the button on the line below. `ctl-narrow` is a rule in the stylesheet
                               rather than a utility, because a utility loses to the trigger's own `width`. */
                            className="ctl-narrow"
                            value={year}
                            onValueChange={setYear}
                            icon={<CalendarDays className="size-3.5" aria-hidden="true" />}
                            // As the server offers them: in range, this year included, newest first.
                            options={(loaded?.years ?? []).map((value) => ({
                                value: String(value),
                                label: String(value),
                            }))}
                        />
                    </>
                }
                actions={
                    <>
                        {/*
                            Every action, side by side where there is room and in one menu where there is not.
                            At phone width three controls wrapped onto their own line and pushed the figures
                            below the fold.
                        */}
                        <div className="hidden items-center gap-2 sm:flex">
                            {/*
                                Disabled rather than unmounted while a year loads. Hiding them made the row
                                rebuild itself on every change of year — buttons vanishing and returning, the
                                ones beside them jumping sideways — which is a worse answer to "there is
                                nothing to export yet" than a control that is plainly not available.
                            */}
                            <PanelButton
                                disabled={loading || assessment === null}
                                onClick={() => {
                                    void exportCsv('figures');
                                }}
                            >
                                <Download className="size-3.5" aria-hidden="true" />
                                {t((d) => d.pages.taxes.exportFigures)}
                            </PanelButton>
                            <PanelButton
                                disabled={loading || assessment === null}
                                onClick={() => {
                                    void exportCsv('evidence');
                                }}
                            >
                                <Download className="size-3.5" aria-hidden="true" />
                                {t((d) => d.pages.taxes.exportEvidence)}
                            </PanelButton>

                            {/* Disabled on a final year rather than removed, for the same reason: the engine
                                refuses to recompute one, and the row should not rearrange itself to say so. */}
                            <PanelButton
                                primary
                                disabled={working || loading || subjectId === null || assessment?.status === 'FINAL'}
                                onClick={() => {
                                    void assess();
                                }}
                            >
                                <Calculator className="size-3.5" aria-hidden="true" />
                                {t((d) => d.pages.taxes.assess)}
                            </PanelButton>
                        </div>

                        {/*
                            Hidden by a wrapper, not by `sm:hidden` on the control: `.shell-control` sets its
                            own `display`, and a Tailwind utility sits in `@layer utilities` where an unlayered
                            rule outranks it — so the class was on the button and the menu showed everywhere.
                        */}
                        <div className="sm:hidden">
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <button
                                        type="button"
                                        className="shell-control"
                                        aria-label={t((d) => d.pages.taxes.actionsMenu)}
                                    >
                                        <EllipsisVertical className="size-4" aria-hidden="true" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="w-52">
                                    <DropdownMenuItem
                                        disabled={
                                            working || loading || subjectId === null || assessment?.status === 'FINAL'
                                        }
                                        onSelect={() => {
                                            void assess();
                                        }}
                                    >
                                        <Calculator className="size-3.5" aria-hidden="true" />
                                        {t((d) => d.pages.taxes.assess)}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        disabled={loading || assessment === null}
                                        onSelect={() => {
                                            void exportCsv('figures');
                                        }}
                                    >
                                        <Download className="size-3.5" aria-hidden="true" />
                                        {t((d) => d.pages.taxes.exportFigures)}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        disabled={loading || assessment === null}
                                        onSelect={() => {
                                            void exportCsv('evidence');
                                        }}
                                    >
                                        <Download className="size-3.5" aria-hidden="true" />
                                        {t((d) => d.pages.taxes.exportEvidence)}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </>
                }
            >
                {loading ? (
                    <p className="note">{t((d) => d.pages.taxes.loadingSaved)}</p>
                ) : assessment === null ? (
                    /* The action is inside the empty state, not only in the page's head: somebody who has
                       nothing on screen should not have to find the one control that changes that. */
                    <Panel title={t((d) => d.pages.taxes.breakdownTitle)} icon={<Receipt className="size-4" />}>
                        <EmptyState
                            icon={<Calculator className="size-5" />}
                            title={t((d) => d.pages.taxes.emptyTitle).replace('{year}', year)}
                            description={t((d) => d.pages.taxes.emptyHint)}
                            action={
                                <PanelButton
                                    primary
                                    disabled={working || subjectId === null}
                                    onClick={() => {
                                        void assess();
                                    }}
                                >
                                    <Calculator className="size-3.5" aria-hidden="true" />
                                    {t((d) => d.pages.taxes.assess)}
                                </PanelButton>
                            }
                        />
                    </Panel>
                ) : (
                    <>
                        {/*
                            Three figures in one row, the bill first and widest. Auto-fit below that width, so
                            the tiles choose their own columns rather than meeting a breakpoint I guessed.
                        */}
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(14rem,1fr))] gap-3 lg:grid-cols-[1.45fr_1fr_1fr]">
                            <section className="stat-primary shadow-float rounded-xl border p-5">
                                <div className="flex items-center gap-2.5 text-xs">
                                    <IconBadge tone="brand">
                                        <Receipt className="size-4" />
                                    </IconBadge>
                                    {t((d) => d.pages.taxes.totalOwedLabel)}
                                </div>
                                {/*
                                    Shortened, with the exact figure in the app's own tooltip rather than a
                                    `title`: a native tooltip is unstyled, takes a second to appear and never
                                    shows on a touch screen, which is three ways for the real number to be
                                    unreachable. The one place here an amount owed is abbreviated — it is the
                                    headline, and the breakdown below states it exactly.
                                */}
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <div
                                            className="stat-value-lead mt-4 w-fit cursor-default"
                                            aria-label={formatAmount(assessment.totalOwedMinor / 100, 'RON', locale)}
                                        >
                                            {formatCompact(assessment.totalOwedMinor / 100, locale)} RON
                                        </div>
                                    </TooltipTrigger>
                                    <TooltipContent side="bottom">
                                        {formatAmount(assessment.totalOwedMinor / 100, 'RON', locale)}
                                    </TooltipContent>
                                </Tooltip>
                                <div className="note mt-1">
                                    {assessment.status === 'FINAL'
                                        ? t((d) => d.pages.taxes.statusFinal)
                                        : t((d) => d.pages.taxes.statusDraft)}
                                    {assessment.computedAt === null
                                        ? null
                                        : ` · ${t((d) => d.pages.taxes.computedAt).replace(
                                              '{when}',
                                              formatDateTime(assessment.computedAt, locale),
                                          )}`}
                                </div>
                            </section>

                            <Kpi
                                tone="info"
                                icon={<Wallet className="size-4" />}
                                label={t((d) => d.pages.taxes.incomeAssessed)}
                                value={`${formatCompact(assessment.incomeAssessedMinor / 100, locale)} RON`}
                                sub={`${String(assessment.fiscalYear)} · ${String(assessment.lines.length)} ${t((d) => d.pages.taxes.rulesApplied)}`}
                            />
                            {/*
                                The status, and the one act its state allows. A draft on a closed year can be
                                made final; a final can be unlocked; a draft on a year still running can be
                                neither, and says why rather than showing a button that refuses.
                            */}
                            <section className="border-border bg-card shadow-hair flex flex-col rounded-xl border p-4">
                                {/* The act sits on the label's line, where a reader is already looking at
                                    what the year's state is — not under a paragraph explaining it. */}
                                <div className="flex items-center gap-2.5">
                                    <IconBadge tone={assessment.status === 'FINAL' ? 'success' : 'amber'}>
                                        <FileText className="size-4" />
                                    </IconBadge>
                                    <span className="text-muted-foreground text-xs">
                                        {t((d) => d.pages.taxes.assessmentStatus)}
                                    </span>

                                    {assessment.status === 'FINAL' ? (
                                        <PanelButton
                                            className="ml-auto"
                                            disabled={working}
                                            onClick={() => {
                                                void run('/unlock');
                                            }}
                                        >
                                            <LockOpen className="size-3.5" aria-hidden="true" />
                                            {t((d) => d.pages.taxes.unlock)}
                                        </PanelButton>
                                    ) : yearHasClosed ? (
                                        <PanelButton
                                            className="ml-auto"
                                            disabled={working}
                                            onClick={() => {
                                                void run('/final');
                                            }}
                                        >
                                            <Lock className="size-3.5" aria-hidden="true" />
                                            {t((d) => d.pages.taxes.markFinal)}
                                        </PanelButton>
                                    ) : null}
                                </div>

                                <div className="mt-3 text-xl font-bold tracking-tight">
                                    {assessment.status === 'FINAL'
                                        ? t((d) => d.pages.taxes.statusFinal)
                                        : t((d) => d.pages.taxes.statusDraft)}
                                </div>

                                <p className="note mt-1">
                                    {assessment.status === 'FINAL'
                                        ? t((d) => d.pages.taxes.finalHint)
                                        : yearHasClosed
                                          ? t((d) => d.pages.taxes.draftHint)
                                          : t((d) => d.pages.taxes.yearStillOpen).replace('{year}', year)}
                                </p>
                            </section>
                        </div>

                        {/* A year with no assessable income would otherwise render three cards of zeroes,
                            which reads as a failure rather than as an answer. */}
                        {assessment.totalOwedMinor === 0 && assessment.incomeAssessedMinor === 0 ? (
                            <p className="note">{t((d) => d.pages.taxes.nothingOwed)}</p>
                        ) : null}

                        {/*
                            The figures left, what explains them right. Siblings in a grid rather than nested —
                            a card inside a card draws three borders around one fact — and one column below the
                            width where a sidebar would be narrower than the sentences in it.
                        */}
                        <div className="grid items-start gap-4 xl:grid-cols-[1.7fr_1fr]">
                            <div className="flex min-w-0 flex-col gap-4">
                                {/*
                                    One card, the taxes as sections inside it. Three cards for three taxes put
                                    a border around each figure and read as three unrelated screens; the
                                    grouping is the point, and a section heading carries it without a frame.
                                */}
                                <Panel
                                    title={t((d) => d.pages.taxes.breakdownTitle)}
                                    description={t((d) => d.pages.taxes.breakdownHint)}
                                    icon={<Receipt className="size-4" />}
                                    aside={
                                        <span className="note">
                                            {t((d) => d.pages.taxes.entryCount).replace(
                                                '{count}',
                                                String(assessment.lines.length),
                                            )}
                                        </span>
                                    }
                                >
                                    {grouped(assessment).map((group) => (
                                        <section className="tax-group" key={group.taxCode}>
                                            <div className="tax-group-head">
                                                <span
                                                    className="tax-group-mark"
                                                    style={taxMarkStyle(group.taxCode)}
                                                    aria-hidden="true"
                                                >
                                                    {taxIcon(group.taxCode)}
                                                </span>
                                                <h3 className="text-xs font-semibold">{taxName(group.taxCode, t)}</h3>
                                                <span className="money-badge" style={taxBadgeStyle(group.taxCode)}>
                                                    {formatAmount(group.totalMinor / 100, 'RON', locale)}
                                                </span>
                                            </div>

                                            {group.components.map((line) => (
                                                <div
                                                    className="tax-component"
                                                    key={`${line.taxCode}-${line.categoryCode}`}
                                                >
                                                    <div className="min-w-0">
                                                        <p className="text-xs font-semibold">{line.categoryCode}</p>

                                                        {/* The rule's own words for what it concluded. A figure
                                                            arriving with its reasoning is the point of this
                                                            engine, so the reasoning gets a line of its own. */}
                                                        <p className="note mt-1">
                                                            {t((d) => d.taxExplanations)[line.explanationKey] ??
                                                                line.explanationKey}
                                                        </p>

                                                        <p className="note tax-figures">
                                                            <span>
                                                                {t((d) => d.pages.taxes.componentIncome)}{' '}
                                                                <b>
                                                                    {line.incomeMinor === null
                                                                        ? '—'
                                                                        : formatAmount(
                                                                              line.incomeMinor / 100,
                                                                              'RON',
                                                                              locale,
                                                                          )}
                                                                </b>
                                                            </span>
                                                            <span>
                                                                {t((d) => d.pages.taxes.componentBase)}{' '}
                                                                <b>
                                                                    {formatAmount(
                                                                        line.taxableBaseMinor / 100,
                                                                        'RON',
                                                                        locale,
                                                                    )}
                                                                </b>
                                                            </span>
                                                            {line.rateDecimal === null ? null : (
                                                                <span>
                                                                    <b>
                                                                        {formatPercent(
                                                                            Number(line.rateDecimal),
                                                                            locale,
                                                                        )}
                                                                    </b>
                                                                </span>
                                                            )}
                                                        </p>

                                                        {/* Why the base is not the income — the step people
                                                            most want to see, and the reason two different
                                                            incomes can owe the same. */}
                                                        {line.baseReached?.threshold_in_wages !== undefined &&
                                                        line.baseReached.threshold_in_wages !== 'none' ? (
                                                            <p className="note mt-1">
                                                                {t((d) => d.pages.taxes.thresholdReached).replace(
                                                                    '{wages}',
                                                                    line.baseReached.threshold_in_wages,
                                                                )}
                                                            </p>
                                                        ) : null}
                                                        {line.baseReached?.quota !== undefined ? (
                                                            <p className="note mt-1">
                                                                {t((d) => d.pages.taxes.quotaDeducted).replace(
                                                                    '{deducted}',
                                                                    line.baseReached.deducted_minor === undefined
                                                                        ? (line.baseReached.deducted ?? '')
                                                                        : formatAmount(
                                                                              Number(line.baseReached.deducted_minor) /
                                                                                  100,
                                                                              'RON',
                                                                              locale,
                                                                          ),
                                                                )}
                                                            </p>
                                                        ) : null}
                                                    </div>

                                                    {/* Quiet beside the section's badge: a component is part
                                                        of that total, not a second headline. A nil is muted,
                                                        because a line owing nothing should not draw the eye. */}
                                                    <span
                                                        className={
                                                            line.taxMinor === 0
                                                                ? 'money-badge money-badge-nil'
                                                                : 'money-badge money-badge-quiet'
                                                        }
                                                    >
                                                        {formatAmount(line.taxMinor / 100, 'RON', locale)}
                                                    </span>
                                                </div>
                                            ))}
                                        </section>
                                    ))}
                                </Panel>
                            </div>

                            <div className="flex min-w-0 flex-col gap-4">
                                {/* Always, including a year that owes nothing: a card that disappears makes
                                    the page jump and leaves the reader wondering what they did. */}
                                <Panel
                                    title={t((d) => d.pages.taxes.composition)}
                                    icon={<ChartPie className="size-4" />}
                                >
                                    {/*
                                            A ring, with every slice named beside it. Three parts of one sum
                                            is what a ring is for, and the hole carries the figure the slices
                                            are shares of — which is why the total is not repeated in the list.
                                        */}
                                    <div className="flex items-center gap-4">
                                        <div className="chart-donut-mid">
                                            <Chart
                                                config={compositionConfig}
                                                label={t((d) => d.pages.taxes.composition)}
                                            />
                                            <div className="pointer-events-none absolute inset-0 grid place-items-center text-center">
                                                <div>
                                                    {/* Context, not an amount to act on, and the hole is
                                                            95px — so it may be shortened, with the exact
                                                            figure a hover away. */}
                                                    <p className="text-sm font-semibold tabular-nums">
                                                        {formatCompact(assessment.totalOwedMinor / 100, locale)}
                                                    </p>
                                                    <p className="note donut-note">RON</p>
                                                </div>
                                            </div>
                                        </div>

                                        {composition.length === 0 ? (
                                            // Only an assessed year reaches this card, so an empty ring means it
                                            // was worked out and owes nothing — said in words beside it.
                                            <p className="note min-w-0 flex-1">{t((d) => d.pages.taxes.nothingOwed)}</p>
                                        ) : (
                                            <dl className="grid min-w-0 flex-1 gap-3">
                                                {composition.map((slice) => (
                                                    <div className="min-w-0" key={slice.taxCode}>
                                                        <dt className="flex items-center gap-2 text-xs">
                                                            <span
                                                                className="size-2.5 shrink-0 rounded-full"
                                                                style={{ background: sliceTint(slice.taxCode) }}
                                                            />
                                                            <span className="truncate">
                                                                {taxName(slice.taxCode, t)}
                                                            </span>
                                                            <span className="text-muted-foreground ml-auto tabular-nums">
                                                                {formatPercent(
                                                                    shareOf(slice.minor, assessment),
                                                                    locale,
                                                                )}
                                                            </span>
                                                        </dt>
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <dd
                                                                    className="mt-0.5 w-fit cursor-default text-sm font-semibold tabular-nums"
                                                                    aria-label={formatAmount(
                                                                        slice.minor / 100,
                                                                        'RON',
                                                                        locale,
                                                                    )}
                                                                >
                                                                    {formatCompact(slice.minor / 100, locale)} RON
                                                                </dd>
                                                            </TooltipTrigger>
                                                            <TooltipContent side="left">
                                                                {formatAmount(slice.minor / 100, 'RON', locale)}
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    </div>
                                                ))}
                                            </dl>
                                        )}
                                    </div>
                                </Panel>

                                {/* What a figure here does not yet account for. Each is a heading and the
                                    reason it matters, because "no deductible expenses are recorded" is not a
                                    caveat somebody acts on without being told what it means for them. */}
                                <Panel
                                    title={t((d) => d.pages.taxes.beforeFinalising)}
                                    icon={<TriangleAlert className="size-4" />}
                                >
                                    {[
                                        {
                                            icon: <Wallet className="size-3.5" aria-hidden="true" />,
                                            title: t((d) => d.pages.taxes.checkExpensesTitle),
                                            body: t((d) => d.pages.taxes.caveatExpenses),
                                        },
                                        {
                                            icon: <Tags className="size-3.5" aria-hidden="true" />,
                                            title: t((d) => d.pages.taxes.checkCategoriesTitle),
                                            body: t((d) => d.pages.taxes.caveatCategorisation),
                                        },
                                        {
                                            icon: <FileText className="size-3.5" aria-hidden="true" />,
                                            title: t((d) => d.pages.taxes.checkFilingTitle),
                                            body: t((d) => d.pages.taxes.caveatNotFiling),
                                        },
                                    ].map((check) => (
                                        <div className="tax-check" key={check.title}>
                                            <span className="tax-check-mark">{check.icon}</span>
                                            <div className="min-w-0">
                                                <p className="text-xs font-semibold">{check.title}</p>
                                                <p className="note mt-0.5">{check.body}</p>
                                            </div>
                                        </div>
                                    ))}
                                </Panel>
                            </div>
                        </div>
                    </>
                )}
            </Page>
        </>
    );
}
