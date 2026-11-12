import { useTranslation } from '../i18n';
import { useSharedAuth } from './sharedProps';

/**
 * Where "settings" goes from the account card: the installation's settings for whoever may open
 * them, and their own account's for everyone else — so the control is there for everybody and never
 * leads to a page that refuses them.
 */
export function useSettingsLink(): { href: string; label: string } {
    const { t } = useTranslation();
    const permissions = useSharedAuth()?.user.permissions ?? [];

    return permissions.includes('system.settings.view')
        ? { href: '/admin/settings', label: t((d) => d.nav.adminSettings) }
        : { href: '/account', label: t((d) => d.nav.accountGroup) };
}
