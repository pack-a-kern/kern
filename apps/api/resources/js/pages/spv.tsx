import type { JSX } from 'react';
import PlaceholderPage from '../components/PlaceholderPage';
import { useTranslation } from '../i18n';

export default function Spv(): JSX.Element {
    const { t } = useTranslation();

    return (
        <PlaceholderPage
            title={t((d) => d.pages.spv.title)}
            description={t((d) => d.pages.spv.description)}
            blockedOn={t((d) => d.pages.spv.blockedOn)}
        />
    );
}
