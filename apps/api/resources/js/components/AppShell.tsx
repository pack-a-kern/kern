import { Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Bell,
    BellOff,
    Building2,
    Calculator,
    CalendarClock,
    ChartColumn,
    CheckCheck,
    ChevronDown,
    CloudOff,
    FileSpreadsheet,
    Files,
    Gavel,
    Hourglass,
    LayoutDashboard,
    ListTodo,
    Landmark,
    LogOut,
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
import { Fragment, useEffect, useRef, useState, type CSSProperties, type JSX, type ReactNode } from 'react';
import { toast } from 'sonner';
import { listNotifications, markAllNotificationsRead } from '../api/notificationsApi';
import ConnectionBanner from './ConnectionBanner';
import SystemHealthBanner from './SystemHealthBanner';
import { PullIndicator } from './PullToRefresh';
import { usePullToRefresh } from '../hooks/usePullToRefresh';
import { useNavigationPending } from '../hooks/useNavigationPending';
import { useMediaQuery } from '../hooks/useMediaQuery';
import ImpersonationBanner from './ImpersonationBanner';
import { usePageHeader } from '../lib/pageHeader';
import { getDictionary, useTranslation } from '../i18n';
import type { Dictionary } from '../i18n/dictionary';
import { ADMIN_UI_ENABLED } from '../lib/featureFlags';
import { cn } from '../lib/utils';
import { BOTTOM_BAR } from '../lib/bottomBar';
import { subscribeToTaxpayerChanges, subscribeToUserNotifications } from '../lib/echo';
import { formatDate, formatDateTime } from '../lib/format';
import { getFilingDeadline } from '../api/fiscalParametersApi';
import type { FilingDeadline } from '../types/fiscalParameters';
import { refreshSharedAuth, useAppName, useSharedAuth } from '../lib/sharedProps';
import type { Notice } from '../types/notifications';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from './ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipTrigger } from './ui/tooltip';
import SubjectSwitcher, { SubjectChooser } from './SubjectSwitcher';
import { ThemeToggle } from './ShellControls';
import { signOut } from '../lib/signOut';
import { useSettingsLink } from '../lib/settingsLink';
import AppMark from './AppMark';
import { EmptyState, PanelButton } from './panel';

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
/** The taxes page: in the menu, and where the fiscal snapshot leads. */
const TAXES_HREF = '/taxes';

const ANALYSIS_GROUP: NavLink = {
    id: 'analysis',
    label: (d) => d.nav.analysis,
    icon: ChartColumn,
    children: [
        { href: TAXES_HREF, label: (d) => d.nav.taxes, icon: Calculator },
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

/**
 * Where a notice takes you: the path it names, inside this application and nowhere else.
 *
 * A full load, not an Inertia visit: the link can name another taxpayer than the one selected
 * (`?subject=`), and the shell reads which one once, when the page loads.
 */
function openNotice(notice: Notice): void {
    const target = new URL(notice.url, window.location.origin);

    window.location.assign(target.origin === window.location.origin ? target.href : '/');
}

/**
 * Notifications: the signed-in person's own, whichever taxpayer each is about.
 *
 * Unread ones only: marking them read empties it. The list is fetched once and fetched again whenever
 * the person's private channel announces a notice, so one raised while they are on another page shows
 * up here — and as a toast — without a refresh.
 */
function NotificationsMenu({ userId }: { userId: string }): JSX.Element {
    const { t, locale } = useTranslation();
    const [notices, setNotices] = useState<Notice[]>([]);
    const [unread, setUnread] = useState(0);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let active = true;

        listNotifications(locale).then(
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

        // The socket says a notice was stored and which; its words come with the list, over HTTPS.
        const unsubscribe = subscribeToUserNotifications(userId, (noticeId) => {
            listNotifications(locale).then(
                (feed) => {
                    if (!active) {
                        return;
                    }

                    setNotices(feed.notices);
                    setUnread(feed.unread);

                    const notice = feed.notices.find((entry) => entry.id === noticeId);

                    if (notice !== undefined) {
                        (notice.level === 'error' ? toast.error : toast.success)(notice.title, {
                            description: notice.body,
                            action: {
                                label: getDictionary().shell.openNotice,
                                onClick: () => {
                                    openNotice(notice);
                                },
                            },
                        });
                    }
                },
                () => {
                    // The bell keeps what it last read; the next notice, or the next page, reads again.
                },
            );
        });

        return () => {
            active = false;
            unsubscribe();
        };
    }, [userId, locale]);

    // Read again each time it is opened: the frame stays mounted for the whole visit, and the socket
    // says when a notice arrives but not when one was read on another device.
    function refresh(): void {
        listNotifications(locale).then(
            (feed) => {
                setNotices(feed.notices);
                setUnread(feed.unread);
                setFailed(false);
            },
            () => {
                // What it last read stays on screen; opening it again asks again.
            },
        );
    }

    function markAllRead(): void {
        markAllNotificationsRead().then(
            () => {
                setUnread(0);
                setNotices([]);
            },
            () => {
                toast.error(t((d) => d.shell.notificationsFailed));
            },
        );
    }

    return (
        <DropdownMenu
            onOpenChange={(open) => {
                if (open) {
                    refresh();
                }
            }}
        >
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="shell-control bar-icon relative size-8 justify-center px-0"
                    aria-label={t((d) => d.shell.notifications)}
                >
                    <Bell className="size-4" />
                    {unread > 0 && (
                        <span className="bg-accent-500 ring-card absolute top-[6px] right-[7px] size-1.5 rounded-full ring-2" />
                    )}
                </button>
            </DropdownMenuTrigger>
            {/* Below its button at 320px on a desktop; on a phone across the screen, each notice its
                own card, the way a phone's own notifications read (`.shell-sheet-menu`). */}
            <DropdownMenuContent align="end" collisionPadding={12} className="shell-sheet-menu w-80 p-0">
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
                <div className="notice-list max-h-[360px] overflow-y-auto p-1.5">
                    {notices.length === 0 &&
                        (failed ? (
                            <EmptyState
                                icon={<CloudOff className="size-5" />}
                                title={t((d) => d.shell.notificationsFailed)}
                            />
                        ) : (
                            <EmptyState
                                icon={<BellOff className="size-5" />}
                                title={t((d) => d.shell.notificationsEmpty)}
                                description={t((d) => d.shell.notificationsEmptyHint)}
                            />
                        ))}
                    {notices.map((notice) => (
                        <DropdownMenuItem
                            key={notice.id}
                            className="notice-item items-start gap-2.5"
                            onSelect={() => {
                                openNotice(notice);
                            }}
                        >
                            <span
                                className={cn(
                                    'mt-1 size-2 shrink-0 rounded-full',
                                    notice.level === 'error' ? 'bg-destructive' : 'bg-accent-500',
                                )}
                                aria-hidden="true"
                            />
                            <span className="min-w-0">
                                <span className="block text-xs font-semibold">{notice.title}</span>
                                <span className="text-ink-400 mt-0.5 block text-xs leading-4">{notice.body}</span>
                                {notice.createdAt !== null && (
                                    <span className="text-ink-400 mt-0.5 block text-2xs tabular-nums">
                                        {formatDateTime(notice.createdAt, locale)}
                                    </span>
                                )}
                            </span>
                        </DropdownMenuItem>
                    ))}
                </div>
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
 * The sidebar's fiscal snapshot: the next Declarația unică and the days left to it, as a person
 * approved its day from the law (`RO.INCOME_TAX.FILING_DEADLINE`). Opens the taxes page.
 *
 * Nothing at all until that day is known — not a placeholder date, which would be a deadline made
 * up — and nothing when the request fails: the connection banner already says what is wrong.
 */
function FiscalSnapshot(): JSX.Element | null {
    const { t, locale } = useTranslation();
    const [deadline, setDeadline] = useState<FilingDeadline | null>(null);

    useEffect(() => {
        let current = true;

        getFilingDeadline().then(
            (next) => {
                if (current) {
                    setDeadline(next);
                }
            },
            () => undefined,
        );

        return () => {
            current = false;
        };
    }, []);

    if (deadline === null) {
        return null;
    }

    return (
        <Link
            href={TAXES_HREF}
            className="border-border hover:border-ring/40 mx-2.5 mt-4 mb-3 block rounded-xl border bg-[#F8FAFC] p-2.5 transition-colors dark:bg-[#2A2C30]"
        >
            <div className="flex items-center justify-between gap-2">
                <span className="text-muted-foreground text-2xs font-bold tracking-[0.12em] uppercase">
                    {t((d) => d.shell.fiscalSnapshot)}
                </span>
                <span className="shadow-hair text-ink-500 dark:text-ink-400 inline-flex items-center gap-1 rounded-md bg-white px-1.5 py-0.5 text-2xs font-semibold tabular-nums dark:bg-[#202225]">
                    <Hourglass className="size-3" aria-hidden="true" />
                    {deadline.daysLeft === 0
                        ? t((d) => d.shell.dueToday)
                        : t((d) => d.shell.daysLeft).replace('{days}', String(deadline.daysLeft))}
                </span>
            </div>
            <div className="mt-2 flex items-end justify-between gap-2">
                <div>
                    <div className="text-xs font-semibold">
                        {t((d) => d.shell.declarationFor).replace('{year}', String(deadline.incomeYear))}
                    </div>
                    <div className="text-muted-foreground mt-0.5 text-2xs">
                        {t((d) => d.shell.dueOn).replace('{date}', formatDate(deadline.dueOn, locale))}
                    </div>
                </div>
                <div className="text-accent-700 dark:text-accent-200 shadow-hair grid size-7 place-items-center rounded-lg bg-white dark:bg-[#202225]">
                    <CalendarClock className="size-3.5" aria-hidden="true" />
                </div>
            </div>
        </Link>
    );
}

/** A group's identity: its route if it has one, otherwise the id declared alongside it. */
function groupKey(item: NavLink): string {
    return item.href ?? item.id ?? '';
}

/**
 * The menu as this account may see it: every row it has no permission for left out, and every group
 * holding only the children it may open. The sidebar draws it; on a phone, the bar along the bottom
 * and the tabs above a page draw the same list, so the two can never offer different destinations.
 */
function useVisibleSections(): typeof NAV_SECTIONS {
    const permissions = useSharedAuth()?.user.permissions ?? [];

    function allowed(entry: { permission?: string }): boolean {
        return entry.permission === undefined || permissions.includes(entry.permission);
    }

    return NAV_SECTIONS.map((section) => ({
        ...section,
        items: section.items
            .filter((item) => item !== ADMIN_GROUP || ADMIN_UI_ENABLED)
            .filter(allowed)
            .map((item) => (item.children === undefined ? item : { ...item, children: item.children.filter(allowed) })),
    })).filter((section) => section.items.length > 0);
}

/** Where a row goes when pressed: its own page, or a container group's first page. */
function firstHref(item: NavLink): string | undefined {
    return item.href ?? item.children?.[0]?.href;
}

/** Whether the page open now is this row's own or one of its children's. */
function containsCurrent(item: NavLink, currentUrl: string): boolean {
    return (
        (item.href !== undefined && isActive(currentUrl, item.href)) ||
        (item.children ?? []).some((child) => isActive(currentUrl, child.href))
    );
}

/** A group's pages as the strip above the bottom bar lists them: its own page first, if it has one. */
function sectionPages(item: NavLink | undefined): NavLink[] {
    if (item?.children === undefined) {
        return [];
    }

    const pages = [
        ...(item.href === undefined ? [] : [{ href: item.href, label: item.label, icon: item.icon }]),
        ...item.children,
    ];

    // A section with one page has nothing to choose between.
    return pages.length < 2 ? [] : pages;
}

/**
 * The phone's navigation: every section of the menu in one bar along the bottom, and above them, in
 * the same bar, the pages of the section you are in. Replaces the drawer below `lg` — the sidebar is
 * still the menu from there up. A group goes to its first page.
 *
 * A few sections at a time ({@link BOTTOM_BAR}), the one you are in kept towards the middle and the
 * rest a swipe away, snapping to whole tabs. How it looks — names, the mark on the current section,
 * floating or edge to edge — is {@link BOTTOM_BAR} too, read by app.css from the `data-*` here. The
 * page's scroll area leaves `--dock-space` below its content so the last card is never under it.
 */
function BottomNav({ currentUrl: loadedUrl }: { currentUrl: string }): JSX.Element {
    const { t } = useTranslation();
    // Where a tap is going, marked at once rather than when the page arrives — the bar answers the
    // finger, not the network. Dropped when the visit ends, arrived or not, so a failed visit leaves
    // the page you are still on marked.
    const [going, setGoing] = useState<string | null>(null);
    const currentUrl = going ?? loadedUrl;

    useEffect(
        () =>
            router.on('finish', () => {
                setGoing(null);
            }),
        [],
    );

    const items = useVisibleSections()
        .flatMap((section) => section.items)
        .filter((item) => firstHref(item) !== undefined);
    const track = useRef<HTMLDivElement>(null);
    const strip = useRef<HTMLDivElement>(null);
    const here = items.findIndex((item) => containsCurrent(item, currentUrl));
    const pages = sectionPages(items[here]);
    const shown = Math.min(items.length, BOTTOM_BAR.shown);
    // Slots across the bar: one per section shown, and the current tab's extra ones when it carries its name.
    const slots = shown + (BOTTOM_BAR.labels === 'beside' ? BOTTOM_BAR.activeSlots - 1 : 0);
    const first = useRef(true);

    // The window of sections moves so the one you are in has as many on either side as the ends
    // allow: tap the fourth of five and it slides one along. Every tab before the current one is
    // one slot wide, so the offset is a whole number of slots and no icon is ever cut at an edge.
    useEffect(() => {
        const bar = track.current;

        if (bar === null || here < 0) {
            return;
        }

        const start = Math.max(0, Math.min(here - Math.floor(shown / 2), items.length - shown));
        bar.scrollTo({ left: (start * bar.clientWidth) / slots, behavior: first.current ? 'instant' : 'smooth' });
        first.current = false;
    }, [here, shown, slots, items.length]);

    // The page you are on, centred in the strip — by moving the strip sideways only: `scrollIntoView`
    // would also scroll the page, and the page is where the reader is.
    useEffect(() => {
        const row = strip.current;
        const page = row?.querySelector<HTMLElement>('[aria-current="page"]') ?? null;

        if (row !== null && page !== null) {
            row.scrollTo({ left: page.offsetLeft - (row.clientWidth - page.offsetWidth) / 2 });
        }
    }, [currentUrl]);

    return (
        <nav
            className="bottom-dock"
            data-labels={BOTTOM_BAR.labels}
            data-mark={BOTTOM_BAR.mark}
            data-shape={BOTTOM_BAR.shape}
            aria-label={t((d) => d.nav.dashboard)}
        >
            <div className="bottom-dock-strip" data-open={pages.length > 0 ? '' : undefined}>
                <div ref={strip} className="bottom-dock-pages">
                    {pages.map((page) => {
                        const Icon = page.icon;

                        return (
                            <Link
                                key={page.href}
                                href={page.href ?? '/'}
                                className="bottom-page"
                                onClick={() => {
                                    setGoing(page.href ?? null);
                                }}
                                aria-current={
                                    page.href !== undefined && isActive(currentUrl, page.href) ? 'page' : undefined
                                }
                            >
                                <Icon className="size-3.5 shrink-0" strokeWidth={1.5} aria-hidden="true" />
                                {t(page.label)}
                            </Link>
                        );
                    })}
                </div>
            </div>
            <div
                ref={track}
                className="bottom-dock-track"
                style={{ '--dock-slots': slots, '--dock-active-slots': BOTTOM_BAR.activeSlots } as CSSProperties}
            >
                {items.map((item, index) => {
                    const Icon = item.icon;

                    return (
                        <Link
                            key={groupKey(item)}
                            href={firstHref(item) ?? '/'}
                            className="bottom-tab"
                            onClick={() => {
                                setGoing(firstHref(item) ?? null);
                            }}
                            aria-current={index === here ? 'page' : undefined}
                        >
                            <span className="bottom-tab-icon">
                                <Icon strokeWidth={1.5} aria-hidden="true" />
                            </span>
                            {/* Always in the document, whatever the bar shows: a screen reader reads
                                every tab's name. */}
                            <span className="bottom-tab-label">{t(item.label)}</span>
                        </Link>
                    );
                })}
            </div>
        </nav>
    );
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

    const sections = useVisibleSections();

    function isOpen(item: NavLink): boolean {
        const key = groupKey(item);
        // The group you are *in* — which includes standing on the group's own page, not only on one
        // of its children. Landing on `/taxes` and finding Taxes folded shut is the same surprise as
        // landing on `/wallet/accounts` and finding Wallet shut.
        const here = containsCurrent(item, currentUrl);

        return overrides[key] ?? here;
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
            <AppMark className="size-8" />
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

            <div className={cn('border-border grid gap-2 border-t', collapsed ? 'p-2' : 'p-2.5')}>
                <SubjectSwitcher collapsed={collapsed} />
                <QuickControls collapsed={collapsed} />
            </div>
        </>
    );
}

/**
 * Under the account card, what is reached for often enough to be one press rather than two:
 * settings, light or dark, and signing out. Side by side under the card; stacked on the
 * rail, where the card is only its mark.
 */
function QuickControls({ collapsed }: { collapsed: boolean }): JSX.Element {
    const { t } = useTranslation();
    const side = collapsed ? 'right' : 'top';
    const settings = useSettingsLink();
    const leave = t((d) => d.shell.signOut);

    return (
        <div className={cn('quick-controls', collapsed && 'quick-controls-rail')}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <Link href={settings.href} className="shell-control quick-control" aria-label={settings.label}>
                        <Settings className="size-4" aria-hidden="true" />
                    </Link>
                </TooltipTrigger>
                <TooltipContent side={side}>{settings.label}</TooltipContent>
            </Tooltip>
            <ThemeToggle className="quick-control" />
            <Tooltip>
                <TooltipTrigger asChild>
                    <button
                        type="button"
                        className="shell-control quick-control quick-control-leave"
                        aria-label={leave}
                        onClick={() => {
                            void signOut();
                        }}
                    >
                        <LogOut className="size-4" aria-hidden="true" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side={side}>{leave}</TooltipContent>
            </Tooltip>
        </div>
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
 * grouped. Below `lg`: the same sections in a bar along the bottom that scrolls sideways
 * ({@link BottomNav}), the pages of the section you are in in a strip on top of it, and the taxpayer switcher in the top bar — the drawer it replaces hid the whole menu behind one
 * button. The active item is derived from Inertia's own `usePage().url`, so no page needs to pass a
 * "which screen am I" prop.
 *
 * A persistent layout (`app.tsx`): Inertia keeps it mounted from one screen to the next and swaps only
 * the page inside it. Every page used to wrap itself in this, so every click built the frame again —
 * the bell fetched its list, both private channels were authorised again, the sidebar lost its place.
 * Now each of those happens once, when the application opens. A page says what it is through `Page`
 * (`lib/pageHeader.ts`), never through props here, since it does not render this.
 */
export default function AppShell({ children }: { children: ReactNode }): JSX.Element {
    const { t } = useTranslation();
    const { url } = usePage();
    const appName = useAppName();
    const auth = useSharedAuth();
    const [collapsed, setCollapsed] = useState(readStoredCollapsed);
    const mainRef = useRef<HTMLElement>(null);
    const { pull, refreshing } = usePullToRefresh(mainRef);
    // A page on its way shows what a pull shows once let go: the same disc, spinning.
    const desktopShell = useMediaQuery('(min-width: 64rem)');
    // On a phone only: from `lg` up the sidebar stays put and the page swaps in place, quickly enough
    // that a veil over it would only flash.
    const loading = useNavigationPending() && !desktopShell;
    // Published by whichever `Page` is rendered below; the shell is where it is drawn.
    const header = usePageHeader();
    const userId = auth?.user.id ?? '';

    // The switcher is drawn from the shared props, and this frame outlives every page: a taxpayer
    // changed here or on another device is announced on the person's channel, and the list asked for
    // again — not on every click, as when each page built the frame anew.
    useEffect(() => {
        return userId === '' ? undefined : subscribeToTaxpayerChanges(userId, refreshSharedAuth);
    }, [userId]);

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
                        {/* On a phone there is no drawer, so the taxpayer the page is about is chosen
                            here, first in the bar where the menu button was, as the switcher's compact
                            mark. From `lg` it is at the sidebar's foot. */}
                        <div className="min-w-0 lg:hidden">
                            <SubjectSwitcher bare />
                        </div>
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
                                {header?.title ?? appName}
                            </h1>
                            {header?.description !== undefined && (
                                <p className="text-muted-foreground mt-px hidden truncate text-2xs leading-4 sm:block">
                                    {header.description}
                                </p>
                            )}
                        </div>

                        {/* Palette and language are in the account's Appearance card: on every screen
                            they took most of a phone's bar for a choice made once. Light or dark is
                            reached for more often, so it stays one press away. */}
                        {/* On a phone the sidebar's light/dark is out of reach, so the bar carries it. */}
                        <div className="lg:hidden">
                            <ThemeToggle className="bar-icon" />
                        </div>
                        {auth !== null && auth.user.id !== '' && <NotificationsMenu userId={auth.user.id} />}
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
                    {/* `scroll-region`: the frame stays mounted across pages, so this is the scroll
                        Inertia must reset on a visit and restore on Back — the window never scrolls. */}
                    {/* The pull's veil and disc sit beside the scroll area rather than in it, so they
                        cover what is on screen wherever the page is scrolled to. */}
                    <div className="relative flex min-h-0 min-w-0 flex-1 flex-col">
                        <PullIndicator
                            pull={pull}
                            refreshing={refreshing || loading}
                            label={t((d) => (refreshing ? d.shell.refreshing : d.shell.loading))}
                        />
                        <main
                            ref={mainRef}
                            scroll-region=""
                            className="relative min-w-0 flex-1 overflow-y-auto overscroll-y-contain p-3 pb-[calc(--spacing(3)+var(--dock-space)+env(safe-area-inset-bottom))] sm:p-5 sm:pb-[calc(--spacing(5)+var(--dock-space)+env(safe-area-inset-bottom))]"
                        >
                            {/*
                            The cap widens on a large monitor, not below one.
                            `2xl:` is 1536px and up, so every laptop keeps the 1420px it had; past
                            that the content takes 1680px and the margins stop growing as fast. It
                            does not go further because this number has to suit a settings form as
                            well as a table, and a form two thousand pixels wide reads worse than one
                            at fourteen hundred.
                        */}
                            <div className="mx-auto w-full max-w-[1420px] space-y-4 2xl:max-w-[1680px]">{children}</div>
                        </main>
                    </div>
                </div>
            </div>

            <BottomNav currentUrl={url} />

            <SubjectChooser
                footer={
                    <PanelButton
                        onClick={() => {
                            void signOut();
                        }}
                    >
                        <LogOut className="size-3.5" aria-hidden="true" />
                        {t((d) => d.shell.signOut)}
                    </PanelButton>
                }
            />
        </div>
    );
}
