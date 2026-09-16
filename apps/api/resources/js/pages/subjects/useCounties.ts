import { usePage } from '@inertiajs/react';

/**
 * The counties this screen was handed by its route, from `config/reference/counties.php`.
 *
 * A hook rather than a prop threaded through two dialogs: both forms need the same list, the page
 * already carries it, and passing it down would be two more parameters that can fall out of step.
 * Empty on a page that was not given one, which renders an empty picker rather than a broken form.
 */
export function useCounties(): { code: string; name: string }[] {
    const counties = usePage().props.counties;

    if (typeof counties !== 'object' || counties === null) {
        return [];
    }

    return Object.entries(counties as Record<string, unknown>)
        .filter((entry): entry is [string, string] => typeof entry[1] === 'string')
        .map(([code, name]) => ({ code, name }));
}
