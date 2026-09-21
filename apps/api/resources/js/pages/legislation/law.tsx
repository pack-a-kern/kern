import { Head, router } from '@inertiajs/react';
import { ArrowLeft, History, Scale } from 'lucide-react';
import { useEffect, useState, type JSX } from 'react';

import { getLaw, getLawText, getProvisionHistory } from '../../api/legislationApi';
import AppShell from '../../components/AppShell';
import Pagination from '../../components/Pagination';
import { PanelDate } from '../../components/PanelDatePicker';
import { Detail, DetailList, Field, Page, Panel, PanelButton, PanelDialog, Pill, Status } from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { formatDate } from '../../lib/format';
import { ApiError } from '../../lib/http';
import { lines, withTables } from '../../lib/lawText';
import { cn } from '../../lib/utils';
import { wordDiff } from '../../lib/wordDiff';
import type { LawDetail, LawSection, LawTable, LawText, ProvisionVersion } from '../../types/legislation';

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

function initialAsOf(): string {
    const requested = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('as_of');

    return requested !== null && /^\d{4}-\d{2}-\d{2}$/.test(requested) ? requested : today();
}

/**
 * One law, as it stood on a chosen date.
 *
 * The date decides which consolidation is shown — each applies from its own date until the day before
 * the next — and the page never shows the nearest text for a date no consolidation covers: it says
 * so. Paragraphs keep their line breaks and tables are tables, because a bracket of the income tax
 * read as one run-on line cannot be told from its neighbour. Each provision opens its own history.
 */
export default function LegislationLaw({ lawId }: { lawId: string }): JSX.Element {
    const { t, locale } = useTranslation();
    const [law, setLaw] = useState<LawDetail | null>(null);
    const [lawFailed, setLawFailed] = useState(false);
    const [asOf, setAsOf] = useState(initialAsOf);
    const [page, setPage] = useState(1);
    // Keyed by what was asked, so a change of date or page reads as loading without an effect having
    // to reset anything: the answer on screen belongs to the question until the next one arrives.
    const textKey = `${asOf}|${String(page)}`;
    const [answer, setAnswer] = useState<{ key: string; text: LawText | null; state: 'ready' | 'none' | 'failed' } | null>(
        null,
    );
    const textState = answer === null || answer.key !== textKey ? 'loading' : answer.state;
    const text = answer?.text ?? null;
    const [historyOf, setHistoryOf] = useState<LawSection | null>(null);

    useEffect(() => {
        let active = true;

        getLaw(lawId).then(
            (loaded) => {
                if (active) {
                    setLaw(loaded);
                }
            },
            () => {
                if (active) {
                    setLawFailed(true);
                }
            },
        );

        return () => {
            active = false;
        };
    }, [lawId]);

    useEffect(() => {
        let active = true;
        const key = `${asOf}|${String(page)}`;

        getLawText(lawId, asOf, page).then(
            (loaded) => {
                if (active) {
                    setAnswer({ key, text: loaded, state: 'ready' });
                }
            },
            (cause: unknown) => {
                if (active) {
                    setAnswer({
                        key,
                        text: null,
                        state: cause instanceof ApiError && cause.status === 404 ? 'none' : 'failed',
                    });
                }
            },
        );

        return () => {
            active = false;
        };
    }, [lawId, asOf, page]);

    const date = (value: string | null): string => (value === null ? '—' : formatDate(value, locale));

    const changeDate = (next: string): void => {
        setAsOf(next);
        setPage(1);
    };

    const title = law?.title ?? t((d) => d.pages.legislation.title);

    return (
        <AppShell>
            <Head title={title} />
            <Page title={title} {...(law?.issuer == null ? {} : { lede: law.issuer })}>
                <Panel
                    title={t((d) => d.pages.legislation.laws.title)}
                    icon={<Scale className="size-4" />}
                    aside={
                        <PanelButton
                            onClick={() => {
                                router.visit('/legislation');
                            }}
                        >
                            <ArrowLeft className="size-3.5" /> {t((d) => d.pages.legislation.law.back)}
                        </PanelButton>
                    }
                >
                    {lawFailed ? (
                        <p className="note">{t((d) => d.pages.legislation.law.loadFailed)}</p>
                    ) : law === null ? (
                        <Skeleton className="tile h-16 w-full" />
                    ) : (
                        <>
                            <div className="flex flex-wrap gap-1.5">
                                {law.typeLabel === null ? null : <Pill tone="accent">{law.typeLabel}</Pill>}
                                {law.number === null ? null : <Pill>nr. {law.number}</Pill>}
                                {law.officialJournalNumber === null ? null : (
                                    <Pill tone="locked">
                                        {t((d) => d.pages.legislation.laws.journal).replace(
                                            '{number}',
                                            law.officialJournalNumber,
                                        )}
                                    </Pill>
                                )}
                            </div>
                            <DetailList>
                                <Detail label={t((d) => d.pages.legislation.laws.actDate)}>{date(law.actDate)}</Detail>
                                <Detail label={t((d) => d.pages.legislation.laws.publishedOn)}>
                                    {date(law.publishedOn)}
                                </Detail>
                                <Detail label={t((d) => d.pages.legislation.laws.inForceFrom)}>
                                    {date(law.inForceFrom)}
                                </Detail>
                            </DetailList>
                        </>
                    )}
                </Panel>

                <Panel
                    title={t((d) => d.pages.legislation.law.textTitle)}
                    foot={
                        text === null ? undefined : (
                            <Pagination
                                page={text.page}
                                pageSize={text.perPage}
                                total={text.total}
                                onPageChange={setPage}
                                unit={t((d) => d.pages.legislation.law.unit)}
                            />
                        )
                    }
                >
                    <div className="flex flex-wrap items-end gap-4">
                        <Field
                            label={t((d) => d.pages.legislation.law.on)}
                            hint={t((d) => d.pages.legislation.law.onHint)}
                            htmlFor="law-as-of"
                            className="max-w-xs"
                        >
                            <PanelDate
                                value={asOf}
                                onChange={(next) => {
                                    changeDate(next ?? today());
                                }}
                            />
                        </Field>
                        {text === null ? null : (
                            <p className="note pb-1">
                                {(text.effectiveUntil === null
                                    ? t((d) => d.pages.legislation.law.versionOpen)
                                    : t((d) => d.pages.legislation.law.versionClosed)
                                )
                                    .replace('{from}', date(text.effectiveFrom))
                                    .replace('{until}', date(text.effectiveUntil))}
                            </p>
                        )}
                    </div>

                    <div className="mt-4">
                        {textState === 'loading' ? (
                            <div className="space-y-2">
                                {[0, 1, 2].map((index) => (
                                    <Skeleton key={index} className="tile h-16 w-full" />
                                ))}
                            </div>
                        ) : textState === 'none' ? (
                            <p className="note">
                                {t((d) => d.pages.legislation.law.noVersion).replace('{date}', date(asOf))}
                            </p>
                        ) : textState === 'failed' || text === null ? (
                            <p className="note">{t((d) => d.pages.legislation.law.loadFailed)}</p>
                        ) : (
                            <div className="space-y-1">
                                {text.sections.map((section) => (
                                    <Provision key={section.id} section={section} onHistory={setHistoryOf} />
                                ))}
                            </div>
                        )}
                    </div>
                </Panel>

                {law === null ? null : <Consolidations law={law} onView={changeDate} />}
            </Page>

            <ProvisionHistory
                lawId={lawId}
                section={historyOf}
                onClose={() => {
                    setHistoryOf(null);
                }}
            />
        </AppShell>
    );
}

/** One provision where it stands: its heading, its lines, its tables, and a way to its history. */
function Provision({ section, onHistory }: { section: LawSection; onHistory: (section: LawSection) => void }): JSX.Element {
    const { t, locale } = useTranslation();
    const isArticle = section.type === 'ARTICOL' || section.type === 'ANEXA';

    return (
        <section
            className={cn('group', isArticle ? 'border-border mt-4 border-t pt-3' : 'py-0.5')}
            style={{ paddingLeft: `${String(Math.min(section.depth, 4) * 1.25)}rem` }}
        >
            <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1 text-sm leading-relaxed">
                    <span className={cn(isArticle ? 'block font-semibold' : 'mr-1.5 font-medium')}>{section.label}</span>
                    <SectionBody text={section.text} tables={section.tables} />
                </div>
                <button
                    type="button"
                    className="text-muted-foreground hover:text-foreground shrink-0 rounded p-1 opacity-60 group-hover:opacity-100 focus-visible:opacity-100"
                    title={t((d) => d.pages.legislation.law.history)}
                    aria-label={`${t((d) => d.pages.legislation.law.history)} — ${section.citation}`}
                    onClick={() => {
                        onHistory(section);
                    }}
                >
                    <History className="size-3.5" />
                </button>
            </div>
            {section.amendedOn === null ? null : (
                <p className="note">
                    {t((d) => d.pages.legislation.law.amended).replace('{date}', formatDate(section.amendedOn, locale))}
                </p>
            )}
        </section>
    );
}

function SectionBody({ text, tables }: { text: string; tables: LawTable[] }): JSX.Element {
    return (
        <>
            {lines(text, tables).map((line, index) =>
                line.kind === 'table' ? (
                    <LawTableView key={index} table={line.table} />
                ) : (
                    <p key={index} className={cn(line.kind === 'note' && 'text-muted-foreground text-xs italic')}>
                        {line.text}
                    </p>
                ),
            )}
        </>
    );
}

/** A table of the law, cell for cell. The first row is its heading, as the portal draws it. */
function LawTableView({ table }: { table: LawTable }): JSX.Element {
    const [head, ...body] = table;

    return (
        <div className="table-wrap my-2">
            <table className="data-table">
                {head === undefined ? null : (
                    <thead>
                        <tr>
                            {head.map((cell, index) => (
                                <th key={index}>{cell}</th>
                            ))}
                        </tr>
                    </thead>
                )}
                <tbody>
                    {body.map((row, rowIndex) => (
                        <tr key={rowIndex}>
                            {row.map((cell, index) => (
                                <td key={index} className="tabular-nums">
                                    {cell}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** Every consolidation the portal lists, newest first, and whether it has been read. */
function Consolidations({ law, onView }: { law: LawDetail; onView: (date: string) => void }): JSX.Element {
    const { t, locale } = useTranslation();

    return (
        <Panel
            title={t((d) => d.pages.legislation.law.consolidationsTitle)}
            description={t((d) => d.pages.legislation.law.consolidationsHint)}
            flush
        >
            <div className="table-wrap">
                <table className="data-table">
                    <tbody>
                        {law.consolidations.map((consolidation) => (
                            <tr key={consolidation.ref}>
                                <td className="tabular-nums">
                                    {formatDate(consolidation.date, locale)}
                                    {consolidation.effectiveUntil === null
                                        ? ''
                                        : ` – ${formatDate(consolidation.effectiveUntil, locale)}`}
                                </td>
                                <td>
                                    {consolidation.failed ? (
                                        <Status tone="bad">{t((d) => d.pages.legislation.law.failed)}</Status>
                                    ) : consolidation.versionId === null ? (
                                        <Status tone="idle">{t((d) => d.pages.legislation.law.notRead)}</Status>
                                    ) : (
                                        <Status tone="ok">
                                            {t((d) => d.pages.legislation.law.sections).replace(
                                                '{count}',
                                                String(consolidation.sectionCount ?? 0),
                                            )}
                                        </Status>
                                    )}
                                </td>
                                <td className="text-right whitespace-nowrap">
                                    {consolidation.versionId === null ? null : (
                                        <PanelButton
                                            onClick={() => {
                                                onView(consolidation.date);
                                            }}
                                        >
                                            {t((d) => d.pages.legislation.law.viewOn)}
                                        </PanelButton>
                                    )}{' '}
                                    <a
                                        className="text-info text-xs underline underline-offset-2"
                                        href={consolidation.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        {t((d) => d.pages.legislation.law.onPortal)}
                                    </a>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Panel>
    );
}

/** Each wording the provision has had, newest first, with what changed from the one before. */
function ProvisionHistory({
    lawId,
    section,
    onClose,
}: {
    lawId: string;
    section: LawSection | null;
    onClose: () => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const citation = section?.citation ?? null;
    const [answer, setAnswer] = useState<{ citation: string; versions: ProvisionVersion[] | null } | null>(null);
    const current = answer !== null && answer.citation === citation ? answer : null;
    const versions = current?.versions ?? null;
    const failed = current !== null && current.versions === null;

    useEffect(() => {
        if (citation === null) {
            return;
        }

        let active = true;

        getProvisionHistory(lawId, citation).then(
            (loaded) => {
                if (active) {
                    setAnswer({ citation, versions: loaded });
                }
            },
            () => {
                if (active) {
                    setAnswer({ citation, versions: null });
                }
            },
        );

        return () => {
            active = false;
        };
    }, [lawId, citation]);

    const date = (value: string): string => formatDate(value, locale);

    return (
        <PanelDialog
            open={section !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
            size="lg"
            title={t((d) => d.pages.legislation.law.historyTitle).replace('{citation}', section?.citation ?? '')}
            description={t((d) => d.pages.legislation.law.historyHint)}
            actions={<PanelButton onClick={onClose}>{t((d) => d.pages.legislation.law.close)}</PanelButton>}
        >
            {failed ? (
                <p className="note">{t((d) => d.pages.legislation.law.historyFailed)}</p>
            ) : versions === null ? (
                <Skeleton className="tile h-24 w-full" />
            ) : versions.length === 0 ? (
                <p className="note">{t((d) => d.pages.legislation.law.historyEmpty)}</p>
            ) : (
                <div className="modal-scroll space-y-4">
                    {versions.map((version, index) => {
                        const previous = versions[index + 1];

                        return (
                            <article key={version.versionIndex} className="tile border-border border p-3">
                                <div className="flex flex-wrap items-center gap-2 text-xs font-semibold tabular-nums">
                                    {(version.validUntil === null
                                        ? t((d) => d.pages.legislation.law.heldOpen)
                                        : t((d) => d.pages.legislation.law.heldClosed)
                                    )
                                        .replace('{from}', date(version.validFrom))
                                        .replace('{until}', version.validUntil === null ? '' : date(version.validUntil))}
                                    {version.amendedOn === null ? null : (
                                        <Pill>
                                            {t((d) => d.pages.legislation.law.amended).replace(
                                                '{date}',
                                                date(version.amendedOn),
                                            )}
                                        </Pill>
                                    )}
                                </div>
                                {version.coverageComplete ? null : (
                                    <p className="mt-1">
                                        <Status tone="warn">{t((d) => d.pages.legislation.law.coverageGap)}</Status>
                                    </p>
                                )}
                                <p className="note mt-2">
                                    {previous === undefined
                                        ? t((d) => d.pages.legislation.law.firstVersion)
                                        : t((d) => d.pages.legislation.law.changes)}
                                </p>
                                {previous === undefined ? (
                                    <div className="mt-1 text-sm leading-relaxed">
                                        <SectionBody text={version.text} tables={version.tables} />
                                    </div>
                                ) : (
                                    <p className="mt-1 text-sm leading-relaxed whitespace-pre-line">
                                        {wordDiff(
                                            withTables(previous.text, previous.tables),
                                            withTables(version.text, version.tables),
                                        ).map((part, partIndex) =>
                                            part.kind === 'same' ? (
                                                <span key={partIndex}>{part.text}</span>
                                            ) : part.kind === 'added' ? (
                                                <ins key={partIndex} className="bg-emerald-500/15 no-underline">
                                                    {part.text}
                                                </ins>
                                            ) : (
                                                <del key={partIndex} className="bg-destructive/10 text-muted-foreground">
                                                    {part.text}
                                                </del>
                                            ),
                                        )}
                                    </p>
                                )}
                            </article>
                        );
                    })}
                </div>
            )}
        </PanelDialog>
    );
}
