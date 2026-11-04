import { useCallback, useEffect, useRef, useState, type JSX } from 'react';
import { cn } from 'cn';
import {
    Box,
    CalendarRange,
    Clock,
    Eye,
    Hash,
    RotateCcw,
    ScrollText,
    ScrollTextIcon,
    Shapes,
    ShieldCheck,
    UsersRound,
} from 'lucide-react';
import { toast } from 'sonner';

import { getAuditSummary, listAuditEvents } from '../api/auditApi';
import {
    DataTable,
    Detail,
    DetailList,
    EmptyState,
    Page,
    Panel,
    PanelButton,
    PanelSelect,
    Pill,
    SearchInput,
    Stat,
    StatGrid,
    Status,
    Toolbar,
    CellLabel,
    EventTime,
} from '../components/panel';
import { Tooltip, TooltipContent, TooltipTrigger } from '../components/ui/tooltip';
import { formatDateTime, formatNumber } from '../lib/format';
import AppShell from '../components/AppShell';
import Pagination from '../components/Pagination';
import { useRememberedPaging, useRememberedState } from '../hooks/useRememberedState';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '../components/ui/sheet';
import { Skeleton } from '../components/ui/skeleton';
import { useTranslation } from '../i18n';
import { describeApiFailure } from '../lib/apiErrors';
import { ApiError } from '../lib/http';
import type { AuditEvent, AuditSummary } from '../types/audit';

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
function AdminAuditBody(): JSX.Element {
    const { t, locale } = useTranslation();

    const [summary, setSummary] = useState<AuditSummary | null>(null);
    const [events, setEvents] = useState<AuditEvent[] | null>(null);
    const [total, setTotal] = useState(0);
    // Remembered, with the filters below: coming back to the trail lands on the page and the filters
    // it was left on. A page past the end is clamped by the server, as it already is after a filter.
    const { page, pageSize, setPage, setPageSize } = useRememberedPaging('audit');
    /*
     * The window this run of paging is frozen to.
     *
     * The trail is append-only and grows while it is being read, so page 2 of a list that gained a
     * row is not page 2 of the list page 1 came from. The server pins both the total and every page
     * to the newest event at the moment the filters were applied, and hands that bound back here to
     * be echoed with each page; changing a filter drops it and a fresh window is taken.
     */
    const anchor = useRef<string | null>(null);
    const [forbidden, setForbidden] = useState(false);
    const [selected, setSelected] = useState<AuditEvent | null>(null);
    /*
     * The event last opened, kept after its details close: the row stays marked so the list says where
     * the reader was. Marking only the open one meant the mark was never seen — while the details are
     * open they cover the list, and closing them took the mark away.
     */
    const [lastOpenedId, setLastOpenedId] = useState<string | null>(null);

    const [search, setSearch] = useRememberedState<string>('audit.search', '');
    const [eventType, setEventType] = useRememberedState<string>('audit.eventType', '');
    const [range, setRange] = useRememberedState<string>('audit.range', 'h24', {
        isValid: (value) => RANGES.some((entry) => entry.key === value),
    });

    const describeFailure = useCallback((cause: unknown): string => describeApiFailure(cause, t), [t]);

    const sinceFor = useCallback((key: string): string | undefined => {
        const hours = RANGES.find((entry) => entry.key === key)?.hours ?? null;

        return hours === null ? undefined : new Date(Date.now() - hours * 3600_000).toISOString();
    }, []);

    // Refetch whenever a filter or the page changes. Debounced on the search box only, since
    // typing there would otherwise issue a request per keystroke.
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
                    ...(anchor.current === null ? {} : { anchor: anchor.current }),
                    page,
                    perPage: pageSize,
                })
                    .then((loaded) => {
                        if (!active) {
                            return;
                        }

                        anchor.current = loaded.anchor;
                        setEvents(loaded.events);
                        setTotal(loaded.total);
                        // The server clamps a page number past the end back into range, so a page
                        // that emptied out lands on the last one that still has rows.
                        setPage(loaded.page);
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
                        setTotal(0);
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
    }, [search, eventType, range, page, pageSize, setPage, sinceFor, describeFailure, t]);

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

    /**
     * Changing a filter starts a new window: the frozen bound and the page number both belong to
     * the list that was being read, and neither describes the list that replaces it.
     */
    const filter = (apply: () => void): void => {
        apply();
        anchor.current = null;
        setPage(1);
    };

    if (forbidden) {
        // Just the body: the frame around it comes from the default export, so a refusal still
        // arrives with the heading that says what was refused and the navigation to leave by.
        return <p className="note p-4">{t((d) => d.pages.adminAudit.forbidden)}</p>;
    }

    return (
        <>
            <div className="page-stack">
                {summary !== null && !summary.reachesTaxpayers && (
                    <p className="note">{t((d) => d.pages.adminAudit.taxpayerLinesHidden)}</p>
                )}
                <StatGrid>
                    <Stat
                        label={t((d) => d.pages.adminAudit.stats.total)}
                        value={summary === null ? '—' : formatNumber(summary.eventsTotal, locale)}
                        icon={<ScrollText className="size-3.5" aria-hidden="true" />}
                    />
                    <Stat
                        label={t((d) => d.pages.adminAudit.stats.last24h)}
                        value={summary === null ? '—' : formatNumber(summary.events24h, locale)}
                        icon={<Clock className="size-3.5" aria-hidden="true" />}
                    />
                    <Stat
                        label={t((d) => d.pages.adminAudit.stats.actors)}
                        value={summary === null ? '—' : formatNumber(summary.actors24h, locale)}
                        sub={t((d) => d.pages.adminAudit.stats.actorsSub)}
                        icon={<UsersRound className="size-3.5" aria-hidden="true" />}
                    />
                    <Stat
                        label={t((d) => d.pages.adminAudit.stats.integrity)}
                        /* The one tile that reports a verdict rather than a count, so it is the one
                           that takes a tone — and `destructive` colours the figure too, which is
                           what a broken chain should do. */
                        icon={<ShieldCheck className="size-3.5" aria-hidden="true" />}
                        tone={summary === null ? 'neutral' : summary.integrity.ok ? 'success' : 'destructive'}
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
                                      .replace('{events}', formatNumber(summary.integrity.eventsVerified, locale))
                                      .replace('{chains}', String(summary.integrity.chainsChecked))
                        }
                    />
                </StatGrid>

                <Panel
                    title={t((d) => d.pages.adminAudit.tableTitle)}
                    description={t((d) => d.pages.adminAudit.tableDescription)}
                    icon={<ScrollTextIcon className="size-4" />}
                    flush
                    foot={
                        <Pagination
                            page={page}
                            pageSize={pageSize}
                            total={total}
                            unit={t((d) => d.pages.adminAudit.unit)}
                            onPageChange={setPage}
                            onPageSizeChange={(size) => {
                                // A different page size redraws the same window from the top: page
                                // 3 of 25 is not page 3 of 100.
                                setPageSize(size);
                                setPage(1);
                            }}
                        />
                    }
                >
                    <Toolbar>
                        <SearchInput
                            value={search}
                            placeholder={t((d) => d.pages.adminAudit.searchPlaceholder)}
                            onChange={(next) => {
                                filter(() => {
                                    setSearch(next);
                                });
                            }}
                        />
                        <PanelSelect
                            className="filter-select"
                            icon={<Shapes className="size-3.5" aria-hidden="true" />}
                            value={eventType}
                            onValueChange={(next) => {
                                filter(() => {
                                    setEventType(next);
                                });
                            }}
                            placeholder={t((d) => d.pages.adminAudit.allEvents)}
                            emptyLabel={t((d) => d.pages.adminAudit.allEvents)}
                            options={(summary?.eventTypes ?? []).map((type) => ({ value: type, label: type }))}
                        />
                        <PanelSelect
                            className="filter-select"
                            icon={<CalendarRange className="size-3.5" aria-hidden="true" />}
                            value={range}
                            onValueChange={(next) => {
                                filter(() => {
                                    setRange(next);
                                });
                            }}
                            options={RANGES.map((entry) => ({
                                value: entry.key,
                                label: t((d) => d.pages.adminAudit.ranges[entry.key] ?? entry.key),
                            }))}
                        />
                        <PanelButton
                            onClick={() => {
                                filter(() => {
                                    setSearch('');
                                    setEventType('');
                                    setRange('h24');
                                });
                            }}
                        >
                            <RotateCcw className="size-3.5" aria-hidden="true" />
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
                        <EmptyState
                            icon={<ScrollText className="size-5" />}
                            title={t((d) => d.pages.adminAudit.empty)}
                        />
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
                                <tr key={event.id} className={cn(lastOpenedId === event.id && 'row-selected')}>
                                    {/* Column roles for the stacked phone layout — see `.data-table-stack`
                                        in app.css. Time leads the row; the actor is the one column that
                                        is nothing but a UUID, so it is the one a phone drops — the
                                        drawer shows it in full. */}
                                    <td className="col-primary whitespace-nowrap">
                                        <EventTime iso={event.occurredAt} />
                                    </td>
                                    <td className="font-semibold">
                                        <CellLabel icon={<Shapes className="size-3.5" aria-hidden="true" />}>
                                            {t((d) => d.pages.adminAudit.columns.event)}
                                        </CellLabel>
                                        {event.eventType}
                                    </td>
                                    {/* What was touched, named rather than numbered. A row of
                                        `01a11aba-2a3b-70d1-…` is unreadable and nothing is scanned by
                                        it; the word is what you look for, and the id is one hover away
                                        for when you need to match it against something. */}
                                    <td>
                                        <CellLabel icon={<Box className="size-3.5" aria-hidden="true" />}>
                                            {t((d) => d.pages.adminAudit.columns.resource)}
                                        </CellLabel>
                                        {event.resourceId === null ? (
                                            event.resourceType
                                        ) : (
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <span className="cursor-help underline decoration-dotted underline-offset-4">
                                                        {event.resourceType}
                                                    </span>
                                                </TooltipTrigger>
                                                <TooltipContent className="mono">{event.resourceId}</TooltipContent>
                                            </Tooltip>
                                        )}
                                    </td>
                                    <td className="col-hide">
                                        {event.actorId === null ? (
                                            <Pill>{t((d) => d.pages.adminAudit.systemActor)}</Pill>
                                        ) : (
                                            <span className="mono">{event.actorId}</span>
                                        )}
                                    </td>
                                    <td className="tabular-nums">
                                        <CellLabel icon={<Hash className="size-3.5" aria-hidden="true" />}>
                                            {t((d) => d.pages.adminAudit.columns.sequence)}
                                        </CellLabel>
                                        {event.sequence}
                                    </td>
                                    {/* The card's last line rather than its corner: `.col-end` reserves
                                        80px, which a labelled button with an icon no longer fits, and
                                        the three screens in this corner of the application should not
                                        each place their row actions somewhere different. */}
                                    <td className="col-actions text-right">
                                        <PanelButton
                                            onClick={() => {
                                                setSelected(event);
                                                setLastOpenedId(event.id);
                                            }}
                                        >
                                            <Eye className="size-3.5" aria-hidden="true" />
                                            {t((d) => d.pages.adminAudit.details)}
                                        </PanelButton>
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    )}
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
                                {/* The exact instant, not the relative phrase the list carries: the
                                    drawer is where a chain is checked against something else. */}
                                <Detail label={t((d) => d.pages.adminAudit.columns.time)}>
                                    {formatDateTime(selected.occurredAt, locale)}
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
        </>
    );
}

/**
 * The screen, framed.
 *
 * Its own page rather than one of three tabs: these three records were kept together because the
 * question "what happened" does not say which table holds the answer, but two of them answered it
 * by *doing* something — retrying a job, running a task — and one is a read-only chain of evidence.
 * They are in the navigation now, where a person can see all three without opening any.
 */
export default function Audit(): JSX.Element {
    const { t } = useTranslation();

    return (
        <AppShell>
            <Page title={t((d) => d.pages.adminAudit.title)} lede={t((d) => d.pages.adminAudit.description)}>
                <AdminAuditBody />
            </Page>
        </AppShell>
    );
}
