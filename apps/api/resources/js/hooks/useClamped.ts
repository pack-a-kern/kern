import { useEffect, useState, type RefObject } from 'react';

/**
 * Whether an element's text is cut by its line clamp — more of it laid out than its box shows —
 * measured again whenever the box changes size, since a narrower card clamps a name a wider one did not.
 */
export function useClamped(ref: RefObject<HTMLElement | null>): boolean {
    const [clamped, setClamped] = useState(false);

    useEffect(() => {
        const element = ref.current;

        if (element === null) {
            return;
        }

        const measure = new ResizeObserver(() => {
            setClamped(element.scrollHeight > element.clientHeight);
        });

        measure.observe(element);

        return () => {
            measure.disconnect();
        };
    }, [ref]);

    return clamped;
}
