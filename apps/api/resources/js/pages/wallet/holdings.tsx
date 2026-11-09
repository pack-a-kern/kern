import { Briefcase, Plus, Tag } from 'lucide-react';
import { useEffect, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { addHolding, listHoldings, listInstruments, sellHolding } from '../../api/investmentsApi';
import { listAccounts } from '../../api/walletApi';
import {
    DataTable,
    EmptyState,
    Field,
    FieldGrid,
    Page,
    Panel,
    PanelButton,
    PanelDialog,
    PanelInput,
    PanelSelect,
    Pill,
    SearchInput,
} from '../../components/panel';
import { PanelDate } from '../../components/PanelDatePicker';
import { useActiveSubjectId } from '../../hooks/useActiveSubjectId';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { formatDate, formatMinor } from '../../lib/format';
import { ApiError } from '../../lib/http';
import { HELD_AT, type HeldAt, type HoldingLine, type Instrument } from '../../types/investments';
import type { AccountSummary } from '../../types/wallet';

/** What a person types for an amount — "12,50" or "12.50" — in minor units; null when it is not one. */
function minorOf(typed: string): number | null {
    const normal = typed.trim().replace(',', '.');

    return /^\d{1,13}(\.\d{1,2})?$/.test(normal) ? Math.round(Number(normal) * 100) : null;
}

/** A number of units or a price as the server takes it: digits, a point, no thousands separator. */
const isDecimal = (typed: string): boolean => /^\d{1,12}(\.\d{1,6})?$/.test(typed.trim().replace(',', '.'));
const decimal = (typed: string): string => typed.trim().replace(',', '.');

/**
 * A person's bonds and shares (ADR 0025 §9): each purchase a row, held or sold, in the accounts kept for
 * them. A holding is added the way a deposit is — the instrument chosen from the market, its terms its
 * own — and sold early by units and the price per unit; what it cost, what it is worth, what it made and
 * what was withheld are the server's figures, shown as they come. Tezaur ends at maturity: never offered
 * for sale.
 */
export default function Holdings(): JSX.Element {
    const { t, locale } = useTranslation();
    const words = t((d) => d.pages.walletHoldings);
    const kinds = t((d) => d.pages.market.kinds);
    const subjectId = useActiveSubjectId();
    const [accounts, setAccounts] = useState<AccountSummary[]>([]);
    const [lines, setLines] = useState<HoldingLine[] | null>(null);
    const [adding, setAdding] = useState(false);
    const [selling, setSelling] = useState<HoldingLine | null>(null);
    /** Bumped after a holding is added or sold: the figures are the server's, so they are asked again. */
    const [version, setVersion] = useState(0);
    const reload = (): void => {
        setVersion((count) => count + 1);
    };

    useEffect(() => {
        if (subjectId === null) {
            return;
        }

        let current = true;

        Promise.all([listAccounts(subjectId), listHoldings(subjectId)])
            .then(([list, held]) => {
                if (current) {
                    setAccounts(list.accounts.filter((account) => account.kind === 'INVESTMENTS'));
                    setLines(held);
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
    }, [subjectId, version, words.failed]);

    const refused = (cause: unknown): string =>
        cause instanceof ApiError && cause.reason !== undefined && words.refusals[cause.reason] !== undefined
            ? (words.refusals[cause.reason] ?? '')
            : describeApiFailure(cause, t);

    const open = lines?.filter((line) => line.soldOn === null) ?? [];
    const sold = lines?.filter((line) => line.soldOn !== null) ?? [];
    const money = (minor: number | null, currency: string): string =>
        minor === null ? '' : formatMinor(minor, currency, locale);

    const table = (rows: HoldingLine[], isOpen: boolean): JSX.Element => (
        <DataTable
            stacked
            head={
                <>
                    <th>{words.columns.instrument}</th>
                    <th>{words.columns.bought}</th>
                    <th className="col-end">{words.columns.units}</th>
                    <th className="col-end">{words.columns.cost}</th>
                    <th className="col-end">{isOpen ? words.columns.value : words.columns.result}</th>
                    {isOpen ? <th /> : null}
                </>
            }
        >
            {rows.map((line) => (
                <tr key={line.id}>
                    <td className="col-primary">
                        <span className="font-semibold">{line.instrumentName}</span>{' '}
                        <Pill>{kinds[line.instrumentKind]}</Pill>
                        <div className="note">{words.heldAt[line.heldAt]}</div>
                    </td>
                    <td>{formatDate(line.boughtOn, locale)}</td>
                    <td className="col-end mono">{line.units}</td>
                    <td className="col-end mono">{money(line.costMinor, line.currency)}</td>
                    <td className="col-end mono">
                        {isOpen ? (
                            <>
                                {money(line.valueMinor, line.currency)}
                                <div className="note">
                                    {line.valuationBasis === 'FACE_VALUE'
                                        ? words.atFace
                                        : line.valuedOn === null
                                          ? ''
                                          : words.pricedOn.replace('{date}', formatDate(line.valuedOn, locale))}
                                </div>
                            </>
                        ) : (
                            <>
                                {money(line.resultMinor, line.currency)}
                                {line.saleTaxWithheldMinor === null ? null : (
                                    <div className="note">
                                        {words.withheld.replace(
                                            '{amount}',
                                            money(line.saleTaxWithheldMinor, line.currency),
                                        )}
                                    </div>
                                )}
                            </>
                        )}
                    </td>
                    {isOpen ? (
                        <td className="col-end">
                            {line.programme === 'TEZAUR' ? null : (
                                <PanelButton
                                    onClick={() => {
                                        setSelling(line);
                                    }}
                                >
                                    <Tag className="size-3.5" aria-hidden="true" />
                                    {words.sell}
                                </PanelButton>
                            )}
                        </td>
                    ) : null}
                </tr>
            ))}
        </DataTable>
    );

    return (
        <Page
            title={words.title}
            lede={words.description}
            actions={
                <PanelButton
                    primary
                    disabled={accounts.length === 0}
                    onClick={() => {
                        setAdding(true);
                    }}
                >
                    <Plus className="size-3.5" aria-hidden="true" />
                    {words.add}
                </PanelButton>
            }
        >
            {lines === null ? null : accounts.length === 0 && lines.length === 0 ? (
                <EmptyState icon={<Briefcase className="size-5" />} title={words.noAccount} />
            ) : (
                <>
                    <Panel title={words.open} flush>
                        {open.length === 0 ? (
                            <EmptyState icon={<Briefcase className="size-5" />} title={words.none} />
                        ) : (
                            table(open, true)
                        )}
                    </Panel>
                    {sold.length === 0 ? null : (
                        <Panel title={words.sold} flush>
                            {table(sold, false)}
                        </Panel>
                    )}
                </>
            )}

            {adding && subjectId !== null ? (
                <AddHoldingDialog
                    subjectId={subjectId}
                    accounts={accounts}
                    onClose={(added) => {
                        setAdding(false);

                        if (added) {
                            toast.success(words.added);
                            reload();
                        }
                    }}
                    refused={refused}
                />
            ) : null}

            {selling !== null && subjectId !== null ? (
                <SellDialog
                    subjectId={subjectId}
                    line={selling}
                    onClose={(done) => {
                        setSelling(null);

                        if (done) {
                            toast.success(words.soldDone);
                            reload();
                        }
                    }}
                    refused={refused}
                />
            ) : null}
        </Page>
    );
}

function AddHoldingDialog(props: {
    subjectId: string;
    accounts: AccountSummary[];
    onClose: (added: boolean) => void;
    refused: (cause: unknown) => string;
}): JSX.Element {
    const { t } = useTranslation();
    const words = t((d) => d.pages.walletHoldings);
    const [accountId, setAccountId] = useState(props.accounts[0]?.id ?? '');
    const [search, setSearch] = useState('');
    const [found, setFound] = useState<Instrument[]>([]);
    const [instrument, setInstrument] = useState<Instrument | null>(null);
    const [units, setUnits] = useState('');
    const [price, setPrice] = useState('');
    const [boughtOn, setBoughtOn] = useState<string | null>(null);
    const [fees, setFees] = useState('0');
    const [heldAt, setHeldAt] = useState<HeldAt>('BANK');

    const typed = search.trim();
    // Shown only for what is typed now: a search shortened below two characters shows nothing.
    const shown = typed.length < 2 ? [] : found;

    useEffect(() => {
        if (typed.length < 2) {
            return;
        }

        let current = true;

        // What can still be bought or held: issues being subscribed and those on the market.
        Promise.all([
            listInstruments({ q: typed, status: 'ACTIVE' }),
            listInstruments({ q: typed, status: 'ON_OFFER' }),
        ])
            .then(([active, offered]) => {
                if (current) {
                    setFound([...offered.data, ...active.data]);
                }
            })
            .catch(() => {
                if (current) {
                    setFound([]);
                }
            });

        return () => {
            current = false;
        };
    }, [typed]);

    const feesMinor = minorOf(fees);
    const ready =
        accountId !== '' &&
        instrument !== null &&
        isDecimal(units) &&
        isDecimal(price) &&
        boughtOn !== null &&
        feesMinor !== null;

    const save = async (): Promise<void> => {
        if (!ready) {
            return;
        }

        try {
            await addHolding(props.subjectId, {
                accountId,
                instrumentId: instrument.id,
                units: decimal(units),
                pricePerUnit: decimal(price),
                boughtOn,
                feesMinor,
                heldAt,
            });
            props.onClose(true);
        } catch (cause) {
            toast.error(props.refused(cause));
        }
    };

    return (
        <PanelDialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    props.onClose(false);
                }
            }}
            title={words.add}
            size="lg"
            actions={
                <>
                    <PanelButton
                        onClick={() => {
                            props.onClose(false);
                        }}
                    >
                        {words.form.cancel}
                    </PanelButton>
                    <PanelButton primary disabled={!ready} onClick={() => void save()}>
                        {words.form.save}
                    </PanelButton>
                </>
            }
        >
            <FieldGrid>
                <Field label={words.form.account} htmlFor="holding-account">
                    <PanelSelect
                        id="holding-account"
                        value={accountId}
                        onValueChange={setAccountId}
                        options={props.accounts.map((account) => ({ value: account.id, label: account.displayName }))}
                    />
                </Field>
                <Field label={words.form.heldAt} htmlFor="holding-held-at">
                    <PanelSelect
                        id="holding-held-at"
                        value={heldAt}
                        onValueChange={(value) => {
                            setHeldAt(HELD_AT.find((known) => known === value) ?? 'BANK');
                        }}
                        options={HELD_AT.map((value) => ({ value, label: words.heldAt[value] }))}
                    />
                </Field>
            </FieldGrid>
            <div className="mt-3 space-y-2">
                <SearchInput value={search} placeholder={words.form.instrumentSearch} onChange={setSearch} />
                {instrument !== null ? (
                    <Pill tone="accent">{instrument.name}</Pill>
                ) : typed.length >= 2 && shown.length === 0 ? (
                    <p className="note">{words.form.noMatch}</p>
                ) : null}
                <div className="flex flex-wrap gap-1.5">
                    {shown.map((candidate) => (
                        <PanelButton
                            key={candidate.id}
                            onClick={() => {
                                setInstrument(candidate);
                                setHeldAt(
                                    candidate.programme === 'TEZAUR'
                                        ? 'TREASURY'
                                        : candidate.kind === 'SHARE'
                                          ? 'BROKER'
                                          : heldAt,
                                );
                            }}
                        >
                            {candidate.name}
                        </PanelButton>
                    ))}
                </div>
            </div>
            <FieldGrid>
                <Field label={words.form.units} htmlFor="holding-units">
                    <PanelInput
                        id="holding-units"
                        inputMode="decimal"
                        value={units}
                        onChange={(event) => {
                            setUnits(event.target.value);
                        }}
                    />
                </Field>
                <Field label={words.form.pricePerUnit} htmlFor="holding-price" help={words.form.pricePerUnitHint}>
                    <PanelInput
                        id="holding-price"
                        inputMode="decimal"
                        value={price}
                        onChange={(event) => {
                            setPrice(event.target.value);
                        }}
                    />
                </Field>
                <Field label={words.form.boughtOn} htmlFor="holding-bought">
                    <PanelDate value={boughtOn} onChange={setBoughtOn} disableFuture />
                </Field>
                <Field label={words.form.fees} htmlFor="holding-fees">
                    <PanelInput
                        id="holding-fees"
                        inputMode="decimal"
                        value={fees}
                        onChange={(event) => {
                            setFees(event.target.value);
                        }}
                    />
                </Field>
            </FieldGrid>
        </PanelDialog>
    );
}

function SellDialog(props: {
    subjectId: string;
    line: HoldingLine;
    onClose: (done: boolean) => void;
    refused: (cause: unknown) => string;
}): JSX.Element {
    const { t } = useTranslation();
    const words = t((d) => d.pages.walletHoldings);
    const [units, setUnits] = useState(props.line.units);
    const [price, setPrice] = useState('');
    const [soldOn, setSoldOn] = useState<string | null>(null);
    const [fees, setFees] = useState('0');
    const feesMinor = minorOf(fees);
    const ready = isDecimal(units) && isDecimal(price) && soldOn !== null && feesMinor !== null;

    const sell = async (): Promise<void> => {
        if (!ready) {
            return;
        }

        try {
            await sellHolding(props.subjectId, {
                accountId: props.line.accountId,
                instrumentId: props.line.instrumentId,
                units: decimal(units),
                pricePerUnit: decimal(price),
                soldOn,
                feesMinor,
            });
            props.onClose(true);
        } catch (cause) {
            toast.error(props.refused(cause));
        }
    };

    return (
        <PanelDialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    props.onClose(false);
                }
            }}
            title={`${words.sell} · ${props.line.instrumentName}`}
            actions={
                <>
                    <PanelButton
                        onClick={() => {
                            props.onClose(false);
                        }}
                    >
                        {words.form.cancel}
                    </PanelButton>
                    <PanelButton primary disabled={!ready} onClick={() => void sell()}>
                        {words.sell}
                    </PanelButton>
                </>
            }
        >
            <FieldGrid>
                <Field label={words.form.units} htmlFor="sale-units">
                    <PanelInput
                        id="sale-units"
                        inputMode="decimal"
                        value={units}
                        onChange={(event) => {
                            setUnits(event.target.value);
                        }}
                    />
                </Field>
                <Field label={words.form.pricePerUnit} htmlFor="sale-price" help={words.form.pricePerUnitHint}>
                    <PanelInput
                        id="sale-price"
                        inputMode="decimal"
                        value={price}
                        onChange={(event) => {
                            setPrice(event.target.value);
                        }}
                    />
                </Field>
                <Field label={words.form.soldOn} htmlFor="sale-date">
                    <PanelDate value={soldOn} onChange={setSoldOn} disableFuture />
                </Field>
                <Field label={words.form.fees} htmlFor="sale-fees">
                    <PanelInput
                        id="sale-fees"
                        inputMode="decimal"
                        value={fees}
                        onChange={(event) => {
                            setFees(event.target.value);
                        }}
                    />
                </Field>
            </FieldGrid>
        </PanelDialog>
    );
}
