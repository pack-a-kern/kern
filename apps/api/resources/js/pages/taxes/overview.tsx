import { useState, type JSX } from 'react';
import { Calculator, Receipt } from 'lucide-react';
import { toast } from 'sonner';

import AppShell from '../../components/AppShell';
import { DataTable, Page, Panel, PanelButton, PanelSelect, Status } from '../../components/panel';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { formatAmount, formatPercent } from '../../lib/format';
import { apiRequest } from '../../lib/http';
import { getActiveSubjectId } from '../../lib/subjectStore';

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
}

interface Assessment {
    fiscalYear: number;
    status: string;
    lines: Line[];
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

    return {
        fiscalYear: int(body, 'fiscal_year'),
        status: str(body, 'status'),
        lines: (Array.isArray(body.lines) ? body.lines : []).map((entry): Line => {
            const row = obj(entry);

            return {
                taxCode: str(row, 'tax_code'),
                categoryCode: str(row, 'category_code'),
                taxableBaseMinor: int(row, 'taxable_base_minor'),
                rateDecimal: typeof row.rate_decimal === 'string' ? row.rate_decimal : null,
                taxMinor: int(row, 'tax_minor'),
                explanationKey: str(row, 'explanation_key'),
            };
        }),
    };
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
export default function TaxesOverview(): JSX.Element {
    const { t, locale } = useTranslation();
    const [subjectId] = useState(() => getActiveSubjectId());
    const [year, setYear] = useState(() => String(new Date().getFullYear()));
    const [assessment, setAssessment] = useState<Assessment | null>(null);
    const [working, setWorking] = useState(false);

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
                            {/* Said once, above the figures, rather than on every row: a draft is a property of
                                the whole assessment, and a badge per line would read as a per-figure caveat. */}
                            <p className="note mb-3">
                                <Status tone="warn">{t((d) => d.pages.taxes.draft)}</Status>{' '}
                                {t((d) => d.pages.taxes.draftHint)}
                            </p>

                            <DataTable
                                stacked
                                head={
                                    <>
                                        <th>{t((d) => d.pages.taxes.columnTax)}</th>
                                        <th>{t((d) => d.pages.taxes.columnCategory)}</th>
                                        <th className="col-figure">{t((d) => d.pages.taxes.columnBase)}</th>
                                        <th className="col-figure">{t((d) => d.pages.taxes.columnRate)}</th>
                                        <th className="col-figure">{t((d) => d.pages.taxes.columnOwed)}</th>
                                    </>
                                }
                            >
                                {assessment.lines.map((line) => (
                                    <tr key={`${line.taxCode}-${line.categoryCode}`}>
                                        <td data-label={t((d) => d.pages.taxes.columnTax)}>{line.taxCode}</td>
                                        <td data-label={t((d) => d.pages.taxes.columnCategory)}>
                                            {line.categoryCode}
                                            {/* The rule's own words for what it concluded. The point of the
                                                engine is that a figure arrives with its reasoning, so the
                                                reasoning sits with the figure rather than a click away. */}
                                            <p className="note mt-0.5">
                                                {t((d) => d.taxExplanations)[line.explanationKey] ??
                                                    line.explanationKey}
                                            </p>
                                        </td>
                                        <td className="col-figure" data-label={t((d) => d.pages.taxes.columnBase)}>
                                            {formatAmount(line.taxableBaseMinor / 100, 'RON', locale)}
                                        </td>
                                        <td className="col-figure" data-label={t((d) => d.pages.taxes.columnRate)}>
                                            {line.rateDecimal === null
                                                ? '—'
                                                : formatPercent(Number(line.rateDecimal), locale)}
                                        </td>
                                        <td className="col-figure" data-label={t((d) => d.pages.taxes.columnOwed)}>
                                            {formatAmount(line.taxMinor / 100, 'RON', locale)}
                                        </td>
                                    </tr>
                                ))}
                            </DataTable>

                            <p className="note mt-3">
                                <Receipt className="mr-1 inline size-3.5" aria-hidden="true" />
                                {t((d) => d.pages.taxes.totalOwed)}{' '}
                                <strong className="tabular-nums">
                                    {formatAmount(
                                        assessment.lines.reduce((sum, line) => sum + line.taxMinor, 0) / 100,
                                        'RON',
                                        locale,
                                    )}
                                </strong>
                            </p>
                        </>
                    )}
                </Panel>
            </Page>
        </AppShell>
    );
}
