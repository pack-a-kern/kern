import type { JSX } from 'react';
import PlaceholderPage from '../../components/PlaceholderPage';
import { useTranslation } from '../../i18n';

export default function WalletDeposits(): JSX.Element {
    const { t } = useTranslation();

    return (
        <PlaceholderPage
            title={t((d) => d.pages.walletDeposits.title)}
            description={t((d) => d.pages.walletDeposits.description)}
            blockedOn={t((d) => d.pages.walletDeposits.blockedOn)}
        />
    );
}
