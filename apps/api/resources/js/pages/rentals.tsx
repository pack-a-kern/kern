import type { JSX } from 'react';
import PlaceholderPage from '../components/PlaceholderPage';
import { useTranslation } from '../i18n';

export default function Rentals(): JSX.Element {
    const { t } = useTranslation();

    return (
        <PlaceholderPage
            title={t((d) => d.pages.rentals.title)}
            description={t((d) => d.pages.rentals.description)}
            blockedOn={t((d) => d.pages.rentals.blockedOn)}
        />
    );
}
