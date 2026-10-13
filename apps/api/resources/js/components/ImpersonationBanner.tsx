import { useState, type JSX } from 'react';
import { ArrowLeft, UserRoundCog } from 'lucide-react';
import { toast } from 'sonner';

import { stopImpersonating } from '../api/adminApi';
import { useSharedAuth } from '../lib/sharedProps';
import { useTranslation } from '../i18n';
import { describeApiFailure } from '../lib/apiErrors';

/**
 * The strip that says whose account you are in.
 *
 * Impersonation is invisible by design — every screen behaves exactly as it does for the person
 * being impersonated, and every record written looks like theirs. That is what makes it useful for
 * support and what makes it dangerous: an administrator who forgets they are still inside someone
 * else's account will act as that person without meaning to.
 *
 * So the state gets a permanent, unmissable line at the top of every screen, and the way out sits
 * in it. Above the shell rather than inside a page, because it is true of the whole session and not
 * of whatever is being looked at.
 */
export default function ImpersonationBanner(): JSX.Element | null {
    const { t } = useTranslation();
    const auth = useSharedAuth();
    const [leaving, setLeaving] = useState(false);

    if (auth === null || !auth.impersonated) {
        return null;
    }

    const stop = async (): Promise<void> => {
        setLeaving(true);

        try {
            await stopImpersonating();
            // A full load: every screen behind this was rendered for the other account, and the
            // session that fetched it is no longer the session in force.
            window.location.assign('/admin/users');
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.shell.impersonationStopFailed),
                { description: describeApiFailure(cause, t) },
            );
            setLeaving(false);
        }
    };

    return (
        <div className="impersonation-banner" role="status">
            <span className="flex min-w-0 items-center gap-2">
                <UserRoundCog className="size-4 shrink-0" aria-hidden="true" />
                <span className="truncate">
                    {t((d) => d.shell.impersonating).replace('{email}', auth.user.email ?? auth.user.id)}
                </span>
            </span>

            <button
                type="button"
                className="impersonation-exit"
                disabled={leaving}
                onClick={() => {
                    void stop();
                }}
            >
                <ArrowLeft className="size-3.5" aria-hidden="true" />
                {t((d) => d.shell.impersonationStop)}
            </button>
        </div>
    );
}
