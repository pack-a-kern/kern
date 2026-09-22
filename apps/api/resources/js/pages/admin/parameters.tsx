import { CalendarPlus, RefreshCw, Scale } from 'lucide-react';
import { useCallback, useEffect, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { getFiscalParameterBoard, readFiscalParameters, startFiscalYear } from '../../api/fiscalParametersApi';
import AppShell from '../../components/AppShell';
import { Field, Page, Panel, PanelButton, PanelDialog, PanelSelect, Status } from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { checkTone, describeCheck, describeFiscalValue } from '../../lib/fiscalValue';
import { formatDateTime } from '../../lib/format';
import { ApiError } from '../../lib/http';
import { useSharedAuth } from '../../lib/sharedProps';
import { cn } from '../../lib/utils';
import type { FiscalParameter, FiscalParameterBoard, ParameterVersion } from '../../types/fiscalParameters';
import FiscalParameterDialog, { StatusPill } from './FiscalParameterDialog';

/**
 * The values every tax is computed from, year by year — each read from an official text, shown with
 * the paragraph of the law it rests on, and counted only once a person has approved it.
 *
 * Read from the minimum-wage decisions and ANAF's D212 files ("Read values"); a year no form states
 * yet is proposed from the year before ("New year"). Nothing here is typed in: a value that is wrong
 * is rejected, and the source corrected.
 */
export default function AdminParameters(): JSX.Element {
    const { t, locale } = useTranslation();
    const permissions = useSharedAuth()?.user.permissions ?? [];
    const canApprove = permissions.includes('fiscal.parameters.approve');
    const canRead = canApprove && permissions.includes('operations.run');
    const [board, setBoard] = useState<FiscalParameterBoard | null>(null);
    const [failed, setFailed] = useState<'forbidden' | 'error' | null>(null);
    const [open, setOpen] = useState<{ parameter: FiscalParameter; version: ParameterVersion } | null>(null);
    const [startingYear, setStartingYear] = useState(false);

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

    const read = async (): Promise<void> => {
        try {
            await readFiscalParameters();
            toast.success(t((d) => d.pages.adminParameters.readStarted));
        } catch (cause) {
            toast.error(
                cause instanceof ApiError && cause.status === 409
                    ? t((d) => d.pages.adminParameters.readBusy)
                    : describeApiFailure(cause, t),
            );
        }
    };

    const years = board === null ? [] : yearsOf(board);
    const name = (code: string): string => t((d) => d.pages.adminParameters.names)[code] ?? code;

    return (
        <AppShell>
            <Page
                title={t((d) => d.pages.adminParameters.title)}
                lede={t((d) => d.pages.adminParameters.description)}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {canRead ? (
                            <PanelButton onClick={() => void read()}>
                                <RefreshCw className="size-3.5" />
                                {t((d) => d.pages.adminParameters.read)}
                            </PanelButton>
                        ) : null}
                        {canApprove ? (
                            <PanelButton
                                onClick={() => {
                                    setStartingYear(true);
                                }}
                            >
                                <CalendarPlus className="size-3.5" />
                                {t((d) => d.pages.adminParameters.newYear)}
                            </PanelButton>
                        ) : null}
                    </div>
                }
            >
                <Panel
                    title={t((d) => d.pages.adminParameters.valuesTitle)}
                    description={t((d) => d.pages.adminParameters.valuesHint)}
                    icon={<Scale className="size-4" />}
                    flush
                >
                    {failed !== null ? (
                        <p className="note p-4">
                            {t((d) =>
                                failed === 'forbidden'
                                    ? d.pages.adminParameters.forbidden
                                    : d.pages.adminParameters.failed,
                            )}
                        </p>
                    ) : board === null ? (
                        <Skeleton className="tile m-4 h-40" />
                    ) : years.length === 0 ? (
                        <p className="note p-4">{t((d) => d.pages.adminParameters.empty)}</p>
                    ) : (
                        <div className="table-wrap">
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>{t((d) => d.pages.adminParameters.parameter)}</th>
                                        {years.map((year) => (
                                            <th key={year} className="tabular-nums">
                                                {year}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {board.parameters.map((parameter) => (
                                        <tr key={parameter.code}>
                                            <th scope="row" className="min-w-48 text-left font-medium">
                                                {name(parameter.code)}
                                            </th>
                                            {years.map((year) => (
                                                <td key={year} className="align-top">
                                                    <div className="flex flex-col gap-1">
                                                        {parameter.versions
                                                            .filter((version) =>
                                                                version.validFrom.startsWith(String(year)),
                                                            )
                                                            .map((version) => (
                                                                <button
                                                                    key={version.id}
                                                                    type="button"
                                                                    className={cn(
                                                                        'hover:bg-muted rounded px-1.5 py-1 text-left text-xs',
                                                                        version.status === 'REJECTED' &&
                                                                            'text-muted-foreground line-through',
                                                                    )}
                                                                    onClick={() => {
                                                                        setOpen({ parameter, version });
                                                                    }}
                                                                >
                                                                    <span className="block font-semibold tabular-nums">
                                                                        {describeFiscalValue(
                                                                            parameter.type,
                                                                            version.value,
                                                                            t,
                                                                            locale,
                                                                        )}
                                                                    </span>
                                                                    <span className="mt-0.5 flex flex-wrap items-center gap-1">
                                                                        <StatusPill status={version.status} />
                                                                        {version.checks.some(
                                                                            (check) => checkTone(check) === 'warn',
                                                                        ) ? (
                                                                            <Status tone="warn">
                                                                                {t((d) => d.pages.adminParameters.look)}
                                                                            </Status>
                                                                        ) : null}
                                                                    </span>
                                                                </button>
                                                            ))}
                                                    </div>
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>

                {board === null || board.forms.length === 0 ? null : (
                    <>
                        <Panel
                            title={t((d) => d.pages.adminParameters.notInLawTitle)}
                            description={t((d) => d.pages.adminParameters.notInLawHint)}
                        >
                            {board.forms.every((form) => form.unmatched.length === 0) ? (
                                <p className="note">{t((d) => d.pages.adminParameters.notInLawNone)}</p>
                            ) : (
                                <ul className="space-y-2 text-sm">
                                    {board.forms.flatMap((form) =>
                                        form.unmatched.map((miss, index) => (
                                            <li key={`${form.id}-${String(index)}`}>
                                                <a
                                                    className="link font-semibold"
                                                    href={form.url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    {t((d) => d.pages.adminParameters.formFile)
                                                        .replace('{form}', form.form)
                                                        .replace('{year}', String(form.year))}
                                                </a>
                                                {miss.parameter === '' ? null : <span> · {name(miss.parameter)}</span>}
                                                <span className="text-muted-foreground block text-xs">
                                                    {describeCheck(miss, t)}
                                                </span>
                                            </li>
                                        )),
                                    )}
                                </ul>
                            )}
                        </Panel>

                        <Panel
                            title={t((d) => d.pages.adminParameters.formsTitle)}
                            description={t((d) => d.pages.adminParameters.formsHint)}
                        >
                            <ul className="space-y-1 text-xs">
                                {board.forms.map((form) => (
                                    <li key={form.id} className="flex flex-wrap gap-x-3">
                                        <a
                                            className="link font-semibold"
                                            href={form.url}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            {t((d) => d.pages.adminParameters.formFile)
                                                .replace('{form}', form.form)
                                                .replace('{year}', String(form.year))}
                                        </a>
                                        <code className="text-muted-foreground">{form.contentHash.slice(0, 12)}</code>
                                        <span className="text-muted-foreground">
                                            {t((d) => d.pages.adminParameters.readAt).replace(
                                                '{date}',
                                                formatDateTime(form.readAt, locale),
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    </>
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

            {startingYear ? (
                <StartYearDialog
                    years={years}
                    onClose={() => {
                        setStartingYear(false);
                    }}
                    onStarted={() => {
                        setStartingYear(false);
                        load();
                    }}
                />
            ) : null}
        </AppShell>
    );
}

/** Every year a value is for, oldest first — the table's columns. */
function yearsOf(board: FiscalParameterBoard): number[] {
    const found = new Set<number>();

    for (const parameter of board.parameters) {
        for (const version of parameter.versions) {
            found.add(Number(version.validFrom.slice(0, 4)));
        }
    }

    return [...found].sort((a, b) => a - b);
}

/** Proposes a year from the one before: pick the year, and every value still in its law comes to review. */
function StartYearDialog({
    years,
    onClose,
    onStarted,
}: {
    years: number[];
    onClose: () => void;
    onStarted: () => void;
}): JSX.Element {
    const { t } = useTranslation();
    const next = (years.at(-1) ?? new Date().getFullYear()) + 1;
    const options = [...new Set([...years.slice(1), next])].sort((a, b) => b - a).map(String);
    const [year, setYear] = useState(String(next));
    const [busy, setBusy] = useState(false);

    const start = async (): Promise<void> => {
        setBusy(true);

        try {
            const proposed = await startFiscalYear(Number(year));
            toast.success(t((d) => d.pages.adminParameters.yearStarted).replace('{count}', String(proposed)));
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
            title={t((d) => d.pages.adminParameters.newYear)}
            description={t((d) => d.pages.adminParameters.newYearHint)}
            actions={
                <>
                    <PanelButton onClick={onClose}>{t((d) => d.pages.adminParameters.close)}</PanelButton>
                    <PanelButton primary disabled={busy} onClick={() => void start()}>
                        {t((d) => d.pages.adminParameters.propose)}
                    </PanelButton>
                </>
            }
        >
            <Field label={t((d) => d.pages.adminParameters.year)} htmlFor="new-fiscal-year">
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
