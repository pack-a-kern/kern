import { useState, type JSX, type ReactNode } from 'react';

import { PanelButton, PanelDialog } from './panel';

/**
 * A confirmation before something irreversible.
 *
 * One component rather than a dialog written out at each call site, because the parts that must not
 * vary are the ones that are easy to get wrong: the destructive action is never the default focus,
 * the button says what will happen rather than "OK", and it stays disabled while the request is in
 * flight so a second click cannot delete twice.
 *
 * `detail` is where a caller says what will actually be lost — how many rows, what stops working.
 * A confirmation that only asks "are you sure?" teaches people to click through it.
 */
export default function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    detail,
    confirmLabel,
    cancelLabel,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: string;
    detail?: ReactNode;
    confirmLabel: string;
    cancelLabel: string;
    /** Resolves when the action succeeded; the dialog closes itself only then. */
    onConfirm: () => Promise<void>;
}): JSX.Element {
    const [working, setWorking] = useState(false);

    const confirm = async (): Promise<void> => {
        setWorking(true);

        try {
            await onConfirm();
            onOpenChange(false);
        } catch {
            // Left open on purpose: the caller has already reported why it failed, and closing
            // would suggest the deletion happened.
        } finally {
            setWorking(false);
        }
    };

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            title={title}
            {...(description === undefined ? {} : { description })}
            actions={
                <>
                    <PanelButton
                        onClick={() => {
                            onOpenChange(false);
                        }}
                    >
                        {cancelLabel}
                    </PanelButton>
                    <PanelButton
                        primary
                        disabled={working}
                        onClick={() => {
                            void confirm();
                        }}
                    >
                        {confirmLabel}
                    </PanelButton>
                </>
            }
        >
            {detail}
        </PanelDialog>
    );
}
