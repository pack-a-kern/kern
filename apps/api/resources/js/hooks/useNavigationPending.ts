import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Whether a visit to another page is under way — from the moment it starts until it ends, arrived or
 * not. Prefetches and reloads of the page's own data are not visits to another page, so they never
 * count.
 */
export function useNavigationPending(): boolean {
    const [pending, setPending] = useState(false);

    useEffect(() => {
        const stopStart = router.on('start', (event) => {
            if (!event.detail.visit.prefetch && event.detail.visit.only.length === 0) {
                setPending(true);
            }
        });
        const stopFinish = router.on('finish', () => {
            setPending(false);
        });

        return () => {
            stopStart();
            stopFinish();
        };
    }, []);

    return pending;
}
