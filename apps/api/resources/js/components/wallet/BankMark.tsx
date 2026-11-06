import { useState, type CSSProperties, type JSX } from 'react';
import { bankHue, bankInitials } from '../../lib/bankMark';
import { cn } from '../../lib/utils';
import type { Bank } from '../../types/wallet';

/**
 * A bank's mark: its logo where this installation has one, its initials otherwise.
 *
 * The logo is an `<img>`, always. The file may be an SVG, and an SVG drawn as an image runs no
 * script and loads nothing, whatever it contains; the server sends it with a policy that says the
 * same. Decorative: the bank's name is always written beside it, so the mark has no text of its own
 * for a screen reader.
 *
 * A logo that fails to load falls back to the initials rather than to a broken image.
 */
export default function BankMark({
    bank,
    size = 'md',
}: {
    bank: Pick<Bank, 'name' | 'code' | 'active' | 'logoUrl'>;
    /**
     * `sm` in a line of small text, `md` beside a name, `two-line` beside a name and the line under
     * it, `lg` heading a card's row.
     */
    size?: 'sm' | 'md' | 'two-line' | 'lg';
}): JSX.Element {
    const [failed, setFailed] = useState<string | null>(null);
    const logo = bank.logoUrl !== null && failed !== bank.logoUrl ? bank.logoUrl : null;

    return (
        <span
            className={cn(
                'bank-tile',
                size !== 'md' && `bank-tile-${size}`,
                logo !== null && 'bank-tile-logo',
                !bank.active && 'bank-tile-closed',
            )}
            style={{ '--bank-hue': bankHue(bank.code ?? bank.name) } as CSSProperties}
            aria-hidden="true"
        >
            {logo !== null ? (
                <img
                    src={logo}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    onError={() => {
                        setFailed(logo);
                    }}
                />
            ) : (
                bankInitials(bank.name)
            )}
        </span>
    );
}
