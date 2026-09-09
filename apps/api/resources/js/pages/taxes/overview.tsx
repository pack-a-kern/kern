import type { JSX } from 'react';
import PlaceholderPage from '../../components/PlaceholderPage';
import { useTranslation } from '../../i18n';

export default function TaxesOverview(): JSX.Element {
    const { t } = useTranslation();

    return (
        <PlaceholderPage
            title={t((d) => d.pages.taxes.title)}
            description={t((d) => d.pages.taxes.description)}
            blockedOn={t((d) => d.pages.taxes.blockedOn)}
        />
    );
}
