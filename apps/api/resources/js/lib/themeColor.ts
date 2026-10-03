/** A theme colour as a value Chart.js can use, since it cannot resolve `var(--…)` itself. */
export function themeColor(name: string, alpha = 1): string {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    if (value === '') {
        return 'transparent';
    }

    return alpha === 1 ? value : `color-mix(in srgb, ${value} ${String(Math.round(alpha * 100))}%, transparent)`;
}
