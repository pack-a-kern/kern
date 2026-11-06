import { Head, Link } from '@inertiajs/react';
import type { JSX } from 'react';
import {
    BadgeCheck,
    Calculator,
    CalendarClock,
    Fingerprint,
    Hourglass,
    Inbox,
    KeyRound,
    Landmark,
    LogIn,
    Percent,
    PiggyBank,
    Scale,
    ScrollText,
    ShieldCheck,
    Upload,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '../i18n';
import AppMark from '../components/AppMark';
import AuthBackdrop from '../components/AuthBackdrop';
import { ThemeToggle } from '../components/ShellControls';
import { useAppName } from '../lib/sharedProps';

/**
 * The page before sign-in: what this is, what it does, and the way in.
 *
 * The cards beside the headline are the application's own shapes — the sidebar's deadline, a rule on
 * its article, a deposit — filled with made-up figures and marked as an example, so nothing on a page
 * anyone can open reads as somebody's affairs. Everything else is said in words: the features are the
 * ones the application has, and the security strip says what the sign-in page says.
 */
export default function Home(): JSX.Element {
    const { t } = useTranslation();
    const appName = useAppName();

    const features = [
        { key: 'wallet', icon: Landmark },
        { key: 'statements', icon: Upload },
        { key: 'spv', icon: Inbox },
        { key: 'taxes', icon: Calculator },
        { key: 'law', icon: Scale },
        { key: 'approval', icon: BadgeCheck },
    ] as const;

    const security = [
        { icon: KeyRound, title: t((d) => d.auth.asideStrongTitle), body: t((d) => d.auth.asideStrongBody) },
        { icon: Fingerprint, title: t((d) => d.auth.asideDeviceTitle), body: t((d) => d.auth.asideDeviceBody) },
        { icon: ScrollText, title: t((d) => d.auth.asideAuditTitle), body: t((d) => d.auth.asideAuditBody) },
    ];

    const signIn = (
        <Button asChild>
            <Link href="/login">
                <LogIn className="size-4" aria-hidden="true" />
                {t((d) => d.home.signIn)}
            </Link>
        </Button>
    );

    return (
        <>
            <Head title={appName} />

            <div className="bg-background text-foreground safe-area min-h-dvh">
                <section className="relative isolate overflow-hidden">
                    <AuthBackdrop />

                    <header className="relative mx-auto flex h-16 max-w-6xl items-center justify-between gap-3 px-5 sm:px-8">
                        <div className="flex min-w-0 items-center gap-2.5">
                            <AppMark className="size-9 rounded-[11px]" />
                            <span className="min-w-0">
                                <span className="block truncate text-sm font-semibold tracking-tight">{appName}</span>
                                <span className="text-muted-foreground block truncate text-2xs">
                                    {t((d) => d.shell.appTagline)}
                                </span>
                            </span>
                        </div>
                        <div className="flex items-center gap-1.5">
                            <ThemeToggle />
                            <span className="hidden sm:block">{signIn}</span>
                        </div>
                    </header>

                    <div className="relative mx-auto grid max-w-6xl items-center gap-12 px-5 pt-10 pb-16 sm:px-8 lg:grid-cols-[1.05fr_0.95fr] lg:pt-16 lg:pb-24">
                        <div>
                            <span className="head-chip inline-flex items-center gap-1.5">
                                <ShieldCheck className="text-accent-700 size-3.5" aria-hidden="true" />
                                {t((d) => d.home.eyebrow)}
                            </span>
                            <h1 className="mt-5 max-w-[560px] text-[36px] leading-[1.05] font-semibold tracking-[-0.05em] text-balance sm:text-[48px]">
                                {t((d) => d.home.title)}
                            </h1>
                            <p className="text-muted-foreground mt-5 max-w-[500px] text-sm leading-6 sm:text-base sm:leading-7">
                                {t((d) => d.home.lead)}
                            </p>
                            <div className="mt-8">{signIn}</div>
                        </div>

                        <Preview />
                    </div>
                </section>

                <section className="border-border border-t">
                    <div className="mx-auto max-w-6xl px-5 py-16 sm:px-8 lg:py-20">
                        <h2 className="text-2xl font-semibold tracking-[-0.03em] text-balance sm:text-3xl">
                            {t((d) => d.home.featuresTitle)}
                        </h2>
                        <p className="text-muted-foreground mt-2 max-w-[520px] text-sm leading-6">
                            {t((d) => d.home.featuresLead)}
                        </p>

                        <div className="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {features.map(({ key, icon: Icon }) => (
                                <div key={key} className="border-border bg-card rounded-2xl border p-5">
                                    <span className="bg-accent-50 text-accent-700 grid size-9 place-items-center rounded-xl">
                                        <Icon className="size-4" aria-hidden="true" />
                                    </span>
                                    <h3 className="mt-4 text-sm font-semibold">
                                        {t((d) => d.home.features[key].title)}
                                    </h3>
                                    <p className="text-muted-foreground mt-1.5 text-xs leading-5">
                                        {t((d) => d.home.features[key].body)}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                <section className="border-border bg-card border-t">
                    <div className="mx-auto max-w-6xl px-5 py-14 sm:px-8">
                        <h2 className="text-muted-foreground text-2xs font-bold tracking-[0.12em] uppercase">
                            {t((d) => d.home.securityTitle)}
                        </h2>
                        <div className="mt-6 grid gap-6 sm:grid-cols-3">
                            {security.map((item) => (
                                <div key={item.title} className="flex items-start gap-3">
                                    <item.icon
                                        className="text-accent-700 dark:text-accent-200 mt-0.5 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <div>
                                        <h3 className="text-xs font-semibold">{item.title}</h3>
                                        <p className="text-muted-foreground mt-1 text-xs leading-5">{item.body}</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                <footer className="border-border text-muted-foreground border-t">
                    <div className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-5 py-5 text-2xs sm:px-8">
                        <span>{t((d) => d.auth.footerCopyright).replace('{appName}', appName)}</span>
                        <Link href="/login" className="hover:text-foreground font-semibold">
                            {t((d) => d.home.signIn)}
                        </Link>
                    </div>
                </footer>
            </div>
        </>
    );
}

/** The application's own cards, with made-up figures, said to be an example. Decorative to a screen reader. */
function Preview(): JSX.Element {
    const { t } = useTranslation();

    return (
        <div className="relative mx-auto w-full max-w-[420px]" aria-hidden="true">
            <span className="head-chip absolute -top-3 right-4 z-10 shadow-sm">{t((d) => d.home.example)}</span>

            <div className="border-border bg-card/90 grid gap-3 rounded-[22px] border p-4 shadow-[var(--elevation-float)] backdrop-blur">
                <div className="border-border flex items-center gap-3 rounded-2xl border p-3.5">
                    <span className="bg-accent-50 text-accent-700 grid size-10 place-items-center rounded-xl">
                        <CalendarClock className="size-4" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="text-xs font-semibold">{t((d) => d.home.previewDeadline)}</div>
                        <div className="text-muted-foreground mt-0.5 text-2xs">
                            {t((d) => d.home.previewDeadlineDue)}
                        </div>
                    </div>
                    <span className="pill bg-muted text-muted-foreground">
                        <Hourglass className="size-3" />
                        {t((d) => d.home.previewDaysLeft)}
                    </span>
                </div>

                <div className="border-border flex items-center gap-3 rounded-2xl border p-3.5">
                    <span className="bg-accent-50 text-accent-700 grid size-10 place-items-center rounded-xl">
                        <Percent className="size-4" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="text-xs font-semibold">{t((d) => d.home.previewRate)}</div>
                        <div className="text-muted-foreground mt-0.5 text-2xs">
                            {t((d) => d.home.previewRateSource)}
                        </div>
                    </div>
                    <span className="text-lg font-semibold tabular-nums">10%</span>
                </div>

                <div className="border-border flex items-center gap-3 rounded-2xl border p-3.5">
                    <span className="bg-accent-50 text-accent-700 grid size-10 place-items-center rounded-xl">
                        <PiggyBank className="size-4" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="text-xs font-semibold">{t((d) => d.home.previewDeposit)}</div>
                        <div className="text-success mt-0.5 text-2xs font-semibold">
                            {t((d) => d.home.previewDepositInterest)}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
