import { Banknote, Bitcoin, CandlestickChart, PiggyBank, Wallet, type LucideIcon } from 'lucide-react';
import type { CSSProperties, JSX } from 'react';
import { cn } from '../../lib/utils';

/**
 * The mark that stands where a bank logo would, for an account that has none.
 *
 * A term deposit is drawn by its bank's {@link BankMark}; cash, a brokerage, crypto and anything
 * else have no logo, so each gets a tile carrying its kind's icon on a hue of its own. It borrows
 * `.bank-tile` geometry — size, radius, ring — *and* its hue formula, the very one a bank with no
 * logo uses for its initials, so the icon tiles read as the same family rather than a second style.
 * A row's name then sits at the same offset whether a logo, initials or a kind icon leads it, which
 * is the inconsistency this fixes. Decorative: the kind is always named in words beside it, so the
 * tile has no text of its own for a screen reader.
 *
 * A deposit opened out of an account nobody tracks has no bank either, and falls here on
 * {@link PiggyBank} rather than on nothing — the one kind that can be drawn both ways.
 */
const KIND_ICON: Record<string, LucideIcon> = {
    CASH: Banknote,
    TERM_DEPOSIT: PiggyBank,
    BROKER: CandlestickChart,
    CRYPTO: Bitcoin,
    OTHER: Wallet,
};

/**
 * A fixed hue (0–359) per kind, spread around the wheel so the five read apart at a glance: cash
 * green, a deposit purple, a brokerage blue, crypto the colour of its coin, anything else teal.
 * Fixed rather than hashed like a bank's, because there are a handful of kinds and they should look
 * the same on every screen.
 */
const KIND_HUE: Record<string, number> = {
    CASH: 150,
    TERM_DEPOSIT: 275,
    BROKER: 210,
    CRYPTO: 35,
    OTHER: 190,
};

export default function AccountKindMark({
    kind,
    size = 'md',
}: {
    kind: string;
    /** `md` beside a name, `two-line` beside a name and the line under it — matching {@link BankMark}. */
    size?: 'md' | 'two-line';
}): JSX.Element {
    const Icon = KIND_ICON[kind] ?? Wallet;

    return (
        <span
            className={cn('bank-tile', size === 'two-line' && 'bank-tile-two-line')}
            style={{ '--bank-hue': KIND_HUE[kind] ?? 220 } as CSSProperties}
            aria-hidden="true"
        >
            <Icon className="kind-tile-icon" />
        </span>
    );
}
