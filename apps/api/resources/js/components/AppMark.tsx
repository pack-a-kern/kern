import type { JSX } from 'react';
import { cn } from '../lib/utils';
import markUrl from '../../images/app-mark.webp';

/**
 * The application's mark: the shield from its icon, without the name under it.
 *
 * `resources/images/app-mark.webp` is the installed app's icon (`public/icons/icon-512.png`) without
 * its lettering — the shield lifted out, a little smaller and centred on the icon's own blue — because at the 32px a header gives it the icon's lettering is a smudge — and
 * the name is written beside the mark wherever it stands. Decorative for the same reason: a screen
 * reader hears that name, not the picture.
 *
 * Imported rather than linked from `public/`, so the build names it by its content: a new cut is a
 * new address, and no browser keeps showing the old one from its cache.
 */
export default function AppMark({ className }: { className?: string }): JSX.Element {
    return (
        <img
            src={markUrl}
            alt=""
            aria-hidden="true"
            width={128}
            height={128}
            className={cn('shrink-0 rounded-[10px] shadow-sm', className)}
        />
    );
}
