import type { JSX } from 'react';
import PlaceholderPage from '../../components/PlaceholderPage';
import { useTranslation } from '../../i18n';

export default function WalletAccounts(): JSX.Element {
    const { t } = useTranslation();

    return (
        <PlaceholderPage
            title={t((d) => d.pages.walletAccounts.title)}
            description={t((d) => d.pages.walletAccounts.description)}
            blockedOn={t((d) => d.pages.walletAccounts.blockedOn)}
        />
    );
}
