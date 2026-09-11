import { Link, usePage } from '@inertiajs/react';
import {
    Banknote,
    Building2,
    Calculator,
    FileCheck2,
    Gavel,
    LayoutDashboard,
    Landmark,
    LogOut,
    Menu,
    Monitor,
    Moon,
    PanelLeftClose,
    PanelLeftOpen,
    PiggyBank,
    Scale,
    Settings,
    Shield,
    ShieldCheck,
    Sun,
    Users,
    Wallet,
} from 'lucide-react';
import { useState, type JSX, type ReactNode } from 'react';
import { logout } from '../api/authApi';
import { useTheme, type ThemePreference } from '../hooks/useTheme';
import { useTranslation, type Locale } from '../i18n';
import type { Dictionary } from '../i18n/dictionary';
import { ADMIN_UI_ENABLED } from '../lib/featureFlags';
import { cn } from '../lib/utils';
import { Button } from './ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from './ui/dropdown-menu';
import { Separator } from './ui/separator';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from './ui/sheet';
import { Tooltip, TooltipContent, TooltipTrigger } from './ui/tooltip';
import SubjectSwitcher from './SubjectSwitcher';

interface NavLink {
    href: string;
    label: (d: Dictionary) => string;
    icon: typeof LayoutDashboard;
    children?: { href: string; label: (d: Dictionary) => string }[];
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
        href: '/portofel',
        label: (d) => d.nav.wallet,
        icon: Wallet,
        children: [
            { href: '/portofel/conturi', label: (d) => d.nav.walletAccounts },
            { href: '/portofel/tranzactii', label: (d) => d.nav.walletTransactions },
            { href: '/documents', label: (d) => d.nav.documents },
        ],
    },
    { href: '/chirii', label: (d) => d.nav.rentals, icon: Building2 },
    {
        href: '/impozite',
        label: (d) => d.nav.taxes,
        icon: Calculator,
        children: [{ href: '/impozite/explica', label: (d) => d.nav.taxesExplain }],
    },
    { href: '/declaratii', label: (d) => d.nav.declarations, icon: FileCheck2 },
    { href: '/legislatie', label: (d) => d.nav.legislation, icon: Gavel },
    { href: '/spv', label: (d) => d.nav.spv, icon: Landmark },
];

const ACCOUNT_ITEMS: NavLink[] = [
    { href: '/setari/profil', label: (d) => d.nav.settingsProfile, icon: Users },
    { href: '/setari/securitate', label: (d) => d.nav.settingsSecurity, icon: ShieldCheck },
    { href: '/setari/subiecte', label: (d) => d.nav.settingsSubjects, icon: PiggyBank },
    { href: '/setari/confidentialitate', label: (d) => d.nav.settingsPrivacy, icon: Shield },
];

const ADMIN_ITEMS: NavLink[] = [
    { href: '/admin/setari', label: (d) => d.nav.adminSettings, icon: Settings },
    { href: '/admin/parametri', label: (d) => d.nav.adminParameters, icon: Scale },
    { href: '/admin/utilizatori', label: (d) => d.nav.adminUsers, icon: Users },
    { href: '/admin/operatiuni', label: (d) => d.nav.adminOperations, icon: Banknote },
    { href: '/admin/audit', label: (d) => d.nav.adminAudit, icon: FileCheck2 },
];

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

function ThemeMenu(): JSX.Element {
    const { t } = useTranslation();
    const { preference, resolved, setPreference } = useTheme();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon-sm" aria-label={t((d) => d.shell.changeTheme)}>
                    {resolved === 'dark' ? <Moon className="size-4" /> : <Sun className="size-4" />}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuRadioGroup
                    value={preference}
                    onValueChange={(value) => {
                        setPreference(value as ThemePreference);
                    }}
                >
                    <DropdownMenuRadioItem value="light">
                        <Sun className="size-4" /> {t((d) => d.shell.themeLight)}
                    </DropdownMenuRadioItem>
                    <DropdownMenuRadioItem value="dark">
                        <Moon className="size-4" /> {t((d) => d.shell.themeDark)}
                    </DropdownMenuRadioItem>
                    <DropdownMenuRadioItem value="system">
                        <Monitor className="size-4" /> {t((d) => d.shell.themeSystem)}
                    </DropdownMenuRadioItem>
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function LanguageMenu(): JSX.Element {
    const { t, locale, setLocale } = useTranslation();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="sm" aria-label={t((d) => d.shell.changeLanguage)}>
                    {locale.toUpperCase()}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuRadioGroup
                    value={locale}
                    onValueChange={(value) => {
                        setLocale(value as Locale);
                    }}
                >
                    <DropdownMenuRadioItem value="ro">{t((d) => d.shell.languageRo)}</DropdownMenuRadioItem>
                    <DropdownMenuRadioItem value="en">{t((d) => d.shell.languageEn)}</DropdownMenuRadioItem>
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function UserMenu(): JSX.Element {
    const { t } = useTranslation();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon-sm" aria-label={t((d) => d.shell.account)}>
                    <Users className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem asChild>
                    <Link href="/setari/profil">{t((d) => d.nav.settingsProfile)}</Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link href="/setari/securitate">{t((d) => d.nav.settingsSecurity)}</Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive" onSelect={() => void handleLogout()}>
                    <LogOut className="size-4" /> {t((d) => d.shell.signOut)}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function NavGroupHeading({ children }: { children: ReactNode }): JSX.Element {
    return (
        <p className="px-[9px] pt-[18px] pb-1.5 text-[10px] font-medium tracking-[0.09em] text-muted-foreground uppercase">
            {children}
        </p>
    );
}

/** One nav row. `collapsed` (desktop rail mode) hides everything but the icon, behind a `Tooltip`
 * carrying the real label so the control stays discoverable and accessible even iconified. */
function NavRow({
    item,
    collapsed,
    currentUrl,
}: {
    item: NavLink;
    collapsed: boolean;
    currentUrl: string;
}): JSX.Element {
    const { t } = useTranslation();
    const Icon = item.icon;
    const active = isActive(currentUrl, item.href);
    const label = t(item.label);

    const link = (
        <Button
            variant={active ? 'secondary' : 'ghost'}
            className={cn(
                'h-8 w-full justify-start gap-[9px] rounded-[7px] px-[9px] text-[13px] font-normal',
                active && 'font-semibold',
                collapsed && 'justify-center px-0',
            )}
            asChild
        >
            <Link href={item.href} aria-current={active ? 'page' : undefined}>
                <Icon className="size-4 shrink-0" aria-hidden="true" />
                {!collapsed && <span className="truncate">{label}</span>}
            </Link>
        </Button>
    );

    return (
        <li>
            {collapsed ? (
                <Tooltip>
                    <TooltipTrigger asChild>{link}</TooltipTrigger>
                    <TooltipContent side="right">{label}</TooltipContent>
                </Tooltip>
            ) : (
                link
            )}
            {!collapsed && item.children && item.children.length > 0 && (
                <ul className="mt-px space-y-px">
                    {item.children.map((child) => {
                        const childActive = isActive(currentUrl, child.href);

                        return (
                            <li key={child.href}>
                                <Button
                                    variant={childActive ? 'secondary' : 'ghost'}
                                    className={cn(
                                        'h-8 w-full justify-start rounded-[7px] py-0 pr-[9px] pl-7 text-[13px] font-normal',
                                        childActive && 'font-semibold',
                                    )}
                                    asChild
                                >
                                    <Link href={child.href} aria-current={childActive ? 'page' : undefined}>
                                        {t(child.label)}
                                    </Link>
                                </Button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </li>
    );
}

function SidebarNav({ collapsed, currentUrl }: { collapsed: boolean; currentUrl: string }): JSX.Element {
    const { t } = useTranslation();

    return (
        <nav aria-label={t((d) => d.nav.dashboard)} className="flex flex-1 flex-col overflow-y-auto px-2.5 pb-4">
            <ul className="space-y-px">
                {MAIN_ITEMS.map((item) => (
                    <NavRow key={item.href} item={item} collapsed={collapsed} currentUrl={currentUrl} />
                ))}
            </ul>

            {!collapsed && <NavGroupHeading>{t((d) => d.nav.accountGroup)}</NavGroupHeading>}
            <ul className="space-y-px">
                {ACCOUNT_ITEMS.map((item) => (
                    <NavRow key={item.href} item={item} collapsed={collapsed} currentUrl={currentUrl} />
                ))}
            </ul>

            {ADMIN_UI_ENABLED && (
                <>
                    {!collapsed && <NavGroupHeading>{t((d) => d.nav.adminGroup)}</NavGroupHeading>}
                    <ul className="space-y-px">
                        {ADMIN_ITEMS.map((item) => (
                            <NavRow key={item.href} item={item} collapsed={collapsed} currentUrl={currentUrl} />
                        ))}
                    </ul>
                </>
            )}
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
}: {
    children: ReactNode;
    contentClassName?: string;
}): JSX.Element {
    const { t } = useTranslation();
    const { url } = usePage();
    const [collapsed, setCollapsed] = useState(readStoredCollapsed);
    const [mobileOpen, setMobileOpen] = useState(false);

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
        <div className="flex min-h-dvh bg-background">
            <aside
                className={cn(
                    'sticky top-0 hidden h-dvh flex-col border-r border-border bg-card transition-[width] sm:flex',
                    collapsed ? 'w-14' : 'w-[230px]',
                )}
            >
                <div className="flex h-[58px] items-center justify-between gap-2 border-b border-border px-4">
                    {!collapsed && (
                        <span className="truncate text-sm font-semibold text-foreground">
                            {t((d) => d.shell.appName)}
                        </span>
                    )}
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        className="ml-auto"
                        aria-label={collapsed ? t((d) => d.shell.expandSidebar) : t((d) => d.shell.collapseSidebar)}
                        onClick={toggleCollapsed}
                    >
                        {collapsed ? <PanelLeftOpen className="size-4" /> : <PanelLeftClose className="size-4" />}
                    </Button>
                </div>
                <SidebarNav collapsed={collapsed} currentUrl={url} />
            </aside>

            {/* `min-w-0` is load-bearing: this is a flex item on a horizontal main axis, so its default
                `min-width: auto` refuses to shrink below its content. A wide table inside then pushes this
                whole column out and the *page* scrolls sideways, instead of the table's own wrapper. */}
            <div className="flex min-h-dvh min-w-0 flex-1 flex-col">
                <header className="sticky top-0 z-30 flex h-[58px] items-center gap-2 border-b border-border bg-card/95 px-3 backdrop-blur supports-backdrop-filter:bg-card/75 sm:px-[22px]">
                    <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
                        <SheetTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                className="sm:hidden"
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

                    <span className="hidden text-sm font-semibold text-foreground sm:inline sm:hidden md:inline">
                        {t((d) => d.shell.appName)}
                    </span>

                    <div className="min-w-0 flex-1">
                        <SubjectSwitcher />
                    </div>

                    <Separator orientation="vertical" className="h-6" />
                    <LanguageMenu />
                    <ThemeMenu />
                    <UserMenu />
                </header>

                <main
                    className={cn(
                        'mx-auto w-full max-w-[1360px] flex-1 space-y-6 px-3.5 pt-5 pb-8 sm:px-[30px] sm:pt-7 sm:pb-13',
                        contentClassName,
                    )}
                >
                    {children}
                </main>
            </div>
        </div>
    );
}
