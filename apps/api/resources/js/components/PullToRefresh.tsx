import type { CSSProperties, JSX } from 'react';

import { THRESHOLD } from '../hooks/usePullToRefresh';

/** How far the page fades under the veil: a hint while pulling, held once the reload has started. */
const VEIL_MAX = 0.55;

/** The disc's own height, so it starts the pull just out of sight above the top edge. */
const DISC = 56;

/**
 * The wait, one look wherever it comes from: the page fading under a veil of its own background, and
 * a disc whose ring turns. Pulled, the disc slides from the top edge with the finger, its ring
 * filling until letting go would reload, and spins where it stopped once let go. A page on its way
 * from a tap has no finger to follow, so its disc settles in the middle of the page.
 */
export function PullIndicator(props: { pull: number; refreshing: boolean; label: string }): JSX.Element | null {
    if (props.pull === 0 && !props.refreshing) {
        return null;
    }

    const pulled = props.pull > 0;
    const veil = props.refreshing ? VEIL_MAX : Math.min(1, props.pull / THRESHOLD) * VEIL_MAX;

    return (
        <>
            <div
                className="pull-veil bg-background pointer-events-none absolute inset-0 transition-opacity duration-200 motion-reduce:transition-none"
                style={{ opacity: veil }}
                aria-hidden="true"
            />
            <div
                className={
                    pulled
                        ? 'pull-mark pointer-events-none absolute inset-x-0 top-0 flex justify-center'
                        : 'pull-mark load-stage pointer-events-none absolute inset-0 grid place-items-center'
                }
                style={pulled ? { transform: `translateY(${String(props.pull - DISC)}px)` } : undefined}
                role={props.refreshing ? 'status' : undefined}
                aria-label={props.refreshing ? props.label : undefined}
                aria-hidden={props.refreshing ? undefined : true}
            >
                <span className="load-disc" data-arrived={pulled ? '' : undefined}>
                    {props.refreshing ? (
                        <span className="load-ring" />
                    ) : (
                        <span
                            className="load-ring"
                            data-progress=""
                            style={{ '--load-progress': Math.min(1, props.pull / THRESHOLD) } as CSSProperties}
                        />
                    )}
                </span>
            </div>
        </>
    );
}
