import type { JSX } from 'react';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * The one loading pattern every list-shaped screen reuses (document inbox, session list, extraction
 * list — `docs/handoff/040-ui-foundation.md` "Conventions"): row-shaped skeletons matching the real
 * content's layout, never a bare spinner, so the page doesn't visibly jump once data arrives.
 * `aria-hidden` because the meaningful state for assistive tech is the "Se încarcă…" text a screen
 * already renders alongside this, not the decorative bars.
 */
export default function ListSkeleton({ rows = 3 }: { rows?: number }): JSX.Element {
    return (
        <ul aria-hidden="true" className="space-y-2">
            {Array.from({ length: rows }, (_, index) => (
                <li key={index} className="rounded-lg border border-border bg-card p-4">
                    <div className="flex items-center justify-between gap-2">
                        <div className="space-y-2">
                            <Skeleton className="h-4 w-40" />
                            <Skeleton className="h-3 w-56" />
                        </div>
                        <Skeleton className="h-5 w-20 rounded-full" />
                    </div>
                </li>
            ))}
        </ul>
    );
}
