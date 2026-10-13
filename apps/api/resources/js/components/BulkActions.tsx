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
 * `alwaysVisible` opts out, for the one case where that argument does not hold: a header whose only
 * other occupant is a tick-everything box. There the row exists whether or not anything is
 * selected, and a control that comes and goes beside a fixed one makes the row jump; disabled, it
 * says what will be possible once something is ticked.
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
    /** Stay on screen, disabled, while nothing is selected — see the note above. */
    alwaysVisible?: boolean;
}): JSX.Element | null {
    const { t } = useTranslation();
    const empty = props.count === 0;

    if (empty && props.alwaysVisible !== true) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                {/* `ctl-row`: this one lives in a toolbar, not in the shell, so it takes the
                    height of the controls it stands beside rather than the bar's. */}
                <button type="button" className="shell-control ctl-row" disabled={empty || props.disabled === true}>
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
