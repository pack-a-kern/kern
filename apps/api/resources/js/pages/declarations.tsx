import type { JSX } from 'react';
import PlaceholderPage from '../components/PlaceholderPage';
import { useTranslation } from '../i18n';

export default function Declarations(): JSX.Element {
    const { t } = useTranslation();

    return (
        <PlaceholderPage
            title={t((d) => d.pages.declarations.title)}
            description={t((d) => d.pages.declarations.description)}
            blockedOn={t((d) => d.pages.declarations.blockedOn)}
        />
    );
}
