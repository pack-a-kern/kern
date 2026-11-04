import type { JSX } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';

/**
 * The single error-presentation pattern every screen reuses (`docs/handoff/040-ui-foundation.md`
 * "Conventions"). `Alert` already sets `role="alert"` itself, so a screen reader announces this the
 * moment it mounts — unchanged behaviour from the pre-conversion `<p role="alert">`.
 */
export default function ErrorText({
    message,
    className,
}: {
    message: string | null;
    /** Spacing where the screen places it, as `Alert` takes it; the look itself stays this one. */
    className?: string;
}): JSX.Element | null {
    if (!message) {
        return null;
    }

    return (
        <Alert variant="destructive" className={className}>
            <AlertDescription>{message}</AlertDescription>
        </Alert>
    );
}
