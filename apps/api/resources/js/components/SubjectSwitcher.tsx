import { Link, router } from '@inertiajs/react';
import { Check, ChevronDown, ChevronsUpDown, LogOut, Search, Settings, UserRound, Users } from 'lucide-react';
import { useEffect, useState, type JSX, type ReactNode } from 'react';
import { clearActiveSubjectId, getActiveSubjectId, setActiveSubjectId, withoutSubjectLink } from '../lib/subjectStore';
import { type SharedSubject, useSharedAuth, useSharedSubjects } from '../lib/sharedProps';
import { signOut } from '../lib/signOut';
import { useSettingsLink } from '../lib/settingsLink';
import { useActiveSubjectId } from '../hooks/useActiveSubjectId';
import { useTranslation } from '../i18n';
import type { Dictionary } from '../i18n/dictionary';
import { initialsFor } from '../lib/initials';
import { subjectAvatarUrl } from '../api/accountApi';
import { Avatar, PanelButton, PanelDialog } from './panel';
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
function SubjectDisc({ subject, className }: { subject: SharedSubject | undefined; className: string }): JSX.Element {
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

/** The taxpayers whose name holds what was typed. Filtering, not searching the server: this list is every taxpayer in use. */
function matching(subjects: SharedSubject[], query: string): SharedSubject[] {
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
function SubjectOption({ subject, current }: { subject: SharedSubject; current: boolean }): JSX.Element {
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

/** The taxpayers as rows to press, in a dialog: the one in use ticked, any other opened when chosen. */
function SubjectList({ subjects, currentId }: { subjects: SharedSubject[]; currentId: string | null }): JSX.Element {
    const { t } = useTranslation();

    if (subjects.length === 0) {
        return (
            <p className="text-muted-foreground px-3 py-7 text-center text-xs">{t((d) => d.shell.noSubjectsFound)}</p>
        );
    }

    return (
        <div className="grid gap-1">
            {subjects.map((subject) => (
                <button
                    key={subject.id}
                    type="button"
                    className="hover:bg-accent/60 flex items-center gap-2.5 rounded-lg px-2 py-1.5 text-left"
                    onClick={() => {
                        if (subject.id !== currentId) {
                            openSubject(subject.id);
                        }
                    }}
                >
                    <SubjectOption subject={subject} current={subject.id === currentId} />
                </button>
            ))}
        </div>
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
    const subjects = useSharedSubjects();
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
                <SubjectList subjects={matches} currentId={null} />
            </div>
        </PanelDialog>
    );
}

/**
 * The account card: whose records the page shows (the taxpayer) and who is looking (the signed-in
 * email), and from it everything about either — switching taxpayer, managing them, the account, and
 * signing out. At the foot of the sidebar, and in the phone's top bar as a compact two-line chip.
 *
 * Switching does a full navigation rather than a client-side state update: every page reads
 * `lib/subjectStore.ts` once per mount (`docs/handoff/030-pwa-frontend.md`), so a reload is what
 * guarantees none of them goes on showing the last taxpayer's figures. Only the first page of
 * subjects is listed — more than that are managed from `/taxpayers`, which paginates.
 */
export default function SubjectSwitcher({
    /** Rail mode: the sidebar is collapsed, so only the taxpayer's mark fits. */
    collapsed = false,
    bare = false,
}: {
    collapsed?: boolean;
    /**
     * The phone's top bar: a compact chip, the taxpayer's mark beside the name over the email, and a
     * menu that opens across the screen rather than off the chip.
     */
    bare?: boolean;
} = {}): JSX.Element {
    const { t } = useTranslation();
    // Carried by the page rather than fetched: `HandleInertiaRequests::share()` sends them with
    // every authenticated response, so the card draws its taxpayer on the first paint instead of
    // blank-then-filled. Closed ones are left out — a closed taxpayer is kept for its records, not
    // for filing against, and offering it here would be offering a choice that leads nowhere.
    const subjects = useSharedSubjects();
    const resolvedId = useActiveSubjectId();
    const email = useSharedAuth()?.user.email ?? '';
    const [query, setQuery] = useState('');
    const [sheetOpen, setSheetOpen] = useState(false);
    const settings = useSettingsLink();

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
    const name = active ? active.displayName : t((d) => d.shell.noActiveSubject);

    // A search box over one or two names is a box nobody needs.
    const searchable = subjects.length > 2;

    /*
     * On a phone the card opens a window over a dimmed page rather than a menu off the chip: it is
     * the whole of who and for whom — the taxpayers, the links, settings and signing out — and a
     * phone gives that the screen, the way its own account screens do.
     */
    if (bare) {
        return (
            <>
                <button
                    type="button"
                    className="account-chip"
                    aria-haspopup="dialog"
                    aria-label={t((d) => d.shell.account)}
                    onClick={() => {
                        setSheetOpen(true);
                    }}
                >
                    <SubjectDisc subject={active} className="avatar-tile account-mark" />
                    <span className="account-chip-text">
                        <span className="account-chip-name">{name}</span>
                        {email === '' ? null : <span className="account-chip-mail">{email}</span>}
                    </span>
                    <ChevronDown className="text-muted-foreground size-3.5 shrink-0" aria-hidden="true" />
                </button>
                <PanelDialog
                    open={sheetOpen}
                    onOpenChange={setSheetOpen}
                    title={name}
                    {...(email === '' ? {} : { description: email })}
                    size="sm"
                    dismissOnOutsideClick
                    actions={
                        <>
                            <PanelButton
                                onClick={() => {
                                    setSheetOpen(false);
                                    router.visit(settings.href);
                                }}
                            >
                                <Settings className="size-3.5" aria-hidden="true" />
                                {settings.label}
                            </PanelButton>
                            <PanelButton
                                variant="destructive"
                                onClick={() => {
                                    void signOut();
                                }}
                            >
                                <LogOut className="size-3.5" aria-hidden="true" />
                                {t((d) => d.shell.signOut)}
                            </PanelButton>
                        </>
                    }
                >
                    <div className="space-y-2">
                        {searchable && <SubjectSearch value={query} onChange={setQuery} />}
                        <p className="eyebrow px-2">{t((d) => d.shell.taxSubject)}</p>
                        <SubjectList subjects={matches} currentId={resolvedId} />
                        <div className="border-border grid gap-1 border-t pt-2">
                            {[
                                { href: '/taxpayers', icon: Users, label: t((d) => d.shell.manageSubjects) },
                                { href: '/account', icon: UserRound, label: t((d) => d.nav.accountGroup) },
                            ].map((link) => (
                                <Link
                                    key={link.href}
                                    href={link.href}
                                    className="hover:bg-accent/60 flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm"
                                    onClick={() => {
                                        setSheetOpen(false);
                                    }}
                                >
                                    <link.icon className="text-muted-foreground size-4" aria-hidden="true" />
                                    {link.label}
                                </Link>
                            ))}
                        </div>
                    </div>
                </PanelDialog>
            </>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className={`shell-control subject-control w-full text-left${collapsed ? ' subject-control-rail' : ''}`}
                    aria-label={collapsed ? t((d) => d.shell.account) : undefined}
                >
                    <SubjectDisc subject={active} className="avatar-tile account-mark" />
                    {!collapsed && (
                        <>
                            <span className="min-w-0 flex-1">
                                <span className="text-foreground block truncate text-xs font-semibold">{name}</span>
                                {email === '' ? null : (
                                    <span className="text-muted-foreground block truncate text-2xs">{email}</span>
                                )}
                            </span>
                            <ChevronsUpDown className="text-muted-foreground size-3.5 shrink-0" aria-hidden="true" />
                        </>
                    )}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                // Expanded, the menu rises from a card that already spans the sidebar, so it takes that
                // width. On the rail the card is a 48px tile — matching it would squeeze the menu to a
                // sliver — so it opens to the rail's right at a fixed 280px, bottom-aligned.
                align="end"
                side={collapsed ? 'right' : 'top'}
                sideOffset={collapsed ? 16 : 4}
                collisionPadding={0}
                // The list inside scrolls, so the search box stays put while it does. Radix puts a
                // max-height and its own scrollbar on the content by default; left on, the two nest
                // and the menu collapses to whatever height the outer one computes.
                className={
                    collapsed
                        ? 'max-h-none w-[280px] overflow-hidden p-0'
                        : 'w-(--radix-dropdown-menu-trigger-width) max-h-none overflow-hidden p-0'
                }
            >
                {searchable && (
                    // Filtering, not searching the server: this list is every taxpayer in use.
                    <div className="border-border border-b p-2">
                        <SubjectSearch value={query} onChange={setQuery} />
                    </div>
                )}

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
                                // The one in use is already open: choosing it again changes nothing.
                                if (subject.id !== resolvedId) {
                                    openSubject(subject.id);
                                }
                            }}
                        >
                            <SubjectOption subject={subject} current={subject.id === resolvedId} />
                        </DropdownMenuItem>
                    ))}
                </div>

                <div className="border-border grid border-t p-1.5">
                    <DropdownMenuItem asChild className="gap-2.5 text-sm">
                        <Link href="/taxpayers">
                            <Users className="size-4" aria-hidden="true" />
                            {t((d) => d.shell.manageSubjects)}
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem asChild className="gap-2.5 text-sm">
                        <Link href="/account">
                            <UserRound className="size-4" aria-hidden="true" />
                            {t((d) => d.nav.accountGroup)}
                        </Link>
                    </DropdownMenuItem>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
