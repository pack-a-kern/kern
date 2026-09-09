import type { JSX } from 'react';
import {
    ClockIcon,
    FileTextIcon,
    GaugeIcon,
    HardDriveIcon,
    HashIcon,
    ImageIcon,
    KeyRoundIcon,
    LinkIcon,
    TypeIcon,
} from 'lucide-react';

import { unitFor } from './settingUnits';

/**
 * The icon that belongs in a setting's field.
 *
 * Chosen from what the setting *is* — its type and its unit — rather than from a table keyed by
 * name. A per-key table would need an entry for every new setting and would silently fall back to
 * nothing when someone forgot; deriving it means a new duration or a new size is decorated
 * correctly the day it is added.
 *
 * The icon is decorative: it repeats what the label already says, for people who scan a form by
 * shape before they read it. It is `aria-hidden` at the point of use, so it adds nothing for a
 * screen reader and takes nothing away.
 */
export function iconForSetting(key: string, type: string): JSX.Element | undefined {
    if (type === 'secret') {
        return <KeyRoundIcon className="size-3.5" />;
    }

    if (type === 'url') {
        return <LinkIcon className="size-3.5" />;
    }

    const unit = unitFor(key);

    switch (unit?.suffix) {
        case 'MB':
            return <HardDriveIcon className="size-3.5" />;
        case 'MP':
            return <ImageIcon className="size-3.5" />;
        case 's':
        case 'ms':
        case 'min':
        case 'd':
            return <ClockIcon className="size-3.5" />;
        default:
            break;
    }

    // A URL template is still a URL, but it is not typed as one because it carries placeholders.
    if (key.endsWith('_url_template')) {
        return <LinkIcon className="size-3.5" />;
    }

    if (type === 'integer') {
        return <HashIcon className="size-3.5" />;
    }

    if (type === 'decimal') {
        return <GaugeIcon className="size-3.5" />;
    }

    if (key.endsWith('.model') || key.endsWith('.provider')) {
        return <FileTextIcon className="size-3.5" />;
    }

    return <TypeIcon className="size-3.5" />;
}
