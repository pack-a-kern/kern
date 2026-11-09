import { Briefcase, CalendarCheck, Coins, Layers, Plus, SearchX, Tag, TrendingUp } from 'lucide-react';
import { useEffect, useMemo, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { addHolding, listHoldings, listInstruments, sellHolding } from '../../api/investmentsApi';
import Pagination from '../../components/Pagination';
import {
    CellLabel,
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
    TableSkeleton,
    Toolbar,
} from '../../components/panel';
import { PanelDate } from '../../components/PanelDatePicker';
import { useActiveSubjectId } from '../../hooks/useActiveSubjectId';
import { useRememberedPaging, useRememberedState } from '../../hooks/useRememberedState';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { formatDate, formatMinor } from '../../lib/format';
import { ApiError } from '../../lib/http';
import { HELD_AT, type HeldAt, type HoldingLine, type Instrument } from '../../types/investments';

/** Which purchases are listed: those still held, or those already sold. */
const STATES = ['open', 'sold'] as const;
type State = (typeof STATES)[number];

/** What a person types for an amount — "12,50" or "12.50" — in minor units; null when it is not one. */
function minorOf(typed: string): number | null {
    const normal = typed.trim().replace(',', '.');

    return /^\d{1,13}(\.\d{1,2})?$/.test(normal) ? Math.round(Number(normal) * 100) : null;
}

/** A number of units or a price as the server takes it: digits, a point, no thousands separator. */
const isDecimal = (typed: string): boolean => /^\d{1,12}(\.\d{1,6})?$/.test(typed.trim().replace(',', '.'));
const decimal = (typed: string): string => typed.trim().replace(',', '.');

/**
 * A person's bonds and shares (ADR 0025 §9): each purchase a row, held or sold. A holding is added the way
 * a deposit is — the instrument chosen from the market, its terms its own — and sold early from its row by
 * units and the price per unit; what it cost, what it is worth, what it made and what was withheld are the
 * server's figures, shown as they come. The money account its payments arrive in is opened by the first
 * holding that needs it, and is not shown here. Tezaur ends at maturity: never offered for sale.
 */
export default function Holdings(): JSX.Element {
    const { t, locale } = useTranslation();
    const words = t((d) => d.pages.holdings);
    const kinds = t((d) => d.pages.market.kinds);
    const subjectId = useActiveSubjectId();
    const [lines, setLines] = useState<HoldingLine[] | null>(null);
    const [failed, setFailed] = useState(false);
    const [adding, setAdding] = useState(false);
    const [selling, setSelling] = useState<HoldingLine | null>(null);
    const [state, setState] = useRememberedState<State>('investments.holdings.state', 'open', {
        perSubject: true,
        isValid: (value) => (STATES as readonly string[]).includes(value),
    });
    const { page, pageSize, setPage, setPageSize } = useRememberedPaging('investments.holdings', {
        perSubject: true,
    });
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

        listHoldings(subjectId)
            .then((held) => {
                if (current) {
                    setLines(held);
                    setFailed(false);
                }
            })
            .catch(() => {
                if (current) {
                    setFailed(true);
                }
            });

        return () => {
            current = false;
        };
    }, [subjectId, version]);

    const isOpen = state === 'open';
    const listed = useMemo(() => (lines ?? []).filter((line) => (line.soldOn === null) === isOpen), [lines, isOpen]);
    const currentPage = Math.min(page, Math.max(1, Math.ceil(listed.length / pageSize)));
    const paged = listed.slice((currentPage - 1) * pageSize, currentPage * pageSize);

    const refused = (cause: unknown): string =>
        cause instanceof ApiError && cause.reason !== undefined && words.refusals[cause.reason] !== undefined
            ? (words.refusals[cause.reason] ?? '')
            : describeApiFailure(cause, t);

    const money = (minor: number | null, currency: string): string =>
        minor === null ? '' : formatMinor(minor, currency, locale);

    if (subjectId === null) {
        return (
            <Page title={words.title}>
                <Panel title={words.title}>
                    <p className="note">{t((d) => d.pages.wallet.noSubject)}</p>
                </Panel>
            </Page>
        );
    }

    return (
        <>
            <Page title={words.title} lede={words.description}>
                <Panel
                    title={words.title}
                    description={words.description}
                    icon={<Briefcase className="size-4" />}
                    flush
                    aside={
                        <PanelButton
                            primary
                            title={words.add}
                            onClick={() => {
                                setAdding(true);
                            }}
                        >
                            <Plus className="size-3.5" aria-hidden="true" />
                            <span className="hidden sm:inline">{words.add}</span>
                        </PanelButton>
                    }
                    foot={
                        <Pagination
                            page={currentPage}
                            pageSize={pageSize}
                            total={listed.length}
                            unit={words.unit}
                            onPageChange={setPage}
                            onPageSizeChange={setPageSize}
                        />
                    }
                >
                    <Toolbar>
                        <PanelSelect
                            icon={<TrendingUp className="size-3.5" aria-hidden="true" />}
                            className="filter-select"
                            aria-label={words.status}
                            value={state}
                            onValueChange={(value) => {
                                setState(STATES.find((known) => known === value) ?? 'open');
                                setPage(1);
                            }}
                            options={STATES.map((value) => ({ value, label: words[value] }))}
                        />
                    </Toolbar>

                    {lines === null ? (
                        failed ? (
                            <p className="note p-4">{words.loadFailed}</p>
                        ) : (
                            <TableSkeleton />
                        )
                    ) : listed.length === 0 && lines.length > 0 ? (
                        <EmptyState icon={<SearchX className="size-5" />} title={words.none} />
                    ) : listed.length === 0 ? (
                        <EmptyState
                            icon={<Briefcase className="size-5" />}
                            title={words.none}
                            description={words.emptyHint}
                        />
                    ) : (
                        <DataTable
                            stacked
                            head={
                                <>
                                    <th>{words.columns.instrument}</th>
                                    <th>{words.columns.bought}</th>
                                    <th className="col-figure">{words.columns.units}</th>
                                    <th className="col-figure">{words.columns.cost}</th>
                                    <th className="col-figure">
                                        {isOpen ? words.columns.value : words.columns.result}
                                    </th>
                                    <th />
                                </>
                            }
                        >
                            {paged.map((line) => (
                                <tr key={line.id}>
                                    <td className="col-primary">
                                        <div className="flex items-center gap-2 font-semibold">
                                            {line.instrumentName}
                                            <Pill>{kinds[line.instrumentKind]}</Pill>
                                        </div>
                                        <div className="note">{words.heldAt[line.heldAt]}</div>
                                    </td>
                                    <td className="whitespace-nowrap">
                                        <CellLabel icon={<CalendarCheck className="size-3.5" aria-hidden="true" />}>
                                            {words.columns.bought}
                                        </CellLabel>
                                        {formatDate(line.boughtOn, locale)}
                                    </td>
                                    <td className="col-figure">
                                        <CellLabel icon={<Layers className="size-3.5" aria-hidden="true" />}>
                                            {words.columns.units}
                                        </CellLabel>
                                        {line.units}
                                    </td>
                                    <td className="col-figure">
                                        <CellLabel icon={<Coins className="size-3.5" aria-hidden="true" />}>
                                            {words.columns.cost}
                                        </CellLabel>
                                        {money(line.costMinor, line.currency)}
                                    </td>
                                    <td className="col-figure">
                                        <CellLabel icon={<TrendingUp className="size-3.5" aria-hidden="true" />}>
                                            {isOpen ? words.columns.value : words.columns.result}
                                        </CellLabel>
                                        {/* One element: a stacked row lays a labelled cell out as a flex row,
                                            so loose siblings become columns beside the label. */}
                                        <div className="min-w-0 text-right">
                                            {isOpen ? (
                                                <>
                                                    {money(line.valueMinor, line.currency)}
                                                    <div className="note">
                                                        {line.valuationBasis === 'FACE_VALUE'
                                                            ? words.atFace
                                                            : line.valuedOn === null
                                                              ? ''
                                                              : words.pricedOn.replace(
                                                                    '{date}',
                                                                    formatDate(line.valuedOn, locale),
                                                                )}
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
                                        </div>
                                    </td>
                                    <td className="col-end text-right">
                                        {!isOpen || line.programme === 'TEZAUR' ? null : (
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
                                </tr>
                            ))}
                        </DataTable>
                    )}
                </Panel>
            </Page>

            {adding ? (
                <AddHoldingDialog
                    subjectId={subjectId}
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

            {selling === null ? null : (
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
            )}
        </>
    );
}

function AddHoldingDialog(props: {
    subjectId: string;
    onClose: (added: boolean) => void;
    refused: (cause: unknown) => string;
}): JSX.Element {
    const { t } = useTranslation();
    const words = t((d) => d.pages.holdings);
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
        instrument !== null && isDecimal(units) && isDecimal(price) && boughtOn !== null && feesMinor !== null;

    const save = async (): Promise<void> => {
        if (!ready) {
            return;
        }

        try {
            await addHolding(props.subjectId, {
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
    const words = t((d) => d.pages.holdings);
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
