import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Bell,
    Building2,
    Calculator,
    CalendarClock,
    ChevronDown,
    CircleHelp,
    FileCheck2,
    Files,
    Gavel,
    LayoutDashboard,
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
    Wallet,
} from 'lucide-react';
import { useState, type JSX, type ReactNode } from 'react';
import { logout } from '../api/authApi';
import ImpersonationBanner from './ImpersonationBanner';
import { LanguageMenu, PaletteMenu, ThemeToggle } from './ShellControls';
import { usePageHeader } from '../lib/pageHeader';
import { useTranslation } from '../i18n';
import type { Dictionary } from '../i18n/dictionary';
import { ADMIN_UI_ENABLED } from '../lib/featureFlags';
import { cn } from '../lib/utils';
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
    children?: { href: string; label: (d: Dictionary) => string; icon: typeof LayoutDashboard }[];
}

/**
 * The single source of truth for the sidebar's structure. Every route here has a matching entry in
 * `routes/web.php` (`docs/handoff/040-ui-foundation.md` "Routes"), either a real screen or
 * `PlaceholderPage` — nothing in this list is a dead link. `Documente` keeps the pre-existing,
 * already-verified `/documents` English slug rather than a `/documente` duplicate.
 *
 * Documents sits *inside* Portofel rather than beside it. A receipt is a transaction, a bank
 * statement is a page of transactions, a deposit contract is an account — a document is evidence
 * for a wallet record, not a parallel filing cabinet, and putting it a level up made the two look
 * like unrelated features (which is exactly how they had been built).
 *
 * There is no `Capturare` entry any more. Adding a document is an action taken from the list you
 * are already looking at, so it is a button and a dialog on `/documents`, not a separate
 * destination you navigate to and then navigate back from.
 */
const MAIN_ITEMS: NavLink[] = [
    { href: '/dashboard', label: (d) => d.nav.dashboard, icon: LayoutDashboard },
    {
        id: 'wallet',
        label: (d) => d.nav.wallet,
        icon: Wallet,
        children: [
            { href: '/wallet/accounts', label: (d) => d.nav.walletAccounts, icon: Landmark },
            { href: '/wallet/transactions', label: (d) => d.nav.walletTransactions, icon: ArrowLeftRight },
            { href: '/documents', label: (d) => d.nav.documents, icon: Files },
        ],
    },
    { href: '/rentals', label: (d) => d.nav.rentals, icon: Building2 },
    {
        href: '/taxes',
        label: (d) => d.nav.taxes,
        icon: Calculator,
        children: [{ href: '/taxes/explain', label: (d) => d.nav.taxesExplain, icon: CircleHelp }],
    },
    { href: '/declarations', label: (d) => d.nav.declarations, icon: FileCheck2 },
    { href: '/legislation', label: (d) => d.nav.legislation, icon: Gavel },
    { href: '/spv', label: (d) => d.nav.spv, icon: Landmark },
];

/* The account has a page of its own *and* a child, the way `Impozite` does: taxpayers belong to an
   account, so the row navigates and the chevron folds the child in. */
const ACCOUNT_GROUP: NavLink = {
    href: '/account',
    label: (d) => d.nav.accountGroup,
    icon: UserRound,
    children: [{ href: '/taxpayers', label: (d) => d.nav.settingsSubjects, icon: Users }],
};

/* Above the administration group, not inside it: reading what happened is not administering the
   installation, and the people who do the first outnumber the people who do the second. */
const LOGS_ITEM: NavLink = { href: '/logs', label: (d) => d.nav.logs, icon: ScrollText };

const ADMIN_GROUP: NavLink = {
    id: 'admin',
    label: (d) => d.nav.adminGroup,
    icon: Settings,
    children: [
        { href: '/admin/settings', label: (d) => d.nav.adminSettings, icon: Settings },
        { href: '/admin/parameters', label: (d) => d.nav.adminParameters, icon: Scale },
        { href: '/admin/users', label: (d) => d.nav.adminUsers, icon: Users },
    ],
};

function isActive(currentUrl: string, href: string): boolean {
    return currentUrl === href || currentUrl.startsWith(`${href}/`);
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

/**
 * Notifications.
 *
 * The entries are the design's placeholders, like the fiscal snapshot above: nothing in the app
 * raises a notification yet. They live in one constant so the screen is complete now and there is a
 * single place to replace when a real feed exists — the dot only shows while any entry is unread,
 * so it goes quiet on its own rather than being decorative.
 */
const NOTIFICATIONS_PLACEHOLDER: { id: string; unread: boolean }[] = [{ id: 'document-processed', unread: true }];

function NotificationsMenu(): JSX.Element {
    const { t } = useTranslation();
    const [read, setRead] = useState(false);
    const unread = !read && NOTIFICATIONS_PLACEHOLDER.some((entry) => entry.unread);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="shell-control relative size-8 justify-center px-0"
                    aria-label={t((d) => d.shell.notifications)}
                >
                    <Bell className="size-4" />
                    {unread && (
                        <span className="bg-accent-500 ring-card absolute top-[6px] right-[7px] size-1.5 rounded-full ring-2" />
                    )}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[300px] p-0">
                <div className="border-border flex items-center justify-between border-b px-3 py-2.5">
                    <span className="text-xs font-semibold">{t((d) => d.shell.notifications)}</span>
                    <button
                        type="button"
                        className="text-accent-600 hover:text-accent-700 text-[11px] font-semibold"
                        onClick={() => {
                            setRead(true);
                        }}
                    >
                        {t((d) => d.shell.markAllRead)}
                    </button>
                </div>
                <div className="p-1.5">
                    {NOTIFICATIONS_PLACEHOLDER.map((entry) => (
                        <DropdownMenuItem key={entry.id} className="items-start gap-2.5">
                            <span
                                className={
                                    unread
                                        ? 'bg-accent-500 mt-1 size-2 shrink-0 rounded-full'
                                        : 'bg-border mt-1 size-2 shrink-0 rounded-full'
                                }
                                aria-hidden="true"
                            />
                            <span className="min-w-0">
                                <span className="block text-xs font-semibold">
                                    {t((d) => d.shell.notificationTitle)}
                                </span>
                                <span className="text-ink-400 mt-0.5 block text-[11px] leading-4">
                                    {t((d) => d.shell.notificationBody)}
                                </span>
                            </span>
                        </DropdownMenuItem>
                    ))}
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
                    <span className="bg-secondary text-secondary-foreground grid size-6 place-items-center rounded-lg text-[10px] font-bold">
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
                <ul className="mt-0.5 space-y-0.5 pl-6">
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
                <span className="text-muted-foreground text-[9px] font-bold tracking-[0.12em] uppercase">
                    {t((d) => d.shell.fiscalSnapshot)}
                </span>
                <span className="shadow-hair text-ink-500 dark:text-ink-400 rounded-md bg-white px-1.5 py-0.5 text-[9px] font-semibold dark:bg-[#202225]">
                    {SNAPSHOT_PLACEHOLDER.period}
                </span>
            </div>
            <div className="mt-2 flex items-end justify-between gap-2">
                <div>
                    <div className="text-xs font-semibold">
                        {t((d) => d.shell.obligationCount).replace('{count}', String(SNAPSHOT_PLACEHOLDER.obligations))}
                    </div>
                    <div className="text-muted-foreground mt-0.5 text-[10px]">
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

    const items = [...MAIN_ITEMS, ACCOUNT_GROUP, LOGS_ITEM, ...(ADMIN_UI_ENABLED ? [ADMIN_GROUP] : [])];

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
            {/* One list, no section headings. The sidebar holds every destination in the app, and
                headings made it a page of its own to read; collapsing instead means only the part
                you are working in is expanded, and the rest is four rows rather than fourteen. */}
            <ul className="space-y-0.5">
                {items.map((item) => (
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
        </nav>
    );
}

const SIDEBAR_COLLAPSED_KEY = 'taxos.sidebarCollapsed';

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
    const [collapsed, setCollapsed] = useState(readStoredCollapsed);
    const [mobileOpen, setMobileOpen] = useState(false);
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
        <div className="bg-background flex h-dvh flex-col overflow-hidden">
            <ImpersonationBanner />

            <div className="flex min-h-0 flex-1 overflow-hidden">
                <aside
                    className={cn(
                        'shell-surface border-border hidden h-full flex-col border-r transition-[width] duration-200 lg:flex',
                        collapsed ? 'w-16' : 'w-[272px]',
                    )}
                >
                    <div className="flex h-14 items-center gap-2 px-3">
                        {!collapsed && (
                            <div className="flex min-w-0 flex-1 items-center gap-2.5">
                                <div className="bg-ink-950 dark:bg-white dark:text-ink-950 grid size-8 shrink-0 place-items-center rounded-[10px] text-[11px] font-bold text-white shadow-sm">
                                    T
                                </div>
                                <div className="min-w-0">
                                    <div className="truncate text-[13px] font-semibold tracking-[-.02em]">
                                        {t((d) => d.shell.appName)}
                                    </div>
                                    <div className="text-ink-400 truncate text-[10px]">
                                        {t((d) => d.shell.appTagline)}
                                    </div>
                                </div>
                            </div>
                        )}
                        <button
                            type="button"
                            className="focus-ring border-border dark:bg-card ml-auto grid size-8 place-items-center rounded-lg border bg-white text-slate-500 hover:bg-slate-50 dark:hover:bg-[#33363B]"
                            aria-label={collapsed ? t((d) => d.shell.expandSidebar) : t((d) => d.shell.collapseSidebar)}
                            onClick={toggleCollapsed}
                        >
                            {collapsed ? <PanelLeftOpen className="size-4" /> : <PanelLeftClose className="size-4" />}
                        </button>
                    </div>

                    <SidebarNav collapsed={collapsed} currentUrl={url} />

                    {/* The subject switcher lives at the foot of the sidebar, not in the header. Which
                    taxpayer you are working on frames everything on the page, so it belongs with the
                    navigation it scopes rather than among the appearance controls. */}
                    {!collapsed && <FiscalSnapshot />}

                    <div className={cn('border-border border-t', collapsed ? 'p-2' : 'p-2.5')}>
                        <SubjectSwitcher collapsed={collapsed} />
                    </div>
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
                            <SheetContent side="left" className="w-72 p-0">
                                <SheetHeader className="h-[58px] justify-center border-b border-border">
                                    <SheetTitle>{t((d) => d.shell.appName)}</SheetTitle>
                                </SheetHeader>
                                <SidebarNav collapsed={false} currentUrl={url} />
                            </SheetContent>
                        </Sheet>

                        {/* The page says what it is; the shell does not repeat the product name on every
                        screen. */}
                        <div className="min-w-0 flex-1">
                            <h1 className="text-ink-950 truncate text-[16px] leading-5 font-semibold tracking-[-0.035em] sm:text-[18px] dark:text-white">
                                {header?.title ?? title ?? t((d) => d.shell.appName)}
                            </h1>
                            {(header?.description ?? description) !== undefined && (
                                <p className="mt-0.5 hidden truncate text-[11px] leading-4 font-medium text-[#7C8592] sm:block dark:text-[#B5B9C0]">
                                    {header?.description ?? description}
                                </p>
                            )}
                        </div>

                        <LanguageMenu />
                        <PaletteMenu />
                        <NotificationsMenu />
                        <ThemeToggle />
                        <UserMenu />
                    </header>

                    <main className="min-w-0 flex-1 overflow-y-auto p-3 sm:p-5">
                        <div className={cn('mx-auto w-full max-w-[1420px] space-y-4', contentClassName)}>
                            {children}
                        </div>
                    </main>
                </div>
            </div>
        </div>
    );
}
