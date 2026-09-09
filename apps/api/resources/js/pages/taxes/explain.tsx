import type { JSX } from 'react';
import PlaceholderPage from '../../components/PlaceholderPage';
import { useTranslation } from '../../i18n';

export default function TaxesExplain(): JSX.Element {
    const { t } = useTranslation();

    return (
        <PlaceholderPage
            title={t((d) => d.pages.taxesExplain.title)}
            description={t((d) => d.pages.taxesExplain.description)}
            blockedOn={t((d) => d.pages.taxesExplain.blockedOn)}
        />
    );
}
