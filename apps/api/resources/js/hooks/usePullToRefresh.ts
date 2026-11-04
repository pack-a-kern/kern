import { useEffect, useState, type RefObject } from 'react';

/** How far the content has to be pulled, in pixels after resistance, before letting go reloads. */
export const THRESHOLD = 64;
/** Where the pull stops following the finger. */
const MAX_PULL = 110;
/** Pulling moves the indicator at half the finger's speed — the resistance that makes it feel held. */
const RESISTANCE = 0.5;

/**
 * Whether the touch began inside something that is itself scrolled down — an inner list, a dialog's
 * body. Pulling there scrolls that thing back up; it is not a request to reload the page.
 */
function startsInScrolledChild(target: EventTarget | null, root: HTMLElement): boolean {
    for (
        let node = target instanceof Element ? target : null;
        node !== null && node !== root;
        node = node.parentElement
    ) {
        if (node.scrollTop > 0) {
            return true;
        }
    }

    return false;
}

/**
 * Pull down from the top of `scroller` to reload the page, on a touch screen.
 *
 * Built here because nothing provides it: the app shell's page never scrolls — its `main` does — so
 * neither an installed iOS app (which has no pull-to-refresh at all) nor Android's browser (which only
 * pulls the document) offers one. The reload is a full one: every screen loads its own data on mount,
 * so this is the one refresh that is certain to bring all of it back.
 *
 * Starts only at the very top, only downwards, and lets go of a gesture that turns out sideways — a
 * swipe across a wide table is not a pull.
 */
export function usePullToRefresh(scroller: RefObject<HTMLElement | null>): { pull: number; refreshing: boolean } {
    const [pull, setPull] = useState(0);
    const [refreshing, setRefreshing] = useState(false);

    useEffect(() => {
        const element = scroller.current;

        if (element === null || !window.matchMedia('(pointer: coarse)').matches) {
            return;
        }

        let startX = 0;
        let startY = 0;
        let tracking = false;
        let current = 0;

        const onStart = (event: TouchEvent): void => {
            const touch = event.touches[0];
            tracking =
                touch !== undefined &&
                event.touches.length === 1 &&
                element.scrollTop <= 0 &&
                !startsInScrolledChild(event.target, element);

            if (touch !== undefined) {
                startX = touch.clientX;
                startY = touch.clientY;
            }
        };

        const onMove = (event: TouchEvent): void => {
            const touch = event.touches[0];

            if (!tracking || touch === undefined) {
                return;
            }

            const dx = touch.clientX - startX;
            const dy = touch.clientY - startY;

            // Upwards, sideways, or the content has moved under the finger: not a pull after all.
            if (dy <= 0 || Math.abs(dx) > Math.abs(dy) || element.scrollTop > 0) {
                tracking = current > 0;

                if (!tracking) {
                    return;
                }
            }

            current = Math.min(MAX_PULL, Math.max(0, dy) * RESISTANCE);
            setPull(current);

            // Hold the content still while the indicator follows, instead of the elastic bounce.
            if (current > 0 && event.cancelable) {
                event.preventDefault();
            }
        };

        const onEnd = (): void => {
            if (!tracking && current === 0) {
                return;
            }

            tracking = false;

            if (current >= THRESHOLD) {
                setPull(THRESHOLD);
                setRefreshing(true);
                window.location.reload();
            } else {
                current = 0;
                setPull(0);
            }
        };

        element.addEventListener('touchstart', onStart, { passive: true });
        element.addEventListener('touchmove', onMove, { passive: false });
        element.addEventListener('touchend', onEnd);
        element.addEventListener('touchcancel', onEnd);

        return () => {
            element.removeEventListener('touchstart', onStart);
            element.removeEventListener('touchmove', onMove);
            element.removeEventListener('touchend', onEnd);
            element.removeEventListener('touchcancel', onEnd);
        };
    }, [scroller]);

    return { pull, refreshing };
}
