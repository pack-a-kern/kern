import type { JSX } from 'react';
import { bankLabel } from '../../types/wallet';
import type { Bank } from '../../types/wallet';

/**
 * A bank as the four bank selects offer it: a dot for whether it still exists, the name, and "closed"
 * on its own line where it does not.
 *
 * ## Why a dot rather than words in the label
 *
 * The state used to be appended to the name — "Alpha Bank · BUCU (closed)" — which made the one thing
 * every row shares the last thing read, and only after the name, the code and a bracket. A mark in a
 * fixed position is read down the column at a glance, and the select puts the chosen option's icon on
 * the trigger too, so the state survives the menu closing.
 *
 * ## Why closed is grey and not red
 *
 * Because choosing a closed bank is usually correct. A statement from 2019 names whichever bank
 * existed in 2019, and half of these lists are there for exactly that. Red would mark a right answer
 * as a mistake; grey says "no longer current" without arguing about it.
 *
 * ## One helper, four callers
 *
 * `accounts`, `transactions` (twice) and `import` all draw this list, and a mark added to three of
 * them is a mark missing from the fourth.
 */
export interface BankSelectOption {
    value: string;
    label: string;
    icon: JSX.Element;
    description?: string;
}

export function bankOptions(banks: Bank[], closedLabel: string): BankSelectOption[] {
    return banks.map((bank) => ({
        value: bank.id,
        // Without the closed suffix: that is what the description below is for, and saying it twice
        // makes the name longer in the one place the name has to be scanned.
        label: bankLabel(bank),
        icon: (
            <span
                className={
                    bank.active
                        ? 'size-1.5 rounded-full bg-emerald-500'
                        : 'size-1.5 rounded-full bg-muted-foreground/50'
                }
                aria-hidden="true"
            />
        ),
        ...(bank.active ? {} : { description: closedLabel }),
    }));
}
