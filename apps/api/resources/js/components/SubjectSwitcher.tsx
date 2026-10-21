import { Check, ChevronsUpDown, Search } from 'lucide-react';
import { useEffect, useState, type JSX } from 'react';
import { clearActiveSubjectId, getActiveSubjectId, setActiveSubjectId } from '../lib/subjectStore';
import { useSharedAuth, type SharedAuth } from '../lib/sharedProps';

/** One row of the shared subject list — what the switcher draws. */
type SubjectRow = SharedAuth['subjects'][number];
import { useTranslation } from '../i18n';
import type { Dictionary } from '../i18n/dictionary';
import { initialsFor } from '../lib/initials';
import { subjectAvatarUrl } from '../api/accountApi';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * The taxpayer's disc: its picture where there is one, its initials where there is not.
 *
 * Three call sites in this file — the static tile, the trigger and every row of the menu — and they
 * used to differ only in their class string, which is how the rail tile and the menu row came to
 * disagree about the disc's size. The classes stay the caller's; what is inside it does not.
 */
function SubjectDisc({ subject, className }: { subject: SubjectRow | undefined; className: string }): JSX.Element {
    const picture =
        subject === undefined || subject.avatarUpdatedAt === null
            ? null
            : subjectAvatarUrl(subject.id, subject.avatarUpdatedAt);

    if (picture !== null) {
        return (
            <img
                src={picture}
                alt=""
                className={`${className} object-cover`}
                // Decoration beside the name it belongs to: a screen reader that announced the file
                // would be reading the taxpayer's id out loud.
                aria-hidden="true"
            />
        );
    }

    return <span className={className}>{initialsFor(subject === undefined ? '?' : subject.displayName)}</span>;
}

/**
 * The topbar's compact "which tax subject am I working on" switcher — every other page still
 * reads `resources/js/lib/subjectStore.ts` once per mount (`docs/handoff/030-pwa-frontend.md`), so
 * switching here does a full navigation rather than a client-side state update, guaranteeing every
 * already-mounted page re-reads the new value instead of a subset silently going stale. Only the
 * first page of subjects is listed — a user with more than that manages the rest from
 * `/taxpayers` (`resources/js/pages/settings/subjects.tsx`, linked below), which already
 * paginates.
 */

export default function SubjectSwitcher({
    /** Rail mode: the sidebar is collapsed, so only the initials tile fits. */
    collapsed = false,
}: {
    collapsed?: boolean;
} = {}): JSX.Element {
    const { t } = useTranslation();
    // Carried by the page rather than fetched: `HandleInertiaRequests::share()` sends them with
    // every authenticated response, so the sidebar draws its taxpayer on the first paint instead
    // of blank-then-filled. Closed ones are left out — a closed taxpayer is kept for its records,
    // not for filing against, and offering it here would be offering a choice that leads nowhere.
    const subjects = (useSharedAuth()?.subjects ?? []).filter((subject) => subject.status !== 'CLOSED');
    const [activeId, setActiveId] = useState<string | null>(() => getActiveSubjectId());
    const [query, setQuery] = useState('');

    const needle = query.trim().toLowerCase();
    const matches =
        needle === '' ? subjects : subjects.filter((subject) => subject.displayName.toLowerCase().includes(needle));

    /*
     * Which taxpayer this browser is working in — resolved, not stored twice.
     *
     * The stored id is a convenience, so it is never the last word: a choice that is no longer
     * offered (closed since, or access revoked) would otherwise make every scoped request fail
     * until someone cleared their browser data. And with exactly one taxpayer there is no choice to
     * make — a question with one possible answer, asked anyway on every new device, while the pages
     * behind it showed nothing.
     */
    const resolvedId = subjects.some((subject) => subject.id === activeId)
        ? activeId
        : subjects.length === 1
          ? (subjects[0]?.id ?? null)
          : null;

    // Only the storage is written here; `resolvedId` is derived above, so there is no state to set
    // and no second render to trigger.
    useEffect(() => {
        if (resolvedId === null) {
            clearActiveSubjectId();
        } else if (resolvedId !== getActiveSubjectId()) {
            setActiveSubjectId(resolvedId);
        }
    }, [resolvedId]);

    const active = subjects.find((subject) => subject.id === resolvedId);

    function activate(subjectId: string): void {
        setActiveSubjectId(subjectId);
        setActiveId(subjectId);
        window.location.reload();
    }

    // A tile that only ever has one answer is not a control. With a single taxpayer the switcher
    // still names what the page's figures belong to — that is worth showing — but nothing opens,
    // because a menu offering the choice already made is a click that changes nothing.
    const switchable = subjects.length > 1;

    const tile = (
        <div className={`shell-control subject-control w-full text-left${collapsed ? ' subject-control-rail' : ''}`}>
            <SubjectDisc
                subject={active}
                className="bg-accent text-accent-600 grid size-7 shrink-0 place-items-center rounded-lg text-2xs font-bold"
            />
            {!collapsed && (
                <span className="min-w-0 flex-1">
                    <span className="text-muted-foreground block text-2xs tracking-[0.08em] uppercase">
                        {t((d) => d.shell.taxSubject)}
                    </span>
                    <span className="text-foreground block truncate text-xs font-semibold">
                        {active ? active.displayName : t((d) => d.shell.noActiveSubject)}
                    </span>
                </span>
            )}
        </div>
    );

    if (!switchable) {
        return tile;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                {/* A bordered tile at the foot of the sidebar rather than a bare button: it names the
                    thing every figure on the page belongs to, so it should look like a control you
                    can change, not a label. */}
                <button
                    type="button"
                    className={`shell-control subject-control w-full text-left${collapsed ? ' subject-control-rail' : ''}`}
                >
                    <SubjectDisc
                        subject={active}
                        className="bg-accent text-accent-600 grid size-7 shrink-0 place-items-center rounded-lg text-2xs font-bold"
                    />
                    {!collapsed && (
                        <>
                            <span className="min-w-0 flex-1">
                                <span className="text-muted-foreground block text-2xs tracking-[0.08em] uppercase">
                                    {t((d) => d.shell.taxSubject)}
                                </span>
                                <span className="text-foreground block truncate text-xs font-semibold">
                                    {active ? active.displayName : t((d) => d.shell.noActiveSubject)}
                                </span>
                            </span>
                            <ChevronsUpDown className="text-muted-foreground size-3.5 shrink-0" aria-hidden="true" />
                        </>
                    )}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                // Expanded, the menu rises from a trigger that already spans the sidebar, so it
                // takes that width. Collapsed to the rail, the trigger is a 48px tile — matching it
                // would squeeze the menu to a sliver — so it opens to the *right* of the rail at a
                // fixed 280px, bottom-aligned, exactly as the design's `.rail #subjectMenu` does.
                align="end"
                side={collapsed ? 'right' : 'top'}
                sideOffset={collapsed ? 16 : 4}
                // The list inside scrolls, so the search box stays put while it does. Radix puts a
                // max-height and its own scrollbar on the content by default; left on, the two nest
                // and the menu collapses to whatever height the outer one computes.
                className={
                    collapsed
                        ? 'max-h-none w-[280px] overflow-hidden p-0'
                        : 'w-(--radix-dropdown-menu-trigger-width) max-h-none overflow-hidden p-0'
                }
            >
                {/* Filtering, not searching the server: this list is the first page of subjects, so
                    the box narrows what is already here rather than implying there is more. */}
                <div className="border-border border-b p-2">
                    <label className="flex h-9 items-center gap-2 rounded-xl bg-[#F8FAFC] px-2.5 dark:bg-[#27292D]">
                        <Search className="text-muted-foreground size-3.5 shrink-0" aria-hidden="true" />
                        <input
                            value={query}
                            onChange={(event) => {
                                setQuery(event.target.value);
                            }}
                            placeholder={t((d) => d.shell.searchSubjects)}
                            className="placeholder:text-muted-foreground min-w-0 flex-1 bg-transparent text-xs outline-none"
                        />
                    </label>
                </div>

                <div className="max-h-64 overflow-y-auto p-1.5">
                    {matches.length === 0 && (
                        <p className="text-muted-foreground px-3 py-7 text-center text-xs">
                            {t((d) => d.shell.noSubjectsFound)}
                        </p>
                    )}
                    {matches.map((subject) => (
                        <DropdownMenuItem
                            key={subject.id}
                            className="gap-2.5 text-sm"
                            onSelect={() => {
                                activate(subject.id);
                            }}
                        >
                            <SubjectDisc
                                subject={subject}
                                className={
                                    subject.id === resolvedId
                                        ? 'bg-accent text-accent-foreground grid size-8 shrink-0 place-items-center rounded-xl text-2xs font-bold'
                                        : 'bg-secondary text-secondary-foreground grid size-8 shrink-0 place-items-center rounded-xl text-2xs font-bold'
                                }
                            />
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-xs font-semibold">{subject.displayName}</span>
                                <span className="text-muted-foreground block truncate text-2xs">
                                    {t((d) => d.subjectType)[subject.type as keyof Dictionary['subjectType']]}
                                </span>
                            </span>
                            {subject.id === resolvedId && (
                                <Check className="text-accent-foreground size-3.5 shrink-0" aria-hidden="true" />
                            )}
                        </DropdownMenuItem>
                    ))}
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
