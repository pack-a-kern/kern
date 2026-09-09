import type { JSX } from 'react';
import PlaceholderPage from '../../components/PlaceholderPage';
import { useTranslation } from '../../i18n';

export default function WalletOverview(): JSX.Element {
    const { t } = useTranslation();

    return (
        <PlaceholderPage
            title={t((d) => d.pages.walletOverview.title)}
            description={t((d) => d.pages.walletOverview.description)}
            blockedOn={t((d) => d.pages.walletOverview.blockedOn)}
        />
    );
}
