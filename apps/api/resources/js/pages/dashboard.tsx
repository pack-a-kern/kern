import { Head } from '@inertiajs/react';
import { type JSX } from 'react';

import AppShell from '../components/AppShell';
import { Page } from '../components/panel';
import { useTranslation } from '../i18n';

/**
 * The dashboard.
 *
 * Deliberately empty for now. It previously carried `SubjectPanel` — a second listing and
 * registration form for tax subjects — which duplicated `/setari/subiecte`, and
 * `AccountSecurityCard`, which duplicated `/setari/securitate`. Both were removed rather than kept
 * in step: two implementations of one screen drift, and the copy on the dashboard was the one
 * nobody would remember to update.
 *
 * The active tax subject is chosen from the switcher in the topbar, which is present on every page.
 */
export default function Dashboard(): JSX.Element {
    const { t } = useTranslation();

    return (
        <AppShell>
            <Head title={t((d) => d.dashboard.title)} />
            <Page title={t((d) => d.dashboard.title)} lede={t((d) => d.dashboard.lede)}>
                <p className="note">{t((d) => d.dashboard.empty)}</p>
            </Page>
        </AppShell>
    );
}
