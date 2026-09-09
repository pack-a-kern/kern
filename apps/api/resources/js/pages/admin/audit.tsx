import { useCallback, useEffect, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { getAuditSummary, listAuditEvents } from '../../api/auditApi';
import AppShell from '../../components/AppShell';
import {
    DataTable,
    Detail,
    DetailList,
    Panel,
    PanelButton,
    PanelSelect,
    Pill,
    SearchInput,
    Stat,
    StatGrid,
    Status,
    Toolbar,
} from '../../components/panel';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { ApiError } from '../../lib/http';
import type { AuditEvent, AuditSummary } from '../../types/audit';

/** Time ranges offered by the filter, as hours back from now. `null` is "everything". */
const RANGES: Array<{ key: string; hours: number | null }> = [
    { key: 'h24', hours: 24 },
    { key: 'd7', hours: 24 * 7 },
    { key: 'd30', hours: 24 * 30 },
    { key: 'all', hours: null },
];

/**
 * The audit trail.
 *
 * What this screen can and cannot show is decided by `BLUEPRINT.md` §4.8, not by design taste:
 * `audit_events` stores a *hash* of each payload, never the payload, so the trail proves that
 * something happened and that nobody has altered the record of it — without becoming a second,
 * unencrypted copy of the data every other screen goes to lengths to protect. There is likewise no
 * IP address and no severity column, so neither is displayed; classifying event types into
 * severities here would write a judgement into an evidentiary record that the record does not make.
 *
 * The integrity figure is a real re-verification: the server walks each chain and recomputes every
 * hash on request. A tamper-evidence indicator that reads a stored flag is evidence of nothing.
 */
export default function AdminAudit(): JSX.Element {
    const { t } = useTranslation();

    const [summary, setSummary] = useState<AuditSummary | null>(null);
    const [events, setEvents] = useState<AuditEvent[] | null>(null);
    const [cursor, setCursor] = useState<number | null>(null);
    // Cursors of the pages already visited, so "previous" can go back. Keyset paging has no page
    // numbers to jump to — the log is append-only and rows shift under an OFFSET — so the history
    // is kept here rather than computed.
    const [history, setHistory] = useState<number[]>([]);
    const [loadingMore, setLoadingMore] = useState(false);
    const [forbidden, setForbidden] = useState(false);
    const [selected, setSelected] = useState<AuditEvent | null>(null);

    const [search, setSearch] = useState('');
    const [eventType, setEventType] = useState('');
    const [range, setRange] = useState('h24');

    const describeFailure = useCallback(
        (cause: unknown): string => {
            if (cause instanceof ApiError && cause.reason === 'network') {
                return t((d) => d.errors.network);
            }

            return t((d) => d.errors.unexpected);
        },
        [t],
    );

    const sinceFor = useCallback((key: string): string | undefined => {
        const hours = RANGES.find((entry) => entry.key === key)?.hours ?? null;

        return hours === null ? undefined : new Date(Date.now() - hours * 3600_000).toISOString();
    }, []);

    // Refetch whenever a filter changes. Debounced on the search box only, since typing there
    // would otherwise issue a request per keystroke.
    useEffect(() => {
        let active = true;
        const timer = window.setTimeout(
            () => {
                setEvents(null);

                const since = sinceFor(range);

                listAuditEvents({
                    ...(search === '' ? {} : { q: search }),
                    ...(eventType === '' ? {} : { eventType }),
                    ...(since === undefined ? {} : { since }),
                })
                    .then((page) => {
                        if (!active) {
                            return;
                        }

                        setEvents(page.events);
                        setCursor(page.nextCursor);
                        setForbidden(false);
                    })
                    .catch((cause: unknown) => {
                        if (!active) {
                            return;
                        }

                        if (cause instanceof ApiError && cause.status === 403) {
                            setForbidden(true);

                            return;
                        }

                        setEvents([]);
                        toast.error(
                            t((d) => d.pages.adminAudit.loadFailed),
                            { description: describeFailure(cause) },
                        );
                    });
            },
            search === '' ? 0 : 250,
        );

        return () => {
            active = false;
            window.clearTimeout(timer);
        };
    }, [search, eventType, range, sinceFor, describeFailure, t]);

    useEffect(() => {
        let active = true;

        void getAuditSummary().then(
            (loaded) => {
                if (active) {
                    setSummary(loaded);
                }
            },
            () => {
                if (active) {
                    setSummary(null);
                }
            },
        );

        return () => {
            active = false;
        };
    }, []);

    const goToPage = async (from: number | null): Promise<void> => {
        setLoadingMore(true);

        try {
            const since = sinceFor(range);
            const page = await listAuditEvents({
                ...(from === null ? {} : { cursor: from }),
                ...(search === '' ? {} : { q: search }),
                ...(eventType === '' ? {} : { eventType }),
                ...(since === undefined ? {} : { since }),
            });

            setEvents(page.events);
            setCursor(page.nextCursor);
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.adminAudit.loadFailed),
                { description: describeFailure(cause) },
            );
        } finally {
            setLoadingMore(false);
        }
    };

    const nextPage = async (): Promise<void> => {
        if (cursor === null) {
            return;
        }

        const from = cursor;
        setHistory((current) => [...current, events?.[0]?.sequence ?? 0]);
        await goToPage(from);
    };

    const previousPage = async (): Promise<void> => {
        const previous = history[history.length - 1];

        if (previous === undefined) {
            return;
        }

        setHistory((current) => current.slice(0, -1));
        // The stored value is the first sequence of the page being returned to, so paging from one
        // above it lands on that same page.
        await goToPage(previous + 1);
    };

    if (forbidden) {
        return (
            <AppShell>
                <div className="mx-auto w-full max-w-lg pt-16 text-center">
                    <h1 className="text-lg font-semibold">{t((d) => d.pages.adminAudit.title)}</h1>
                    <p className="note mt-2">{t((d) => d.pages.adminAudit.forbidden)}</p>
                </div>
            </AppShell>
        );
    }

    return (
        <AppShell>
            <div className="page-stack">
                <header>
                    <h1 className="page-title">{t((d) => d.pages.adminAudit.title)}</h1>
                    <p className="page-lede max-w-2xl">{t((d) => d.pages.adminAudit.description)}</p>
                </header>

                <StatGrid>
                    <Stat
                        label={t((d) => d.pages.adminAudit.stats.total)}
                        value={summary === null ? '—' : summary.eventsTotal.toLocaleString()}
                    />
                    <Stat
                        label={t((d) => d.pages.adminAudit.stats.last24h)}
                        value={summary === null ? '—' : summary.events24h.toLocaleString()}
                    />
                    <Stat
                        label={t((d) => d.pages.adminAudit.stats.actors)}
                        value={summary === null ? '—' : summary.actors24h.toLocaleString()}
                        sub={t((d) => d.pages.adminAudit.stats.actorsSub)}
                    />
                    <Stat
                        label={t((d) => d.pages.adminAudit.stats.integrity)}
                        value={
                            summary === null ? (
                                '—'
                            ) : (
                                <Status tone={summary.integrity.ok ? 'ok' : 'bad'}>
                                    {summary.integrity.ok
                                        ? t((d) => d.pages.adminAudit.stats.verified)
                                        : t((d) => d.pages.adminAudit.stats.broken)}
                                </Status>
                            )
                        }
                        sub={
                            summary === null
                                ? undefined
                                : t((d) => d.pages.adminAudit.stats.integritySub)
                                      .replace('{events}', summary.integrity.eventsVerified.toLocaleString())
                                      .replace('{chains}', String(summary.integrity.chainsChecked))
                        }
                    />
                </StatGrid>

                <Panel title={t((d) => d.pages.adminAudit.tableTitle)} flush>
                    <Toolbar>
                        <SearchInput
                            value={search}
                            placeholder={t((d) => d.pages.adminAudit.searchPlaceholder)}
                            onChange={setSearch}
                        />
                        <PanelSelect
                            className="filter-select"
                            value={eventType}
                            onValueChange={setEventType}
                            placeholder={t((d) => d.pages.adminAudit.allEvents)}
                            emptyLabel={t((d) => d.pages.adminAudit.allEvents)}
                            options={(summary?.eventTypes ?? []).map((type) => ({ value: type, label: type }))}
                        />
                        <PanelSelect
                            className="filter-select"
                            value={range}
                            onValueChange={setRange}
                            options={RANGES.map((entry) => ({
                                value: entry.key,
                                label: t((d) => d.pages.adminAudit.ranges[entry.key] ?? entry.key),
                            }))}
                        />
                        <PanelButton
                            onClick={() => {
                                setSearch('');
                                setEventType('');
                                setRange('h24');
                            }}
                        >
                            {t((d) => d.pages.adminAudit.reset)}
                        </PanelButton>
                    </Toolbar>

                    {events === null ? (
                        <div className="space-y-2 p-3">
                            {[0, 1, 2, 3, 4].map((index) => (
                                <Skeleton key={index} className="tile h-9 w-full" />
                            ))}
                        </div>
                    ) : events.length === 0 ? (
                        <p className="note p-4">{t((d) => d.pages.adminAudit.empty)}</p>
                    ) : (
                        <DataTable
                            stacked
                            head={
                                <>
                                    <th>{t((d) => d.pages.adminAudit.columns.time)}</th>
                                    <th>{t((d) => d.pages.adminAudit.columns.event)}</th>
                                    <th>{t((d) => d.pages.adminAudit.columns.resource)}</th>
                                    <th>{t((d) => d.pages.adminAudit.columns.actor)}</th>
                                    <th>{t((d) => d.pages.adminAudit.columns.sequence)}</th>
                                    <th />
                                </>
                            }
                        >
                            {events.map((event) => (
                                <tr key={event.id}>
                                    {/* Column roles for the stacked phone layout — see `.data-table-stack`
                                        in app.css. Resource and actor are both full UUIDs: unreadable at
                                        this width and useless for scanning, and the drawer shows them in
                                        full. Time leads the row, event and sequence identify it, the
                                        action stays reachable without scrolling. */}
                                    <td className="col-primary whitespace-nowrap tabular-nums">
                                        {new Date(event.occurredAt).toLocaleString()}
                                    </td>
                                    <td className="font-semibold">{event.eventType}</td>
                                    <td className="col-hide">
                                        {event.resourceType}
                                        {event.resourceId !== null ? (
                                            <div className="mono">{event.resourceId}</div>
                                        ) : null}
                                    </td>
                                    <td className="col-hide">
                                        {event.actorId === null ? (
                                            <Pill>{t((d) => d.pages.adminAudit.systemActor)}</Pill>
                                        ) : (
                                            <span className="mono">{event.actorId}</span>
                                        )}
                                    </td>
                                    <td
                                        className="tabular-nums"
                                        data-label={t((d) => d.pages.adminAudit.columns.sequence)}
                                    >
                                        {event.sequence}
                                    </td>
                                    <td className="col-end text-right">
                                        <PanelButton
                                            onClick={() => {
                                                setSelected(event);
                                            }}
                                        >
                                            {t((d) => d.pages.adminAudit.details)}
                                        </PanelButton>
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    )}

                    {cursor !== null || history.length > 0 ? (
                        <div className="pager">
                            <span className="note">
                                {t((d) => d.pages.adminAudit.page).replace('{page}', String(history.length + 1))}
                            </span>
                            <div className="flex gap-1.5">
                                <PanelButton
                                    disabled={loadingMore || history.length === 0}
                                    onClick={() => {
                                        void previousPage();
                                    }}
                                >
                                    {t((d) => d.pages.adminAudit.previous)}
                                </PanelButton>
                                <PanelButton
                                    disabled={loadingMore || cursor === null}
                                    onClick={() => {
                                        void nextPage();
                                    }}
                                >
                                    {t((d) => d.pages.adminAudit.next)}
                                </PanelButton>
                            </div>
                        </div>
                    ) : null}
                </Panel>
            </div>

            <Sheet
                open={selected !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelected(null);
                    }
                }}
            >
                <SheetContent side="right" className="drawer gap-0 p-0">
                    <SheetHeader className="border-b p-4">
                        <SheetTitle className="dialog-title">{t((d) => d.pages.adminAudit.detailsTitle)}</SheetTitle>
                    </SheetHeader>

                    {selected === null ? null : (
                        <div className="space-y-4 overflow-auto p-4">
                            <DetailList>
                                <Detail label={t((d) => d.pages.adminAudit.columns.event)}>
                                    <span className="font-semibold">{selected.eventType}</span>
                                </Detail>
                                <Detail label={t((d) => d.pages.adminAudit.columns.time)}>
                                    {new Date(selected.occurredAt).toLocaleString()}
                                </Detail>
                                <Detail label={t((d) => d.pages.adminAudit.columns.resource)}>
                                    {selected.resourceType}
                                    {selected.resourceId !== null ? (
                                        <div className="mono">{selected.resourceId}</div>
                                    ) : null}
                                </Detail>
                                <Detail label={t((d) => d.pages.adminAudit.columns.actor)}>
                                    {selected.actorId === null ? (
                                        <Pill>{t((d) => d.pages.adminAudit.systemActor)}</Pill>
                                    ) : (
                                        <span className="mono">{selected.actorId}</span>
                                    )}
                                </Detail>
                                <Detail label={t((d) => d.pages.adminAudit.subject)}>
                                    {selected.subjectId === null ? (
                                        <Pill>{t((d) => d.pages.adminAudit.platformScope)}</Pill>
                                    ) : (
                                        <span className="mono">{selected.subjectId}</span>
                                    )}
                                </Detail>
                            </DetailList>

                            <div className="tile border p-3">
                                <div className="eyebrow mb-2">{t((d) => d.pages.adminAudit.chainTitle)}</div>
                                <DetailList>
                                    <Detail label={t((d) => d.pages.adminAudit.columns.sequence)}>
                                        <span className="tabular-nums">{selected.sequence}</span>
                                    </Detail>
                                    <Detail label={t((d) => d.pages.adminAudit.chainKey)}>
                                        <span className="mono">{selected.chainKey}</span>
                                    </Detail>
                                    <Detail label={t((d) => d.pages.adminAudit.eventHash)}>
                                        <span className="mono">{selected.eventHash}</span>
                                    </Detail>
                                    <Detail label={t((d) => d.pages.adminAudit.previousHash)}>
                                        <span className="mono">{selected.previousHash}</span>
                                    </Detail>
                                    <Detail label={t((d) => d.pages.adminAudit.payloadHash)}>
                                        <span className="mono">{selected.payloadHash}</span>
                                    </Detail>
                                    <Detail label={t((d) => d.pages.adminAudit.keyVersion)}>
                                        <span className="tabular-nums">{selected.signatureKeyVersion}</span>
                                    </Detail>
                                </DetailList>
                            </div>

                            <p className="note">{t((d) => d.pages.adminAudit.noPayloadNotice)}</p>
                        </div>
                    )}
                </SheetContent>
            </Sheet>
        </AppShell>
    );
}
