import type { JSX, ReactNode } from 'react';
import { Monitor, Moon, Palette, Sun } from 'lucide-react';

import { ButtonGroup, Panel, PanelSelect } from '../../components/panel';
import { LOCALE_OPTIONS } from '../../lib/locales';
import { usePalette } from '../../hooks/usePalette';
import { useTheme, type ThemePreference } from '../../hooks/useTheme';
import { useTranslation } from '../../i18n';
import { PALETTES, PALETTE_NAMES, isPaletteName } from '../../lib/palette';

/**
 * Theme, palette and language, where a person looks for settings rather than in the top bar.
 *
 * The bar carried all three on every screen, and on a phone they took most of it. They change how the
 * app looks on this device and nothing else, so they sit here with the rest of what is the person's
 * own. The sign-in screens keep the compact controls (`ShellControls`): the language has to be
 * choosable before there is an account to choose it in. Same hooks, same lists, so the two places can
 * never offer different things.
 */
/**
 * One setting: its name and its control on one line, name left and control right, then what it does
 * underneath at the card's full width. The explanation is what ran long — beside the control on a
 * phone it wrapped a word to a line — so it is the part that gets the whole width.
 */
function Setting(props: { title: string; hint: string; children: ReactNode }): JSX.Element {
    return (
        <div className="flex flex-col gap-1.5 py-3 first:pt-0 last:pb-0">
            <div className="flex items-center justify-between gap-3">
                <div className="text-xs font-semibold">{props.title}</div>
                <div className="flex min-w-0 justify-end">{props.children}</div>
            </div>
            <div className="note">{props.hint}</div>
        </div>
    );
}

export default function AppearanceCard(): JSX.Element {
    const { t, locale, setLocale } = useTranslation();
    const { preference, setPreference } = useTheme();
    const { palette, setPalette } = usePalette();

    return (
        <Panel
            title={t((d) => d.pages.account.appearance.title)}
            description={t((d) => d.pages.account.appearance.description)}
            icon={<Palette className="size-4" />}
        >
            <div className="divide-border divide-y">
                <Setting
                    title={t((d) => d.pages.account.appearance.theme)}
                    hint={t((d) => d.pages.account.appearance.themeHint)}
                >
                    <ButtonGroup<ThemePreference>
                        value={preference}
                        onChange={setPreference}
                        options={[
                            {
                                value: 'light',
                                label: (
                                    <>
                                        <Sun className="size-3.5" aria-hidden="true" />
                                        {t((d) => d.pages.account.appearance.light)}
                                    </>
                                ),
                            },
                            {
                                value: 'dark',
                                label: (
                                    <>
                                        <Moon className="size-3.5" aria-hidden="true" />
                                        {t((d) => d.pages.account.appearance.dark)}
                                    </>
                                ),
                            },
                            {
                                value: 'system',
                                label: (
                                    <>
                                        <Monitor className="size-3.5" aria-hidden="true" />
                                        {t((d) => d.pages.account.appearance.system)}
                                    </>
                                ),
                            },
                        ]}
                    />
                </Setting>

                <Setting
                    title={t((d) => d.pages.account.appearance.language)}
                    hint={t((d) => d.pages.account.appearance.languageHint)}
                >
                    <PanelSelect
                        className="w-44"
                        aria-label={t((d) => d.pages.account.appearance.language)}
                        value={locale}
                        onValueChange={(value) => {
                            const chosen = LOCALE_OPTIONS.find((option) => option.value === value);

                            if (chosen !== undefined) {
                                setLocale(chosen.value);
                            }
                        }}
                        options={LOCALE_OPTIONS.map((option) => ({
                            value: option.value,
                            label: t(option.label),
                            description: option.value.toUpperCase(),
                        }))}
                    />
                </Setting>

                <Setting
                    title={t((d) => d.pages.account.appearance.palette)}
                    hint={t((d) => d.pages.account.appearance.paletteHint)}
                >
                    <PanelSelect
                        className="w-44"
                        aria-label={t((d) => d.pages.account.appearance.palette)}
                        value={palette}
                        onValueChange={(value) => {
                            if (isPaletteName(value)) {
                                setPalette(value);
                            }
                        }}
                        options={PALETTE_NAMES.map((name) => ({
                            value: name,
                            label: t((d) => d.shell.paletteNames)[name],
                            description: t((d) => d.shell.paletteDescriptions)[name],
                            // Each palette by its own three colours, which says more than its name.
                            icon: (
                                <span className="flex shrink-0 -space-x-1" aria-hidden="true">
                                    {[PALETTES[name].primary, PALETTES[name].support, PALETTES[name].attention].map(
                                        (color) => (
                                            <span
                                                key={color}
                                                className="border-card size-3.5 rounded-full border-2"
                                                style={{ background: color }}
                                            />
                                        ),
                                    )}
                                </span>
                            ),
                        }))}
                    />
                </Setting>
            </div>
        </Panel>
    );
}
