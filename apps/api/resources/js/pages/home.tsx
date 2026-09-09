import { Head, Link } from '@inertiajs/react';
import type { JSX } from 'react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '../i18n';
import GuestLanguageToggle from '../components/GuestLanguageToggle';

export default function Home(): JSX.Element {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t((d) => d.shell.appName)} />
            <GuestLanguageToggle />
            <main className="flex min-h-dvh items-center justify-center bg-background text-foreground">
                <div className="text-center">
                    <h1 className="text-2xl font-semibold">{t((d) => d.shell.appName)}</h1>
                    <p className="mt-2 text-muted-foreground">{t((d) => d.home.tagline)}</p>
                    <Button asChild className="mt-6">
                        <Link href="/login">{t((d) => d.home.signIn)}</Link>
                    </Button>
                </div>
            </main>
        </>
    );
}
