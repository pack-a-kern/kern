import { Head, Link } from '@inertiajs/react';
import {
    CalendarDays,
    ChevronRight,
    CircleCheck,
    ExternalLink,
    History,
    Link2,
    ListTree,
    Newspaper,
    Search,
} from 'lucide-react';
import { useEffect, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { getLaw, getLawOutline, getLawText } from '../../api/legislationApi';
import AppShell from '../../components/AppShell';
import Pagination from '../../components/Pagination';
import { Page, PanelButton, PanelSelect, SearchInput } from '../../components/panel';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { formatDate } from '../../lib/format';
import { ApiError } from '../../lib/http';
import { anchorOf, foldForFind } from '../../lib/lawText';
import { cn } from '../../lib/utils';
import type { LawDetail, LawHeading, LawOutline, LawSection, LawText } from '../../types/legislation';
import LawArticle from './LawArticle';
import LawContents from './LawContents';
import LawVersions from './LawVersions';
import ProvisionHistory from './ProvisionHistory';

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

function initialAsOf(): string {
    const requested = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('as_of');

    return requested !== null && /^\d{4}-\d{2}-\d{2}$/.test(requested) ? requested : today();
}

/** An article with everything under it, as the page shows it: one card. */
interface ArticleGroup {
    article: LawSection;
    provisions: LawSection[];
}

/** The page's provisions as articles: a new one at each provision of depth 0. */
function articlesOf(sections: LawSection[]): ArticleGroup[] {
    const groups: ArticleGroup[] = [];

    for (const section of sections) {
        const last = groups.at(-1);

        if (section.depth === 0 || last === undefined) {
            groups.push({ article: section, provisions: [] });
        } else {
            last.provisions.push(section);
        }
    }

    return groups;
}

/** Whether an article, or anything under it, holds every word looked for. */
function holds(group: ArticleGroup, words: string[]): boolean {
    const text = foldForFind(
        [group.article, ...group.provisions]
            .map((section) => `${section.citation} ${section.label} ${section.text}`)
            .join(' '),
    );

    return words.every((word) => text.includes(word));
}

/**
 * One law, as it stood on a chosen date.
 *
 * The date decides which consolidation is shown — each applies from its own date until the day before
 * the next — and the page never shows the nearest text for a date no consolidation covers: it says
 * so. Read like a book: its contents to the side, one card per article with its paragraphs on their
 * own labels, the version shown and its source at hand, every other version in a panel beside it.
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
    const [answer, setAnswer] = useState<{
        key: string;
        text: LawText | null;
        state: 'ready' | 'none' | 'failed';
    } | null>(null);
    const textState = answer === null || answer.key !== textKey ? 'loading' : answer.state;
    const text = answer?.text ?? null;
    const [historyOf, setHistoryOf] = useState<LawSection | null>(null);
    const [outline, setOutline] = useState<{ asOf: string; outline: LawOutline | null } | null>(null);
    const [find, setFind] = useState('');
    const [versionsOpen, setVersionsOpen] = useState(false);
    const [sheet, setSheet] = useState<'contents' | 'find' | null>(null);

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

    useEffect(() => {
        let active = true;

        getLawOutline(lawId, asOf).then(
            (loaded) => {
                if (active) {
                    setOutline({ asOf, outline: loaded });
                }
            },
            () => {
                if (active) {
                    setOutline({ asOf, outline: null });
                }
            },
        );

        return () => {
            active = false;
        };
    }, [lawId, asOf]);

    // A link to an article opens at it, once its page is on screen.
    useEffect(() => {
        if (textState === 'ready' && window.location.hash !== '') {
            document.getElementById(window.location.hash.slice(1))?.scrollIntoView({ block: 'start' });
        }
    }, [textState]);

    const date = (value: string | null): string => (value === null ? '—' : formatDate(value, locale));

    const goToPage = (next: number): void => {
        setPage(next);
        setSheet(null);
        document.getElementById('law-reader')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const changeDate = (next: string): void => {
        setAsOf(next);
        setPage(1);
    };

    const copyLink = (section: LawSection | null): void => {
        const url = new URL(window.location.href);
        url.searchParams.set('as_of', text?.effectiveFrom ?? asOf);
        url.hash = section === null ? '' : anchorOf(section);

        navigator.clipboard.writeText(url.toString()).then(
            () => {
                toast.success(t((d) => d.pages.legislation.law.linkCopied));
            },
            () => {
                toast.error(t((d) => d.errors.unexpected));
            },
        );
    };

    const jump = (section: LawSection): void => {
        setSheet(null);
        document.getElementById(anchorOf(section))?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const articles = text === null ? [] : articlesOf(text.sections);
    const words = foldForFind(find)
        .split(/\s+/u)
        .filter((word) => word !== '');
    const found = words.length === 0 ? articles : articles.filter((group) => holds(group, words));
    const current = new Set((articles[0]?.article.headings ?? []).map((heading) => heading.id));
    const readVersions =
        law === null ? [] : law.consolidations.filter((consolidation) => consolidation.versionId !== null);
    const shown = law?.consolidations.find((consolidation) => consolidation.date === text?.effectiveFrom) ?? null;
    const shownIndex = shown === null ? -1 : readVersions.indexOf(shown);
    const shortName =
        law === null
            ? t((d) => d.pages.legislation.title)
            : [law.typeLabel, law.number === null ? null : `nr. ${law.number}`].filter(Boolean).join(' ');
    const period = (from: string, until: string | null): string =>
        until === null
            ? `${date(from)} — ${t((d) => d.pages.legislation.law.current)}`
            : `${date(from)} – ${date(until)}`;
    const findCount = t((d) => d.pages.legislation.law.findCount)
        .replace('{shown}', String(found.length))
        .replace('{total}', String(articles.length));

    return (
        <AppShell>
            <Head title={law?.title ?? shortName} />
            <Page title={shortName} lede={t((d) => d.pages.legislation.law.reader)}>
                {/* The law's heading stays in sight while its text scrolls under it. */}
                <header className="panel panel-pad sticky -top-3 z-20 sm:-top-5">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                            <nav className="text-muted-foreground mb-1.5 flex items-center gap-1.5 text-xs">
                                <Link href="/legislation" className="text-info">
                                    {t((d) => d.pages.legislation.title)}
                                </Link>
                                <ChevronRight className="size-3" />
                                <span className="truncate">{shortName}</span>
                            </nav>
                            {lawFailed ? (
                                <p className="note">{t((d) => d.pages.legislation.law.loadFailed)}</p>
                            ) : law === null ? (
                                <Skeleton className="h-5 w-80 max-w-full" />
                            ) : (
                                <>
                                    <h2 className="line-clamp-2 text-base font-semibold text-balance">{law.title}</h2>
                                    <div className="text-muted-foreground mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs">
                                        {law.actDate === null ? null : (
                                            <span className="inline-flex items-center gap-1">
                                                <CalendarDays className="size-3.5" />
                                                {date(law.actDate)}
                                            </span>
                                        )}
                                        {law.officialJournalNumber === null ? null : (
                                            <span className="inline-flex items-center gap-1">
                                                <Newspaper className="size-3.5" />
                                                {t((d) => d.pages.legislation.laws.journal).replace(
                                                    '{number}',
                                                    law.officialJournalNumber,
                                                )}
                                            </span>
                                        )}
                                        {text === null ? null : (
                                            <span
                                                className={cn(
                                                    'inline-flex items-center gap-1 font-semibold',
                                                    text.effectiveUntil === null &&
                                                        'text-emerald-700 dark:text-emerald-400',
                                                )}
                                            >
                                                <CircleCheck className="size-3.5" />
                                                {t((d) => d.pages.legislation.law.consolidatedOn).replace(
                                                    '{period}',
                                                    period(text.effectiveFrom, text.effectiveUntil),
                                                )}
                                            </span>
                                        )}
                                    </div>
                                </>
                            )}
                        </div>
                        <div className="hidden gap-2 sm:flex">
                            <PanelButton
                                onClick={() => {
                                    copyLink(null);
                                }}
                            >
                                <Link2 className="size-3.5" />
                                {t((d) => d.pages.legislation.law.copyLink)}
                            </PanelButton>
                            <PanelButton
                                disabled={law === null}
                                onClick={() => {
                                    setVersionsOpen(true);
                                }}
                            >
                                <History className="size-3.5" />
                                {t((d) => d.pages.legislation.law.versions)}
                            </PanelButton>
                        </div>
                    </div>

                    <div className="mt-3 hidden gap-2 lg:grid lg:grid-cols-[minmax(0,1fr)_16rem] lg:items-center">
                        <div className="flex items-center gap-2">
                            <div className="min-w-0 flex-1">
                                <SearchInput
                                    value={find}
                                    placeholder={t((d) => d.pages.legislation.law.findPlaceholder)}
                                    onChange={setFind}
                                />
                            </div>
                            <span className="note shrink-0 tabular-nums">{findCount}</span>
                        </div>
                        <PanelSelect
                            value={shown?.date ?? ''}
                            onValueChange={changeDate}
                            placeholder={t((d) => d.pages.legislation.law.version)}
                            options={readVersions.map((consolidation) => ({
                                value: consolidation.date,
                                label: period(consolidation.date, consolidation.effectiveUntil),
                            }))}
                        />
                    </div>
                </header>

                <div
                    id="law-reader"
                    className="grid scroll-mt-44 items-start gap-3 pb-24 lg:grid-cols-[16rem_minmax(0,1fr)] lg:pb-0 xl:grid-cols-[17rem_minmax(0,1fr)_15rem]"
                >
                    <aside className="panel panel-pad-sm reader-rail hidden lg:block">
                        <p className="eyebrow px-2 pt-1 pb-2">{t((d) => d.pages.legislation.law.contentsTitle)}</p>
                        {outline === null || outline.asOf !== asOf ? (
                            <Skeleton className="tile h-40 w-full" />
                        ) : outline.outline === null ? (
                            <p className="note px-2">{t((d) => d.pages.legislation.law.contentsEmpty)}</p>
                        ) : (
                            <LawContents outline={outline.outline} current={current} onOpen={goToPage} />
                        )}
                    </aside>

                    <section className="flex min-w-0 flex-col gap-2.5">
                        {textState === 'loading' ? (
                            [0, 1, 2].map((index) => <Skeleton key={index} className="tile h-32 w-full" />)
                        ) : textState === 'none' ? (
                            <p className="panel panel-pad note">
                                {t((d) => d.pages.legislation.law.noVersion).replace('{date}', date(asOf))}
                            </p>
                        ) : textState === 'failed' || text === null ? (
                            <p className="panel panel-pad note">{t((d) => d.pages.legislation.law.loadFailed)}</p>
                        ) : found.length === 0 ? (
                            <p className="panel panel-pad note">{t((d) => d.pages.legislation.law.findNone)}</p>
                        ) : (
                            found.map((group, index) => (
                                <div key={group.article.id} className="flex flex-col gap-2.5">
                                    <Headings
                                        chain={group.article.headings}
                                        before={index === 0 ? [] : (found[index - 1]?.article.headings ?? [])}
                                    />
                                    <LawArticle
                                        article={group.article}
                                        provisions={group.provisions}
                                        onHistory={setHistoryOf}
                                        onCopyLink={copyLink}
                                    />
                                </div>
                            ))
                        )}
                        {text === null ? null : (
                            <div className="panel panel-pad-sm">
                                <Pagination
                                    page={text.page}
                                    pageSize={text.perPage}
                                    total={text.total}
                                    onPageChange={goToPage}
                                    unit={t((d) => d.pages.legislation.law.unit)}
                                />
                            </div>
                        )}
                    </section>

                    <aside className="reader-rail hidden flex-col gap-2.5 xl:flex">
                        <div className="panel panel-pad-sm">
                            <h4 className="mb-1.5 text-xs font-semibold">
                                {t((d) => d.pages.legislation.law.onThisPage)}
                            </h4>
                            <div className="max-h-64 overflow-y-auto">
                                {found.map((group) => (
                                    <button
                                        key={group.article.id}
                                        type="button"
                                        className="border-border text-muted-foreground hover:text-foreground flex w-full justify-between gap-2 border-t py-1.5 text-left text-xs first:border-t-0"
                                        onClick={() => {
                                            jump(group.article);
                                        }}
                                    >
                                        <span className="text-foreground font-semibold whitespace-nowrap">
                                            {group.article.citation}
                                        </span>
                                        <span className="truncate">
                                            {group.provisions.length > 0 ? group.article.text : ''}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        </div>
                        {law === null ? null : (
                            <div className="panel panel-pad-sm">
                                <h4 className="mb-1.5 text-xs font-semibold">
                                    {t((d) => d.pages.legislation.law.thisVersion)}
                                </h4>
                                {readVersions
                                    .slice(Math.max(shownIndex, 0), Math.max(shownIndex, 0) + 3)
                                    .map((consolidation) => (
                                        <div
                                            key={consolidation.ref}
                                            className="grid grid-cols-[0.625rem_minmax(0,1fr)] gap-2 py-1"
                                        >
                                            <span
                                                className={cn(
                                                    'mt-1.5 size-1.5 rounded-full',
                                                    consolidation === shown
                                                        ? 'bg-emerald-500'
                                                        : 'bg-muted-foreground/30',
                                                )}
                                            />
                                            <div>
                                                <p className="text-xs font-semibold tabular-nums">
                                                    {period(consolidation.date, consolidation.effectiveUntil)}
                                                </p>
                                                <p className="note">
                                                    {t((d) => d.pages.legislation.law.sections).replace(
                                                        '{count}',
                                                        String(consolidation.sectionCount ?? 0),
                                                    )}
                                                </p>
                                            </div>
                                        </div>
                                    ))}
                                <PanelButton
                                    className="mt-2 w-full"
                                    onClick={() => {
                                        setVersionsOpen(true);
                                    }}
                                >
                                    {t((d) => d.pages.legislation.law.allVersions)}
                                </PanelButton>
                            </div>
                        )}
                        {shown === null ? null : (
                            <div className="panel panel-pad-sm">
                                <h4 className="mb-1 text-xs font-semibold">
                                    {t((d) => d.pages.legislation.law.source)}
                                </h4>
                                <p className="note">{t((d) => d.pages.legislation.law.sourceHint)}</p>
                                <a
                                    className="text-info mt-2 inline-flex items-center gap-1 text-xs font-semibold"
                                    href={shown.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    {t((d) => d.pages.legislation.law.openSource)}
                                    <ExternalLink className="size-3" />
                                </a>
                            </div>
                        )}
                    </aside>
                </div>
            </Page>

            {/* On a phone the contents, the find and the versions are one tap away at the bottom. */}
            <nav className="bg-foreground text-background fixed inset-x-3 bottom-3 z-40 grid grid-cols-3 rounded-2xl p-1.5 shadow-lg lg:hidden">
                {(
                    [
                        ['contents', ListTree, t((d) => d.pages.legislation.law.contentsTitle)],
                        ['find', Search, t((d) => d.pages.legislation.law.find)],
                        ['versions', History, t((d) => d.pages.legislation.law.versions)],
                    ] as const
                ).map(([key, Icon, label]) => (
                    <button
                        key={key}
                        type="button"
                        className="flex min-h-12 flex-col items-center justify-center gap-1 rounded-xl text-xs font-semibold active:bg-white/10"
                        onClick={() => {
                            if (key === 'versions') {
                                setVersionsOpen(true);
                            } else {
                                setSheet(key);
                            }
                        }}
                    >
                        <Icon className="size-4" />
                        {label}
                    </button>
                ))}
            </nav>

            <Sheet
                open={sheet !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSheet(null);
                    }
                }}
            >
                <SheetContent side="bottom" className="sheet-rise gap-0 rounded-t-2xl p-0">
                    <SheetHeader className="border-border border-b p-4">
                        <SheetTitle>
                            {sheet === 'find'
                                ? t((d) => d.pages.legislation.law.find)
                                : t((d) => d.pages.legislation.law.contentsTitle)}
                        </SheetTitle>
                    </SheetHeader>
                    <div className="overflow-y-auto p-3">
                        {sheet === 'find' ? (
                            <div className="space-y-2">
                                <SearchInput
                                    value={find}
                                    placeholder={t((d) => d.pages.legislation.law.findPlaceholder)}
                                    onChange={setFind}
                                />
                                <p className="note">{findCount}</p>
                                {words.length === 0
                                    ? null
                                    : found.map((group) => (
                                          <button
                                              key={group.article.id}
                                              type="button"
                                              className="border-border w-full border-b py-2.5 text-left"
                                              onClick={() => {
                                                  jump(group.article);
                                              }}
                                          >
                                              <span className="text-info block text-xs font-semibold">
                                                  {group.article.citation}
                                              </span>
                                              <span className="text-muted-foreground block truncate text-xs">
                                                  {group.article.text}
                                              </span>
                                          </button>
                                      ))}
                            </div>
                        ) : outline?.outline == null ? (
                            <p className="note">{t((d) => d.pages.legislation.law.contentsEmpty)}</p>
                        ) : (
                            <LawContents outline={outline.outline} current={current} onOpen={goToPage} />
                        )}
                    </div>
                </SheetContent>
            </Sheet>

            {law === null ? null : (
                <LawVersions
                    law={law}
                    shown={shown?.date ?? null}
                    open={versionsOpen}
                    onOpenChange={setVersionsOpen}
                    onView={changeDate}
                />
            )}

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

/**
 * The headings that begin at this article — the ones that differ from the article before, as a quiet
 * card above it. A new chapter shows its chapter and sections, not the title again.
 */
function Headings({ chain, before }: { chain: LawHeading[]; before: LawHeading[] }): JSX.Element | null {
    const firstNew = chain.findIndex((heading, index) => before[index]?.id !== heading.id);

    if (chain.length === 0 || firstNew === -1) {
        return null;
    }

    return (
        <div className="bg-muted/50 border-border rounded-xl border px-4 py-2.5">
            {chain.slice(firstNew).map((heading) => (
                <p
                    key={heading.id}
                    className={cn(
                        'text-balance',
                        heading.level === 'TITLU' && 'text-sm font-semibold',
                        heading.level === 'CAPITOL' && 'text-sm font-medium',
                        heading.level === 'SECTIUNE' && 'text-muted-foreground text-xs font-medium',
                    )}
                >
                    {heading.label}
                    {heading.name === '' ? '' : ` — ${heading.name}`}
                </p>
            ))}
        </div>
    );
}
