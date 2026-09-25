import { ChevronDown } from 'lucide-react';
import type { JSX, ReactNode } from 'react';

import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from './ui/dropdown-menu';
import { useTranslation } from '../i18n';

/**
 * What to do with the rows that are ticked.
 *
 * Renders nothing while nothing is selected. A permanently-present "0 selected" button is a control
 * that does nothing most of the time, sitting in a row that is already full of filters — and its
 * appearing is itself the feedback that ticking a box did something.
 *
 * The menu items are the caller's: what can be done in bulk differs per screen, and the only thing
 * shared is the shape of the control and the count it shows. Where it sits is the caller's too — at
 * the end of a toolbar on one screen, beside the card's own action on another.
 */
export default function BulkActions(props: {
    count: number;
    /** `DropdownMenuItem`s — one per action this screen offers. */
    children: ReactNode;
    disabled?: boolean;
}): JSX.Element | null {
    const { t } = useTranslation();

    if (props.count === 0) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button type="button" className="shell-control" disabled={props.disabled === true}>
                    {t((d) => d.common.selectedCount).replace('{count}', String(props.count))}
                    <ChevronDown className="text-muted-foreground size-3" aria-hidden="true" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
                {props.children}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
