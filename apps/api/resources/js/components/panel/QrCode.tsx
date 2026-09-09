import { useEffect, useState, type JSX } from 'react';
import { toDataURL } from 'qrcode';

import { cn } from '../../lib/utils';

/**
 * Renders an `otpauth://` URI (or any string) as a scannable QR code.
 *
 * Generated in the browser from a data URI rather than fetched from an image service: the value
 * encoded here is a TOTP shared secret, and sending it to a third party to be drawn would hand
 * that party the second factor for the account.
 *
 * Deliberately drawn black-on-white in both themes, on its own white tile. A QR is read by a
 * camera, not by a person: maximum contrast is the requirement, and theme-coloured modules are
 * where scanning starts failing. (The library also takes only hex colours — passing the app's
 * `oklch()` tokens made it throw, and the component silently rendered nothing.)
 */
export function QrCode({
    value,
    size = 168,
    className,
}: {
    value: string;
    size?: number;
    className?: string;
}): JSX.Element {
    const [source, setSource] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let active = true;

        toDataURL(value, {
            width: size * 2,
            margin: 1,
            color: { dark: '#000000', light: '#ffffff' },
        }).then(
            (url) => {
                if (active) {
                    setSource(url);
                }
            },
            () => {
                if (active) {
                    setFailed(true);
                }
            },
        );

        return () => {
            active = false;
        };
    }, [value, size]);

    // A failure here is not fatal: the secret is shown as text beside this, which is enough to
    // finish enrolment. Rendering a broken image would be worse than rendering nothing.
    if (failed) {
        return <></>;
    }

    return (
        <img
            src={source ?? ''}
            width={size}
            height={size}
            alt=""
            // Decorative: the secret is also shown as selectable text beside it, which is what a
            // screen-reader user needs. Announcing an unreadable QR image helps nobody.
            aria-hidden="true"
            className={cn('tile border bg-white p-1', className)}
            style={{ width: size, height: size }}
        />
    );
}
