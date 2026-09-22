import { ChevronDown, ChevronRight } from 'lucide-react';
import { useState, type JSX } from 'react';

import { SearchInput } from '../../components/panel';
import { useTranslation } from '../../i18n';
import { cn } from '../../lib/utils';
import type { LawOutline, LawOutlineHeading } from '../../types/legislation';

/**
 * The law's table of contents as it stood on the chosen date: its titles, each opening onto its
 * chapters and sections, with the articles each spans. Choosing one opens the text there; the title
 * the page is in stays open and marked. Filtered, it lists every heading whose label or name holds
 * the words, at any depth.
 */
export default function LawContents({
    outline,
    current,
    onOpen,
}: {
    outline: LawOutline;
    /** The headings the page's first article stands under — the title it is in, open and marked. */
    current: Set<string>;
    onOpen: (page: number) => void;
}): JSX.Element {
    const { t } = useTranslation();
    const [filter, setFilter] = useState('');
    const [toggled, setToggled] = useState<Record<string, boolean>>({});
    const childrenOf = (parentId: string | null): LawOutlineHeading[] =>
        outline.headings.filter((heading) => heading.parentId === parentId);
    const words = filter.trim().toLocaleLowerCase();

    const range = (heading: LawOutlineHeading): string | null =>
        heading.firstCitation === null
            ? null
            : t((d) => d.pages.legislation.law.contentsRange)
                  .replace('{first}', heading.firstCitation)
                  .replace('{last}', heading.lastCitation ?? heading.firstCitation);

    const entry = (heading: LawOutlineHeading, depth: number): JSX.Element => (
        <button
            key={heading.id}
            type="button"
            disabled={heading.page === null}
            className={cn(
                'hover:bg-muted w-full rounded-md px-2 py-1.5 text-left text-xs disabled:opacity-50',
                current.has(heading.id) && 'bg-info/10 text-info',
            )}
            style={{ paddingLeft: `${String(0.5 + depth * 0.75)}rem` }}
            onClick={() => {
                if (heading.page !== null) {
                    onOpen(heading.page);
                }
            }}
        >
            <span className="font-medium">{heading.label}</span>
            {heading.name === '' ? null : <span> — {heading.name}</span>}
        </button>
    );

    const below = (heading: LawOutlineHeading, depth: number): JSX.Element[] =>
        childrenOf(heading.id).flatMap((child) => [entry(child, depth), ...below(child, depth + 1)]);

    const titles = childrenOf(null);

    return (
        <div className="space-y-2">
            <SearchInput
                value={filter}
                placeholder={t((d) => d.pages.legislation.law.contentsFilter)}
                onChange={setFilter}
            />

            {titles.length === 0 ? (
                <p className="note px-2">{t((d) => d.pages.legislation.law.contentsEmpty)}</p>
            ) : words !== '' ? (
                <div>
                    {outline.headings
                        .filter((heading) => `${heading.label} ${heading.name}`.toLocaleLowerCase().includes(words))
                        .map((heading) => entry(heading, 0))}
                </div>
            ) : (
                <ul>
                    {titles.map((title) => {
                        const open = toggled[title.id] ?? current.has(title.id);
                        const span = range(title);

                        return (
                            <li key={title.id} className="border-border border-t first:border-t-0">
                                <button
                                    type="button"
                                    aria-expanded={open}
                                    className={cn(
                                        'hover:bg-muted flex w-full items-start gap-1.5 rounded-md px-1.5 py-2 text-left',
                                        current.has(title.id) && 'bg-info/10 text-info',
                                    )}
                                    onClick={() => {
                                        setToggled((state) => ({ ...state, [title.id]: !open }));

                                        if (title.page !== null) {
                                            onOpen(title.page);
                                        }
                                    }}
                                >
                                    {open ? (
                                        <ChevronDown className="mt-0.5 size-3.5 shrink-0" />
                                    ) : (
                                        <ChevronRight className="mt-0.5 size-3.5 shrink-0" />
                                    )}
                                    <span className="min-w-0">
                                        <span className="block text-xs font-semibold">
                                            {title.label}
                                            {title.name === '' ? '' : ` — ${title.name}`}
                                        </span>
                                        {span === null ? null : <span className="note block">{span}</span>}
                                    </span>
                                </button>
                                {open ? <div className="pb-1.5 pl-4">{below(title, 0)}</div> : null}
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
