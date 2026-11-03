import { CalendarDays, Columns3, FileSpreadsheet, Hash, SplitSquareHorizontal } from 'lucide-react';
import { useEffect, useRef, useState, type JSX } from 'react';

import { readDepositCsv, type DepositRequest } from '../../api/walletApi';
import { DataTable, Field, FieldSpan, PanelInput, PanelSelect, Status } from '../panel';
import type { FormErrors } from '../../hooks/useFormErrors';
import { useTranslation } from '../../i18n';
import { line, type DepositCsvShared } from '../../lib/depositCsv';
import { formatDate, formatMinor } from '../../lib/format';
import { DATE_FORMATS } from '../../types/statementImport';
import { DEPOSIT_CSV_COLUMNS, type Bank, type DepositCsv, type DepositCsvColumn } from '../../types/wallet';

/** What the file reads as, for the dialog around it. */
export interface DepositCsvState {
    /** Every line as a deposit the form would send, or null while any line is not one yet. */
    deposits: DepositRequest[] | null;
    /** Whether the bank and the currency come from a column — when not, the dialog asks for them once. */
    bankFromFile: boolean;
    currencyFromFile: boolean;
    /** How many deposits the file holds, read or not — what the dialog's button counts. */
    lines: number;
    /** Once a file has been read, every field still without an answer is marked on the form. */
    read: boolean;
}

/** How the file is written, and which column holds which field. */
interface How {
    file: File | null;
    separator: string;
    dateFormat: string;
    decimal: string;
    mapping: Partial<Record<DepositCsvColumn, string>>;
}

/** The four fields that differ on every line of a file and so can only come from a column. */
const PER_LINE: DepositCsvColumn[] = ['starts_at', 'principal', 'annual_rate', 'term_months'];

/**
 * Many term deposits at once, from a CSV — inside the add-deposit dialog, not beside it.
 *
 * The file is sent to the server to be read and is never stored there: dates and amounts are read
 * by the same parser that reads a bank statement, so a figure means the same thing in both. The
 * maturity of each is derived here from its start and its term with `maturityFor`, exactly as the
 * one-deposit form derives it. What is sent at the end is a list of deposits shaped exactly as that
 * form sends one, and the server checks each by the same rules — so nothing here decides whether a
 * deposit is acceptable. It only says which lines cannot be read yet.
 */
export default function DepositCsvImport(props: {
    subjectId: string;
    banks: Bank[];
    shared: DepositCsvShared;
    errors: FormErrors;
    onChange: (state: DepositCsvState) => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const { subjectId, banks, shared, errors, onChange } = props;
    // The hook's own callbacks are stable; the object around them is not.
    const { capture, reset } = errors;

    // A spreadsheet saved in a Romanian locale writes `;` between cells and `,` for the decimal.
    const [how, setHow] = useState<How>({
        file: null,
        separator: ';',
        dateFormat: DATE_FORMATS[0] ?? 'DD.MM.YYYY',
        decimal: ',',
        mapping: {},
    });
    const [read, setRead] = useState<DepositCsv | null>(null);
    const [reading, setReading] = useState(false);
    const [failed, setFailed] = useState(false);
    // Which reading is the latest, so an answer that arrives after a newer question is dropped.
    const latest = useRef(0);
    const { separator, dateFormat, decimal, mapping } = how;

    /**
     * Changes how the file is read, and reads it again. The server keeps no copy, so there is no id
     * to refer back to — the file goes with every reading, and it is bounded in size.
     */
    const change = (patch: Partial<How>): void => {
        const next = { ...how, ...patch };
        setHow(next);
        reset();

        if (next.file === null) {
            setRead(null);

            return;
        }

        const request = latest.current + 1;
        latest.current = request;
        setReading(true);
        setFailed(false);

        readDepositCsv(subjectId, next.file, {
            separator: next.separator,
            dateFormat: next.dateFormat,
            decimalSeparator: next.decimal,
            mapping: next.mapping,
        }).then(
            (result) => {
                if (latest.current === request) {
                    setRead(result);
                    setReading(false);
                }
            },
            (cause: unknown) => {
                if (latest.current === request) {
                    setRead(null);
                    setReading(false);
                    // A refused file says why on its own field; anything else is the reading itself.
                    setFailed(!capture(cause));
                }
            },
        );
    };

    const bankFromFile = (mapping.bank ?? '') !== '';
    const currencyFromFile = (mapping.currency ?? '') !== '';
    const mapped = PER_LINE.every((field) => (mapping[field] ?? '') !== '');

    const lines = read === null || !mapped ? [] : read.rows.map((row) => line(row, shared, t));

    const ready =
        lines.length > 0 &&
        lines.every((entry) => entry.deposit !== null) &&
        (bankFromFile || shared.bankId !== '') &&
        (currencyFromFile || shared.currencyCode !== '');

    // Compared by value: the list is rebuilt on every render, and reporting each rebuild would set the
    // dialog's state, render this again and report again, for ever.
    const reported = JSON.stringify({
        deposits: ready ? lines.map((entry) => entry.deposit).filter((entry) => entry !== null) : null,
        bankFromFile,
        currencyFromFile,
        lines: read?.rows.length ?? 0,
        read: read !== null,
    });

    useEffect(() => {
        onChange(JSON.parse(reported) as DepositCsvState);
    }, [reported, onChange]);

    const columnOptions = (read?.headers ?? []).map((header, index) => ({
        value: header,
        label: header,
        // The first line's value beside the name, so a column is recognised by what it holds.
        description: read?.sample[0]?.[index] ?? '',
    }));

    const columnLabel = (field: DepositCsvColumn): string =>
        ({
            starts_at: t((d) => d.pages.walletAccounts.form.startsAt),
            principal: t((d) => d.pages.walletDeposits.form.principal),
            annual_rate: t((d) => d.pages.walletDeposits.form.annualRate),
            term_months: t((d) => d.pages.walletDeposits.csv.termMonths),
            bank: t((d) => d.pages.walletDeposits.form.bank),
            currency: t((d) => d.pages.walletAccounts.form.currency),
        })[field];

    const bankName = (id: string): string => banks.find((bank) => bank.id === id)?.name ?? '';

    /** The server's own refusal of a line, once it has refused one — the first field it named. */
    const refusal = (index: number): string | undefined =>
        ['bank_id', 'currency_code', 'starts_at', 'principal_minor', 'maturity_at', 'annual_rate', 'funding_account_id']
            .map((field) => errors.get(`deposits.${String(index)}.${field}`))
            .find((message) => message !== undefined);

    return (
        <>
            <Field
                label={t((d) => d.pages.walletDeposits.csv.file)}
                htmlFor="deposit-csv-file"
                error={errors.get('file')}
                hint={t((d) => d.pages.walletDeposits.csv.fileHint)}
            >
                <PanelInput
                    id="deposit-csv-file"
                    type="file"
                    accept=".csv"
                    icon={<FileSpreadsheet className="size-3.5" aria-hidden="true" />}
                    onChange={(event) => {
                        change({ file: event.target.files?.[0] ?? null, mapping: {} });
                    }}
                />
            </Field>

            <Field label={t((d) => d.pages.walletDeposits.csv.separator)} htmlFor="deposit-csv-separator">
                <PanelSelect
                    id="deposit-csv-separator"
                    icon={<SplitSquareHorizontal className="size-3.5" aria-hidden="true" />}
                    value={separator}
                    onValueChange={(next) => {
                        // Another separator is another set of columns.
                        change({ separator: next, mapping: {} });
                    }}
                    options={[
                        { value: ';', label: t((d) => d.pages.walletDeposits.csv.separatorSemicolon) },
                        { value: ',', label: t((d) => d.pages.walletDeposits.csv.separatorComma) },
                    ]}
                />
            </Field>

            <Field label={t((d) => d.pages.walletImports.columns.dateFormat)} htmlFor="deposit-csv-date-format">
                <PanelSelect
                    id="deposit-csv-date-format"
                    icon={<CalendarDays className="size-3.5" aria-hidden="true" />}
                    value={dateFormat}
                    onValueChange={(next) => {
                        change({ dateFormat: next });
                    }}
                    options={DATE_FORMATS.map((format) => ({ value: format, label: format }))}
                />
            </Field>

            <Field label={t((d) => d.pages.walletImports.columns.decimal)} htmlFor="deposit-csv-decimal">
                <PanelSelect
                    id="deposit-csv-decimal"
                    icon={<Hash className="size-3.5" aria-hidden="true" />}
                    value={decimal}
                    onValueChange={(next) => {
                        change({ decimal: next });
                    }}
                    options={[
                        { value: ',', label: '1.234,56' },
                        { value: '.', label: '1,234.56' },
                    ]}
                />
            </Field>

            {read === null ? null : (
                <>
                    <FieldSpan>
                        <p className="eyebrow">{t((d) => d.pages.walletDeposits.csv.columnsTitle)}</p>
                        <p className="note">{t((d) => d.pages.walletDeposits.csv.columnsHint)}</p>
                    </FieldSpan>

                    {DEPOSIT_CSV_COLUMNS.map((field) => (
                        <Field
                            key={field}
                            label={columnLabel(field)}
                            htmlFor={`deposit-csv-column-${field}`}
                            // Marked as soon as the file is read: a field with no column is the one
                            // thing standing between the file and its deposits.
                            error={
                                errors.get(`mapping.${field}`) ??
                                (PER_LINE.includes(field) && (mapping[field] ?? '') === ''
                                    ? t((d) => d.pages.walletDeposits.csv.missing)
                                    : undefined)
                            }
                        >
                            <PanelSelect
                                id={`deposit-csv-column-${field}`}
                                icon={<Columns3 className="size-3.5" aria-hidden="true" />}
                                value={mapping[field] ?? ''}
                                // Bank and currency may be one answer for the whole file instead.
                                {...(PER_LINE.includes(field)
                                    ? { placeholder: t((d) => d.pages.walletDeposits.csv.missing) }
                                    : { emptyLabel: t((d) => d.pages.walletDeposits.csv.sameForAll) })}
                                onValueChange={(next) => {
                                    change({ mapping: { ...mapping, [field]: next } });
                                }}
                                options={columnOptions}
                            />
                        </Field>
                    ))}
                </>
            )}

            <FieldSpan>
                {reading ? (
                    <p className="note">{t((d) => d.pages.walletDeposits.csv.reading)}</p>
                ) : failed ? (
                    <p className="note">{t((d) => d.pages.walletDeposits.csv.readFailed)}</p>
                ) : read !== null && !mapped ? (
                    <p className="note">{t((d) => d.pages.walletDeposits.csv.pickColumns)}</p>
                ) : lines.length > 0 ? (
                    <DataTable
                        head={
                            <>
                                <th>{t((d) => d.pages.walletDeposits.csv.line)}</th>
                                <th>{t((d) => d.pages.walletDeposits.form.bank)}</th>
                                <th>{t((d) => d.pages.walletDeposits.form.principal)}</th>
                                <th>{t((d) => d.pages.walletDeposits.form.annualRate)}</th>
                                <th>{t((d) => d.pages.walletAccounts.form.startsAt)}</th>
                                <th>{t((d) => d.pages.walletDeposits.columns.maturity)}</th>
                                <th>{t((d) => d.pages.walletDeposits.columns.status)}</th>
                            </>
                        }
                    >
                        {lines.map((entry, index) => {
                            const problem = entry.problem ?? refusal(index);
                            const deposit = entry.deposit;

                            return (
                                <tr key={entry.line}>
                                    <td className="mono">{entry.line}</td>
                                    <td>{deposit === null ? '—' : bankName(deposit.bank_id)}</td>
                                    <td>
                                        {deposit === null
                                            ? '—'
                                            : formatMinor(deposit.principal_minor, deposit.currency_code, locale)}
                                    </td>
                                    <td>{deposit === null ? '—' : `${deposit.annual_rate}%`}</td>
                                    <td>{deposit === null ? '—' : formatDate(deposit.starts_at, locale)}</td>
                                    <td>{deposit === null ? '—' : formatDate(deposit.maturity_at, locale)}</td>
                                    <td>
                                        {problem === undefined ? (
                                            <Status tone="ok">{t((d) => d.pages.walletDeposits.csv.ready)}</Status>
                                        ) : (
                                            <Status tone="bad">{problem}</Status>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </DataTable>
                ) : null}
            </FieldSpan>
        </>
    );
}
