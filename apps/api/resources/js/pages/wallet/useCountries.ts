import { usePage } from '@inertiajs/react';

/**
 * The countries this screen was handed by its route, from `config/reference/countries.php`.
 *
 * A page prop rather than a shared one: only the classification dialog offers the list, and putting
 * it on every Inertia response in the application would be a cost every other screen pays for
 * nothing. Mirrors `pages/subjects/useCounties`, which made the same trade for the same reason.
 */
export function useCountries(): { code: string; name: string }[] {
    const countries = usePage().props.countries;

    if (typeof countries !== 'object' || countries === null) {
        return [];
    }

    return Object.entries(countries as Record<string, unknown>)
        .filter((entry): entry is [string, string] => typeof entry[1] === 'string')
        .map(([code, name]) => ({ code, name }));
}
