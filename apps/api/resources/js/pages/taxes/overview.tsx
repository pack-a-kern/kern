import { useMemo, useState, type JSX } from 'react';
import type { ChartConfiguration } from 'chart.js';
import {
    Calculator,
    HeartPulse,
    Landmark,
    PiggyBank,
    Receipt,
    TriangleAlert,
    Wallet,
} from 'lucide-react';
import { toast } from 'sonner';

import AppShell from '../../components/AppShell';
import {
    DataTable,
    DonutTile,
    Page,
    Panel,
    PanelButton,
    PanelSelect,
    Stat,
    Status,
} from '../../components/panel';
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
     * earned" appeared with no sign of what was earned. Already formatted with its currency by the engine's own
     * `Money`, so it is printed rather than reformatted.
     */
    income: string | null;
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
                income: typeof row.income === 'string' ? row.income : null,
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
    /** The biggest slice, which the tile names beside the ring. */
    const largest = composition[0];

    /*
     * One ring, shades of a single hue rather than three unrelated colours: the slices are parts of one bill,
     * and a palette of separate colours would suggest three unrelated things. Rebuilt only when the figures
     * change — Chart.js keeps the object it is handed, so a new one every render would redraw continuously.
     */
    const compositionConfig = useMemo<ChartConfiguration>(
        () => ({
            type: 'doughnut',
            data: {
                labels: [],
                datasets: [
                    {
                        data: composition.map((slice) => slice.minor / 100),
                        backgroundColor: [
                            themeColor('--primary', 0.95),
                            themeColor('--primary', 0.6),
                            themeColor('--primary', 0.32),
                            themeColor('--muted-foreground', 0.25),
                        ],
                        borderWidth: 0,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
            },
        }),
        [assessment],
    );

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
            <Page title={t((d) => d.pages.taxes.title)} lede={t((d) => d.pages.taxes.description)}>
                <Panel
                    title={t((d) => d.pages.taxes.assessTitle)}
                    description={t((d) => d.pages.taxes.assessHint)}
                    icon={<Calculator className="size-4" />}
                    aside={
                        <>
                            <PanelSelect
                                id="assess-year"
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
                                What somebody wants before any detail: the bill, what it was computed from, and
                                whether it counts for anything yet. The first is set apart because it is the one
                                figure the page exists to produce; the other two explain it.
                            */}
                            <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr]">
                                <Stat
                                    className="stat-primary"
                                    label={t((d) => d.pages.taxes.totalOwedLabel)}
                                    icon={<Receipt className="size-4" aria-hidden="true" />}
                                    value={
                                        <span className="stat-value-lead">
                                            {formatAmount(totalOwedMinor(assessment) / 100, 'RON', locale)}
                                        </span>
                                    }
                                    sub={
                                        assessment.status === 'FINAL'
                                            ? t((d) => d.pages.taxes.statusFinal)
                                            : t((d) => d.pages.taxes.statusDraft)
                                    }
                                />
                                <Stat
                                    label={t((d) => d.pages.taxes.incomeAssessed)}
                                    icon={<Wallet className="size-4" aria-hidden="true" />}
                                    tone="accent"
                                    value={formatAmount(assessment.incomeAssessedMinor / 100, 'RON', locale)}
                                    sub={`${String(assessment.fiscalYear)} · ${String(assessment.lines.length)} ${t((d) => d.pages.taxes.rulesApplied)}`}
                                />
                                <Stat
                                    label={t((d) => d.pages.taxes.assessmentStatus)}
                                    tone={assessment.status === 'FINAL' ? 'success' : 'neutral'}
                                    value={
                                        <Status tone={assessment.status === 'FINAL' ? 'ok' : 'warn'}>
                                            {assessment.status === 'FINAL'
                                                ? t((d) => d.pages.taxes.statusFinal)
                                                : t((d) => d.pages.taxes.statusDraft)}
                                        </Status>
                                    }
                                    sub={t((d) => d.pages.taxes.draftHint)}
                                />
                            </div>

                            {/* Which of the three taxes is most of the bill — the question the total provokes,
                                answered beside it rather than worked out from the groups below. Drawn only when
                                there is something to compare: a ring that is always 100% of itself says nothing. */}
                            {largest !== undefined && composition.length > 1 ? (
                                <div className="mb-4">
                                    <DonutTile
                                        config={compositionConfig}
                                        centre={formatAmount(totalOwedMinor(assessment) / 100, 'RON', locale)}
                                        centreNote={t((d) => d.pages.taxes.totalOwedLabel)}
                                        label={t((d) => d.pages.taxes.composition)}
                                        value={taxName(largest.taxCode, t)}
                                        note={`${formatAmount(largest.minor / 100, 'RON', locale)} · ${String(
                                            Math.round((largest.minor / totalOwedMinor(assessment)) * 100),
                                        )}%`}
                                    />
                                </div>
                            ) : null}

                            {/* A year with no assessable income would otherwise render three tables of
                                zeroes, which reads as a failure rather than as an answer. */}
                            {totalOwedMinor(assessment) === 0 && assessment.incomeAssessedMinor === 0 ? (
                                <p className="note">{t((d) => d.pages.taxes.nothingOwed)}</p>
                            ) : null}

                            {grouped(assessment).map((group) => (
                                <section key={group.taxCode} className="mb-5">
                                    {/* The tax, its total, and then its parts. A taxpayer owes three taxes, not
                                        six rule outputs, and the total they pay is stated before the breakdown
                                        rather than left to be added up from it. */}
                                    <div className="tax-group-head">
                                        <div className="flex min-w-0 items-center gap-2">
                                            <span className="text-muted-foreground">{taxIcon(group.taxCode)}</span>
                                            <h3 className="truncate text-sm font-semibold">
                                                {taxName(group.taxCode, t)}
                                            </h3>
                                            {/* The code the law and every export use, kept beside the name
                                                rather than replacing it. */}
                                            <span className="tax-badge">{group.taxCode}</span>
                                        </div>
                                        <strong className="tabular-nums">
                                            {formatAmount(group.totalMinor / 100, 'RON', locale)}
                                        </strong>
                                    </div>

                                    <DataTable
                                        stacked
                                        head={
                                            <>
                                                <th>{t((d) => d.pages.taxes.columnCategory)}</th>
                                                <th className="col-figure">
                                                    {t((d) => d.pages.taxes.componentIncome)}
                                                </th>
                                                <th className="col-figure">
                                                    {t((d) => d.pages.taxes.componentBase)}
                                                </th>
                                                <th className="col-figure">{t((d) => d.pages.taxes.columnRate)}</th>
                                                <th className="col-figure">{t((d) => d.pages.taxes.columnOwed)}</th>
                                            </>
                                        }
                                    >
                                        {group.components.map((line) => (
                                            <tr key={`${line.taxCode}-${line.categoryCode}`}>
                                                <td data-label={t((d) => d.pages.taxes.columnCategory)}>
                                                    {line.categoryCode}
                                                    {/* The rule's own words for what it concluded. The point of
                                                        the engine is that a figure arrives with its reasoning,
                                                        so the reasoning sits with the figure. */}
                                                    <p className="note mt-0.5">
                                                        {t((d) => d.taxExplanations)[line.explanationKey] ??
                                                            line.explanationKey}
                                                    </p>
                                                </td>
                                                <td
                                                    className="col-figure"
                                                    data-label={t((d) => d.pages.taxes.componentIncome)}
                                                >
                                                    {line.income ?? '—'}
                                                </td>
                                                <td
                                                    className="col-figure"
                                                    data-label={t((d) => d.pages.taxes.componentBase)}
                                                >
                                                    {formatAmount(line.taxableBaseMinor / 100, 'RON', locale)}
                                                    {/* Why the base is not the income — the step people most
                                                        want to see, and the reason two different incomes can
                                                        owe the same. */}
                                                    {line.baseReached?.threshold_in_wages !== undefined &&
                                                    line.baseReached.threshold_in_wages !== 'none' ? (
                                                        <p className="note mt-0.5">
                                                            {t((d) => d.pages.taxes.thresholdReached).replace(
                                                                '{wages}',
                                                                line.baseReached.threshold_in_wages,
                                                            )}
                                                        </p>
                                                    ) : null}
                                                    {line.baseReached?.quota !== undefined ? (
                                                        <p className="note mt-0.5">
                                                            {t((d) => d.pages.taxes.quotaDeducted).replace(
                                                                '{deducted}',
                                                                line.baseReached.deducted ?? '',
                                                            )}
                                                        </p>
                                                    ) : null}
                                                </td>
                                                <td
                                                    className="col-figure"
                                                    data-label={t((d) => d.pages.taxes.columnRate)}
                                                >
                                                    {line.rateDecimal === null
                                                        ? '—'
                                                        : formatPercent(Number(line.rateDecimal), locale)}
                                                </td>
                                                <td
                                                    className="col-figure"
                                                    data-label={t((d) => d.pages.taxes.columnOwed)}
                                                >
                                                    {formatAmount(line.taxMinor / 100, 'RON', locale)}
                                                </td>
                                            </tr>
                                        ))}
                                    </DataTable>
                                </section>
                            ))}

                            {/* What a figure on this screen does not yet account for. Below the numbers, because
                                somebody came here for a number — but on the page rather than in a tooltip,
                                because each of these changes what is owed. */}
                            <Panel
                                title={t((d) => d.pages.taxes.beforeFinalising)}
                                icon={<TriangleAlert className="size-4" />}
                            >
                                <ul className="note list-disc space-y-1 pl-4">
                                    <li>{t((d) => d.pages.taxes.caveatExpenses)}</li>
                                    <li>{t((d) => d.pages.taxes.caveatCategorisation)}</li>
                                    <li>{t((d) => d.pages.taxes.caveatNotFiling)}</li>
                                </ul>
                            </Panel>
                        </>
                    )}
                </Panel>
            </Page>
        </AppShell>
    );
}
