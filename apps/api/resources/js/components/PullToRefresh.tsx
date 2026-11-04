import type { JSX } from 'react';
import { RefreshCw } from 'lucide-react';
import { cn } from 'cn';

import { THRESHOLD } from '../hooks/usePullToRefresh';

/**
 * The mark that follows the pull: a disc that slides down from the top edge, turning as it comes, in
 * the app's colour once letting go would reload — and spinning while it does.
 */
export function PullIndicator(props: { pull: number; refreshing: boolean; label: string }): JSX.Element | null {
    if (props.pull === 0 && !props.refreshing) {
        return null;
    }

    const ready = props.refreshing || props.pull >= THRESHOLD;

    return (
        <div
            className="pointer-events-none absolute inset-x-0 top-0 z-20 flex justify-center"
            style={{ transform: `translateY(${String(props.pull - 40)}px)` }}
            role="status"
            aria-label={props.label}
        >
            <span
                className={cn(
                    'bg-card shadow-float grid size-9 place-items-center rounded-full border transition-colors',
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
    );
}
