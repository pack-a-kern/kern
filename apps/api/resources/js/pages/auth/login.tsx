import { Head } from '@inertiajs/react';
import { useState, type JSX, type SyntheticEvent } from 'react';
import { startAuthentication } from '@simplewebauthn/browser';
import { AtSignIcon, LockIcon, ShieldCheckIcon } from 'lucide-react';
import ErrorText from '../../components/ErrorText';
import GuestLanguageToggle from '../../components/GuestLanguageToggle';
import {
    completeMfaChallenge,
    completePasskeyLogin,
    login,
    register,
    requestPasskeyLoginOptions,
} from '../../api/authApi';
import { useTranslation } from '../../i18n';
import { useFormErrors } from '../../hooks/useFormErrors';
import { ApiError } from '../../lib/http';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, PanelInput } from '@/components/panel';
import { Alert, AlertDescription } from '@/components/ui/alert';

type Mode = 'password' | 'mfa' | 'register';

function goToDashboard(): void {
    // Full navigation, not an Inertia visit — the dashboard page is behind `auth` middleware and
    // this guarantees the freshly-authenticated session cookie is what the next document load
    // uses (resources/js/lib/csrf.ts's docblock explains why auth transitions avoid client-side
    // routing entirely).
    window.location.assign('/dashboard');
}

export default function Login(): JSX.Element {
    const { t } = useTranslation();
    const [mode, setMode] = useState<Mode>('password');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | null>(null);
    const errors = useFormErrors();
    const [info, setInfo] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    function messageFor(cause: unknown, fallback: string): string {
        if (cause instanceof ApiError) {
            const firstFieldError = cause.errors ? Object.values(cause.errors)[0]?.[0] : undefined;

            if (cause.isRateLimited) {
                return t((d) => d.auth.rateLimited);
            }

            return firstFieldError ?? cause.message;
        }

        return fallback;
    }

    async function handlePasswordSubmit(event: SyntheticEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();
        setError(null);
        errors.reset();
        setBusy(true);

        try {
            const result = await login(email, password);

            if (result.mfaRequired) {
                setMode('mfa');
            } else {
                goToDashboard();
            }
        } catch (cause) {
            // A 422 names the field it rejected; the banner is for everything else.
            if (errors.capture(cause)) {
                return;
            }

            setError(
                messageFor(
                    cause,
                    t((d) => d.auth.genericSignInError),
                ),
            );
        } finally {
            setBusy(false);
        }
    }

    async function handleMfaSubmit(event: SyntheticEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();
        setError(null);
        errors.reset();
        setBusy(true);

        try {
            await completeMfaChallenge(code);
            goToDashboard();
        } catch (cause) {
            // A 422 names the field it rejected; the banner is for everything else.
            if (errors.capture(cause)) {
                return;
            }

            setError(
                messageFor(
                    cause,
                    t((d) => d.auth.genericMfaError),
                ),
            );
        } finally {
            setBusy(false);
        }
    }

    async function handleRegisterSubmit(event: SyntheticEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();
        setError(null);
        errors.reset();
        setBusy(true);

        try {
            await register(email, password);
            setInfo(t((d) => d.auth.accountCreated));
            setMode('password');
            setPassword('');
        } catch (cause) {
            // A 422 names the field it rejected; the banner is for everything else.
            if (errors.capture(cause)) {
                return;
            }

            setError(
                messageFor(
                    cause,
                    t((d) => d.auth.genericRegisterError),
                ),
            );
        } finally {
            setBusy(false);
        }
    }

    async function handlePasskeyLogin(): Promise<void> {
        if (!email) {
            setError(t((d) => d.auth.enterEmailForPasskey));

            return;
        }

        setError(null);
        setBusy(true);

        try {
            const optionsJson = (await requestPasskeyLoginOptions(email)) as unknown as Parameters<
                typeof startAuthentication
            >[0]['optionsJSON'];
            const assertion = await startAuthentication({ optionsJSON: optionsJson });
            await completePasskeyLogin(email, assertion as unknown as Record<string, unknown>);
            goToDashboard();
        } catch (cause) {
            // A 422 names the field it rejected; the banner is for everything else.
            if (errors.capture(cause)) {
                return;
            }

            setError(
                messageFor(
                    cause,
                    t((d) => d.auth.genericPasskeyError),
                ),
            );
        } finally {
            setBusy(false);
        }
    }

    return (
        <>
            <Head title={t((d) => d.auth.signInTitle)} />
            <GuestLanguageToggle />
            <main className="flex min-h-dvh items-center justify-center bg-background px-4">
                <Card className="w-full max-w-sm">
                    <CardHeader>
                        <CardTitle>{t((d) => d.auth.appName)}</CardTitle>
                        <CardDescription>
                            {mode === 'register' ? t((d) => d.auth.registerTitle) : t((d) => d.auth.signInTitle)}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <ErrorText message={error} />
                        {info && (
                            <Alert className="border-success/30 bg-success/10 text-success">
                                <AlertDescription className="text-success">{info}</AlertDescription>
                            </Alert>
                        )}

                        {mode === 'mfa' && (
                            <form onSubmit={(event) => void handleMfaSubmit(event)} className="space-y-3">
                                <Field
                                    label={t((d) => d.auth.mfaCodeLabel)}
                                    hint={t((d) => d.auth.mfaCodeHint)}
                                    error={errors.get('code')}
                                    htmlFor="mfa-code"
                                >
                                    <PanelInput
                                        id="mfa-code"
                                        icon={<ShieldCheckIcon className="size-3.5" />}
                                        type="text"
                                        inputMode="numeric"
                                        autoFocus
                                        value={code}
                                        onChange={(event) => {
                                            setCode(event.target.value);
                                            errors.clear('code');
                                        }}
                                        placeholder={t((d) => d.auth.mfaCodePlaceholder)}
                                    />
                                </Field>
                                <Button type="submit" disabled={busy} className="w-full">
                                    {t((d) => d.auth.confirm)}
                                </Button>
                                <Button
                                    type="button"
                                    variant="link"
                                    className="w-full"
                                    onClick={() => {
                                        setMode('password');
                                        setError(null);
                                    }}
                                >
                                    {t((d) => d.auth.back)}
                                </Button>
                            </form>
                        )}

                        {mode !== 'mfa' && (
                            <form
                                onSubmit={(event) =>
                                    void (mode === 'register'
                                        ? handleRegisterSubmit(event)
                                        : handlePasswordSubmit(event))
                                }
                                className="space-y-3"
                            >
                                <Field label={t((d) => d.auth.emailLabel)} error={errors.get('email')} htmlFor="email">
                                    <PanelInput
                                        id="email"
                                        icon={<AtSignIcon className="size-3.5" />}
                                        type="email"
                                        autoComplete="email"
                                        required
                                        value={email}
                                        onChange={(event) => {
                                            setEmail(event.target.value);
                                            errors.clear('email');
                                        }}
                                    />
                                </Field>
                                <Field
                                    label={t((d) => d.auth.passwordLabel)}
                                    error={errors.get('password')}
                                    htmlFor="password"
                                >
                                    <PanelInput
                                        id="password"
                                        icon={<LockIcon className="size-3.5" />}
                                        type="password"
                                        autoComplete={mode === 'register' ? 'new-password' : 'current-password'}
                                        required
                                        minLength={mode === 'register' ? 12 : undefined}
                                        value={password}
                                        onChange={(event) => {
                                            setPassword(event.target.value);
                                            errors.clear('password');
                                        }}
                                    />
                                </Field>
                                <Button type="submit" disabled={busy} className="w-full">
                                    {mode === 'register'
                                        ? t((d) => d.auth.submitRegister)
                                        : t((d) => d.auth.submitSignIn)}
                                </Button>
                            </form>
                        )}

                        {mode === 'password' && (
                            <>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => void handlePasskeyLogin()}
                                    className="w-full"
                                >
                                    {t((d) => d.auth.passkeySignIn)}
                                </Button>
                                <Button
                                    type="button"
                                    variant="link"
                                    className="w-full"
                                    onClick={() => {
                                        setMode('register');
                                        setError(null);
                                        setInfo(null);
                                    }}
                                >
                                    {t((d) => d.auth.noAccount)}
                                </Button>
                            </>
                        )}

                        {mode === 'register' && (
                            <Button
                                type="button"
                                variant="link"
                                className="w-full"
                                onClick={() => {
                                    setMode('password');
                                    setError(null);
                                }}
                            >
                                {t((d) => d.auth.haveAccount)}
                            </Button>
                        )}
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
