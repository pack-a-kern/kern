import { useEffect, useRef, useState, type JSX } from 'react';
import { Wifi, WifiOff } from 'lucide-react';

import { useOnlineStatus } from '../hooks/useOnlineStatus';
import { useTranslation } from '../i18n';

/** How long "back online" stays once the connection returns — long enough to read, then out of the way. */
const BACK_ONLINE_MS = 3000;

/**
 * Whether this device is reaching the internet, said across the top while it is not.
 *
 * Opening a page with no connection gets the service worker's offline page (`public/offline.html`).
 * This is the other case: the app is already open and the connection drops. Nothing on screen changed,
 * so every figure looked current and every save looked like it should work — this says otherwise until
 * the connection is back, then says that briefly and goes.
 *
 * `navigator.onLine` is the browser's own answer, which errs one way only: "offline" is reliable, while
 * "online" can mean a network with no internet behind it. So the strip can miss an outage, never invent
 * one.
 */
export default function ConnectionBanner(): JSX.Element | null {
    const { t } = useTranslation();
    const online = useOnlineStatus();
    const [justBack, setJustBack] = useState(false);
    const wasOffline = useRef(!online);

    useEffect(() => {
        if (!online) {
            wasOffline.current = true;

            return;
        }

        if (!wasOffline.current) {
            return;
        }

        wasOffline.current = false;
        const show = window.setTimeout(() => {
            setJustBack(true);
        }, 0);
        const hide = window.setTimeout(() => {
            setJustBack(false);
        }, BACK_ONLINE_MS);

        return () => {
            window.clearTimeout(show);
            window.clearTimeout(hide);
        };
    }, [online]);

    if (!online) {
        return (
            <div className="shell-banner shell-banner-offline" role="status">
                <span className="flex min-w-0 items-center gap-2">
                    <WifiOff className="size-4 shrink-0" aria-hidden="true" />
                    <span className="min-w-0">
                        {t((d) => d.shell.offline)}{' '}
                        <span className="font-normal opacity-80">{t((d) => d.shell.offlineHint)}</span>
                    </span>
                </span>
            </div>
        );
    }

    return justBack ? (
        <div className="shell-banner shell-banner-success" role="status">
            <span className="flex min-w-0 items-center gap-2">
                <Wifi className="size-4 shrink-0" aria-hidden="true" />
                {t((d) => d.shell.backOnline)}
            </span>
        </div>
    ) : null;
}
