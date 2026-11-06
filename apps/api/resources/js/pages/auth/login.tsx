import { Head, usePage } from '@inertiajs/react';
import { useRef, useState, type ClipboardEvent, type JSX, type KeyboardEvent, type SyntheticEvent } from 'react';
import { startAuthentication } from '@simplewebauthn/browser';
import {
    ArrowLeft,
    AtSignIcon,
    Eye,
    EyeOff,
    Fingerprint,
    Info,
    KeyRound,
    LockIcon,
    LogIn,
    ScrollText,
    ShieldCheck,
    Smartphone,
    UserRoundPlus,
} from 'lucide-react';
import AppMark from '../../components/AppMark';
import AuthBackdrop from '../../components/AuthBackdrop';
import ErrorText from '../../components/ErrorText';
import { ThemeToggle } from '../../components/ShellControls';
import { useNoFocusOnLoad } from '../../hooks/useNoFocusOnLoad';
import {
    completeMfaChallenge,
    completePasskeyLogin,
    login,
    register,
    requestPasskeyLoginOptions,
} from '../../api/authApi';
import { useTranslation } from '../../i18n';
import type { Dictionary } from '../../i18n/dictionary';
import { useFormErrors } from '../../hooks/useFormErrors';
import { ApiError } from '../../lib/http';
import { Button } from '@/components/ui/button';
import { Field, PanelInput } from '@/components/panel';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useAppName } from '../../lib/sharedProps';

/**
 * The one unauthenticated screen that does something: password, then the second factor.
 *
 * ## Why the second factor is two screens and one endpoint
 *
 * `CompleteMfaChallenge` takes a single `code` and tries it as a TOTP code first, then as a
 * recovery code. The server therefore needs no mode flag — but a person does. A six-box grid asks
 * for one thing and a 24-character field asks for another, and showing both at once would leave
 * someone deciding which box their code goes in. So the step has two faces over one request.
 *
 * ## Recovery codes are uppercase on the wire
 *
 * They are hashed exactly as issued (`Hash::check`, no normalising), and issued as uppercase hex in
 * four groups of five. The field therefore uppercases the *value*, not just its appearance — a CSS
 * `text-transform` would show the right thing and submit the wrong one.
 */

type Mode = 'password' | 'mfa' | 'recovery' | 'register';

/** How many boxes the authenticator grid has. TOTP is six digits everywhere this app talks to. */
const OTP_LENGTH = 6;

function goToDashboard(): void {
    // Full navigation, not an Inertia visit — the dashboard page is behind `auth` middleware and
    // this guarantees the freshly-authenticated session cookie is what the next document load
    // uses (resources/js/lib/csrf.ts's docblock explains why auth transitions avoid client-side
    // routing entirely).
    window.location.assign('/dashboard');
}

export default function Login(): JSX.Element {
    const appName = useAppName();
    const { t } = useTranslation();
    useNoFocusOnLoad();
    // Shared by `HandleInertiaRequests`. Absent only if this page were rendered outside Inertia,
    // and "closed" is the safer reading of an unknown — the endpoint decides either way.
    const registrationOpen = usePage().props.registrationOpen === true;
    const [mode, setMode] = useState<Mode>('password');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [passwordVisible, setPasswordVisible] = useState(false);
    const [digits, setDigits] = useState<string[]>(Array.from({ length: OTP_LENGTH }, () => ''));
    const [recoveryCode, setRecoveryCode] = useState('');
    const [error, setError] = useState<string | null>(null);
    const errors = useFormErrors();
    const [info, setInfo] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    // How the last passkey attempt ended, shown under its button: the person closed the browser's
    // window (or let it time out), or the server did not know the passkey. Anything else is `error`.
    const [passkeyOutcome, setPasskeyOutcome] = useState<'cancelled' | 'refused' | null>(null);

    function messageFor(cause: unknown, fallback: string): string {
        if (cause instanceof ApiError) {
            const firstFieldError = cause.errors ? Object.values(cause.errors)[0]?.[0] : undefined;

            if (cause.isRateLimited) {
                return t((d) => d.auth.rateLimited);
            }

            // A named refusal, in the reader's language — `ACCOUNT_SUSPENDED` is the one sign-in can meet.
            const named = cause.reason === undefined ? undefined : t((d) => d.errors.reasons)[cause.reason];

            return firstFieldError ?? named ?? cause.message;
        }

        return fallback;
    }

    /** Runs a request that signs the person in, funnelling every failure to one place. */
    async function attempt(work: () => Promise<void>, fallback: (d: Dictionary) => string): Promise<void> {
        setError(null);
        errors.reset();
        setPasskeyOutcome(null);
        setBusy(true);

        try {
            await work();
        } catch (cause) {
            // A 422 names the field it rejected; the banner is for everything else.
            if (errors.capture(cause)) {
                return;
            }

            setError(messageFor(cause, t(fallback)));
        } finally {
            setBusy(false);
        }
    }

    async function handlePasswordSubmit(event: SyntheticEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();

        await attempt(
            async () => {
                const result = await login(email, password);

                if (result.mfaRequired) {
                    setMode('mfa');
                } else {
                    goToDashboard();
                }
            },
            (d) => d.auth.genericSignInError,
        );
    }

    async function handleRegisterSubmit(event: SyntheticEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();

        await attempt(
            async () => {
                await register(email, password);
                setMode('password');
                setPassword('');
                setInfo(t((d) => d.auth.registrationAnswered));
            },
            (d) => d.auth.genericRegisterError,
        );
    }

    async function submitCode(code: string): Promise<void> {
        await attempt(
            async () => {
                await completeMfaChallenge(code);
                goToDashboard();
            },
            (d) => d.auth.genericMfaError,
        );
    }

    async function handlePasskeyLogin(): Promise<void> {
        setError(null);
        errors.reset();
        setPasskeyOutcome(null);
        setBusy(true);

        try {
            const optionsJson = (await requestPasskeyLoginOptions()) as unknown as Parameters<
                typeof startAuthentication
            >[0]['optionsJSON'];
            const assertion = await startAuthentication({ optionsJSON: optionsJson });
            await completePasskeyLogin(assertion as unknown as Record<string, unknown>);
            goToDashboard();
        } catch (cause) {
            // The browser's own names for "closed the window" and "timed out" — SimpleWebAuthn keeps them.
            if (cause instanceof Error && (cause.name === 'NotAllowedError' || cause.name === 'AbortError')) {
                setPasskeyOutcome('cancelled');
            } else if (cause instanceof ApiError && cause.errors?.credential !== undefined) {
                // The server's one answer for a passkey it will not accept — it says no more, on purpose.
                setPasskeyOutcome('refused');
            } else {
                setError(
                    messageFor(
                        cause,
                        t((d) => d.auth.genericPasskeyError),
                    ),
                );
            }
        } finally {
            setBusy(false);
        }
    }

    const heading: Record<Mode, { eyebrow: string; title: string; subtitle: string }> = {
        password: {
            eyebrow: t((d) => d.auth.eyebrow),
            title: t((d) => d.auth.welcomeTitle),
            subtitle: t((d) => d.auth.welcomeSubtitle),
        },
        mfa: {
            eyebrow: t((d) => d.auth.mfaEyebrow),
            title: t((d) => d.auth.mfaTitle),
            subtitle: t((d) => d.auth.mfaSubtitle),
        },
        recovery: {
            eyebrow: t((d) => d.auth.recoveryEyebrow),
            title: t((d) => d.auth.recoveryTitle),
            subtitle: t((d) => d.auth.recoverySubtitle),
        },
        register: {
            eyebrow: t((d) => d.auth.registerEyebrow),
            title: t((d) => d.auth.registerTitle),
            subtitle: t((d) => d.auth.registerSubtitle),
        },
    };

    function leaveSecondStep(): void {
        setMode('password');
        setDigits(Array.from({ length: OTP_LENGTH }, () => ''));
        setRecoveryCode('');
        setError(null);
        errors.reset();
    }

    return (
        <>
            <Head title={t((d) => d.auth.signInTitle)} />

            <div className="auth-page bg-background min-h-dvh lg:grid lg:grid-cols-[minmax(420px,0.92fr)_minmax(520px,1.08fr)]">
                <main className="bg-background lg:bg-card safe-area relative isolate flex min-h-dvh flex-col overflow-hidden">
                    <AuthBackdrop compact />
                    <header className="relative flex h-16 items-center justify-between gap-3 px-5 sm:px-8">
                        <div className="flex items-center gap-2.5">
                            <AppMark className="size-9 rounded-[11px]" />
                            <span>
                                <span className="block text-sm font-semibold tracking-tight">{appName}</span>
                                <span className="text-muted-foreground block text-2xs">
                                    {t((d) => d.shell.appTagline)}
                                </span>
                            </span>
                        </div>

                        <div className="flex items-center gap-1.5">
                            <ThemeToggle />
                        </div>
                    </header>

                    {/* The form in the middle of what is left, as an app's sign-in sits — not hung from
                        the top with the rest of the screen empty under it. */}
                    <div className="relative flex flex-1 items-center justify-center px-4 py-6 sm:px-8 lg:py-8">
                        <section className="w-full max-w-[420px]">
                            <div className="mb-6 px-1 lg:mb-7 lg:px-0">
                                <span className="head-chip mb-3 inline-flex items-center gap-1.5">
                                    <span className="bg-accent-500 size-1.5 rounded-full" aria-hidden="true" />
                                    {heading[mode].eyebrow}
                                </span>
                                <h1 className="text-[30px] leading-tight font-semibold tracking-[-0.045em] lg:text-[26px]">
                                    {heading[mode].title}
                                </h1>
                                <p className="text-muted-foreground mt-1.5 text-sm leading-6 lg:text-xs lg:leading-5">
                                    {heading[mode].subtitle}
                                </p>
                            </div>

                            <div className="auth-card">
                                <ErrorText message={error} className="mb-4" />
                                {info !== null && (
                                    <Alert className="border-success/30 bg-success/10 text-success mb-4">
                                        <AlertDescription className="text-success">{info}</AlertDescription>
                                    </Alert>
                                )}

                                {(mode === 'password' || mode === 'register') && (
                                    <form
                                        onSubmit={(event) =>
                                            void (mode === 'register'
                                                ? handleRegisterSubmit(event)
                                                : handlePasswordSubmit(event))
                                        }
                                        className="space-y-4"
                                    >
                                        <Field
                                            label={t((d) => d.auth.emailLabel)}
                                            error={errors.get('email')}
                                            htmlFor="email"
                                        >
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
                                                type={passwordVisible ? 'text' : 'password'}
                                                autoComplete={mode === 'register' ? 'new-password' : 'current-password'}
                                                required
                                                minLength={mode === 'register' ? 12 : undefined}
                                                value={password}
                                                onChange={(event) => {
                                                    setPassword(event.target.value);
                                                    errors.clear('password');
                                                }}
                                                actionEnd={
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={t((d) =>
                                                            passwordVisible ? d.auth.hidePassword : d.auth.showPassword,
                                                        )}
                                                        aria-pressed={passwordVisible}
                                                        onClick={() => {
                                                            setPasswordVisible(!passwordVisible);
                                                        }}
                                                    >
                                                        {passwordVisible ? (
                                                            <EyeOff className="size-3.5" />
                                                        ) : (
                                                            <Eye className="size-3.5" />
                                                        )}
                                                    </Button>
                                                }
                                            />
                                        </Field>

                                        <Button type="submit" disabled={busy} className="h-11 w-full lg:h-10">
                                            {mode === 'register' ? (
                                                <UserRoundPlus className="size-4" aria-hidden="true" />
                                            ) : (
                                                <LogIn className="size-4" aria-hidden="true" />
                                            )}
                                            {mode === 'register'
                                                ? t((d) => d.auth.submitRegister)
                                                : t((d) => d.auth.submitSignIn)}
                                        </Button>

                                        {mode === 'password' && (
                                            <>
                                                <div className="flex items-center gap-3 py-1">
                                                    <span className="bg-border h-px flex-1" />
                                                    <span className="text-muted-foreground text-2xs">
                                                        {t((d) => d.auth.orSeparator)}
                                                    </span>
                                                    <span className="bg-border h-px flex-1" />
                                                </div>

                                                <Button
                                                    type="button"
                                                    variant="card"
                                                    disabled={busy}
                                                    onClick={() => void handlePasskeyLogin()}
                                                    className="h-11 w-full lg:h-10"
                                                >
                                                    <KeyRound className="size-4" aria-hidden="true" />
                                                    {t((d) => d.auth.passkeySignIn)}
                                                </Button>

                                                {passkeyOutcome === 'cancelled' && (
                                                    <p
                                                        role="status"
                                                        className="text-muted-foreground flex items-start gap-2 text-xs leading-5"
                                                    >
                                                        <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                                                        {t((d) => d.auth.passkeyCancelled)}
                                                    </p>
                                                )}

                                                {passkeyOutcome === 'refused' && (
                                                    <Alert variant="destructive">
                                                        <KeyRound aria-hidden="true" />
                                                        <AlertTitle>
                                                            {t((d) => d.auth.passkeyNotRecognisedTitle)}
                                                        </AlertTitle>
                                                        <AlertDescription>
                                                            {t((d) => d.auth.passkeyNotRecognised)}
                                                        </AlertDescription>
                                                    </Alert>
                                                )}
                                            </>
                                        )}
                                    </form>
                                )}

                                {(mode === 'mfa' || mode === 'recovery') && (
                                    <>
                                        <div className="bg-secondary border-border mb-5 flex items-start gap-3 rounded-2xl border p-3">
                                            <span className="bg-accent-50 text-accent-700 grid size-9 shrink-0 place-items-center rounded-xl">
                                                <ShieldCheck className="size-4" aria-hidden="true" />
                                            </span>
                                            <span>
                                                <span className="block text-xs font-semibold">
                                                    {t((d) => d.auth.mfaPanelTitle)}
                                                </span>
                                                <span className="text-muted-foreground mt-0.5 block text-2xs leading-4">
                                                    {t((d) =>
                                                        mode === 'mfa' ? d.auth.mfaPanelBody : d.auth.recoveryCodeHint,
                                                    )}
                                                </span>
                                            </span>
                                        </div>

                                        {mode === 'mfa' ? (
                                            <form
                                                onSubmit={(event) => {
                                                    event.preventDefault();
                                                    void submitCode(digits.join(''));
                                                }}
                                                className="space-y-4"
                                            >
                                                <OtpGrid
                                                    digits={digits}
                                                    onChange={(next) => {
                                                        setDigits(next);
                                                        errors.clear('code');
                                                    }}
                                                    onComplete={(code) => void submitCode(code)}
                                                    label={t((d) => d.auth.mfaCodeLabel)}
                                                />
                                                {errors.get('code') !== undefined && (
                                                    <ErrorText message={errors.get('code') ?? null} />
                                                )}

                                                <Button
                                                    type="submit"
                                                    disabled={busy || digits.join('').length < OTP_LENGTH}
                                                    className="h-11 w-full lg:h-10"
                                                >
                                                    <ShieldCheck className="size-4" aria-hidden="true" />
                                                    {t((d) => d.auth.mfaVerify)}
                                                </Button>

                                                <div className="flex items-center justify-between text-2xs">
                                                    <button
                                                        type="button"
                                                        className="text-accent-700 inline-flex items-center gap-1 font-semibold"
                                                        onClick={() => {
                                                            setMode('recovery');
                                                            setError(null);
                                                            errors.reset();
                                                        }}
                                                    >
                                                        <KeyRound className="size-3" aria-hidden="true" />
                                                        {t((d) => d.auth.useRecoveryCode)}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="text-muted-foreground inline-flex items-center gap-1 font-semibold"
                                                        onClick={leaveSecondStep}
                                                    >
                                                        <ArrowLeft className="size-3" aria-hidden="true" />
                                                        {t((d) => d.auth.back)}
                                                    </button>
                                                </div>
                                            </form>
                                        ) : (
                                            <form
                                                onSubmit={(event) => {
                                                    event.preventDefault();
                                                    void submitCode(recoveryCode);
                                                }}
                                                className="space-y-4"
                                            >
                                                <Field
                                                    label={t((d) => d.auth.recoveryCodeLabel)}
                                                    error={errors.get('code')}
                                                    htmlFor="recovery-code"
                                                >
                                                    <PanelInput
                                                        id="recovery-code"
                                                        icon={<KeyRound className="size-3.5" />}
                                                        className="recovery-field"
                                                        autoComplete="one-time-code"
                                                        value={recoveryCode}
                                                        placeholder={t((d) => d.auth.recoveryCodePlaceholder)}
                                                        onChange={(event) => {
                                                            // The value itself, not its appearance: codes are
                                                            // hashed exactly as issued.
                                                            setRecoveryCode(event.target.value.toUpperCase());
                                                            errors.clear('code');
                                                        }}
                                                    />
                                                </Field>

                                                <Button
                                                    type="submit"
                                                    disabled={busy || recoveryCode.trim() === ''}
                                                    className="h-11 w-full lg:h-10"
                                                >
                                                    <ShieldCheck className="size-4" aria-hidden="true" />
                                                    {t((d) => d.auth.recoveryVerify)}
                                                </Button>

                                                <Button
                                                    type="button"
                                                    variant="card"
                                                    className="h-9 w-full"
                                                    onClick={() => {
                                                        setMode('mfa');
                                                        setError(null);
                                                        errors.reset();
                                                    }}
                                                >
                                                    <Smartphone className="size-4" aria-hidden="true" />
                                                    {t((d) => d.auth.useAuthenticatorCode)}
                                                </Button>
                                            </form>
                                        )}
                                    </>
                                )}

                                {mode === 'password' && registrationOpen && (
                                    <Button
                                        type="button"
                                        variant="link"
                                        className="mt-4 w-full"
                                        onClick={() => {
                                            setMode('register');
                                            setError(null);
                                            setInfo(null);
                                            setPasskeyOutcome(null);
                                        }}
                                    >
                                        {t((d) => d.auth.noAccount)}
                                    </Button>
                                )}

                                {mode === 'register' && (
                                    <Button
                                        type="button"
                                        variant="link"
                                        className="mt-4 w-full"
                                        onClick={() => {
                                            setMode('password');
                                            setError(null);
                                        }}
                                    >
                                        {t((d) => d.auth.haveAccount)}
                                    </Button>
                                )}
                            </div>
                        </section>
                    </div>

                    {/* The terms at the foot of the screen, apart from the form: small print, not a
                        step of signing in. */}
                    <p className="text-muted-foreground relative mx-auto max-w-[420px] px-6 pb-4 text-center text-2xs leading-4">
                        {t((d) => d.auth.legalNote).replace('{appName}', appName)}
                    </p>

                    <footer className="border-border text-muted-foreground hidden min-h-12 items-center justify-between gap-3 border-t px-5 text-2xs sm:px-8 lg:flex">
                        <span>{t((d) => d.auth.footerCopyright).replace('{appName}', appName)}</span>
                    </footer>
                </main>

                <AuthAside />
            </div>
        </>
    );
}

/**
 * Six boxes rather than one field.
 *
 * A code arrives as six separate characters — read off a phone one at a time — and the grid keeps
 * that shape, moving focus as they land. Paste still works, and a pasted code fills every box at
 * once, because people paste from password managers far more often than they type.
 */
function OtpGrid(props: {
    digits: string[];
    onChange: (digits: string[]) => void;
    onComplete: (code: string) => void;
    label: string;
}): JSX.Element {
    const refs = useRef<(HTMLInputElement | null)[]>([]);

    function write(index: number, value: string): void {
        const next = [...props.digits];
        next[index] = value;
        props.onChange(next);

        if (value !== '' && index < OTP_LENGTH - 1) {
            refs.current[index + 1]?.focus();
        }

        const code = next.join('');

        if (code.length === OTP_LENGTH) {
            props.onComplete(code);
        }
    }

    function onKeyDown(index: number, event: KeyboardEvent<HTMLInputElement>): void {
        // Backspace in an empty box steps back rather than doing nothing — otherwise correcting a
        // typo means reaching for the mouse.
        if (event.key === 'Backspace' && props.digits[index] === '' && index > 0) {
            event.preventDefault();
            refs.current[index - 1]?.focus();
        }

        if (event.key === 'ArrowLeft' && index > 0) {
            event.preventDefault();
            refs.current[index - 1]?.focus();
        }

        if (event.key === 'ArrowRight' && index < OTP_LENGTH - 1) {
            event.preventDefault();
            refs.current[index + 1]?.focus();
        }
    }

    function onPaste(event: ClipboardEvent<HTMLInputElement>): void {
        const pasted = event.clipboardData.getData('text').replace(/\D/g, '').slice(0, OTP_LENGTH);

        if (pasted === '') {
            return;
        }

        event.preventDefault();
        const next = Array.from({ length: OTP_LENGTH }, (_, index) => pasted[index] ?? '');
        props.onChange(next);
        refs.current[Math.min(pasted.length, OTP_LENGTH - 1)]?.focus();

        if (pasted.length === OTP_LENGTH) {
            props.onComplete(pasted);
        }
    }

    return (
        <div className="grid grid-cols-6 gap-2" role="group" aria-label={props.label}>
            {props.digits.map((digit, index) => (
                <input
                    key={index}
                    ref={(node) => {
                        refs.current[index] = node;
                    }}
                    className="otp-box"
                    inputMode="numeric"
                    autoComplete={index === 0 ? 'one-time-code' : 'off'}
                    maxLength={1}
                    aria-label={`${props.label} ${String(index + 1)}`}
                    value={digit}
                    onChange={(event) => {
                        write(index, event.target.value.replace(/\D/g, '').slice(-1));
                    }}
                    onKeyDown={(event) => {
                        onKeyDown(index, event);
                    }}
                    onPaste={onPaste}
                />
            ))}
        </div>
    );
}

/**
 * The panel beside the form.
 *
 * It says what protects the account, because that is the question a sign-in screen raises. Hidden
 * below `lg`, where the form is the whole screen and anything else is in its way.
 */
function AuthAside(): JSX.Element {
    const { t } = useTranslation();

    const cards = [
        { icon: KeyRound, title: t((d) => d.auth.asideStrongTitle), body: t((d) => d.auth.asideStrongBody) },
        { icon: Fingerprint, title: t((d) => d.auth.asideDeviceTitle), body: t((d) => d.auth.asideDeviceBody) },
        { icon: ScrollText, title: t((d) => d.auth.asideAuditTitle), body: t((d) => d.auth.asideAuditBody) },
    ];

    return (
        <aside className="border-border bg-background relative hidden overflow-hidden border-l lg:block">
            <AuthBackdrop />

            <div className="relative flex h-full flex-col justify-between p-10 xl:p-14">
                <div className="max-w-[540px]">
                    <span className="head-chip inline-flex items-center gap-1.5">
                        <ShieldCheck className="text-accent-700 size-3.5" aria-hidden="true" />
                        {t((d) => d.auth.asideBadge)}
                    </span>
                    <h2 className="mt-5 max-w-[520px] text-[34px] leading-[1.08] font-semibold tracking-[-0.05em] xl:text-[40px]">
                        {t((d) => d.auth.asideTitle)}
                    </h2>
                    <p className="text-muted-foreground mt-4 max-w-[480px] text-sm leading-6">
                        {t((d) => d.auth.asideBody)}
                    </p>
                </div>

                <div className="border-border bg-card/75 max-w-[560px] rounded-[22px] border p-4 backdrop-blur">
                    <div className="grid gap-3 sm:grid-cols-3">
                        {cards.map((card) => (
                            <div key={card.title} className="bg-card rounded-2xl p-3">
                                <span className="bg-accent-50 text-accent-700 grid size-8 place-items-center rounded-xl">
                                    <card.icon className="size-4" aria-hidden="true" />
                                </span>
                                <span className="mt-3 block text-xs font-semibold">{card.title}</span>
                                <span className="text-muted-foreground mt-1 block text-2xs leading-4">{card.body}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </aside>
    );
}
