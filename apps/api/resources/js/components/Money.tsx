import type { JSX } from 'react';
import { cn } from 'cn';

import { useTranslation } from '../i18n';
import { amountParts } from '../lib/format';

/** The pieces read at full size: the whole part and its sign. The rest is read as a qualifier. */
const MAJOR = new Set<Intl.NumberFormatPartTypes>(['integer', 'group', 'minusSign', 'plusSign']);

/**
 * An amount where it is the headline: the whole part at the size it is set, the decimals and the
 * currency smaller and quieter after it — "9.205.959,88 RON" read as 9.205.959.
 *
 * Every digit is still there; only the weight moves. A headline is compared at a glance, and at one
 * weight the bani and the currency code are as loud as the millions. The text is exactly what
 * `formatAmount` writes (`amountParts`), so a reader that copies it, or a screen reader, gets the
 * ordinary amount.
 */
export function Money(props: { minor: number; currency: string; className?: string }): JSX.Element {
    const { locale } = useTranslation();

    return (
        <span className={cn('money tabular-nums', props.className)}>
            {amountParts(props.minor / 100, props.currency, locale).map((part, index) => (
                <span key={index} className={MAJOR.has(part.type) ? undefined : 'money-minor'}>
                    {part.value}
                </span>
            ))}
        </span>
    );
}
