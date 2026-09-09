import type { JSX } from 'react';
import { useTranslation } from '../i18n';
import { Button } from '@/components/ui/button';

/** The language switcher for the two unauthenticated pages (home, login) — `AppShell`'s
 * `LanguageMenu` covers every authenticated screen; these two render outside the shell entirely. */
export default function GuestLanguageToggle(): JSX.Element {
    const { locale, setLocale, t } = useTranslation();
    const next = locale === 'ro' ? 'en' : 'ro';

    return (
        <div className="fixed top-3 right-3">
            <Button
                variant="ghost"
                size="sm"
                aria-label={t((d) => d.shell.changeLanguage)}
                onClick={() => {
                    setLocale(next);
                }}
            >
                {next.toUpperCase()}
            </Button>
        </div>
    );
}
