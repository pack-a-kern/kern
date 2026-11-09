import { Link } from '@inertiajs/react';
import { ArrowLeft, CalendarClock } from 'lucide-react';
import { useEffect, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { getInstrument, getPrices } from '../../api/investmentsApi';
import { DataTable, Detail, DetailList, EmptyState, Page, Panel, Pill } from '../../components/panel';
import { useTranslation } from '../../i18n';
import { formatDate, formatMinor, formatNumber } from '../../lib/format';
import type { InstrumentDetail, PricePoint } from '../../types/investments';

/** How far back the price history reaches on this screen. */
const PRICE_DAYS = 90;

const isoDay = (date: Date): string => date.toISOString().slice(0, 10);

/**
 * One instrument (ADR 0025 §11): its terms as its source set them — the order for a government bond, BVB
 * for the rest — every payment it makes, and its price over the last weeks. Read-only: a holding of it is
 * added from Holdings.
 */
export default function InstrumentPage({ instrumentId }: { instrumentId: string }): JSX.Element {
    const { t, locale } = useTranslation();
    const words = t((d) => d.pages.instrument);
    const kinds = t((d) => d.pages.market.kinds);
    const [instrument, setInstrument] = useState<InstrumentDetail | null>(null);
    const [prices, setPrices] = useState<PricePoint[]>([]);

    useEffect(() => {
        let current = true;
        const today = new Date();
        const from = new Date(today.getTime() - PRICE_DAYS * 86_400_000);

        Promise.all([getInstrument(instrumentId), getPrices(instrumentId, isoDay(from), isoDay(today))])
            .then(([found, history]) => {
                if (current) {
                    setInstrument(found);
                    setPrices(history);
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
    }, [instrumentId, words.failed]);

    const day = (iso: string | null): string => (iso === null ? '' : formatDate(iso, locale));

    return (
        <Page
            title={instrument?.name ?? ''}
            {...(instrument === null ? {} : { lede: `${kinds[instrument.kind]} · ${instrument.issuer}` })}
            actions={
                <Link href="/investments" className="text-info inline-flex items-center gap-1 text-xs">
                    <ArrowLeft className="size-3.5" aria-hidden="true" />
                    {words.back}
                </Link>
            }
        >
            {instrument === null ? null : (
                <>
                    <Panel title={words.terms}>
                        <DetailList split>
                            {instrument.isin === null ? null : <Detail label={words.isin}>{instrument.isin}</Detail>}
                            {instrument.symbol === null ? null : (
                                <Detail label={words.symbol}>{instrument.symbol}</Detail>
                            )}
                            {instrument.issueCode === null ? null : (
                                <Detail label={words.code}>{instrument.issueCode}</Detail>
                            )}
                            <Detail label={words.currency}>{instrument.currency}</Detail>
                            {instrument.faceValue === null ? null : (
                                <Detail
                                    label={words.faceValue}
                                >{`${instrument.faceValue} ${instrument.currency}`}</Detail>
                            )}
                            {instrument.ratePercent === null ? null : (
                                <Detail label={words.rate}>{`${instrument.ratePercent}%`}</Detail>
                            )}
                            {instrument.issueDate === null ? null : (
                                <Detail label={words.issued}>{day(instrument.issueDate)}</Detail>
                            )}
                            {instrument.maturityDate === null ? null : (
                                <Detail label={words.matures}>{day(instrument.maturityDate)}</Detail>
                            )}
                            {instrument.subscribedValueMinor === null || instrument.subscriptions === null ? null : (
                                <Detail label={words.issuer}>
                                    {words.subscribed
                                        .replace(
                                            '{value}',
                                            formatMinor(instrument.subscribedValueMinor, instrument.currency, locale),
                                        )
                                        .replace('{count}', formatNumber(instrument.subscriptions, locale))}
                                </Detail>
                            )}
                        </DetailList>
                    </Panel>

                    <Panel title={words.payouts} flush>
                        {instrument.payouts.length === 0 ? (
                            <EmptyState icon={<CalendarClock className="size-5" />} title={words.noPayouts} />
                        ) : (
                            <DataTable
                                stacked
                                head={
                                    <>
                                        <th>{words.payouts}</th>
                                        <th>{words.paid}</th>
                                        <th className="col-end">{words.perUnit}</th>
                                    </>
                                }
                            >
                                {instrument.payouts.map((payout) => (
                                    <tr key={payout.id}>
                                        <td className="col-primary">
                                            {words.payoutKinds[payout.kind]}{' '}
                                            <Pill {...(payout.status === 'PAID' ? {} : { tone: 'accent' as const })}>
                                                {payout.status === 'PAID' ? words.paid : words.announced}
                                            </Pill>
                                        </td>
                                        <td>{day(payout.paymentDate)}</td>
                                        <td className="col-end mono">
                                            {payout.amountPerUnit === null
                                                ? payout.ratePercent === null
                                                    ? ''
                                                    : `${payout.ratePercent}%`
                                                : `${payout.amountPerUnit} ${instrument.currency}`}
                                        </td>
                                    </tr>
                                ))}
                            </DataTable>
                        )}
                    </Panel>

                    <Panel title={words.prices} flush>
                        {prices.length === 0 ? (
                            <EmptyState icon={<CalendarClock className="size-5" />} title={words.noPrices} />
                        ) : (
                            <DataTable
                                stacked
                                head={
                                    <>
                                        <th>{words.issued}</th>
                                        <th className="col-end">{t((d) => d.pages.market.columns.price)}</th>
                                    </>
                                }
                            >
                                {[...prices].reverse().map((point) => (
                                    <tr key={point.tradingDate}>
                                        <td className="col-primary">{day(point.tradingDate)}</td>
                                        <td className="col-end mono">
                                            {point.price}
                                            {point.changePercent === null ? '' : ` (${point.changePercent}%)`}
                                        </td>
                                    </tr>
                                ))}
                            </DataTable>
                        )}
                    </Panel>
                </>
            )}
        </Page>
    );
}
