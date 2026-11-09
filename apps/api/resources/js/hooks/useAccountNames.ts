import { useMemo } from 'react';

import { useTranslation } from '../i18n';
import { sourceName, type NamedSource } from '../types/wallet';

/**
 * What every screen calls an account, in the person's language: General by its label, an investments
 * account by its place and currency, the rest by the name its owner gave it — and, in a picker, with the
 * currency after it where the name does not already say it.
 */
export function useAccountNames(): {
    name: (account: NamedSource) => string;
    option: (account: NamedSource) => string;
} {
    const { t } = useTranslation();
    const general = t((d) => d.pages.wallet.generalSource);
    const places = t((d) => d.pages.holdings.heldAt);

    // Kept across renders while the language is the same, so a memoised list that names accounts depends on it.
    return useMemo(() => {
        const name = (account: NamedSource): string => sourceName(account, general, places);

        return {
            name,
            option: (account: NamedSource) =>
                account.currencyCode == null || account.kind === 'INVESTMENTS'
                    ? name(account)
                    : `${name(account)} · ${account.currencyCode}`,
        };
    }, [general, places]);
}
