/**
 * Every interface palette the app offers, and the rule that turns one into a full colour ramp.
 *
 * This file is the only place a palette is defined. Adding one means adding an entry below and
 * nothing else in this module — the 50–900 ramp is derived, never hand-picked, so a palette is four
 * colours rather than thirteen and two palettes cannot drift out of step with each other.
 *
 * Names and descriptions are deliberately absent: they are display text and belong in
 * `resources/js/i18n`, keyed by {@link PaletteName}. The dictionary types them as a
 * `Record<PaletteName, …>`, so adding an entry here fails `tsc` until both languages have a label —
 * the compiler walks you through the rest rather than letting a palette ship unnamed.
 */

/** The four colours a palette is built from. Everything else is derived. */
export interface Palette {
    /** The brand. The whole 50–900 ramp is mixed from this one value. */
    primary: string;
    /** Structural, non-brand text and chrome — kept separate so it survives a brand change. */
    support: string;
    /** "Needs a person": a proposed extraction, a pending confidence bar. Never an error. */
    attention: string;
    /** Something is wrong and a person must act. */
    critical: string;
}

export const PALETTES = {
    /** The accent family the approved documents/review versions are drawn in. The default. */
    legacyV16V17: {
        primary: '#386A96',
        support: '#667085',
        attention: '#F59E0B',
        critical: '#DC2626',
    },
    balanced: {
        primary: '#5474B4',
        support: '#63687C',
        attention: '#F06418',
        critical: '#C91A52',
    },
    /** Same colours as `balanced`, kept as its own entry because it is a different proposition:
     *  one is "the compromise", the other is "the identity". Both are in the design. */
    blue: {
        primary: '#5474B4',
        support: '#63687C',
        attention: '#F06418',
        critical: '#C91A52',
    },
    slate: {
        primary: '#63687C',
        support: '#5474B4',
        attention: '#F06418',
        critical: '#C91A52',
    },
    berry: {
        primary: '#C91A52',
        support: '#63687C',
        attention: '#F06418',
        critical: '#9F1239',
    },
    cocoa: {
        primary: '#5D4037',
        support: '#63687C',
        attention: '#F06418',
        critical: '#C91A52',
    },
    orange: {
        primary: '#F06418',
        support: '#63687C',
        attention: '#5474B4',
        critical: '#C91A52',
    },
} as const satisfies Record<string, Palette>;

export type PaletteName = keyof typeof PALETTES;

export const PALETTE_NAMES = Object.keys(PALETTES) as PaletteName[];

/**
 * The palette a person sees before they ever choose one.
 *
 * From the environment rather than a literal, so a deployment can set its own default without a
 * code change. An unrecognised value falls back rather than leaving the app unstyled — a typo in a
 * build variable should not be able to break every colour in the interface.
 */
export const DEFAULT_PALETTE: PaletteName = isPaletteName(import.meta.env.VITE_DEFAULT_PALETTE)
    ? import.meta.env.VITE_DEFAULT_PALETTE
    : 'legacyV16V17';

export function isPaletteName(value: unknown): value is PaletteName {
    return typeof value === 'string' && Object.prototype.hasOwnProperty.call(PALETTES, value);
}

/**
 * How far each step of the ramp is mixed toward white (below 500) or black (above it).
 *
 * Fixed weights rather than a perceptual model: the ramp has to be reproducible for any brand
 * colour someone drops in, including ones nobody has looked at yet, and predictable beats optimal
 * when the alternative is hand-tuning ten values per palette.
 */
const RAMP = [
    [50, '#FFFFFF', 0.91],
    [100, '#FFFFFF', 0.82],
    [200, '#FFFFFF', 0.66],
    [300, '#FFFFFF', 0.47],
    [400, '#FFFFFF', 0.25],
    [500, null, 0],
    [600, '#000000', 0.12],
    [700, '#000000', 0.25],
    [800, '#000000', 0.36],
    [900, '#000000', 0.48],
] as const;

function toRgb(hex: string): [number, number, number] {
    const value = hex.replace('#', '');

    return [
        parseInt(value.slice(0, 2), 16),
        parseInt(value.slice(2, 4), 16),
        parseInt(value.slice(4, 6), 16),
    ];
}

function toHex(channels: number[]): string {
    return '#' + channels.map((channel) => channel.toString(16).padStart(2, '0')).join('').toUpperCase();
}

/** Blends two colours in sRGB. `weight` is how much of `towards` ends up in the result. */
export function mix(base: string, towards: string, weight: number): string {
    const [fromRed, fromGreen, fromBlue] = toRgb(base);
    const [toRed, toGreen, toBlue] = toRgb(towards);
    const blend = (from: number, to: number): number => Math.round(from * (1 - weight) + to * weight);

    return toHex([blend(fromRed, toRed), blend(fromGreen, toGreen), blend(fromBlue, toBlue)]);
}

/** The full 50–900 ramp for a brand colour. */
export function buildRamp(primary: string): Record<number, string> {
    const ramp: Record<number, string> = {};

    for (const [shade, towards, weight] of RAMP) {
        ramp[shade] = towards === null ? primary.toUpperCase() : mix(primary, towards, weight);
    }

    return ramp;
}

/**
 * A palette as the CSS custom properties `app.css` styles against.
 *
 * `--brand-rgb` accompanies the ramp so a rule can build a translucent brand colour
 * (`rgb(var(--brand-rgb) / 12%)`) without a second hard-coded value going out of step with it.
 */
export function paletteVariables(name: PaletteName): Record<string, string> {
    const palette = PALETTES[name];
    const ramp = buildRamp(palette.primary);

    const variables: Record<string, string> = {
        '--support': palette.support,
        '--attention': palette.attention,
        '--critical': palette.critical,
        '--brand-rgb': toRgb(palette.primary).join(' '),
    };

    for (const [shade, color] of Object.entries(ramp)) {
        variables[`--brand-${shade}`] = color;
    }

    return variables;
}
