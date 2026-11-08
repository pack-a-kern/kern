import { Check, ChevronDown, ChevronsUpDown, Search } from 'lucide-react';
import { useEffect, useState, type JSX, type ReactNode } from 'react';
import { clearActiveSubjectId, getActiveSubjectId, setActiveSubjectId, withoutSubjectLink } from '../lib/subjectStore';
import { type SharedAuth } from '../lib/sharedProps';
import { useActiveSubjectId, useOpenSubjects } from '../hooks/useActiveSubjectId';

/** One row of the shared subject list — what the switcher draws. */
type SubjectRow = SharedAuth['subjects'][number];
import { useTranslation } from '../i18n';
import type { Dictionary } from '../i18n/dictionary';
import { initialsFor } from '../lib/initials';
import { subjectAvatarUrl } from '../api/accountApi';
import { Avatar, PanelDialog } from './panel';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * The taxpayer's disc: the shared {@link Avatar} — its picture where there is one, its initials
 * where there is not or the picture fails to load — in the switcher's square tile shapes.
 *
 * Three call sites in this file — the static tile, the trigger and every row of the menu — and they
 * used to differ only in their class string, which is how the rail tile and the menu row came to
 * disagree about the disc's size. The classes stay the caller's; what is inside it does not.
 */
function SubjectDisc({ subject, className }: { subject: SubjectRow | undefined; className: string }): JSX.Element {
    return (
        <Avatar
            initials={initialsFor(subject === undefined ? '?' : subject.displayName)}
            src={
                subject === undefined || subject.avatarUpdatedAt === null
                    ? undefined
                    : subjectAvatarUrl(subject.id, subject.avatarUpdatedAt)
            }
            className={className}
        />
    );
}

/**
 * Works in this taxpayer from now on. Not a plain reload: a page opened from a notice still names
 * that notice's taxpayer, and would choose it again.
 */
function openSubject(subjectId: string): void {
    setActiveSubjectId(subjectId);
    window.location.replace(withoutSubjectLink(window.location.href));
}

/** The taxpayers whose name holds what was typed. Filtering, not searching the server: this list is the first page of subjects. */
function matching(subjects: SubjectRow[], query: string): SubjectRow[] {
    const needle = query.trim().toLowerCase();

    return needle === '' ? subjects : subjects.filter((subject) => subject.displayName.toLowerCase().includes(needle));
}

function SubjectSearch({ value, onChange }: { value: string; onChange: (value: string) => void }): JSX.Element {
    const { t } = useTranslation();

    return (
        <label className="flex h-9 items-center gap-2 rounded-xl bg-[#F8FAFC] px-2.5 dark:bg-[#27292D]">
            <Search className="text-muted-foreground size-3.5 shrink-0" aria-hidden="true" />
            <input
                value={value}
                onChange={(event) => {
                    onChange(event.target.value);
                }}
                placeholder={t((d) => d.shell.searchSubjects)}
                className="placeholder:text-muted-foreground min-w-0 flex-1 bg-transparent text-xs outline-none"
            />
        </label>
    );
}

/** One taxpayer as a list offers it: its disc, its name and its kind, and a tick on the one in use. */
function SubjectOption({ subject, current }: { subject: SubjectRow; current: boolean }): JSX.Element {
    const { t } = useTranslation();

    return (
        <>
            <SubjectDisc
                subject={subject}
                className={
                    current ? 'avatar-tile avatar-tile-md avatar-current' : 'avatar-tile avatar-tile-md avatar-other'
                }
            />
            <span className="min-w-0 flex-1">
                <span className="block truncate text-xs font-semibold">{subject.displayName}</span>
                <span className="text-muted-foreground block truncate text-2xs">
                    {t((d) => d.subjectType)[subject.type as keyof Dictionary['subjectType']]}
                </span>
            </span>
            {current && <Check className="text-accent-foreground size-3.5 shrink-0" aria-hidden="true" />}
        </>
    );
}

/**
 * Asks which taxpayer to work in, where there are several and none is chosen yet — a new device, a
 * cleared browser, a choice that was closed or revoked.
 *
 * Every screen's figures belong to one taxpayer, so nothing is guessed and nothing is shown until one
 * is picked: the dialog cannot be closed any other way. The one way out that is not a choice is the
 * screen's own (`footer` — signing out), so a person on the wrong account is not trapped in it.
 */
export function SubjectChooser({ footer }: { footer: ReactNode }): JSX.Element | null {
    const { t } = useTranslation();
    const subjects = useOpenSubjects();
    const resolvedId = useActiveSubjectId();
    const [query, setQuery] = useState('');

    if (subjects.length < 2 || resolvedId !== null) {
        return null;
    }

    const matches = matching(subjects, query);

    return (
        <PanelDialog
            open
            onOpenChange={() => undefined}
            title={t((d) => d.shell.chooseSubject)}
            description={t((d) => d.shell.chooseSubjectHint)}
            size="sm"
            dismissable={false}
            closeButton={false}
            actions={footer}
        >
            <div className="space-y-2">
                <SubjectSearch value={query} onChange={setQuery} />
                {matches.length === 0 ? (
                    <p className="text-muted-foreground px-3 py-7 text-center text-xs">
                        {t((d) => d.shell.noSubjectsFound)}
                    </p>
                ) : (
                    <div className="grid gap-1">
                        {matches.map((subject) => (
                            <button
                                key={subject.id}
                                type="button"
                                className="hover:bg-accent/60 flex items-center gap-2.5 rounded-lg px-2 py-1.5 text-left"
                                onClick={() => {
                                    openSubject(subject.id);
                                }}
                            >
                                <SubjectOption subject={subject} current={false} />
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </PanelDialog>
    );
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
    bare = false,
}: {
    collapsed?: boolean;
    /**
     * The phone's top bar: one compact chip — the mark, the name cut to fit, and a chevron where
     * there is a choice — the same with one taxpayer as with several, so the bar does not change
     * shape with the number of taxpayers. Its menu opens across the screen rather than off the chip.
     */
    bare?: boolean;
} = {}): JSX.Element {
    const { t } = useTranslation();
    // Carried by the page rather than fetched: `HandleInertiaRequests::share()` sends them with
    // every authenticated response, so the sidebar draws its taxpayer on the first paint instead
    // of blank-then-filled. Closed ones are left out — a closed taxpayer is kept for its records,
    // not for filing against, and offering it here would be offering a choice that leads nowhere.
    const subjects = useOpenSubjects();
    const resolvedId = useActiveSubjectId();
    const [query, setQuery] = useState('');

    const matches = matching(subjects, query);

    // Which taxpayer this browser is working in is worked out by `useActiveSubjectId`, the same way
    // for every screen. Only the storage is written here, so the choice survives the next visit — there
    // is no state to set and no second render to trigger.
    useEffect(() => {
        if (resolvedId === null) {
            clearActiveSubjectId();
        } else if (resolvedId !== getActiveSubjectId()) {
            setActiveSubjectId(resolvedId);
        }
    }, [resolvedId]);

    const active = subjects.find((subject) => subject.id === resolvedId);

    // A tile that only ever has one answer is not a control. With a single taxpayer the switcher
    // still names what the page's figures belong to — that is worth showing — but nothing opens,
    // because a menu offering the choice already made is a click that changes nothing.
    const switchable = subjects.length > 1;

    const name = active ? active.displayName : t((d) => d.shell.noActiveSubject);

    /*
     * The phone's chip, as a label where there is no choice and as the menu's button where there is:
     * one shape either way. It was a bare 46px picture with one taxpayer and the sidebar's full
     * two-line tile with several — the largest thing in the bar, in two different designs.
     */
    const chip = (button: boolean): JSX.Element => {
        const inside = (
            <>
                <SubjectDisc subject={active} className="avatar-tile avatar-tile-chip avatar-accent" />
                <span className="subject-chip-name">{name}</span>
                {button && <ChevronDown className="text-muted-foreground size-3 shrink-0" aria-hidden="true" />}
            </>
        );

        return button ? (
            <button type="button" className="shell-control subject-chip" aria-label={t((d) => d.shell.taxSubject)}>
                {inside}
            </button>
        ) : (
            <div className="shell-control subject-chip subject-chip-static" title={name}>
                {inside}
            </div>
        );
    };

    const tile = bare ? (
        chip(false)
    ) : (
        <div className={`shell-control subject-control w-full text-left${collapsed ? ' subject-control-rail' : ''}`}>
            <SubjectDisc subject={active} className="avatar-tile avatar-accent" />
            {!collapsed && (
                <span className="min-w-0 flex-1">
                    <span className="text-muted-foreground block text-2xs tracking-[0.08em] uppercase">
                        {t((d) => d.shell.taxSubject)}
                    </span>
                    <span className="text-foreground block truncate text-xs font-semibold">{name}</span>
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
                {bare ? (
                    chip(true)
                ) : (
                    /* A bordered tile at the foot of the sidebar rather than a bare button: it names
                       the thing every figure on the page belongs to, so it should look like a control
                       you can change, not a label. */
                    <button
                        type="button"
                        className={`shell-control subject-control w-full text-left${collapsed ? ' subject-control-rail' : ''}`}
                    >
                        <SubjectDisc subject={active} className="avatar-tile avatar-accent" />
                        {!collapsed && (
                            <>
                                <span className="min-w-0 flex-1">
                                    <span className="text-muted-foreground block text-2xs tracking-[0.08em] uppercase">
                                        {t((d) => d.shell.taxSubject)}
                                    </span>
                                    <span className="text-foreground block truncate text-xs font-semibold">{name}</span>
                                </span>
                                <ChevronsUpDown
                                    className="text-muted-foreground size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                            </>
                        )}
                    </button>
                )}
            </DropdownMenuTrigger>
            <DropdownMenuContent
                // Expanded, the menu rises from a trigger that already spans the sidebar, so it
                // takes that width. Collapsed to the rail, the trigger is a 48px tile — matching it
                // would squeeze the menu to a sliver — so it opens to the *right* of the rail at a
                // fixed 280px, bottom-aligned, exactly as the design's `.rail #subjectMenu` does.
                // On the phone it drops below the chip across the screen (`.shell-sheet-menu`).
                align={bare ? 'center' : 'end'}
                side={bare ? 'bottom' : collapsed ? 'right' : 'top'}
                sideOffset={collapsed ? 16 : bare ? 8 : 4}
                collisionPadding={bare ? 12 : 0}
                // The list inside scrolls, so the search box stays put while it does. Radix puts a
                // max-height and its own scrollbar on the content by default; left on, the two nest
                // and the menu collapses to whatever height the outer one computes.
                className={
                    bare
                        ? 'shell-sheet-menu max-h-none overflow-hidden p-0'
                        : collapsed
                          ? 'max-h-none w-[280px] overflow-hidden p-0'
                          : 'w-(--radix-dropdown-menu-trigger-width) max-h-none overflow-hidden p-0'
                }
            >
                {/* Filtering, not searching the server: this list is the first page of subjects, so
                    the box narrows what is already here rather than implying there is more. */}
                <div className="border-border border-b p-2">
                    <SubjectSearch value={query} onChange={setQuery} />
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
                                openSubject(subject.id);
                            }}
                        >
                            <SubjectOption subject={subject} current={subject.id === resolvedId} />
                        </DropdownMenuItem>
                    ))}
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
