import type { JSX } from 'react';
import { RefreshCw } from 'lucide-react';
import { cn } from 'cn';

import { THRESHOLD } from '../hooks/usePullToRefresh';

/** How far the page fades under the veil: a hint while pulling, held once the reload has started. */
const VEIL_MAX = 0.55;

/**
 * What the pull shows: the page fading under a veil of its own background as the finger draws it down,
 * and a disc that slides from the top edge, turning as it comes, in the app's colour once letting go
 * would reload. After letting go the veil stays at its fullest and the disc spins until the new page
 * replaces this one — so the reload reads as something happening, not as a page that went still.
 */
export function PullIndicator(props: { pull: number; refreshing: boolean; label: string }): JSX.Element | null {
    if (props.pull === 0 && !props.refreshing) {
        return null;
    }

    const ready = props.refreshing || props.pull >= THRESHOLD;
    const veil = props.refreshing ? VEIL_MAX : Math.min(1, props.pull / THRESHOLD) * VEIL_MAX;

    return (
        <>
            <div
                className="pull-veil bg-background pointer-events-none absolute inset-0 transition-opacity duration-200 motion-reduce:transition-none"
                style={{ opacity: veil }}
                aria-hidden="true"
            />
            <div
                className="pull-mark pointer-events-none absolute inset-x-0 top-0 flex justify-center"
                style={{ transform: `translateY(${String(props.pull - 40)}px)` }}
                role="status"
                aria-label={props.label}
            >
                <span
                    className={cn(
                        'bg-card shadow-float grid size-10 place-items-center rounded-full border transition-colors',
                        ready ? 'border-primary text-primary' : 'border-border text-muted-foreground',
                    )}
                >
                    <RefreshCw
                        className={cn('size-4', props.refreshing && 'animate-spin motion-reduce:animate-none')}
                        style={props.refreshing ? undefined : { transform: `rotate(${String(props.pull * 3)}deg)` }}
                        aria-hidden="true"
                    />
                </span>
            </div>
        </>
    );
}
