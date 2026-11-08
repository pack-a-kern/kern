import type { JSX } from 'react';
import { cn } from 'cn';
import { Moon, Sun } from 'lucide-react';
import { useTranslation } from '../i18n';
import { useTheme } from '../hooks/useTheme';

/**
 * The one chrome control the screens before sign-in carry — login, email verification, the landing
 * page: light or dark. Language and palette are chosen once signed in, on the account's Appearance
 * card (`pages/account/AppearanceCard`); before it the interface is in the language last chosen on
 * this device, English otherwise.
 */

/**
 * Light or dark, in one press.
 *
 * The design has a single sun/moon button rather than a menu, so this toggles between the two
 * resolved themes; a first press from "system" lands on the opposite of whatever the OS says,
 * which is what someone reaching for the control wants.
 */
export function ThemeToggle({ className }: { className?: string } = {}): JSX.Element {
    const { t } = useTranslation();
    const { resolved, setPreference } = useTheme();

    return (
        <button
            type="button"
            className={cn('shell-control size-8 justify-center px-0', className)}
            aria-label={t((d) => d.shell.changeTheme)}
            aria-pressed={resolved === 'dark'}
            onClick={() => {
                setPreference(resolved === 'dark' ? 'light' : 'dark');
            }}
        >
            {resolved === 'dark' ? <Moon className="size-4" /> : <Sun className="size-4" />}
        </button>
    );
}
