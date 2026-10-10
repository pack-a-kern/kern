import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useState, type JSX, type SyntheticEvent } from 'react';
import { CheckCircle2Icon, MailIcon } from 'lucide-react';
import ErrorText from '../../components/ErrorText';
import { LanguageMenu, PaletteMenu, ThemeToggle } from '../../components/ShellControls';
import { fetchEmailVerificationStatus, logout, resendEmailVerification, verifyEmail } from '../../api/authApi';
import { useTranslation } from '../../i18n';
import { useFormErrors } from '../../hooks/useFormErrors';
import { ApiError } from '../../lib/http';
import type { EmailVerificationStatus } from '../../types/auth';
import { Button } from '@/components/ui/button';
import { Field, PanelInput } from '@/components/panel';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useAppName } from '../../lib/sharedProps';

/**
 * Where an account with an unconfirmed address is held, and how it gets out.
 *
 * ## Arriving does not send anything
 *
 * The screen draws itself from `GET /v1/auth/email/verification`, which is free, and asks for a code
 * only when somebody presses the button. A screen that sent on mount would send twice to anyone who
 * refreshed it, and three times to anyone with the page open in two tabs — straight into the resend
 * limiter, which would then look like the feature being broken.
 *
 * ## Sign out is always offered
 *
 * Because the alternative is a screen with no way off it. An address that can no longer receive mail
 * is a real situation, and the person in it needs to be able to leave and come back as somebody else,
 * or ring whoever runs the installation. `RequireVerifiedEmail` keeps the logout route reachable for
 * exactly this button.
 *
 * ## `required` changes the words, not the mechanism
 *
 * When the setting is off this page is still reachable and still works — verifying is simply offered
 * rather than demanded, and "Continue" goes to the application either way.
 */
export default function VerifyEmail(): JSX.Element {
    const appName = useAppName();
    const { t } = useTranslation();
    const errors = useFormErrors();

    const [status, setStatus] = useState<EmailVerificationStatus | null>(null);
    const [code, setCode] = useState('');
    const [busy, setBusy] = useState(false);
    const [resent, setResent] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);

    useEffect(() => {
        void fetchEmailVerificationStatus()
            .then(setStatus)
            .catch(() => {
                setFailure(t((d) => d.pages.verifyEmail.genericError));
            });
    }, [t]);

    const handleResend = useCallback(async (): Promise<void> => {
        setBusy(true);
        setFailure(null);
        setResent(false);

        try {
            await resendEmailVerification();
            setResent(true);
        } catch (cause) {
            setFailure(
                cause instanceof ApiError && cause.status === 429
                    ? t((d) => d.pages.verifyEmail.rateLimited)
                    : t((d) => d.pages.verifyEmail.genericError),
            );
        } finally {
            setBusy(false);
        }
    }, [t]);

    const handleSubmit = useCallback(
        async (event: SyntheticEvent): Promise<void> => {
            event.preventDefault();
            setBusy(true);
            setFailure(null);
            setResent(false);

            try {
                await verifyEmail(code.trim());
                setStatus((current) => (current === null ? current : { ...current, verified: true }));
            } catch (cause) {
                // A wrong code comes back as a 422 naming `code`, so it lands under the field the
                // person typed into rather than as a banner they have to connect to it themselves.
                if (!errors.capture(cause)) {
                    setFailure(
                        cause instanceof ApiError && cause.status === 429
                            ? t((d) => d.pages.verifyEmail.rateLimited)
                            : t((d) => d.pages.verifyEmail.genericError),
                    );
                }
            } finally {
                setBusy(false);
            }
        },
        [code, errors, t],
    );

    const verified = status?.verified === true;

    return (
        <>
            <Head title={t((d) => d.pages.verifyEmail.title)} />

            <div className="bg-background min-h-dvh">
                <main className="bg-card flex min-h-dvh flex-col">
                    <header className="flex h-16 items-center justify-between gap-3 px-5 sm:px-8">
                        <span className="text-sm font-semibold tracking-tight">{appName}</span>
                        <div className="flex items-center gap-1.5">
                            <LanguageMenu />
                            <PaletteMenu />
                            <ThemeToggle />
                        </div>
                    </header>

                    <div className="flex flex-1 items-center justify-center px-5 py-8 sm:px-8">
                        <section className="w-full max-w-[420px]">
                            <div className="mb-7">
                                <span className="head-chip mb-3 inline-flex items-center gap-1.5">
                                    {verified ? (
                                        <CheckCircle2Icon className="size-3.5" aria-hidden="true" />
                                    ) : (
                                        <MailIcon className="size-3.5" aria-hidden="true" />
                                    )}
                                    {t((d) => d.pages.verifyEmail.codeLabel)}
                                </span>
                                <h1 className="text-[26px] font-semibold tracking-[-0.045em]">
                                    {verified
                                        ? t((d) => d.pages.verifyEmail.verifiedTitle)
                                        : t((d) => d.pages.verifyEmail.title)}
                                </h1>
                                <p className="text-muted-foreground mt-1.5 text-xs leading-5">
                                    {verified
                                        ? t((d) => d.pages.verifyEmail.verifiedBody)
                                        : t((d) => d.pages.verifyEmail.subtitle)}
                                </p>
                                {!verified && status?.destination !== null && status !== null ? (
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        {t((d) => d.pages.verifyEmail.sentTo).replace(
                                            ':destination',
                                            status.destination,
                                        )}
                                    </p>
                                ) : null}
                            </div>

                            {verified ? (
                                <Button
                                    className="w-full"
                                    onClick={() => {
                                        window.location.assign('/dashboard');
                                    }}
                                >
                                    {t((d) => d.pages.verifyEmail.continueToApp)}
                                </Button>
                            ) : (
                                <div className="space-y-4">
                                    {status !== null ? (
                                        <Alert>
                                            <AlertDescription>
                                                {status.required
                                                    ? t((d) => d.pages.verifyEmail.requiredNotice)
                                                    : t((d) => d.pages.verifyEmail.optionalNotice)}
                                            </AlertDescription>
                                        </Alert>
                                    ) : null}

                                    {status !== null && !status.deliverable ? (
                                        <ErrorText message={t((d) => d.pages.verifyEmail.undeliverable)} />
                                    ) : (
                                        <form onSubmit={(event) => void handleSubmit(event)} className="space-y-4">
                                            <Field
                                                label={t((d) => d.pages.verifyEmail.codeLabel)}
                                                error={errors.get('code')}
                                                htmlFor="code"
                                            >
                                                <PanelInput
                                                    id="code"
                                                    // `one-time-code` is what lets a phone offer the code
                                                    // straight from the message it arrived in.
                                                    autoComplete="one-time-code"
                                                    inputMode="numeric"
                                                    required
                                                    value={code}
                                                    placeholder={t((d) => d.pages.verifyEmail.codePlaceholder)}
                                                    onChange={(event) => {
                                                        setCode(event.target.value);
                                                        errors.clear('code');
                                                    }}
                                                />
                                            </Field>

                                            <Button type="submit" className="w-full" disabled={busy || code.trim() === ''}>
                                                {t((d) => d.pages.verifyEmail.submit)}
                                            </Button>

                                            <Button
                                                type="button"
                                                variant="ghost"
                                                className="w-full"
                                                disabled={busy}
                                                onClick={() => void handleResend()}
                                            >
                                                {t((d) => d.pages.verifyEmail.resend)}
                                            </Button>
                                        </form>
                                    )}

                                    {resent ? (
                                        <Alert>
                                            <AlertDescription>
                                                {t((d) => d.pages.verifyEmail.resent)}
                                            </AlertDescription>
                                        </Alert>
                                    ) : null}

                                    <ErrorText message={failure} />

                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="w-full"
                                        onClick={() => {
                                            void logout().finally(() => {
                                                window.location.assign('/login');
                                            });
                                        }}
                                    >
                                        {t((d) => d.pages.verifyEmail.signOut)}
                                    </Button>
                                </div>
                            )}
                        </section>
                    </div>
                </main>
            </div>
        </>
    );
}
