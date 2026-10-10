import { useCallback, useEffect, useRef, useState, type JSX, type ReactNode } from 'react';
import { KeyRound, LockKeyhole } from 'lucide-react';

import { Field, PanelButton, PanelDialog, PanelInput } from './panel';
import { useTranslation } from '../i18n';
import { describeApiFailure } from '../lib/apiErrors';
import { apiRequest, setStepUpPrompt, type StepUpFactor } from '../lib/http';
import { ApiError } from '../lib/http';

/**
 * Asks for an authenticator code when the server says a sensitive act needs one, then lets the act
 * continue.
 *
 * ## Why this is one component at the root and not a dialog per screen
 *
 * Every route behind `verify-recent` answers `403 VERIFICATION_REQUIRED`, and the screens that call
 * them do not know in advance which will. Handling it per screen would mean the same dialog written
 * several times, drifting, and each one would have to remember to re-issue the request it interrupted.
 * `withStepUp()` in `lib/http.ts` already does the re-issuing; this supplies the one thing a plain
 * module cannot, which is something on screen to answer.
 *
 * ## The promise is the whole mechanism
 *
 * `setStepUpPrompt` is handed a function that resolves `true` once a code has been accepted, and
 * `false` if the person closes the dialog. The interceptor is waiting on it, so resolving is what lets
 * the original request go a second time — there is no event, no store, and nothing polls.
 */
export default function StepUpGate({ children }: { children: ReactNode }): JSX.Element {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    /** Which secret this prompt is for, as the server's refusal named it. */
    const [factor, setFactor] = useState<StepUpFactor>('totp');
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [working, setWorking] = useState(false);

    const askingForPassword = factor === 'password';
    /* The field the server answers against, so a rejection lands under the box it belongs to. */
    const field = askingForPassword ? 'password' : 'code';

    /**
     * The waiting interceptor's `resolve`, held across renders.
     *
     * A ref rather than state: resolving must not depend on a re-render having happened, and the
     * value is never read during one.
     */
    const resolver = useRef<((verified: boolean) => void) | null>(null);

    const settle = useCallback((verified: boolean): void => {
        const resolve = resolver.current;
        resolver.current = null;
        setOpen(false);
        setCode('');
        setError(null);
        setWorking(false);
        resolve?.(verified);
    }, []);

    useEffect(() => {
        setStepUpPrompt(
            (next) =>
                new Promise<boolean>((resolve) => {
                    // A second request arriving while the dialog is already open: the one already
                    // waiting is told no rather than silently dropped, so its caller reports a failure
                    // instead of hanging for ever on a promise nobody will resolve.
                    resolver.current?.(false);
                    resolver.current = resolve;
                    setFactor(next);
                    setCode('');
                    setError(null);
                    setOpen(true);
                }),
        );

        return () => {
            setStepUpPrompt(null);
        };
    }, []);

    const submit = async (): Promise<void> => {
        setWorking(true);
        setError(null);

        try {
            await apiRequest('/auth/step-up', { method: 'POST', body: { [field]: code } });
            settle(true);
        } catch (cause: unknown) {
            /*
             * A wrong answer comes back 422 against the field it was sent as, and the dialog stays
             * open and says so in the reader's language — the server's own message is English prose
             * and belongs in a log, not under a Romanian input (`BLUEPRINT.md` §19.1).
             *
             * Only an error on the field this dialog asked about means "that was not right".
             * Anything else — a spent budget's 429, an account with nothing to confirm with — is
             * described by the shared translator, which already knows those.
             */
            const wrongAnswer =
                cause instanceof ApiError && cause.status === 422 && cause.errors?.[field] !== undefined;

            setError(wrongAnswer ? t((d) => d.stepUp.incorrect) : describeApiFailure(cause, t));
            setWorking(false);
        }
    };

    return (
        <>
            {children}

            <PanelDialog
                open={open}
                onOpenChange={(next) => {
                    if (!next) {
                        settle(false);
                    }
                }}
                title={t((d) => d.stepUp.title)}
                description={askingForPassword ? t((d) => d.stepUp.passwordHint) : t((d) => d.stepUp.hint)}
                size="sm"
                actions={
                    <>
                        <PanelButton
                            onClick={() => {
                                settle(false);
                            }}
                        >
                            {t((d) => d.stepUp.cancel)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={working || code.trim() === ''}
                            onClick={() => {
                                void submit();
                            }}
                        >
                            {t((d) => d.stepUp.confirm)}
                        </PanelButton>
                    </>
                }
            >
                <Field
                    label={askingForPassword ? t((d) => d.stepUp.password) : t((d) => d.stepUp.code)}
                    htmlFor="step-up-code"
                    error={error ?? undefined}
                >
                    {/* One input for both, keyed on the factor so switching between them starts a
                        fresh field rather than carrying a half-typed code into a password box — and
                        so the browser's password manager is offered a box it recognises as new. */}
                    <PanelInput
                        key={factor}
                        id="step-up-code"
                        value={code}
                        type={askingForPassword ? 'password' : 'text'}
                        autoComplete={askingForPassword ? 'current-password' : 'one-time-code'}
                        inputMode={askingForPassword ? 'text' : 'numeric'}
                        icon={
                            askingForPassword ? (
                                <LockKeyhole className="size-3.5" aria-hidden="true" />
                            ) : (
                                <KeyRound className="size-3.5" aria-hidden="true" />
                            )
                        }
                        onChange={(event) => {
                            setCode(event.target.value);
                        }}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter' && code.trim() !== '' && !working) {
                                void submit();
                            }
                        }}
                    />
                </Field>
            </PanelDialog>
        </>
    );
}
