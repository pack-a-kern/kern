import { useEffect, useState } from 'react';

/** Drives the offline capture queue's retry trigger (`BLUEPRINT.md` §1.4 "offline capture queue"). */
export function useOnlineStatus(): boolean {
    const [online, setOnline] = useState(navigator.onLine);

    useEffect(() => {
        const handleOnline = (): void => {
            setOnline(true);
        };
        const handleOffline = (): void => {
            setOnline(false);
        };

        window.addEventListener('online', handleOnline);
        window.addEventListener('offline', handleOffline);

        return () => {
            window.removeEventListener('online', handleOnline);
            window.removeEventListener('offline', handleOffline);
        };
    }, []);

    return online;
}
