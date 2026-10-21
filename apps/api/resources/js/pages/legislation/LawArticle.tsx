import { History, Link2 } from 'lucide-react';
import type { JSX } from 'react';

import { Pill } from '../../components/panel';
import { useTranslation } from '../../i18n';
import { formatDate } from '../../lib/format';
import { anchorOf, lines } from '../../lib/lawText';
import { cn } from '../../lib/utils';
import type { LawNote, LawSection, LawTable } from '../../types/legislation';

/** A title the portal gives an article above its text — "Scopul și sfera de cuprindere" — is one short line. */
const SUBTITLE_MAX = 160;

/**
 * One article as a card: its heading and marginal title, then each of its paragraphs, letters and
 * points on a column of their own labels, the way the law is printed — "(1)", "a)", "–" to the left,
 * the words to the right. When and how a provision was last changed stands beside it, not in a wall
 * of notes below the article.
 */
export default function LawArticle({
    article,
    provisions,
    focus,
    onHistory,
    onCopyLink,
}: {
    article: LawSection;
    /** Everything under the article, in reading order. */
    provisions: LawSection[];
    /** The provision a link opened at, marked. */
    focus: string | null;
    onHistory: (section: LawSection) => void;
    onCopyLink: (section: LawSection) => void;
}): JSX.Element {
    const { t } = useTranslation();
    const ownText = article.text.trim();
    const subtitle =
        provisions.length > 0 && ownText !== '' && !ownText.includes('\n') && ownText.length <= SUBTITLE_MAX
            ? ownText
            : null;

    return (
        <article
            id={anchorOf(article)}
            className={cn('panel scroll-mt-44 overflow-hidden', focus === article.id && 'ring-info/50 ring-2')}
        >
            <header className="border-border flex items-start justify-between gap-3 border-b px-4 py-3">
                <div className="min-w-0">
                    <h3 className="text-sm font-semibold">{article.label}</h3>
                    {subtitle === null ? null : (
                        <p className="text-muted-foreground mt-0.5 text-xs text-balance">{subtitle}</p>
                    )}
                </div>
                <div className="flex shrink-0 gap-0.5">
                    <IconButton
                        label={t((d) => d.pages.legislation.law.copyArticleLink)}
                        onClick={() => {
                            onCopyLink(article);
                        }}
                    >
                        <Link2 className="size-3.5" />
                    </IconButton>
                    <IconButton
                        label={`${t((d) => d.pages.legislation.law.history)} — ${article.citation}`}
                        onClick={() => {
                            onHistory(article);
                        }}
                    >
                        <History className="size-3.5" />
                    </IconButton>
                </div>
            </header>

            <div className="px-4 py-3 text-sm leading-relaxed">
                {subtitle === null && ownText !== '' ? (
                    <SectionBody text={article.text} tables={article.tables} />
                ) : null}
                <Changed section={article} />

                {provisions.map((provision) => (
                    <div
                        key={provision.id}
                        id={anchorOf(provision)}
                        className={cn(
                            'group hover:bg-muted/40 scroll-mt-44 rounded-md py-1',
                            focus === provision.id && 'bg-info/10 ring-info/40 ring-1',
                        )}
                        style={{ paddingLeft: `${String(Math.min(Math.max(provision.depth - 1, 0), 4) * 1.25)}rem` }}
                    >
                        <div className="grid grid-cols-[2.25rem_minmax(0,1fr)_auto] gap-1">
                            <span className="text-muted-foreground pt-px text-xs font-medium tabular-nums">
                                {provision.label}
                            </span>
                            <div className="min-w-0">
                                <SectionBody text={provision.text} tables={provision.tables} />
                            </div>
                            <IconButton
                                subtle
                                label={`${t((d) => d.pages.legislation.law.history)} — ${provision.citation}`}
                                onClick={() => {
                                    onHistory(provision);
                                }}
                            >
                                <History className="size-3.5" />
                            </IconButton>
                        </div>
                        <div className="pl-9">
                            <Changed section={provision} />
                        </div>
                    </div>
                ))}
            </div>
        </article>
    );
}

function IconButton({
    label,
    onClick,
    subtle = false,
    children,
}: {
    label: string;
    onClick: () => void;
    /** Shown only when its row is hovered or focused — one per paragraph would crowd the text. */
    subtle?: boolean;
    children: JSX.Element;
}): JSX.Element {
    return (
        <button
            type="button"
            className={cn(
                'text-muted-foreground hover:bg-muted hover:text-foreground grid size-7 place-items-center rounded-md',
                subtle && 'opacity-0 group-hover:opacity-100 focus-visible:opacity-100',
            )}
            title={label}
            aria-label={label}
            onClick={onClick}
        >
            {children}
        </button>
    );
}

/** When the provision was last changed, and the portal's notes on it — beside it, quietly. */
function Changed({ section }: { section: LawSection }): JSX.Element | null {
    const { t, locale } = useTranslation();

    if (section.amendedOn === null && section.notes.length === 0) {
        return null;
    }

    return (
        <div className="border-info/30 bg-info/5 text-muted-foreground my-1.5 rounded-r-md border-l-2 px-2.5 py-1.5 text-xs">
            {section.amendedOn === null ? null : (
                <span className="text-info font-semibold">
                    {t((d) => d.pages.legislation.law.amended).replace('{date}', formatDate(section.amendedOn, locale))}
                </span>
            )}
            <ProvisionNotes notes={section.notes} />
        </div>
    );
}

/**
 * The portal's notes on a provision, folded away: what each says about when the provision applies,
 * the act it quotes, and its words with those sentences marked.
 */
export function ProvisionNotes({ notes }: { notes: LawNote[] }): JSX.Element | null {
    const { t, locale } = useTranslation();

    if (notes.length === 0) {
        return null;
    }

    const date = (value: string | null): string => (value === null ? '—' : formatDate(value, locale));

    return (
        <details className="mt-1 text-xs">
            <summary className="text-muted-foreground cursor-pointer select-none">
                {t((d) => d.pages.legislation.law.notes).replace('{count}', String(notes.length))}
            </summary>
            <p className="note mt-1">{t((d) => d.pages.legislation.law.notesHint)}</p>
            <div className="mt-2 space-y-3">
                {notes.map((note, index) => (
                    <div key={index} className="border-border space-y-1 border-l-2 pl-2">
                        <div className="flex flex-wrap gap-1">
                            {note.applies.length === 0 ? (
                                <Pill>{t((d) => d.pages.legislation.law.noteNoDate)}</Pill>
                            ) : (
                                note.applies.map((applies, appliesIndex) => (
                                    <Pill key={appliesIndex} tone="accent">
                                        {t((d) =>
                                            applies.kind === 'from_date' && applies.until !== null
                                                ? d.pages.legislation.law.noteApplies.from_date_until
                                                : d.pages.legislation.law.noteApplies[applies.kind],
                                        )
                                            .replace('{from}', date(applies.from))
                                            .replace('{until}', date(applies.until))
                                            .replace('{year}', applies.year === null ? '—' : String(applies.year))}
                                    </Pill>
                                ))
                            )}
                        </div>
                        {note.acts.map((act, actIndex) => (
                            <p key={actIndex} className="text-muted-foreground">
                                {t((d) => d.pages.legislation.law.noteAct)
                                    .replace('{type}', act.type)
                                    .replace('{number}', act.number)
                                    .replace('{date}', date(act.date))
                                    .replace('{journal}', act.journalNumber)
                                    .replace('{journalDate}', date(act.journalDate))}
                            </p>
                        ))}
                        <p className="text-muted-foreground leading-relaxed whitespace-pre-line">
                            {marked(
                                note.text,
                                note.applies.map((applies) => applies.quote),
                            ).map((part, partIndex) =>
                                part.quoted ? (
                                    <mark key={partIndex} className="bg-info/15 text-foreground rounded-sm">
                                        {part.text}
                                    </mark>
                                ) : (
                                    <span key={partIndex}>{part.text}</span>
                                ),
                            )}
                        </p>
                    </div>
                ))}
            </div>
        </details>
    );
}

/** A text cut where each quote stands in it, in order — the quotes come in the order they appear. */
function marked(text: string, quotes: string[]): { text: string; quoted: boolean }[] {
    const parts: { text: string; quoted: boolean }[] = [];
    let at = 0;

    for (const quote of quotes) {
        const start = text.indexOf(quote, at);

        if (quote === '' || start < 0) {
            continue;
        }

        parts.push({ text: text.slice(at, start), quoted: false }, { text: quote, quoted: true });
        at = start + quote.length;
    }

    parts.push({ text: text.slice(at), quoted: false });

    return parts.filter((part) => part.text !== '');
}

/** A provision's words as printed: its lines, and its tables as tables. */
export function SectionBody({ text, tables }: { text: string; tables: LawTable[] }): JSX.Element {
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
