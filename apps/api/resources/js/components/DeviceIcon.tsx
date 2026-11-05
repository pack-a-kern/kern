import type { JSX } from 'react';
import { Bot, Monitor, MonitorSmartphoneIcon, Smartphone, Tablet } from 'lucide-react';

/**
 * The mark for what a session or a notification device is — a phone, a tablet, a computer.
 *
 * Decoration, and deliberately so: the kind is read out of a string the client chose, so nothing is
 * decided from it — see `Kern\Platform\Identity\Enums\DeviceKind`. A robot gets its own mark rather
 * than a laptop's, because a session opened by one is either this installation's own tooling or
 * something worth a second look, and a laptop icon would say neither. An unknown kind is drawn as
 * either, never as an error.
 */
export default function DeviceIcon({ kind }: { kind: string }): JSX.Element {
    const className = 'text-muted-foreground size-4 shrink-0';

    switch (kind) {
        case 'mobile':
            return <Smartphone className={className} aria-hidden="true" />;
        case 'tablet':
            return <Tablet className={className} aria-hidden="true" />;
        case 'desktop':
            return <Monitor className={className} aria-hidden="true" />;
        case 'robot':
            return <Bot className={className} aria-hidden="true" />;
        default:
            return <MonitorSmartphoneIcon className={className} aria-hidden="true" />;
    }
}
