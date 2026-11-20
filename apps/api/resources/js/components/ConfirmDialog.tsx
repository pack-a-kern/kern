import { Check, X } from 'lucide-react';
import { useState, type JSX, type ReactNode } from 'react';

import { PanelButton, PanelDialog, PanelInput } from './panel';

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
 *
 * `requireText` is for the gravest actions: the confirm button unlocks only once the person has
 * typed that exact phrase, so a wipe that cannot be undone needs a deliberate keystroke, not one
 * stray click on a focused button.
 */
export default function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    detail,
    confirmLabel,
    cancelLabel,
    requireText,
    requireTextLabel,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: string;
    detail?: ReactNode;
    confirmLabel: string;
    cancelLabel: string;
    /** When set, the confirm button stays locked until the person types this exact phrase. */
    requireText?: string;
    /** The instruction shown above that field — name the word to type. */
    requireTextLabel?: string;
    /** Resolves when the action succeeded; the dialog closes itself only then. */
    onConfirm: () => Promise<void>;
}): JSX.Element {
    const [working, setWorking] = useState(false);
    const [typed, setTyped] = useState('');

    const locked = requireText !== undefined && typed !== requireText;

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
                        <X className="size-3.5" aria-hidden="true" />
                        {cancelLabel}
                    </PanelButton>
                    <PanelButton
                        primary
                        disabled={working || locked}
                        onClick={() => {
                            void confirm();
                        }}
                    >
                        <Check className="size-3.5" aria-hidden="true" />
                        {confirmLabel}
                    </PanelButton>
                </>
            }
        >
            {detail}
            {requireText === undefined ? null : (
                <label className="mt-3 flex flex-col gap-1.5">
                    {requireTextLabel === undefined ? null : (
                        <span className="text-sm text-muted-foreground">{requireTextLabel}</span>
                    )}
                    <PanelInput
                        icon={null}
                        value={typed}
                        autoComplete="off"
                        autoCapitalize="off"
                        autoCorrect="off"
                        spellCheck={false}
                        onChange={(event) => {
                            setTyped(event.target.value);
                        }}
                    />
                </label>
            )}
        </PanelDialog>
    );
}
