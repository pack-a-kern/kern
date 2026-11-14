import { Link } from '@inertiajs/react';
import {
    Briefcase,
    CalendarCheck,
    Check,
    Coins,
    Landmark,
    Layers,
    ListOrdered,
    Plus,
    Search,
    Tag,
    TrendingUp,
    Wallet,
    X,
} from 'lucide-react';
import { useEffect, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { addHolding, listHoldings, listInstruments, sellHolding } from '../../api/investmentsApi';
import Pagination from '../../components/Pagination';
import {
    ActionGroup,
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
    TableSkeleton,
    Tabs,
    Toolbar,
} from '../../components/panel';
import { PanelDate } from '../../components/PanelDatePicker';
import { useActiveSubjectId } from '../../hooks/useActiveSubjectId';
import { useRememberedPaging, useRememberedState } from '../../hooks/useRememberedState';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { formatDate, formatMinor } from '../../lib/format';
import { ApiError } from '../../lib/http';
import { INSTRUMENT_KIND_LOOK } from '../../lib/instrumentKinds';
import { cn } from '../../lib/utils';
import { HELD_AT, type HeldAt, type HoldingPage, type HoldingPosition, type Instrument } from '../../types/investments';

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
 * A person's bonds and shares (ADR 0025 §9), one row per instrument per place it is held: the units, the
 * weighted average price paid, what it cost, what it is worth or what its sales made, and what it has paid
 * in — each row opening on the purchases it is made of, which the law counts one by one. A numbered page at
 * a time, still held or sold, from the server; every figure is its. A holding is added the way a deposit
 * is — the instrument chosen from the market — and sold from its row by units and the price per unit, the
 * server taking the oldest purchases first. Tezaur ends at maturity: never offered for sale.
 */
export default function Holdings(): JSX.Element {
    const { t } = useTranslation();
    const words = t((d) => d.pages.holdings);
    const subjectId = useActiveSubjectId();
    const [answer, setAnswer] = useState<HoldingPage | null>(null);
    const [failed, setFailed] = useState(false);
    const [adding, setAdding] = useState(false);
    const [selling, setSelling] = useState<HoldingPosition | null>(null);
    const [showing, setShowing] = useState<HoldingPosition | null>(null);
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

        listHoldings(subjectId, state, page, pageSize)
            .then((loaded) => {
                if (!current) {
                    return;
                }

                // A page past the last — a position sold, or fewer of them — is the last.
                if (loaded.positions.length === 0 && loaded.total > 0) {
                    setPage(Math.ceil(loaded.total / pageSize));

                    return;
                }

                setAnswer(loaded);
                setFailed(false);
            })
            .catch(() => {
                if (current) {
                    setFailed(true);
                }
            });

        return () => {
            current = false;
        };
    }, [subjectId, state, page, pageSize, version, setPage]);

    const isOpen = state === 'open';

    const refused = (cause: unknown): string =>
        cause instanceof ApiError && cause.reason !== undefined && words.refusals[cause.reason] !== undefined
            ? (words.refusals[cause.reason] ?? '')
            : describeApiFailure(cause, t);

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
                    title={words.listTitle}
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
                        answer === null ? undefined : (
                            <Pagination
                                page={page}
                                pageSize={pageSize}
                                total={answer.total}
                                unit={words.unit}
                                onPageChange={setPage}
                                onPageSizeChange={setPageSize}
                            />
                        )
                    }
                >
                    <Toolbar>
                        <Tabs
                            value={state}
                            onChange={(value) => {
                                setState(value);
                                setPage(1);
                            }}
                            options={STATES.map((value) => ({ value, label: words[value] }))}
                        />
                    </Toolbar>

                    {answer === null ? (
                        failed ? (
                            <p className="note p-4">{words.loadFailed}</p>
                        ) : (
                            <TableSkeleton />
                        )
                    ) : answer.positions.length === 0 ? (
                        <EmptyState
                            icon={<Briefcase className="size-5" />}
                            title={isOpen ? words.none : words.noneSold}
                            {...(isOpen ? { description: words.emptyHint } : {})}
                        />
                    ) : (
                        <DataTable
                            stacked
                            head={
                                <>
                                    <th>{words.columns.instrument}</th>
                                    <th className="col-figure">{words.columns.units}</th>
                                    <th className="col-figure">{words.columns.cost}</th>
                                    <th className="col-figure">
                                        {isOpen ? words.columns.value : words.columns.proceeds}
                                    </th>
                                    <th className="col-figure">{words.columns.result}</th>
                                    <th />
                                </>
                            }
                        >
                            {answer.positions.map((position) => (
                                <PositionRow
                                    key={`${position.accountId}-${position.instrumentId}`}
                                    position={position}
                                    open={isOpen}
                                    onPurchases={() => {
                                        setShowing(position);
                                    }}
                                    onSell={() => {
                                        setSelling(position);
                                    }}
                                />
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

            {showing === null ? null : (
                <PurchasesDialog
                    position={showing}
                    open={isOpen}
                    onClose={() => {
                        setShowing(null);
                    }}
                />
            )}

            {selling === null ? null : (
                <SellDialog
                    subjectId={subjectId}
                    position={selling}
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

/**
 * One position: what it is and where it is held; its units and the weighted average price paid; what it
 * cost; what it is worth now (or what its sales brought); the result, with what it has paid in. Its
 * purchases open in a dialog; a sale starts from here.
 */
function PositionRow({
    position,
    open,
    onPurchases,
    onSell,
}: {
    position: HoldingPosition;
    open: boolean;
    onPurchases: () => void;
    onSell: () => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const words = t((d) => d.pages.holdings);
    const look = INSTRUMENT_KIND_LOOK[position.instrumentKind];
    const money = (minor: number | null): string =>
        minor === null ? '—' : formatMinor(minor, position.currency, locale);
    const result = position.resultMinor;
    const code = position.symbol ?? position.issueCode;

    return (
        <tr>
            <td className="col-primary">
                <div className="flex min-w-0 items-center gap-2.5">
                    <span className={cn('tone grid size-8 shrink-0 place-items-center rounded-lg', look.tone)}>
                        <look.icon className="size-4" aria-hidden="true" />
                    </span>
                    <div className="min-w-0">
                        {/* The symbol is what a holder knows it by; the name under it, cut where it must, on a
                            desktop only — a phone's row has no room for both. */}
                        <div className="flex items-center gap-1.5">
                            <Link
                                href={`/investments/${position.instrumentId}`}
                                className="text-sm font-semibold tracking-wide"
                            >
                                {code ?? position.instrumentName}
                            </Link>
                            <Pill>{words.heldAt[position.heldAt]}</Pill>
                        </div>
                        {code === null ? null : (
                            <div
                                className="text-muted-foreground hidden max-w-64 truncate text-2xs sm:block"
                                title={position.instrumentName}
                            >
                                {position.instrumentName}
                            </div>
                        )}
                    </div>
                </div>
            </td>
            <td className="col-figure">
                <CellLabel icon={<Layers className="size-3.5" aria-hidden="true" />}>{words.columns.units}</CellLabel>
                <div className="min-w-0 text-right">
                    {units(position.units)}
                    <div className="note">
                        {words.atAverage.replace('{price}', `${position.averagePrice} ${position.currency}`)}
                    </div>
                </div>
            </td>
            <td className="col-figure">
                <CellLabel icon={<Coins className="size-3.5" aria-hidden="true" />}>{words.columns.cost}</CellLabel>
                {money(position.costMinor)}
            </td>
            <td className="col-figure">
                <CellLabel icon={<Wallet className="size-3.5" aria-hidden="true" />}>
                    {open ? words.columns.value : words.columns.proceeds}
                </CellLabel>
                <div className="min-w-0 text-right">
                    {money(open ? position.valueMinor : position.proceedsMinor)}
                    <div className="note">
                        {!open
                            ? position.lastSoldOn === null
                                ? ''
                                : words.soldOn.replace('{date}', formatDate(position.lastSoldOn, locale))
                            : position.valuationBasis === 'FACE_VALUE'
                              ? words.atFace
                              : position.valuedOn === null
                                ? ''
                                : words.pricedOn.replace('{date}', formatDate(position.valuedOn, locale))}
                    </div>
                </div>
            </td>
            <td className="col-figure">
                <CellLabel icon={<TrendingUp className="size-3.5" aria-hidden="true" />}>
                    {words.columns.result}
                </CellLabel>
                <div className="min-w-0 text-right">
                    <span
                        className={cn(
                            'font-semibold',
                            result !== null && result > 0 && 'text-success',
                            result !== null && result < 0 && 'text-destructive',
                        )}
                    >
                        {result === null ? '—' : `${result > 0 ? '+' : ''}${money(result)}`}
                        {position.resultPercent === null
                            ? null
                            : ` (${result !== null && result > 0 ? '+' : ''}${position.resultPercent}%)`}
                    </span>
                    {position.receivedMinor === 0 ? null : (
                        <div className="note">{words.received.replace('{amount}', money(position.receivedMinor))}</div>
                    )}
                </div>
            </td>
            <td className="col-end text-right">
                {/* Joined buttons on a desktop, one menu on a phone: the same two, in the same order. */}
                <ActionGroup
                    collapse
                    actions={[
                        {
                            key: 'purchases',
                            label: words.purchases.replace('{count}', String(position.lots.length)),
                            icon: <ListOrdered className="size-3.5" aria-hidden="true" />,
                            onClick: onPurchases,
                        },
                        ...(!open || position.programme === 'TEZAUR'
                            ? []
                            : [
                                  {
                                      key: 'sell',
                                      label: words.sell,
                                      icon: <Tag className="size-3.5" aria-hidden="true" />,
                                      onClick: onSell,
                                  },
                              ]),
                    ]}
                />
            </td>
        </tr>
    );
}

/** A number of units as a person writes it: no trailing zeros, no trailing point. */
function units(value: string): string {
    return value.includes('.') ? value.replace(/\.?0+$/, '') : value;
}

/**
 * The purchases a position is made of, oldest first — each counted on its own by the law: when, how many, at
 * what price and fees, what it cost, and what it is worth or what its sale made and what was withheld.
 */
function PurchasesDialog({
    position,
    open,
    onClose,
}: {
    position: HoldingPosition;
    open: boolean;
    onClose: () => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const words = t((d) => d.pages.holdings);
    const money = (minor: number | null): string =>
        minor === null ? '—' : formatMinor(minor, position.currency, locale);

    return (
        <PanelDialog
            open
            onOpenChange={(next) => {
                if (!next) {
                    onClose();
                }
            }}
            size="lg"
            dismissOnOutsideClick
            title={`${words.showPurchases} · ${position.symbol ?? position.issueCode ?? position.instrumentName}`}
            description={`${words.heldAt[position.heldAt]} · ${words.purchasesHint}`}
            actions={
                <PanelButton onClick={onClose}>
                    <X className="size-3.5" aria-hidden="true" />
                    {words.form.cancel}
                </PanelButton>
            }
        >
            <DataTable
                stacked
                head={
                    <>
                        <th>{open ? words.columns.bought : words.columns.sold}</th>
                        <th className="col-figure">{words.columns.units}</th>
                        <th className="col-figure">{words.columns.price}</th>
                        <th className="col-figure">{words.columns.cost}</th>
                        <th className="col-figure">{words.columns.result}</th>
                    </>
                }
            >
                {position.lots.map((lot) => {
                    const result = open
                        ? lot.valueMinor === null
                            ? null
                            : lot.valueMinor - lot.costMinor
                        : lot.resultMinor;

                    return (
                        <tr key={lot.id}>
                            <td className="col-primary">
                                <div className="flex items-center gap-2">
                                    <CalendarCheck className="text-muted-foreground size-3.5" aria-hidden="true" />
                                    {formatDate(lot.boughtOn, locale)}
                                </div>
                                {lot.soldOn === null ? null : (
                                    <div className="note">
                                        {words.soldOn.replace('{date}', formatDate(lot.soldOn, locale))}
                                    </div>
                                )}
                            </td>
                            <td className="col-figure">
                                <CellLabel icon={<Layers className="size-3.5" aria-hidden="true" />}>
                                    {words.columns.units}
                                </CellLabel>
                                {units(lot.units)}
                            </td>
                            <td className="col-figure">
                                <CellLabel icon={<Tag className="size-3.5" aria-hidden="true" />}>
                                    {words.columns.price}
                                </CellLabel>
                                {`${lot.pricePerUnit} ${lot.currency}`}
                            </td>
                            <td className="col-figure">
                                <CellLabel icon={<Coins className="size-3.5" aria-hidden="true" />}>
                                    {words.columns.cost}
                                </CellLabel>
                                <div className="min-w-0 text-right">
                                    {money(lot.costMinor)}
                                    {lot.feesMinor === 0 ? null : (
                                        <div className="note">
                                            {words.fees.replace('{amount}', money(lot.feesMinor))}
                                        </div>
                                    )}
                                </div>
                            </td>
                            <td className="col-figure">
                                <CellLabel icon={<TrendingUp className="size-3.5" aria-hidden="true" />}>
                                    {words.columns.result}
                                </CellLabel>
                                <div className="min-w-0 text-right">
                                    <span
                                        className={cn(
                                            result !== null && result > 0 && 'text-success',
                                            result !== null && result < 0 && 'text-destructive',
                                        )}
                                    >
                                        {result === null ? '—' : `${result > 0 ? '+' : ''}${money(result)}`}
                                    </span>
                                    {lot.saleTaxWithheldMinor === null ? null : (
                                        <div className="note">
                                            {words.withheld.replace('{amount}', money(lot.saleTaxWithheldMinor))}
                                        </div>
                                    )}
                                </div>
                            </td>
                        </tr>
                    );
                })}
            </DataTable>
        </PanelDialog>
    );
}

function AddHoldingDialog(props: {
    subjectId: string;
    onClose: (added: boolean) => void;
    refused: (cause: unknown) => string;
}): JSX.Element {
    const { t } = useTranslation();
    const words = t((d) => d.pages.holdings);
    const kinds = t((d) => d.pages.market.kinds);
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
                        <X className="size-3.5" aria-hidden="true" />
                        {words.form.cancel}
                    </PanelButton>
                    <PanelButton primary disabled={!ready} onClick={() => void save()}>
                        <Check className="size-3.5" aria-hidden="true" />
                        {words.form.save}
                    </PanelButton>
                </>
            }
        >
            <FieldGrid>
                <Field label={words.form.heldAt} htmlFor="holding-held-at">
                    <PanelSelect
                        id="holding-held-at"
                        icon={<Landmark className="size-3.5" aria-hidden="true" />}
                        value={heldAt}
                        onValueChange={(value) => {
                            setHeldAt(HELD_AT.find((known) => known === value) ?? 'BANK');
                        }}
                        options={HELD_AT.map((value) => ({ value, label: words.heldAt[value] }))}
                    />
                </Field>
                {/* A select whose search asks the market as it is typed: the market is too large to list. */}
                <Field label={words.form.instrument} htmlFor="holding-instrument">
                    <PanelSelect
                        id="holding-instrument"
                        icon={<Search className="size-3.5" aria-hidden="true" />}
                        searchable
                        onSearch={setSearch}
                        searchPlaceholder={words.form.instrumentSearch}
                        placeholder={words.form.instrumentChoose}
                        emptyText={typed.length < 2 ? words.form.typeToSearch : words.form.noMatch}
                        value={instrument?.id ?? ''}
                        onValueChange={(value) => {
                            const chosen = [...shown, ...(instrument === null ? [] : [instrument])].find(
                                (candidate) => candidate.id === value,
                            );

                            if (chosen === undefined) {
                                return;
                            }

                            setInstrument(chosen);
                            setHeldAt(
                                chosen.programme === 'TEZAUR'
                                    ? 'TREASURY'
                                    : chosen.kind === 'SHARE'
                                      ? 'BROKER'
                                      : heldAt,
                            );
                        }}
                        options={[
                            // The one chosen stays among them, so the field still names it once the search moves on.
                            ...(instrument === null || shown.some((candidate) => candidate.id === instrument.id)
                                ? []
                                : [instrument]),
                            ...shown,
                        ].map((candidate) => ({
                            value: candidate.id,
                            label: `${candidate.symbol ?? candidate.issueCode ?? ''} ${candidate.name}`.trim(),
                            description: kinds[candidate.kind],
                        }))}
                    />
                </Field>
                <Field label={words.form.units} htmlFor="holding-units">
                    <PanelInput
                        id="holding-units"
                        icon={<Layers className="size-3.5" />}
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
                        icon={<Tag className="size-3.5" />}
                        inputMode="decimal"
                        value={price}
                        onChange={(event) => {
                            setPrice(event.target.value);
                        }}
                    />
                </Field>
                <Field label={words.form.boughtOn} htmlFor="holding-bought">
                    <PanelDate value={boughtOn} onChange={setBoughtOn} disableFuture block />
                </Field>
                <Field label={words.form.fees} htmlFor="holding-fees">
                    <PanelInput
                        id="holding-fees"
                        icon={<Coins className="size-3.5" />}
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
    position: HoldingPosition;
    onClose: (done: boolean) => void;
    refused: (cause: unknown) => string;
}): JSX.Element {
    const { t } = useTranslation();
    const words = t((d) => d.pages.holdings);
    const [sold, setSold] = useState(units(props.position.units));
    const [price, setPrice] = useState('');
    const [soldOn, setSoldOn] = useState<string | null>(null);
    const [fees, setFees] = useState('0');
    const feesMinor = minorOf(fees);
    const ready = isDecimal(sold) && isDecimal(price) && soldOn !== null && feesMinor !== null;

    const sell = async (): Promise<void> => {
        if (!ready) {
            return;
        }

        try {
            await sellHolding(props.subjectId, {
                accountId: props.position.accountId,
                instrumentId: props.position.instrumentId,
                units: decimal(sold),
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
            title={`${words.sell} · ${props.position.instrumentName}`}
            actions={
                <>
                    <PanelButton
                        onClick={() => {
                            props.onClose(false);
                        }}
                    >
                        <X className="size-3.5" aria-hidden="true" />
                        {words.form.cancel}
                    </PanelButton>
                    <PanelButton primary disabled={!ready} onClick={() => void sell()}>
                        <Tag className="size-3.5" aria-hidden="true" />
                        {words.sell}
                    </PanelButton>
                </>
            }
        >
            <p className="note mb-3">{words.form.saleOrder.replace('{place}', words.heldAt[props.position.heldAt])}</p>
            <FieldGrid>
                <Field
                    label={words.form.units}
                    htmlFor="sale-units"
                    help={words.form.unitsHeld.replace('{units}', units(props.position.units))}
                >
                    <PanelInput
                        id="sale-units"
                        icon={<Layers className="size-3.5" />}
                        inputMode="decimal"
                        value={sold}
                        onChange={(event) => {
                            setSold(event.target.value);
                        }}
                    />
                </Field>
                <Field label={words.form.pricePerUnit} htmlFor="sale-price" help={words.form.pricePerUnitHint}>
                    <PanelInput
                        id="sale-price"
                        icon={<Tag className="size-3.5" />}
                        inputMode="decimal"
                        value={price}
                        onChange={(event) => {
                            setPrice(event.target.value);
                        }}
                    />
                </Field>
                <Field label={words.form.soldOn} htmlFor="sale-date">
                    <PanelDate value={soldOn} onChange={setSoldOn} disableFuture block />
                </Field>
                <Field label={words.form.fees} htmlFor="sale-fees">
                    <PanelInput
                        id="sale-fees"
                        icon={<Coins className="size-3.5" />}
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
