import { useState, type JSX } from 'react';
import {
    Calculator,
    ChartPie,
    FileText,
    HeartPulse,
    Landmark,
    PiggyBank,
    Receipt,
    Tags,
    TriangleAlert,
    Wallet,
} from 'lucide-react';
import { toast } from 'sonner';

import AppShell from '../../components/AppShell';
import { IconBadge, Kpi, Page, Panel, PanelButton, PanelSelect } from '../../components/panel';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { formatAmount, formatPercent } from '../../lib/format';
import { apiRequest } from '../../lib/http';
import { getActiveSubjectId } from '../../lib/subjectStore';
import { themeColor } from '../../lib/themeColor';

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
    lines: Line[];
    /**
     * What each tax comes to, as the assessment recorded it.
     *
     * Read rather than added up here. A screen that sums money is a second place money is decided, and the two
     * drift; these are the figures `tax_assessments.totals_json` holds, which is what the assessment itself says
     * is owed.
     */
    totals: Record<string, number>;
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

function obj(value: unknown): Record<string, unknown> {
    return typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {};
}

function str(row: Record<string, unknown>, key: string): string {
    return typeof row[key] === 'string' ? row[key] : '';
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
        totals,
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
                baseReached: typeof row.base_reached === 'object' && row.base_reached !== null
                    ? (row.base_reached as Record<string, string>)
                    : null,
            };
        }),
    };
}

/**
 * What the year comes to across every tax — the engine's totals added, not the lines.
 *
 * Adding the six lines would be the same figure, but only because each line belongs to exactly one tax. Taking
 * the totals means the screen agrees with the assessment by construction rather than by coincidence.
 */
function totalOwedMinor(assessment: Assessment): number {
    return Object.values(assessment.totals).reduce((sum, minor) => sum + minor, 0);
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

/** The last ten years plus this one, newest first — as far back as the fiscal dataset goes. */
function assessableYears(): number[] {
    const current = new Date().getFullYear();

    return Array.from({ length: 9 }, (_unused, index) => current - index).filter((year) => year >= 2018);
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
 * The bar's colours, in slice order.
 *
 * Declared once because the legend's dots read the same list: two lists would drift, and a dot that does not
 * match its slice is worse than no dot — it names the wrong figure with confidence.
 *
 * Tints of `--primary` on an ordinary card. Worth stating because it has been wrong in both directions: on the
 * lead card, whose background *is* `--primary`, these same values drew a bar the colour of what it sat on.
 */
const SLICE_TINTS: [string, number][] = [
    ['--primary', 0.95],
    ['--primary', 0.6],
    ['--primary', 0.35],
    ['--primary', 0.2],
];

/** A tax's share of the bill, for the bar's width and the percentage beside its name. */
function shareOf(minor: number, assessment: Assessment): number {
    const total = totalOwedMinor(assessment);

    return total === 0 ? 0 : minor / total;
}

/** The tint for a slice, falling back to the last one so an unexpected fourth tax still gets a dot. */
function sliceTint(index: number): string {
    const [token, alpha] = SLICE_TINTS[index] ?? ['--muted-foreground', 0.3];

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
    const [subjectId] = useState(() => getActiveSubjectId());
    const [year, setYear] = useState(() => String(new Date().getFullYear()));
    const [assessment, setAssessment] = useState<Assessment | null>(null);
    const [working, setWorking] = useState(false);

    const composition = compositionOf(assessment);


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

            setAssessment(parse(JSON.parse(text)));
        } catch (cause: unknown) {
            toast.error(describeApiFailure(cause, t));
        } finally {
            setWorking(false);
        }
    };

    return (
        <AppShell>
            <Page
                title={t((d) => d.pages.taxes.title)}
                lede={t((d) => d.pages.taxes.description)}
                actions={
                    <>
                        <PanelSelect
                            id="assess-year"
                            /* A year is four characters; left at its own width the control takes the row and
                               strands the button on the line below. `ctl-narrow` is a rule in the stylesheet
                               rather than a utility, because a utility loses to the trigger's own `width`. */
                            className="ctl-narrow"
                            value={year}
                            onValueChange={setYear}
                            options={assessableYears().map((value) => ({
                                value: String(value),
                                label: String(value),
                            }))}
                        />
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
                    </>
                }
            >
                {assessment === null ? (
                    <p className="note">{t((d) => d.pages.taxes.notAssessedYet)}</p>
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
                                <div className="stat-value-lead mt-4">
                                    {formatAmount(totalOwedMinor(assessment) / 100, 'RON', locale)}
                                </div>
                                <div className="note mt-1">
                                    {assessment.status === 'FINAL'
                                        ? t((d) => d.pages.taxes.statusFinal)
                                        : t((d) => d.pages.taxes.statusDraft)}
                                </div>
                            </section>

                            <Kpi
                                tone="info"
                                icon={<Wallet className="size-4" />}
                                label={t((d) => d.pages.taxes.incomeAssessed)}
                                value={formatAmount(assessment.incomeAssessedMinor / 100, 'RON', locale)}
                                sub={`${String(assessment.fiscalYear)} · ${String(assessment.lines.length)} ${t((d) => d.pages.taxes.rulesApplied)}`}
                            />
                            <Kpi
                                tone={assessment.status === 'FINAL' ? 'success' : 'amber'}
                                icon={<FileText className="size-4" />}
                                label={t((d) => d.pages.taxes.assessmentStatus)}
                                value={
                                    assessment.status === 'FINAL'
                                        ? t((d) => d.pages.taxes.statusFinal)
                                        : t((d) => d.pages.taxes.statusDraft)
                                }
                                sub={t((d) => d.pages.taxes.draftHint)}
                            />
                        </div>

                        {/* A year with no assessable income would otherwise render three cards of zeroes,
                            which reads as a failure rather than as an answer. */}
                        {totalOwedMinor(assessment) === 0 && assessment.incomeAssessedMinor === 0 ? (
                            <p className="note">{t((d) => d.pages.taxes.nothingOwed)}</p>
                        ) : null}

                        {/*
                            The figures left, what explains them right. Siblings in a grid rather than nested —
                            a card inside a card draws three borders around one fact — and one column below the
                            width where a sidebar would be narrower than the sentences in it.
                        */}
                        <div className="grid items-start gap-4 xl:grid-cols-[1.7fr_1fr]">
                            <div className="flex min-w-0 flex-col gap-4">
                                {grouped(assessment).map((group) => (
                                    <Panel
                                        key={group.taxCode}
                                        title={taxName(group.taxCode, t)}
                                        icon={taxIcon(group.taxCode)}
                                        aside={
                                            <strong className="text-sm tabular-nums">
                                                {formatAmount(group.totalMinor / 100, 'RON', locale)}
                                            </strong>
                                        }
                                    >
                                        {group.components.map((line) => (
                                            <div
                                                className="tax-component"
                                                key={`${line.taxCode}-${line.categoryCode}`}
                                            >
                                                <div className="min-w-0">
                                                    <p className="text-xs font-semibold">{line.categoryCode}</p>

                                                    {/* The rule's own words for what it concluded. The point of
                                                        the engine is that a figure arrives with its reasoning,
                                                        so the reasoning gets a line rather than a column. */}
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

                                                    {/* Why the base is not the income — the step people most
                                                        want to see, and the reason two different incomes can
                                                        owe the same. */}
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

                                                <strong className="shrink-0 text-sm tabular-nums">
                                                    {formatAmount(line.taxMinor / 100, 'RON', locale)}
                                                </strong>
                                            </div>
                                        ))}
                                    </Panel>
                                ))}
                            </div>

                            <div className="flex min-w-0 flex-col gap-4">
                                {composition.length > 1 ? (
                                    <Panel
                                        title={t((d) => d.pages.taxes.composition)}
                                        icon={<ChartPie className="size-4" />}
                                    >
                                        {/* A bar, not a ring: no hole to fit a total into, it reads at any
                                            width, and three shares compare along a line more easily than
                                            around a circle. */}
                                        <div
                                            className="bg-muted flex h-2.5 gap-0.5 overflow-hidden rounded-full"
                                            aria-hidden="true"
                                        >
                                            {composition.map((slice, index) => (
                                                <div
                                                    key={slice.taxCode}
                                                    className="h-full"
                                                    style={{
                                                        width: `${String(shareOf(slice.minor, assessment) * 100)}%`,
                                                        background: sliceTint(index),
                                                    }}
                                                />
                                            ))}
                                        </div>

                                        <dl className="mt-4 grid gap-3">
                                            {composition.map((slice, index) => (
                                                <div className="min-w-0" key={slice.taxCode}>
                                                    <dt className="flex items-center gap-2 text-xs">
                                                        <span
                                                            className="size-2.5 shrink-0 rounded-full"
                                                            style={{ background: sliceTint(index) }}
                                                        />
                                                        {taxName(slice.taxCode, t)}
                                                        <span className="text-muted-foreground ml-auto tabular-nums">
                                                            {formatPercent(
                                                                shareOf(slice.minor, assessment),
                                                                locale,
                                                            )}
                                                        </span>
                                                    </dt>
                                                    <dd className="mt-0.5 text-sm font-semibold tabular-nums">
                                                        {formatAmount(slice.minor / 100, 'RON', locale)}
                                                    </dd>
                                                </div>
                                            ))}
                                        </dl>
                                    </Panel>
                                ) : null}

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
        </AppShell>
    );
}
