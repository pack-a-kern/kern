/**
 * How the phone's bottom bar looks (AppShell's BottomNav). Every combination is styled in app.css
 * under `.bottom-dock[data-*]`, so changing the bar is changing a value here.
 */

/** Where a section's name shows: hidden (read out only), under every icon, or beside the current one. */
export type BottomBarLabels = 'none' | 'below' | 'beside';

/**
 * How the current section is marked: its colour only, a soft tinted shape, a solid brand-coloured
 * shape, or a short line on the bar's top edge. With `beside` the shape is the whole tab; otherwise
 * it sits behind the icon.
 */
export type BottomBarMark = 'color' | 'tint' | 'solid' | 'line';

/** A rounded bar floating above the bottom edge, or one running edge to edge along it. */
export type BottomBarShape = 'floating' | 'docked';

export interface BottomBarStyle {
    labels: BottomBarLabels;
    mark: BottomBarMark;
    shape: BottomBarShape;
    /** How many sections show at once; the rest are a swipe, or a tap at either end, away. */
    shown: number;
    /** With `beside`, how many icon-wide slots the current tab takes to carry its name. */
    activeSlots: number;
}

export const BOTTOM_BAR: BottomBarStyle = {
    labels: 'none',
    mark: 'tint',
    shape: 'docked',
    shown: 5,
    activeSlots: 3,
};
