import type { JSX } from 'react';
import PlaceholderPage from '../../components/PlaceholderPage';
import { useTranslation } from '../../i18n';

export default function AdminParameters(): JSX.Element {
    const { t } = useTranslation();

    return (
        <PlaceholderPage
            title={t((d) => d.pages.adminParameters.title)}
            description={t((d) => d.pages.adminParameters.description)}
            blockedOn={t((d) => d.pages.adminParameters.blockedOn)}
        />
    );
}
