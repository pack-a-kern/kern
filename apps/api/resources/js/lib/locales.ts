import type { Locale } from '../i18n';
import type { Dictionary } from '../i18n/dictionary';

/**
 * The languages offered, in the order the design lists them — one list for the compact menu on the
 * sign-in screens (`components/ShellControls`) and the account's Appearance card, so they never differ.
 */
export const LOCALE_OPTIONS: { value: Locale; label: (d: Dictionary) => string }[] = [
    { value: 'en', label: (d) => d.shell.languageEn },
    { value: 'ro', label: (d) => d.shell.languageRo },
];
