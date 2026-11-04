import { useEffect, useState, type JSX } from 'react';
import { Link } from '@inertiajs/react';
import { ServerCrash } from 'lucide-react';

import { useTranslation } from '../i18n';
import { subscribeToSystemHealth } from '../lib/echo';
import { useSystemHealthDown } from '../lib/sharedProps';

/** Where the probe's full answer is: Settings, whose System status card asks every service again. */
const STATUS_PATH = '/admin/settings';

/**
 * A strip across the top while a service this installation runs is not answering — for the people who
 * may read the system status, and nobody else.
 *
 * Starts from what the page carried (the scheduler's last look) and follows the WebSocket from there:
 * a store that goes down while someone is working shows up within one scheduled look, and the strip
 * goes away by itself when it answers again. Nothing here asks the server on a timer.
 */
export default function SystemHealthBanner(): JSX.Element | null {
    const { t } = useTranslation();
    const shared = useSystemHealthDown();
    const [pushed, setPushed] = useState<string[] | null>(null);
    const allowed = shared !== null;

    useEffect(() => {
        if (!allowed) {
            return;
        }

        return subscribeToSystemHealth(setPushed);
    }, [allowed]);

    const down = pushed ?? shared ?? [];

    if (!allowed || down.length === 0) {
        return null;
    }

    const names = down.map((key) => t((d) => d.pages.adminSettings.health.components)[key] ?? key).join(', ');
    const message =
        down.length === 1
            ? t((d) => d.shell.systemDownOne).replace('{names}', names)
            : t((d) => d.shell.systemDownMany)
                  .replace('{count}', String(down.length))
                  .replace('{names}', names);

    return (
        <div className="shell-banner shell-banner-danger" role="alert">
            <span className="flex min-w-0 items-center gap-2">
                <ServerCrash className="size-4 shrink-0" aria-hidden="true" />
                <span className="min-w-0">{message}</span>
            </span>
            <Link href={STATUS_PATH} className="shrink-0 underline underline-offset-2">
                {t((d) => d.shell.viewSystemStatus)}
            </Link>
        </div>
    );
}
