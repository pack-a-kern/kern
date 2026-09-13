import type { JSX } from 'react';
import { AtSignIcon, CalendarIcon, HashIcon, KeyRoundIcon, LinkIcon, PhoneIcon, SearchIcon } from 'lucide-react';

/**
 * The icon a field gets from its own `type`, when nobody passed one.
 *
 * Every control on these screens carries a leading mark, and until now each call site had to
 * remember one — so a password field in a dialog sat bare beside the identical field on the card
 * behind it. Deriving the obvious ones means a date input is a date input wherever it appears, and
 * a screen only names an icon where the type cannot say what the field is for.
 *
 * `text` deliberately returns nothing: "some text" is not a category, and a generic glyph on every
 * text box is decoration that makes the meaningful icons harder to see. Those fields name their own
 * icon at the call site — an institution, an IBAN, a person — or go without.
 */
export function iconForInputType(type: string | undefined): JSX.Element | undefined {
    switch (type) {
        case 'password':
            return <KeyRoundIcon className="size-3.5" />;
        case 'date':
        case 'datetime-local':
        case 'month':
        case 'week':
            return <CalendarIcon className="size-3.5" />;
        case 'email':
            return <AtSignIcon className="size-3.5" />;
        case 'url':
            return <LinkIcon className="size-3.5" />;
        case 'tel':
            return <PhoneIcon className="size-3.5" />;
        case 'search':
            return <SearchIcon className="size-3.5" />;
        case 'number':
            return <HashIcon className="size-3.5" />;
        default:
            return undefined;
    }
}
