import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Bell,
    Building2,
    Calculator,
    CalendarClock,
    ChartColumn,
    CheckCheck,
    ChevronDown,
    FileSpreadsheet,
    Files,
    Gavel,
    LayoutDashboard,
    ListTodo,
    Landmark,
    LogOut,
    Menu,
    PanelLeftClose,
    PanelLeftOpen,
    Scale,
    ScrollText,
    Settings,
    UserRound,
    Users,
    UsersRound,
    Wallet,
} from 'lucide-react';
import { Fragment, useEffect, useRef, useState, type JSX, type ReactNode } from 'react';
import { toast } from 'sonner';
import { logout } from '../api/authApi';
import { listNotifications, markAllNotificationsRead } from '../api/notificationsApi';
import ConnectionBanner from './ConnectionBanner';
import SystemHealthBanner from './SystemHealthBanner';
import { PullIndicator } from './PullToRefresh';
import { usePullToRefresh } from '../hooks/usePullToRefresh';
import ImpersonationBanner from './ImpersonationBanner';
import { usePageHeader } from '../lib/pageHeader';
import { getDictionary, useTranslation } from '../i18n';
import type { Dictionary } from '../i18n/dictionary';
import { ADMIN_UI_ENABLED } from '../lib/featureFlags';
import { cn } from '../lib/utils';
import { subscribeToUserNotifications } from '../lib/echo';
import { formatDateTime } from '../lib/format';
import { useAppName, useSharedAuth } from '../lib/sharedProps';
import { setActiveSubjectId } from '../lib/subjectStore';
import type { ImportNotice } from '../types/notifications';
import { Button } from './ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from './ui/dropdown-menu';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from './ui/sheet';
import { Tooltip, TooltipContent, TooltipTrigger } from './ui/tooltip';
import SubjectSwitcher from './SubjectSwitcher';

interface NavLink {
    /**
     * Where the row goes. Omitted for a group that is only a container — "My account" has no page
     * of its own, so its row folds the group open instead of navigating to a route that does not
     * exist. Wallet is one of those: its summary is the dashboard. `Impozite` has a page, so it keeps
     * an href and gets a separate chevron.
     */
    href?: string;
    label: (d: Dictionary) => string;
    icon: typeof LayoutDashboard;
    /** A stable key for a group with no href, so its open/closed state can be tracked. */
    id?: string;
    /**
     * Shown only to those holding `permission` — the route checks it too; this only hides what
     * cannot be used. On the row itself as well as on a child, since a destination does not become
     * freely readable by being promoted out of a fold: Tax rules needs `fiscal.parameters.view`
     * whether it sits under Legislation or beside it.
     */
    permission?: string;
    children?: { href: string; label: (d: Dictionary) => string; icon: typeof LayoutDashboard; permission?: string }[];
}

/**
 * The sidebar's structure, as the destinations it is assembled from. `NAV_SECTIONS` further down
 * puts them in order; these say what each one holds and why.
 *
 * Every route named here has a matching entry in `routes/web.php`
 * (`docs/handoff/040-ui-foundation.md` "Routes"), so nothing in the menu is a dead link. `Documente`
 * keeps the pre-existing, already-verified `/documents` English slug rather than adding a
 * `/documente` duplicate.
 *
 * The one row that is not a kind of work, and so belongs to no band.
 */
const DASHBOARD_ITEM: NavLink = { href: '/dashboard', label: (d) => d.nav.dashboard, icon: LayoutDashboard };

/**
 * What the taxpayer has: the sources, what moved through them, and the paper behind it.
 *
 * Rentals belongs here rather than beside it. A rental is a source of income with a contract — the
 * same kind of thing as an account, read the same way — and standing it at the top level implied the
 * product had two parallel ideas of where money comes from.
 *
 * Documents sits inside for the same reason. A receipt is a transaction, a bank statement is a page
 * of transactions, a deposit contract is an account — a document is evidence for a record, not a
 * parallel filing cabinet, and putting it a level up made the two look like unrelated features
 * (which is exactly how they had been built).
 *
 * There is no `Capturare` entry. Adding a document is an action taken from the list you are already
 * looking at, so it is a button and a dialog on `/documents`, not a destination you navigate to and
 * then navigate back from.
 */
const RECORDS_GROUP: NavLink = {
    id: 'wallet',
    label: (d) => d.nav.wallet,
    icon: Wallet,
    children: [
        { href: '/wallet/accounts', label: (d) => d.nav.walletAccounts, icon: Landmark },
        { href: '/wallet/transactions', label: (d) => d.nav.walletTransactions, icon: ArrowLeftRight },
        { href: '/wallet/counterparties', label: (d) => d.nav.walletCounterparties, icon: UsersRound },
        { href: '/wallet/imports', label: (d) => d.nav.walletImports, icon: FileSpreadsheet },
        { href: '/rentals', label: (d) => d.nav.rentals, icon: Building2 },
        { href: '/documents', label: (d) => d.nav.documents, icon: Files },
    ],
};

/**
 * What those records add up to: the tax, and the figures it is read against.
 *
 * The reports moved out of the wallet to sit here. They answer "is this complete enough to compute
 * from" — data quality, completeness, what needs attention — which is a question about the tax, not
 * about bookkeeping, and the answer is worth nothing on the other side of the menu from the number
 * it qualifies.
 */
const ANALYSIS_GROUP: NavLink = {
    id: 'analysis',
    label: (d) => d.nav.analysis,
    icon: ChartColumn,
    children: [
        { href: '/taxes', label: (d) => d.nav.taxes, icon: Calculator },
        { href: '/wallet/reports', label: (d) => d.nav.walletReports, icon: ChartColumn },
    ],
};

/**
 * The law, and the numbers read out of it — two rows, neither owning the other.
 *
 * The rules hung off Legislation as its only child, which said the wrong thing twice: that the
 * rules are part of the acts, and that the acts are somewhere you pass through to reach them. They
 * are the same subject — an act says 10%, the rule holds 10%, and changing one without the other is
 * the mistake keeping them side by side is meant to make obvious — but they are two destinations,
 * and a fold around a single child is a fold that only ever hides one thing.
 *
 * The address stays `/taxes/rules`: a menu is free to disagree with a URL, and moving it would
 * break every link already written down. That disagreement is also why `isActive` matches the
 * longest declared address rather than any prefix.
 */
const LAW_ITEMS: NavLink[] = [
    { href: '/legislation', label: (d) => d.nav.legislation, icon: Gavel },
    { href: '/taxes/rules', label: (d) => d.nav.taxRules, icon: Scale, permission: 'fiscal.parameters.view' },
];

/* Everything the installation talks to on the taxpayer's behalf. SPV is the only one today, and the
   page's own words are already these: it speaks of a connection, of revoking one, of its sync runs. */
const CONNECTION_ITEMS: NavLink[] = [{ href: '/spv', label: (d) => d.nav.spv, icon: Landmark }];

/* The account has a page of its own *and* a child, the way `Impozite` does: taxpayers belong to an
   account, so the row navigates and the chevron folds the child in. */
const ACCOUNT_GROUP: NavLink = {
    href: '/account',
    label: (d) => d.nav.accountGroup,
    icon: UserRound,
    children: [{ href: '/taxpayers', label: (d) => d.nav.settingsSubjects, icon: Users }],
};

/* Above the administration group, not inside it: reading what happened is not administering the
   installation, and the people who do the first outnumber the people who do the second.

   Alone here now. The queue and the scheduled tasks used to share a screen with it, on the grounds
   that "what happened" does not say which table holds the answer — but both of those answer it by
   doing something, retrying a job or running a task, and this one is a chain of evidence nobody can
   write to. Reading and doing are what separate them, so the two that act moved to administration. */
const AUDIT_ITEM: NavLink = { href: '/audit', label: (d) => d.nav.adminAudit, icon: ScrollText };

const ADMIN_GROUP: NavLink = {
    id: 'admin',
    label: (d) => d.nav.adminGroup,
    icon: Settings,
    children: [
        { href: '/admin/settings', label: (d) => d.nav.adminSettings, icon: Settings },
        { href: '/admin/users', label: (d) => d.nav.adminUsers, icon: Users },
        /* Hidden rather than refused: both read the operations API, and a row that answers 403 when
           pressed tells a person about a permission instead of about the installation. */
        { href: '/admin/jobs', label: (d) => d.nav.adminJobs, icon: ListTodo, permission: 'operations.view' },
        {
            href: '/admin/tasks',
            label: (d) => d.nav.adminOperations,
            icon: CalendarClock,
            permission: 'operations.view',
        },
    ],
};

/**
 * The sidebar in bands, each named for the question it answers.
 *
 * TEST — this replaces a deliberately flat list. The note that stood here said headings had made
 * the sidebar "a page of its own to read", which is the thing to judge: whether three words of
 * grey earn their line, or whether the folds alone were already enough. Revert is one commit.
 *
 * The dashboard leads with no heading of its own: it is the one row that is not a kind of work.
 */
const NAV_SECTIONS: Array<{ id: string; heading?: (d: Dictionary) => string; items: NavLink[] }> = [
    { id: 'home', items: [DASHBOARD_ITEM] },
    // Analysis sits with the records it reads, not with the law. What a figure adds up to is a
    // question about the bookkeeping above it; what the law says is a different kind of question,
    // and the heading between them is the honest place to change subject.
    { id: 'records', heading: (d) => d.nav.sections.records, items: [RECORDS_GROUP, ANALYSIS_GROUP] },
    { id: 'law', heading: (d) => d.nav.sections.law, items: LAW_ITEMS },
    { id: 'connections', heading: (d) => d.nav.sections.connections, items: CONNECTION_ITEMS },
    { id: 'system', heading: (d) => d.nav.sections.system, items: [ACCOUNT_GROUP, AUDIT_ITEM, ADMIN_GROUP] },
];

/** Every address the menu declares, longest first — see {@link isActive}. */
const NAV_HREFS: string[] = NAV_SECTIONS.flatMap((section) =>
    section.items.flatMap((item) => [item.href, ...(item.children ?? []).map((child) => child.href)]),
)
    .filter((href): href is string => href !== undefined)
    .sort((left, right) => right.length - left.length);

/**
 * The active row is the **longest** declared address this URL sits under — not every address it
 * happens to begin with.
 *
 * `/taxes/rules` sits under `/taxes`, and the two are deliberately different rows: the rules are
 * read out of the law, so they hang off Legislation while their address stays where every link
 * already written points (see `LEGISLATION_GROUP`). A plain prefix test marked both, so opening Tax
 * rules told a reader they were on Taxes as well — two rows claiming to be the page you are on.
 *
 * The query string is dropped first. `usePage().url` carries it, and a page reached with one
 * (`/legislation/{id}?as_of=…`) is still that page.
 */
function isActive(currentUrl: string, href: string): boolean {
    const path = currentUrl.split('?')[0] ?? currentUrl;
    const match = NAV_HREFS.find((candidate) => path === candidate || path.startsWith(`${candidate}/`));

    return match === href;
}

async function handleLogout(): Promise<void> {
    try {
        await logout();
    } finally {
        // Full navigation, not an Inertia visit — guarantees a fresh CSRF token on the login
        // page's next document load (`resources/js/lib/csrf.ts`'s docblock).
        window.location.assign('/login');
    }
}

/** Where a notice takes you: the import it is about, in the taxpayer it belongs to. */
function openImportNotice(notice: ImportNotice): void {
    // A full load, not an Inertia visit: the notice can be about another taxpayer than the one
    // selected, and the shell reads the selection once, when the page loads.
    setActiveSubjectId(notice.subjectId);
    window.location.assign(`/wallet/imports/new?import=${encodeURIComponent(notice.importId)}`);
}

function noticeText(notice: ImportNotice, dictionary: Dictionary): { title: string; body: string } {
    const words = dictionary.shell.importNotices[notice.kind];
    const reason =
        notice.failureReason === null
            ? undefined
            : (dictionary.errors.reasons[notice.failureReason] ??
              dictionary.pages.walletImports.aiReasons[notice.failureReason]);

    return {
        title: words.title,
        body: words.body
            .replace('{rows}', String(notice.rowCount))
            .replace('{imported}', String(notice.importedCount))
            .replace('{suggested}', String(notice.rowsSuggested))
            .replace('{reason}', reason ?? dictionary.shell.importNoticeFailedUnknown),
    };
}

/**
 * Notifications: the signed-in person's own, whichever taxpayer each is about.
 *
 * The list is fetched once and then kept current from the person's private channel, so a statement
 * that finishes reading while they are on another page shows up here — and as a toast — without a
 * refresh. The unread count is the server's; a notice arriving live adds one to it.
 */
function NotificationsMenu({ userId }: { userId: string }): JSX.Element {
    const { t, locale } = useTranslation();
    const [notices, setNotices] = useState<ImportNotice[]>([]);
    const [unread, setUnread] = useState(0);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let active = true;

        listNotifications().then(
            (feed) => {
                if (active) {
                    setNotices(feed.notices);
                    setUnread(feed.unread);
                }
            },
            () => {
                if (active) {
                    setFailed(true);
                }
            },
        );

        const unsubscribe = subscribeToUserNotifications(userId, (notice) => {
            setNotices((current) => [notice, ...current.filter((entry) => entry.id !== notice.id)]);
            setUnread((count) => count + 1);

            const text = noticeText(notice, getDictionary());
            const show = notice.kind === 'FAILED' ? toast.error : toast.success;
            show(text.title, {
                description: text.body,
                action: {
                    label: getDictionary().shell.openNotice,
                    onClick: () => {
                        openImportNotice(notice);
                    },
                },
            });
        });

        return () => {
            active = false;
            unsubscribe();
        };
    }, [userId]);

    function markAllRead(): void {
        markAllNotificationsRead().then(
            () => {
                setUnread(0);
                setNotices((current) => current.map((entry) => ({ ...entry, read: true })));
            },
            () => {
                toast.error(t((d) => d.shell.notificationsFailed));
            },
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="shell-control relative size-8 justify-center px-0"
                    aria-label={t((d) => d.shell.notifications)}
                >
                    <Bell className="size-4" />
                    {unread > 0 && (
                        <span className="bg-accent-500 ring-card absolute top-[6px] right-[7px] size-1.5 rounded-full ring-2" />
                    )}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[min(320px,calc(100vw-32px))] p-0">
                <div className="border-border flex items-center justify-between border-b px-3 py-2.5">
                    <span className="text-xs font-semibold">{t((d) => d.shell.notifications)}</span>
                    {unread > 0 && (
                        <button
                            type="button"
                            className="text-accent-600 hover:text-accent-700 inline-flex items-center gap-1 text-xs font-semibold"
                            onClick={markAllRead}
                        >
                            <CheckCheck className="size-3" aria-hidden="true" />
                            {t((d) => d.shell.markAllRead)}
                        </button>
                    )}
                </div>
                <div className="max-h-[360px] overflow-y-auto p-1.5">
                    {notices.length === 0 && (
                        <p className="text-ink-400 px-2 py-3 text-xs leading-4">
                            {failed ? t((d) => d.shell.notificationsFailed) : t((d) => d.shell.notificationsEmpty)}
                        </p>
                    )}
                    {notices.map((notice) => {
                        const text = t((d) => noticeText(notice, d));

                        return (
                            <DropdownMenuItem
                                key={notice.id}
                                className="items-start gap-2.5"
                                onSelect={() => {
                                    openImportNotice(notice);
                                }}
                            >
                                <span
                                    className={cn(
                                        'mt-1 size-2 shrink-0 rounded-full',
                                        notice.read
                                            ? 'bg-border'
                                            : notice.kind === 'FAILED'
                                              ? 'bg-destructive'
                                              : 'bg-accent-500',
                                    )}
                                    aria-hidden="true"
                                />
                                <span className="min-w-0">
                                    <span className="block text-xs font-semibold">{text.title}</span>
                                    <span className="text-ink-400 mt-0.5 block text-xs leading-4">{text.body}</span>
                                    {notice.createdAt !== null && (
                                        <span className="text-ink-400 mt-0.5 block text-2xs tabular-nums">
                                            {formatDateTime(notice.createdAt, locale)}
                                        </span>
                                    )}
                                </span>
                            </DropdownMenuItem>
                        );
                    })}
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function UserMenu(): JSX.Element {
    const { t } = useTranslation();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button type="button" className="shell-control px-1.5 pr-2" aria-label={t((d) => d.shell.account)}>
                    <span className="bg-secondary text-secondary-foreground grid size-6 place-items-center rounded-lg text-2xs font-bold">
                        <Users className="size-3.5" aria-hidden="true" />
                    </span>
                    <ChevronDown className="text-muted-foreground size-3" aria-hidden="true" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem asChild>
                    <Link href="/account">{t((d) => d.nav.accountGroup)}</Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link href="/taxpayers">{t((d) => d.nav.settingsSubjects)}</Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive" onSelect={() => void handleLogout()}>
                    <LogOut className="size-4" /> {t((d) => d.shell.signOut)}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** One nav row. `collapsed` (desktop rail mode) hides everything but the icon, behind a `Tooltip`
 * carrying the real label so the control stays discoverable and accessible even iconified. */
function NavRow({
    item,
    collapsed,
    currentUrl,
    open,
    onToggle,
}: {
    item: NavLink;
    collapsed: boolean;
    currentUrl: string;
    /** Whether this group's children are showing. Meaningless for a row without children. */
    open: boolean;
    onToggle: () => void;
}): JSX.Element {
    const { t } = useTranslation();
    const Icon = item.icon;
    const label = t(item.label);
    const children = item.children ?? [];
    const hasChildren = children.length > 0;
    // A group with no page of its own is "active" when one of its children is — that is what makes
    // the fold state meaningful.
    const active =
        item.href !== undefined
            ? isActive(currentUrl, item.href)
            : children.some((child) => isActive(currentUrl, child.href));

    // `.nav-item` carries the whole treatment — hover, the brand wash and the indicator bar — keyed
    // off `aria-current`, so the state a screen reader announces and the state the eye sees are the
    // same attribute and cannot disagree.
    const rowClass = cn('nav-item', collapsed && 'nav-item-rail', hasChildren && 'nav-item-group');
    const rowBody = (
        <>
            <Icon className="size-4 shrink-0" aria-hidden="true" />
            {!collapsed && <span className="truncate">{label}</span>}
        </>
    );

    const link =
        item.href === undefined ? (
            <button type="button" onClick={onToggle} aria-expanded={open} className={rowClass}>
                {rowBody}
            </button>
        ) : (
            <Link href={item.href} aria-current={active ? 'page' : undefined} className={rowClass}>
                {rowBody}
            </Link>
        );

    return (
        <li>
            {collapsed ? (
                <Tooltip>
                    <TooltipTrigger asChild>{link}</TooltipTrigger>
                    <TooltipContent side="right">{label}</TooltipContent>
                </Tooltip>
            ) : hasChildren ? (
                <div className="relative flex items-center">
                    {link}
                    <button
                        type="button"
                        onClick={onToggle}
                        aria-expanded={open}
                        aria-label={label}
                        className="text-ink-400 hover:text-foreground absolute right-1 grid size-6 place-items-center rounded-md"
                    >
                        <ChevronDown
                            className={cn('size-3.5 transition-transform', !open && '-rotate-90')}
                            aria-hidden="true"
                        />
                    </button>
                </div>
            ) : (
                link
            )}
            {!collapsed && open && hasChildren && (
                /* A rail down the children, so a fold reads as one branch rather than four rows that
                   happen to be indented. The indent is the rail's offset plus `pl-3`, which is what
                   the active child's mark is positioned against in `app.css`. */
                <ul className="border-border mt-0.5 ml-[1.125rem] space-y-0.5 border-l pl-3">
                    {children.map((child) => {
                        const childActive = isActive(currentUrl, child.href);
                        const ChildIcon = child.icon;

                        return (
                            <li key={child.href}>
                                <Link
                                    href={child.href}
                                    aria-current={childActive ? 'page' : undefined}
                                    className="nav-item nav-item-child"
                                >
                                    <ChildIcon className="size-4 shrink-0" aria-hidden="true" />
                                    <span className="truncate">{t(child.label)}</span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            )}
        </li>
    );
}

/**
 * The sidebar's fiscal snapshot.
 *
 * The figures are the design's own placeholders, kept deliberately so the shell is complete while
 * the taxes module that will supply them does not exist yet. They are declared here, once, rather
 * than scattered through the markup — when `Impozite` can answer "how many obligations, due when",
 * this component changes and nothing else does.
 */
const SNAPSHOT_PLACEHOLDER = { obligations: 2, nextDeadline: 'Sep 25', period: 'Sep' } as const;

function FiscalSnapshot(): JSX.Element {
    const { t } = useTranslation();

    return (
        <div className="border-border mx-2.5 mt-4 mb-3 rounded-xl border bg-[#F8FAFC] p-2.5 dark:bg-[#2A2C30]">
            <div className="flex items-center justify-between gap-2">
                <span className="text-muted-foreground text-2xs font-bold tracking-[0.12em] uppercase">
                    {t((d) => d.shell.fiscalSnapshot)}
                </span>
                <span className="shadow-hair text-ink-500 dark:text-ink-400 rounded-md bg-white px-1.5 py-0.5 text-2xs font-semibold dark:bg-[#202225]">
                    {SNAPSHOT_PLACEHOLDER.period}
                </span>
            </div>
            <div className="mt-2 flex items-end justify-between gap-2">
                <div>
                    <div className="text-xs font-semibold">
                        {t((d) => d.shell.obligationCount).replace('{count}', String(SNAPSHOT_PLACEHOLDER.obligations))}
                    </div>
                    <div className="text-muted-foreground mt-0.5 text-2xs">
                        {t((d) => d.shell.nextDeadline).replace('{date}', SNAPSHOT_PLACEHOLDER.nextDeadline)}
                    </div>
                </div>
                <div className="text-accent-700 dark:text-accent-200 shadow-hair grid size-7 place-items-center rounded-lg bg-white dark:bg-[#202225]">
                    <CalendarClock className="size-3.5" aria-hidden="true" />
                </div>
            </div>
        </div>
    );
}

/** A group's identity: its route if it has one, otherwise the id declared alongside it. */
function groupKey(item: NavLink): string {
    return item.href ?? item.id ?? '';
}

function SidebarNav({ collapsed, currentUrl }: { collapsed: boolean; currentUrl: string }): JSX.Element {
    const { t } = useTranslation();

    /**
     * Folds the person has changed by hand, keyed by group.
     *
     * Only overrides live here, not the open/closed state itself — the default is "the group you
     * are in is open, the rest are closed", which has to keep working as you navigate. Storing the
     * state outright would freeze whatever was open when the page first rendered.
     */
    const [overrides, setOverrides] = useState<Record<string, boolean>>({});

    const permissions = useSharedAuth()?.user.permissions ?? [];

    /** What this account may actually open — a row it has no permission for is not drawn at all. */
    function allowed(entry: { permission?: string }): boolean {
        return entry.permission === undefined || permissions.includes(entry.permission);
    }

    function visible(item: NavLink): NavLink {
        return item.children === undefined ? item : { ...item, children: item.children.filter(allowed) };
    }

    const sections = NAV_SECTIONS.map((section) => ({
        ...section,
        items: section.items
            .filter((item) => item !== ADMIN_GROUP || ADMIN_UI_ENABLED)
            .filter(allowed)
            .map(visible),
    })).filter((section) => section.items.length > 0);

    function isOpen(item: NavLink): boolean {
        const key = groupKey(item);
        // The group you are *in* — which includes standing on the group's own page, not only on one
        // of its children. Landing on `/taxes` and finding Taxes folded shut is the same surprise as
        // landing on `/wallet/accounts` and finding Wallet shut.
        const containsCurrent =
            (item.href !== undefined && isActive(currentUrl, item.href)) ||
            (item.children ?? []).some((child) => isActive(currentUrl, child.href));

        return overrides[key] ?? containsCurrent;
    }

    return (
        <nav
            aria-label={t((d) => d.nav.dashboard)}
            className="scroll-hairline flex flex-1 flex-col overflow-y-auto px-2.5 pt-1 pb-3"
        >
            {sections.map((section) => (
                <Fragment key={section.id}>
                    {/* A dashed rule, which is what this interface already means by "these belong
                        apart but not to different pages" — the stacked tables draw the same line
                        between a card's labelled pairs. Solid would read as the end of something.

                        The first band is the dashboard alone and needs neither line nor name. A
                        rail-collapsed sidebar keeps the line and drops the words: at 64px there is
                        no room to read one, and the rule alone still groups the icons. */}
                    {section.heading !== undefined && (
                        <div
                            className={cn(
                                'border-border text-ink-400 mt-3 border-t border-dashed px-2.5 text-2xs font-bold tracking-[0.12em] uppercase',
                                collapsed ? 'mb-1' : 'pt-2 pb-1',
                            )}
                        >
                            {!collapsed && t(section.heading)}
                        </div>
                    )}
                    <ul className="space-y-0.5">
                        {section.items.map((item) => (
                            <NavRow
                                key={groupKey(item)}
                                item={item}
                                collapsed={collapsed}
                                currentUrl={currentUrl}
                                open={isOpen(item)}
                                onToggle={() => {
                                    const key = groupKey(item);

                                    setOverrides((current) => ({ ...current, [key]: !isOpen(item) }));
                                }}
                            />
                        ))}
                    </ul>
                </Fragment>
            ))}
        </nav>
    );
}

/** The product's mark and name, as the sidebar and the phone drawer both open with. */
function SidebarBrand(): JSX.Element {
    const { t } = useTranslation();
    const appName = useAppName();

    return (
        <div className="flex min-w-0 flex-1 items-center gap-2.5">
            <div className="bg-ink-950 dark:bg-white dark:text-ink-950 grid size-8 shrink-0 place-items-center rounded-[10px] text-xs font-bold text-white shadow-sm">
                T
            </div>
            <div className="min-w-0">
                <div className="truncate text-sm font-semibold tracking-[-.02em]">{appName}</div>
                <div className="text-ink-400 truncate text-2xs">{t((d) => d.shell.appTagline)}</div>
            </div>
        </div>
    );
}

/**
 * Everything under the sidebar's header: where you can go, what is due, and whose affairs you are
 * looking at.
 *
 * One component because the phone drawer is the same sidebar, not a reduced copy of it. It used to
 * be a copy, and the copy had drifted: the drawer held the navigation alone, so a phone had no way
 * to change taxpayer at all — the control exists once, here, and the aside that held it is hidden
 * below 1024px. A card of empty white under the last menu row was the visible half of that.
 */
function SidebarBody({ collapsed, currentUrl }: { collapsed: boolean; currentUrl: string }): JSX.Element {
    return (
        <>
            <SidebarNav collapsed={collapsed} currentUrl={currentUrl} />

            {/* The subject switcher lives at the foot of the sidebar, not in the header. Which
                taxpayer you are working on frames everything on the page, so it belongs with the
                navigation it scopes rather than among the appearance controls. */}
            {!collapsed && <FiscalSnapshot />}

            <div className={cn('border-border border-t', collapsed ? 'p-2' : 'p-2.5')}>
                <SubjectSwitcher collapsed={collapsed} />
            </div>
        </>
    );
}

const SIDEBAR_COLLAPSED_KEY = 'kern.sidebarCollapsed';

function readStoredCollapsed(): boolean {
    try {
        return localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1';
    } catch {
        return false;
    }
}

/**
 * The one authenticated-page layout every screen renders inside of
 * (`docs/handoff/040-ui-foundation.md` "AppShell"). Desktop: a collapsible left sidebar (persisted
 * per-device, non-sensitive UI state — `BLUEPRINT.md` §17) holding every navigation destination,
 * grouped. Phone: the same navigation becomes a `Sheet` drawer opened from the topbar's hamburger
 * button (`BLUEPRINT.md` §1.4's "mobile-first" — a drawer beats a bottom tab bar once the menu holds
 * this many destinations). The active item is derived from Inertia's own `usePage().url`, so no
 * page needs to pass a "which screen am I" prop.
 */
export default function AppShell({
    children,
    contentClassName,
    title,
    description,
}: {
    children: ReactNode;
    contentClassName?: string;
    /** What this screen is, shown in the header. Falls back to the product name. */
    title?: string;
    /** One line under the title, on wide screens only. */
    description?: string;
}): JSX.Element {
    const { t } = useTranslation();
    const { url } = usePage();
    const appName = useAppName();
    const auth = useSharedAuth();
    const [collapsed, setCollapsed] = useState(readStoredCollapsed);
    const [mobileOpen, setMobileOpen] = useState(false);
    const mainRef = useRef<HTMLElement>(null);
    const { pull, refreshing } = usePullToRefresh(mainRef);
    // Published by whichever `Page` is rendered below; the shell is where it is drawn.
    const header = usePageHeader();

    function toggleCollapsed(): void {
        setCollapsed((current) => {
            const next = !current;

            try {
                localStorage.setItem(SIDEBAR_COLLAPSED_KEY, next ? '1' : '0');
            } catch {
                // Non-fatal — the preference just won't survive a reload on this device.
            }

            return next;
        });
    }

    return (
        // The page itself never scrolls. Sidebar and header are fixed furniture and only `main`
        // moves — which is what makes this read as an application rather than a document, and is
        // the single biggest reason the previous version did not look like the design.
        //
        // The impersonation strip sits *outside* that frame, across the top: it is true of the
        // whole session rather than of the screen, and it must not scroll away.
        <div className="bg-background safe-area-frame flex h-dvh flex-col overflow-hidden">
            <ImpersonationBanner />
            <ConnectionBanner />
            <SystemHealthBanner />

            <div className="flex min-h-0 flex-1 overflow-hidden">
                <aside
                    className={cn(
                        'shell-surface border-border hidden h-full flex-col border-r pb-[env(safe-area-inset-bottom)] transition-[width] duration-200 lg:flex',
                        collapsed ? 'w-16' : 'w-[272px]',
                    )}
                >
                    <div className="flex h-14 items-center gap-2 px-3">
                        {!collapsed && <SidebarBrand />}
                        <button
                            type="button"
                            className="focus-ring border-border dark:bg-card ml-auto grid size-8 place-items-center rounded-lg border bg-white text-slate-500 hover:bg-slate-50 dark:hover:bg-[#33363B]"
                            aria-label={collapsed ? t((d) => d.shell.expandSidebar) : t((d) => d.shell.collapseSidebar)}
                            onClick={toggleCollapsed}
                        >
                            {collapsed ? <PanelLeftOpen className="size-4" /> : <PanelLeftClose className="size-4" />}
                        </button>
                    </div>

                    <SidebarBody collapsed={collapsed} currentUrl={url} />
                </aside>

                {/* `min-w-0` is load-bearing: this is a flex item on a horizontal main axis, so its default
                `min-width: auto` refuses to shrink below its content. A wide table inside then pushes this
                whole column out and the *page* scrolls sideways, instead of the table's own wrapper. */}
                <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
                    <header className="shell-surface border-border flex min-h-14 shrink-0 items-center gap-3 border-b px-3 py-2 sm:px-5">
                        <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
                            <SheetTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    className="lg:hidden"
                                    aria-label={t((d) => d.shell.openMenu)}
                                >
                                    <Menu className="size-5" />
                                </Button>
                            </SheetTrigger>
                            {/* `gap-0`: the sheet spaces its children by default, which left a band of
                                nothing between the header's rule and the first menu row. The parts
                                below carry their own spacing, as they do in the sidebar. */}
                            {/* The sidebar's own width, so the drawer is that sidebar and not a wider
                                relative of it. Stated through the same `data-[side=left]:` variant the
                                sheet uses for its default `w-3/4`: a plain `w-[272px]` is one class
                                against a class *and* an attribute, so it loses and never applied — on
                                a tablet that default is 615px of menu over the page it belongs to. */}
                            <SheetContent
                                side="left"
                                className="shell-surface safe-area gap-0 p-0 data-[side=left]:w-[272px]"
                            >
                                {/* The same height as the sidebar's header and the page's, so the
                                    drawer's rule continues the topbar's instead of cutting across it.
                                    The title is the accessible name Radix requires; the mark beside
                                    it is what the eye reads, so the words are not drawn twice. */}
                                <SheetHeader className="border-border h-14 justify-center border-b p-0 px-3">
                                    <SheetTitle className="sr-only">{appName}</SheetTitle>
                                    <SidebarBrand />
                                </SheetHeader>
                                <SidebarBody collapsed={false} currentUrl={url} />
                            </SheetContent>
                        </Sheet>

                        {/*
                            The page says what it is; the shell does not repeat the product name on
                            every screen.

                            On a phone it says it to a screen reader only. The bar there holds a menu
                            button and four controls, and a page name between them left none of it
                            room — while the first card below almost always carries the same words in
                            its own head. `sr-only` rather than `hidden`, because a document with no
                            `h1` is a document a screen reader cannot summarise, and the name is the
                            one thing it should be able to say.

                            Colours from the tokens, not from hexes: this was the one place in the
                            application painting text `#7C8592` and `#B5B9C0`, which is `muted`
                            under another name and does not follow a palette when one is switched.

                            Sized as chrome, not as a heading. It was `lg` — 18px, four pixels over
                            every card title on the screen below it and the largest text in the
                            application — so the bar announced the page louder than the page did.
                            The note on the type scale makes the argument for the card title it broke:
                            two or three pixels above the text it introduces is where a title starts
                            reading as a page heading. `base` over `2xs` is the same pairing a card
                            head uses, which is what this is.
                        */}
                        <div className="min-w-0 flex-1">
                            <h1 className="text-foreground sr-only truncate text-base leading-5 font-semibold tracking-tight sm:not-sr-only">
                                {header?.title ?? title ?? appName}
                            </h1>
                            {(header?.description ?? description) !== undefined && (
                                <p className="text-muted-foreground mt-px hidden truncate text-2xs leading-4 sm:block">
                                    {header?.description ?? description}
                                </p>
                            )}
                        </div>

                        {/* Theme, palette and language are in the account's Appearance card: on every
                            screen they took most of a phone's bar for a choice made once. */}
                        {auth !== null && auth.user.id !== '' && <NotificationsMenu userId={auth.user.id} />}
                        <UserMenu />
                    </header>

                    {/*
                        `relative`, which is not decoration: it is what makes this element the
                        containing block for the absolutely positioned boxes inside it.

                        Without it, a `.sr-only` span — `position: absolute`, which is how the
                        utility hides text while leaving it readable to a screen reader — resolves
                        against the initial containing block instead. An overflow only clips its own
                        descendants, and a box positioned against something outside this one is not
                        one: the twelve tooltip labels on the tasks screen therefore sat in the
                        *document's* layout overflow, at the y where their rows would have been, and
                        the window grew a second scrollbar beside this element's own. Positioned,
                        they resolve here and are clipped here.
                    */}
                    <main
                        ref={mainRef}
                        className="relative min-w-0 flex-1 overflow-y-auto overscroll-y-contain p-3 pb-[calc(--spacing(3)+env(safe-area-inset-bottom))] sm:p-5 sm:pb-[calc(--spacing(5)+env(safe-area-inset-bottom))]"
                    >
                        <PullIndicator pull={pull} refreshing={refreshing} label={t((d) => d.shell.refreshing)} />
                        {/*
                            The cap widens on a large monitor, not below one.
                            `2xl:` is 1536px and up, so every laptop keeps the 1420px it had; past
                            that the content takes 1680px and the margins stop growing as fast. It
                            does not go further because this number has to suit a settings form as
                            well as a table, and a form two thousand pixels wide reads worse than one
                            at fourteen hundred. A screen that is genuinely table-shaped says so with
                            `contentClassName`, which lands after this and wins.
                        */}
                        <div
                            className={cn(
                                'mx-auto w-full max-w-[1420px] space-y-4 2xl:max-w-[1680px]',
                                contentClassName,
                            )}
                        >
                            {children}
                        </div>
                    </main>
                </div>
            </div>
        </div>
    );
}
