import { describe, expect, it } from 'vitest';
import { buildRamp, isPaletteName, mix, PALETTES, PALETTE_NAMES, paletteVariables } from '../palette';

describe('buildRamp', () => {
    it('keeps the brand colour itself at 500', () => {
        expect(buildRamp('#386A96')[500]).toBe('#386A96');
    });

    it('runs light to dark across the ramp', () => {
        const ramp = buildRamp('#386A96');

        // 50 is nearly white, 900 nearly black — the ordering every rule in app.css assumes.
        expect(ramp[50]).toBe('#EDF2F6');
        expect(ramp[900]).toBe('#1D374E');
    });

    it('matches the literal fallbacks app.css carries for the default palette', () => {
        // Those literals exist so the page is styled before JavaScript runs. If the generator and
        // the stylesheet disagree, the palette visibly shifts on load.
        const ramp = buildRamp(PALETTES.legacyV16V17.primary);

        expect(ramp[100]).toBe('#DBE4EC');
        expect(ramp[200]).toBe('#BBCCDB');
        expect(ramp[400]).toBe('#6A8FB0');
        expect(ramp[800]).toBe('#244460');
    });
});

describe('mix', () => {
    it('returns each end at the extremes', () => {
        expect(mix('#386A96', '#FFFFFF', 0)).toBe('#386A96');
        expect(mix('#386A96', '#FFFFFF', 1)).toBe('#FFFFFF');
    });
});

describe('paletteVariables', () => {
    it('emits every custom property app.css styles against', () => {
        const variables = paletteVariables('legacyV16V17');

        expect(variables['--brand-500']).toBe('#386A96');
        expect(variables['--support']).toBe('#667085');
        expect(variables['--attention']).toBe('#F59E0B');
        expect(variables['--critical']).toBe('#DC2626');
        // Space-separated, so a rule can write `rgb(var(--brand-rgb) / 12%)`.
        expect(variables['--brand-rgb']).toBe('56 106 150');
    });

    it('covers every palette in the registry, so adding one needs no change here', () => {
        for (const name of PALETTE_NAMES) {
            const variables = paletteVariables(name);

            expect(Object.keys(variables)).toHaveLength(14);
            expect(variables['--brand-500']).toBe(PALETTES[name].primary);
        }
    });
});

describe('isPaletteName', () => {
    it('rejects anything not in the registry', () => {
        // Guards VITE_DEFAULT_PALETTE and the stored preference: a typo must not leave the app
        // unstyled, it must fall back.
        expect(isPaletteName('legacyV16V17')).toBe(true);
        expect(isPaletteName('nope')).toBe(false);
        expect(isPaletteName('toString')).toBe(false);
        expect(isPaletteName(null)).toBe(false);
    });
});
