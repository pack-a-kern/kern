import { Link } from '@inertiajs/react';
import { CalendarCheck, ChartCandlestick, Coins, Percent, SearchX, Shapes } from 'lucide-react';
import { useEffect, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { listInstruments } from '../../api/investmentsApi';
import {
    CellLabel,
    DataTable,
    EmptyState,
    Page,
    Panel,
    PanelButton,
    PanelSelect,
    Pill,
    SearchInput,
    TableSkeleton,
    Toolbar,
} from '../../components/panel';
import { useTranslation } from '../../i18n';
import { formatDate } from '../../lib/format';
import {
    INSTRUMENT_KINDS,
    INSTRUMENT_STATUSES,
    type Instrument,
    type InstrumentKind,
    type InstrumentStatus,
} from '../../types/investments';

/** The words a search waits for before it asks — a code is at least two characters, as the server takes. */
const MIN_SEARCH = 2;

/**
 * The market (ADR 0025 §11): government bonds, the shares and bonds listed on BVB, each with its latest
 * price — active by default, filtered by kind and status, searched by a code or words of a name, a page at
 * a time. Everything shown is computed by the server; a bond's price is its percent of the face value.
 */
export default function Market(): JSX.Element {
    const { t, locale } = useTranslation();
    const words = t((d) => d.pages.market);
    const [kind, setKind] = useState<InstrumentKind | ''>('');
    const [status, setStatus] = useState<InstrumentStatus>('ACTIVE');
    const [search, setSearch] = useState('');
    const [rows, setRows] = useState<Instrument[] | null>(null);
    const [failed, setFailed] = useState(false);
    const [next, setNext] = useState<string | null>(null);

    const query = search.trim().length >= MIN_SEARCH ? search.trim() : '';

    useEffect(() => {
        let current = true;

        listInstruments({ ...(kind === '' ? {} : { kind }), status, q: query })
            .then((page) => {
                if (current) {
                    setRows(page.data);
                    setNext(page.nextCursor);
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
    }, [kind, status, query]);

    const more = async (): Promise<void> => {
        try {
            const page = await listInstruments({ ...(kind === '' ? {} : { kind }), status, q: query, cursor: next });
            setRows((shown) => [...(shown ?? []), ...page.data]);
            setNext(page.nextCursor);
        } catch {
            toast.error(words.failed);
        }
    };

    return (
        <Page title={words.title} lede={words.description}>
            <Panel
                title={words.title}
                description={words.description}
                icon={<ChartCandlestick className="size-4" />}
                flush
                {...(next === null
                    ? {}
                    : {
                          footActions: (
                              <PanelButton onClick={() => void more()}>
                                  <ChartCandlestick className="size-3.5" aria-hidden="true" />
                                  {words.more}
                              </PanelButton>
                          ),
                      })}
            >
                <Toolbar>
                    <SearchInput value={search} placeholder={words.search} onChange={setSearch} />
                    <PanelSelect
                        icon={<Shapes className="size-3.5" aria-hidden="true" />}
                        className="filter-select"
                        aria-label={words.kind}
                        value={kind}
                        onValueChange={(value) => {
                            setKind(INSTRUMENT_KINDS.find((known) => known === value) ?? '');
                        }}
                        emptyLabel={words.allKinds}
                        options={INSTRUMENT_KINDS.map((value) => ({ value, label: words.kinds[value] }))}
                    />
                    <PanelSelect
                        className="filter-select"
                        aria-label={words.status}
                        value={status}
                        onValueChange={(value) => {
                            setStatus(INSTRUMENT_STATUSES.find((known) => known === value) ?? 'ACTIVE');
                        }}
                        options={INSTRUMENT_STATUSES.map((value) => ({ value, label: words.statuses[value] }))}
                    />
                </Toolbar>
                {rows === null ? (
                    failed ? (
                        <p className="note p-4">{words.failed}</p>
                    ) : (
                        <TableSkeleton />
                    )
                ) : rows.length === 0 ? (
                    <EmptyState icon={<SearchX className="size-5" />} title={words.none} />
                ) : (
                    <DataTable
                        stacked
                        head={
                            <>
                                <th>{words.columns.name}</th>
                                <th>{words.columns.kind}</th>
                                <th className="col-figure">{words.columns.rate}</th>
                                <th>{words.columns.maturity}</th>
                                <th className="col-figure">{words.columns.price}</th>
                            </>
                        }
                    >
                        {rows.map((instrument) => (
                            <tr key={instrument.id}>
                                <td className="col-primary">
                                    <Link href={`/investments/${instrument.id}`} className="font-semibold">
                                        {instrument.name}
                                    </Link>
                                    <div className="note mono">
                                        {instrument.symbol ?? instrument.issueCode ?? ''} {instrument.isin ?? ''}
                                    </div>
                                </td>
                                <td>
                                    <CellLabel icon={<Shapes className="size-3.5" aria-hidden="true" />}>
                                        {words.columns.kind}
                                    </CellLabel>
                                    <Pill {...(instrument.status === 'ON_OFFER' ? { tone: 'accent' as const } : {})}>
                                        {words.kinds[instrument.kind]}
                                    </Pill>
                                </td>
                                <td className="col-figure">
                                    {instrument.ratePercent === null ? null : (
                                        <>
                                            <CellLabel icon={<Percent className="size-3.5" aria-hidden="true" />}>
                                                {words.columns.rate}
                                            </CellLabel>
                                            {`${instrument.ratePercent}%`}
                                        </>
                                    )}
                                </td>
                                <td className="whitespace-nowrap">
                                    {instrument.maturityDate === null ? null : (
                                        <>
                                            <CellLabel icon={<CalendarCheck className="size-3.5" aria-hidden="true" />}>
                                                {words.columns.maturity}
                                            </CellLabel>
                                            {formatDate(instrument.maturityDate, locale)}
                                        </>
                                    )}
                                </td>
                                <td className="col-figure">
                                    {instrument.latestPrice === null ? null : (
                                        <>
                                            <CellLabel icon={<Coins className="size-3.5" aria-hidden="true" />}>
                                                {words.columns.price}
                                            </CellLabel>
                                            {instrument.kind === 'SHARE'
                                                ? `${instrument.latestPrice.price} ${instrument.currency}`
                                                : words.ofFace.replace('{price}', instrument.latestPrice.price)}
                                        </>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </DataTable>
                )}
            </Panel>
        </Page>
    );
}
