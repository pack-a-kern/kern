import type { JSX } from 'react';
import { ChevronDown, Moon, Sun } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTranslation, type Locale } from '../i18n';
import { usePalette } from '../hooks/usePalette';
import { useTheme } from '../hooks/useTheme';
import { PALETTES, PALETTE_NAMES, isPaletteName } from '../lib/palette';
import { LOCALE_OPTIONS } from '../lib/locales';

/**
 * The three chrome controls: theme, palette and language.
 *
 * The compact form, for the screens before sign-in — login, email verification, the landing page —
 * where someone has to be able to pick the language they sign in in. Once signed in, the same choices
 * are on the account's Appearance card (`pages/account/AppearanceCard`), which reads the same hooks and
 * the same lists (`LOCALE_OPTIONS`, `lib/palette.ts`), so the two can never offer different things.
 */

/**
 * Light or dark, in one press.
 *
 * The design has a single sun/moon button rather than a menu, so this toggles between the two
 * resolved themes; a first press from "system" lands on the opposite of whatever the OS says,
 * which is what someone reaching for the control wants.
 */
export function ThemeToggle(): JSX.Element {
    const { t } = useTranslation();
    const { resolved, setPreference } = useTheme();

    return (
        <button
            type="button"
            className="shell-control size-8 justify-center px-0"
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

/**
 * Switching the interface palette.
 *
 * Driven entirely by the registry in `lib/palette.ts` — this renders whatever is defined there, so
 * a new palette appears here without touching this file. The three swatches are the palette's own
 * brand, support and attention colours, which says more about a choice than its name does.
 */
export function PaletteMenu(): JSX.Element {
    const { t } = useTranslation();
    const { palette, setPalette } = usePalette();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button type="button" className="shell-control" aria-label={t((d) => d.shell.changePalette)}>
                    <span
                        className="size-3 rounded-full border border-black/5"
                        style={{ background: PALETTES[palette].primary }}
                        aria-hidden="true"
                    />
                    <span className="hidden sm:inline">{t((d) => d.shell.paletteNames)[palette]}</span>
                    <ChevronDown className="text-muted-foreground size-3" aria-hidden="true" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-72">
                <DropdownMenuRadioGroup
                    value={palette}
                    onValueChange={(value) => {
                        if (isPaletteName(value)) {
                            setPalette(value);
                        }
                    }}
                >
                    {PALETTE_NAMES.map((name) => (
                        <DropdownMenuRadioItem key={name} value={name}>
                            <span className="flex shrink-0 -space-x-1" aria-hidden="true">
                                {[PALETTES[name].primary, PALETTES[name].support, PALETTES[name].attention].map(
                                    (color) => (
                                        <span
                                            key={color}
                                            className="border-popover size-4 rounded-full border-2"
                                            style={{ background: color }}
                                        />
                                    ),
                                )}
                            </span>
                            <span className="min-w-0">
                                <span className="block truncate font-medium">
                                    {t((d) => d.shell.paletteNames)[name]}
                                </span>
                                <span className="text-muted-foreground block truncate text-2xs">
                                    {t((d) => d.shell.paletteDescriptions)[name]}
                                </span>
                            </span>
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export function LanguageMenu(): JSX.Element {
    const { t, locale, setLocale } = useTranslation();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button type="button" className="shell-control" aria-label={t((d) => d.shell.changeLanguage)}>
                    {locale.toUpperCase()}
                    <ChevronDown className="text-muted-foreground size-3" aria-hidden="true" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-36 rounded-xl p-1">
                <DropdownMenuRadioGroup
                    value={locale}
                    onValueChange={(value) => {
                        setLocale(value as Locale);
                    }}
                >
                    {/* The language's own name, with its code on the right — the code is the label,
                        so a reader who does not know "Română" still recognises RO. No tick: the
                        trigger already shows which one is on, and `aria-checked` carries it for a
                        screen reader, which is why the indicator is hidden rather than removed. */}
                    {LOCALE_OPTIONS.map((option) => (
                        <DropdownMenuRadioItem
                            key={option.value}
                            value={option.value}
                            className="justify-between rounded-lg pr-2 [&_[data-slot=dropdown-menu-radio-item-indicator]]:hidden"
                        >
                            <span>{t(option.label)}</span>
                            <span className="text-ink-400">{option.value.toUpperCase()}</span>
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
