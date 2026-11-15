import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState, type JSX } from 'react';
import {
    ArrowRight,
    Check,
    Coins,
    Download,
    Landmark,
    Loader2,
    Lock,
    Scale,
    Sparkles,
    TriangleAlert,
} from 'lucide-react';
import { toast } from 'sonner';

import { getReadiness, importRequiredLaw, importWatchedActs } from '../../api/setupApi';
import { useTranslation } from '../../i18n';
import type { Dictionary } from '../../i18n/dictionary';
import { describeApiFailure } from '../../lib/apiErrors';
import { describeFiscalValue } from '../../lib/fiscalValue';
import { useSharedAuth } from '../../lib/sharedProps';
import { cn } from '../../lib/utils';
import type { Readiness, ReadinessNeed, ReadinessRequirement } from '../../types/setup';

type SetupCopy = Dictionary['setup'];

/** Where the dashboard is — where a bootstrapped install sends a person who lands on setup. */
const HOME = '/dashboard';
/** Where the minimum wage is approved — the one step setup hands off to an existing screen. */
const TAX_RULES = '/taxes/rules';

/** An import a requirement has in flight: the step that started it, and when, so a stuck one is let go. */
interface Run {
    kind: string;
    since: number;
}

/** The first need not yet satisfied — the one step a requirement is waiting on, or null when met. */
function nextNeed(requirement: ReadinessRequirement): ReadinessNeed | null {
    return requirement.needs.find((need) => !need.met) ?? null;
}

/**
 * The imports still worth spinning on, given a fresh readiness: each whose step is still the
 * requirement's next one (so it has not landed) and whose wait is under the server's ceiling. The
 * cadence is the server's ({@link ReadinessPoll}), never a number written in here.
 *
 * Returns the SAME object when nothing is dropped, so a poll that changes nothing leaves `working`
 * referentially equal and sets off neither a re-render nor a re-subscribed interval.
 */
function stillRunning(current: Record<string, Run>, next: Readiness): Record<string, Run> {
    const kept: Record<string, Run> = {};
    let dropped = false;

    for (const [key, run] of Object.entries(current)) {
        const requirement = next.requirements.find((entry) => entry.key === key);
        const need = requirement === undefined ? null : nextNeed(requirement);
        // Keep spinning while the run's own step is still next, or while the minimum wage is being
        // read: the watch has landed its acts but the active year's value is not yet proposed, so the
        // row waits out the reading rather than jumping to "approve" with nothing to approve.
        const progressing =
            need !== null &&
            (need.kind === run.kind || (need.kind === 'parameters' && need.parameters?.state === 'absent'));

        if (progressing && Date.now() - run.since < next.poll.ceilingMs) {
            kept[key] = run;
        } else {
            dropped = true;
        }
    }

    return dropped ? kept : current;
}

/**
 * The imports not yet past the ceiling, without a fresh readiness — the guard a poll that keeps
 * failing still answers to, so a step whose answer never comes stops spinning rather than forever.
 * Same referential-equality contract as {@link stillRunning}.
 */
function dropExpired(current: Record<string, Run>, ceilingMs: number): Record<string, Run> {
    const kept: Record<string, Run> = {};
    let dropped = false;

    for (const [key, run] of Object.entries(current)) {
        if (Date.now() - run.since < ceilingMs) {
            kept[key] = run;
        } else {
            dropped = true;
        }
    }

    return dropped ? kept : current;
}

/** The last step a requirement is made of, whose done label stands for the whole once it is met. */
function finalKind(requirement: ReadinessRequirement): string {
    return requirement.needs[requirement.needs.length - 1]?.kind ?? 'law';
}

function metLabel(copy: SetupCopy, kind: string): string {
    return kind === 'parameters'
        ? copy.need.parameters.met
        : kind === 'watch'
          ? copy.need.watch.met
          : copy.need.law.met;
}

function requirementIcon(key: string): JSX.Element {
    return key === 'tax-romania.minimum-wage' ? (
        <Coins className="size-[18px]" aria-hidden="true" />
    ) : (
        <Scale className="size-[18px]" aria-hidden="true" />
    );
}

function featureIcon(key: string): JSX.Element {
    return key === 'investments.bonds' ? (
        <Landmark className="size-[18px]" aria-hidden="true" />
    ) : (
        <Sparkles className="size-[18px]" aria-hidden="true" />
    );
}

/**
 * The install setup screen (ADR 0027). A person signed in but held by {@see RequireBootstrapped} lands
 * here until the install has the legislation its required features rest on. Each required requirement
 * is one row with the one action it is waiting on — import the law, import the decision, or approve the
 * value — so setting the install up is doing the steps in front of you, not reading a technical list.
 *
 * It stands outside the application frame (`PAGES_WITHOUT_SHELL`): while not bootstrapped the shell's
 * every destination is closed, so the page wears its own full-page layout.
 *
 * The server says what is ready and what each step is; the screen computes nothing (`docs/UI-PATTERNS.md`).
 * It maps a need's kind to the one call that resolves it, re-asks readiness while that runs, and — the
 * moment every required piece is in — steps aside for the dashboard.
 */
export default function Setup(): JSX.Element {
    const { t } = useTranslation();
    const s = t((d) => d.setup);
    const canRun = useSharedAuth()?.user.permissions.includes('operations.run') ?? false;

    const [readiness, setReadiness] = useState<Readiness | null>(null);
    const [failed, setFailed] = useState(false);
    const [leaving, setLeaving] = useState(false);
    // The requirements with an import in flight, by key, each with the step kind that started it and
    // when — so a step that never lands is let go of rather than spun on forever.
    const [working, setWorking] = useState<Record<string, Run>>({});
    // A step is in flight when this screen started it, or when the server says a watch is fetching now
    // (so a reload mid-import still spins and re-asks until the acts land).
    const serverBusy = (readiness?.requirements ?? []).some((requirement) =>
        requirement.needs.some((need) => need.watch?.running === true),
    );
    const anyWorking = Object.keys(working).length > 0 || serverBusy;
    // The one pending "open the dashboard" timer, held so it fires once and is cleared on unmount.
    const redirectTimer = useRef<number | null>(null);

    const leave = useCallback((delayMs: number) => {
        if (redirectTimer.current !== null) {
            return;
        }

        setLeaving(true);
        redirectTimer.current = window.setTimeout(() => {
            router.visit(HOME);
        }, delayMs);
    }, []);

    // Whatever is pending when the screen goes away does not then navigate a screen that is gone.
    useEffect(
        () => () => {
            if (redirectTimer.current !== null) {
                window.clearTimeout(redirectTimer.current);
            }
        },
        [],
    );

    // One answer, applied: the requirements, the imports that have now landed let go of, and — once
    // every required piece is in — the move to the dashboard, on the server's own delay.
    const absorb = useCallback(
        (next: Readiness) => {
            setReadiness(next);
            setFailed(false);
            setWorking((current) => stillRunning(current, next));

            if (next.isBootstrapped) {
                leave(next.poll.redirectMs);
            }
        },
        [leave],
    );

    // The first read of readiness. A failure here — before any import — is the one the error card is for.
    useEffect(() => {
        let active = true;

        getReadiness().then(
            (next) => {
                if (active) {
                    absorb(next);
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
    }, [absorb]);

    // Re-ask readiness while an import is in flight, so each row turns itself over when its step lands.
    // Keyed off whether anything is in flight (not the map's identity), so a poll that changes nothing
    // does not re-subscribe the interval. Each tick first lets go of anything past the ceiling, so a
    // step whose answer never comes still stops spinning; a poll that fails is left to the next tick.
    const everyMs = readiness?.poll.everyMs ?? null;
    const ceilingMs = readiness?.poll.ceilingMs ?? null;

    useEffect(() => {
        if (everyMs === null || ceilingMs === null || !anyWorking) {
            return;
        }

        const timer = window.setInterval(() => {
            setWorking((current) => dropExpired(current, ceilingMs));
            getReadiness().then(absorb, () => undefined);
        }, everyMs);

        return () => {
            window.clearInterval(timer);
        };
    }, [anyWorking, everyMs, ceilingMs, absorb]);

    const start = useCallback(
        async (requirement: ReadinessRequirement, need: ReadinessNeed) => {
            setWorking((current) => ({ ...current, [requirement.key]: { kind: need.kind, since: Date.now() } }));

            try {
                await (need.kind === 'law' ? importRequiredLaw(need.argument) : importWatchedActs(need.argument));
                toast.success(s.importStarted);
            } catch (cause) {
                setWorking((current) =>
                    Object.fromEntries(Object.entries(current).filter(([key]) => key !== requirement.key)),
                );
                toast.error(describeApiFailure(cause, t) || s.importFailed);
            }
        },
        [s.importStarted, s.importFailed, t],
    );

    const required = (readiness?.requirements ?? []).filter((requirement) => requirement.tier === 'required');
    const done = required.filter((requirement) => requirement.met).length;
    const total = required.length;
    const ready = readiness?.isBootstrapped ?? false;

    return (
        <div className="bg-background text-foreground min-h-dvh">
            <Head title={s.title} />
            <div className="mx-auto w-full max-w-[760px] px-4 pt-8 pb-16 sm:px-6">
                <header>
                    <div className="flex items-center gap-2.5">
                        <span className="bg-primary text-primary-foreground grid size-7 place-items-center rounded-lg text-sm font-bold">
                            T
                        </span>
                        <span className="font-semibold">TaxOS</span>
                    </div>
                    <h1 className="mt-3 text-2xl font-bold tracking-tight text-balance">{s.title}</h1>
                    <p className="text-muted-foreground mt-1.5 max-w-[48ch] text-sm">{s.lede}</p>
                </header>

                {failed ? (
                    <div className="border-border bg-card mt-6 flex items-center gap-3 rounded-2xl border p-5 shadow-sm">
                        <TriangleAlert className="text-destructive size-5 shrink-0" aria-hidden="true" />
                        <span className="text-sm">{s.loadError}</span>
                    </div>
                ) : readiness === null ? (
                    <div className="border-border bg-card text-muted-foreground mt-6 flex items-center gap-3 rounded-2xl border p-5 text-sm shadow-sm">
                        <Loader2
                            className="size-5 shrink-0 animate-spin motion-reduce:animate-none"
                            aria-hidden="true"
                        />
                        {s.working}
                    </div>
                ) : (
                    <>
                        <StatusCard done={done} total={total} ready={ready} working={anyWorking} />

                        {ready || leaving ? (
                            <div className="border-success/40 bg-success/10 text-success mt-3 flex items-center gap-2.5 rounded-xl border px-4 py-3 text-sm font-semibold">
                                <Check className="size-4 shrink-0" aria-hidden="true" />
                                {s.readyBanner}
                            </div>
                        ) : (
                            <div className="text-muted-foreground mt-2.5 flex items-start gap-2 text-[0.8rem]">
                                <Lock
                                    className="text-muted-foreground/70 mt-0.5 size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                <span>{s.gateNote}</span>
                            </div>
                        )}

                        <section className="mt-6">
                            <SectionHead title={s.requiredHeading} hint={s.requiredHint} />
                            <div className="border-border bg-card divide-border divide-y overflow-hidden rounded-2xl border shadow-sm">
                                {required.length === 0 ? (
                                    <p className="text-muted-foreground p-4 text-sm">{s.empty}</p>
                                ) : (
                                    required.map((requirement) => (
                                        <RequiredRow
                                            key={requirement.key}
                                            requirement={requirement}
                                            working={working[requirement.key]?.kind ?? null}
                                            canRun={canRun}
                                            onImport={(need) => {
                                                void start(requirement, need);
                                            }}
                                        />
                                    ))
                                )}
                            </div>
                            {canRun ? null : (
                                <p className="text-muted-foreground mt-2.5 text-[0.8rem]">{s.noPermission}</p>
                            )}
                        </section>

                        {ready && readiness.features.length > 0 ? (
                            <section className="mt-6">
                                <SectionHead title={s.featuresHeading} hint={s.featuresHint} />
                                <div className="border-border bg-card divide-border divide-y overflow-hidden rounded-2xl border shadow-sm">
                                    {readiness.features.map((feature) => (
                                        <div key={feature.feature} className="flex items-start gap-3.5 p-4">
                                            <span className="bg-muted text-muted-foreground grid size-8 shrink-0 place-items-center rounded-lg">
                                                {featureIcon(feature.feature)}
                                            </span>
                                            <div className="min-w-0 flex-1 text-[0.92rem] font-semibold">
                                                {s.feature[feature.feature] ?? feature.feature}
                                            </div>
                                            <div className="flex flex-col items-end gap-1 text-right">
                                                <Chip tone={feature.available ? 'ok' : 'idle'}>
                                                    {feature.available ? s.featureAvailable : s.featureNotYet}
                                                </Chip>
                                                <span className="text-muted-foreground/80 text-[0.76rem]">
                                                    {feature.available ? s.featureReady : s.featureArrives}
                                                </span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        ) : null}
                    </>
                )}
            </div>
        </div>
    );
}

/** The status card — a ring, the headline by state, a subtitle and the count. */
function StatusCard({
    done,
    total,
    ready,
    working,
}: {
    done: number;
    total: number;
    ready: boolean;
    working: boolean;
}): JSX.Element {
    const { t } = useTranslation();
    const s = t((d) => d.setup);
    const fraction = total === 0 ? 0 : done / total;
    const radius = 15.5;
    const circumference = 2 * Math.PI * radius;
    const title = ready ? s.ready : working ? s.working : s.notReady;
    const sub = ready ? s.subReady : done >= 1 ? s.subOneLeft : s.subBothMissing;

    return (
        <div className="border-border bg-card mt-6 flex flex-wrap items-center gap-4 rounded-2xl border p-5 shadow-sm">
            <svg viewBox="0 0 36 36" className="size-11 shrink-0" aria-hidden="true">
                <circle cx="18" cy="18" r={radius} fill="none" stroke="var(--muted)" strokeWidth="3.2" />
                <circle
                    cx="18"
                    cy="18"
                    r={radius}
                    fill="none"
                    stroke={ready ? 'var(--success)' : 'var(--warning)'}
                    strokeWidth="3.2"
                    strokeLinecap="round"
                    strokeDasharray={circumference}
                    strokeDashoffset={circumference * (1 - fraction)}
                    transform="rotate(-90 18 18)"
                    className="transition-[stroke-dashoffset] duration-500"
                />
            </svg>
            <div className="min-w-0 flex-1">
                <div className="text-[1.05rem] font-bold">{title}</div>
                <div className="text-muted-foreground mt-0.5 text-sm">{sub}</div>
            </div>
            <div className="text-muted-foreground text-sm font-medium tabular-nums">
                {s.count.replace('{done}', String(done)).replace('{total}', String(total))}
            </div>
        </div>
    );
}

function SectionHead({ title, hint }: { title: string; hint: string }): JSX.Element {
    return (
        <div className="mb-2.5 flex items-baseline justify-between gap-3">
            <h2 className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">{title}</h2>
            <span className="text-muted-foreground/70 text-xs">{hint}</span>
        </div>
    );
}

/** One required requirement: its name, the step it waits on, and the one action that resolves it. */
function RequiredRow({
    requirement,
    working,
    canRun,
    onImport,
}: {
    requirement: ReadinessRequirement;
    /** The step kind currently importing for this requirement, or null when idle. */
    working: string | null;
    canRun: boolean;
    onImport: (need: ReadinessNeed) => void;
}): JSX.Element {
    const { t } = useTranslation();
    const s = t((d) => d.setup);
    const named = s.req[requirement.key] ?? { name: requirement.key, desc: '' };
    const lawIdentifier = requirement.needs.find((need) => need.kind === 'law')?.argument ?? null;

    return (
        <div className="flex items-start gap-3.5 p-4">
            <span
                className={cn(
                    'grid size-8 shrink-0 place-items-center rounded-lg',
                    requirement.met ? 'bg-success/10 text-success' : 'bg-muted text-muted-foreground',
                )}
            >
                {requirementIcon(requirement.key)}
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2 text-[0.92rem] font-semibold">
                    {named.name}
                    {lawIdentifier === null ? null : (
                        <span className="border-border bg-muted/60 text-muted-foreground rounded-md border px-1.5 py-px font-mono text-[0.68rem] font-medium">
                            {lawIdentifier}
                        </span>
                    )}
                </div>
                {named.desc === '' ? null : <p className="text-muted-foreground mt-0.5 text-[0.82rem]">{named.desc}</p>}
            </div>
            <div className="flex min-w-[7rem] flex-col items-end gap-2 text-right">
                <RowAside requirement={requirement} working={working} canRun={canRun} onImport={onImport} />
            </div>
        </div>
    );
}

/** The right side of a required row: the step's state, and the action that advances it. */
function RowAside({
    requirement,
    working,
    canRun,
    onImport,
}: {
    requirement: ReadinessRequirement;
    working: string | null;
    canRun: boolean;
    onImport: (need: ReadinessNeed) => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const s = t((d) => d.setup);
    const importClass =
        'bg-primary text-primary-foreground focus-visible:ring-ring inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[0.78rem] font-semibold hover:brightness-105 focus-visible:ring-2 focus-visible:outline-none';

    if (requirement.met) {
        return <Chip tone="ok">{metLabel(s, finalKind(requirement))}</Chip>;
    }

    const next = nextNeed(requirement);

    if (working !== null) {
        // The decision's value is being read once the watch has landed its acts and the next step is
        // the approval; before that the watch itself is still searching.
        const line =
            working === 'law'
                ? s.need.law.importing
                : next?.kind === 'parameters'
                  ? s.need.parameters.reading
                  : s.need.watch.importing;

        return (
            <>
                <Chip tone="busy">{s.working}</Chip>
                <span className="text-info inline-flex items-center gap-1.5 text-[0.78rem] font-medium">
                    <Loader2 className="size-3.5 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                    {line}
                </span>
            </>
        );
    }

    if (next === null) {
        // Not met, yet nothing unmet: a race the next readiness poll settles. Show it as working.
        return <Chip tone="busy">{s.working}</Chip>;
    }

    if (next.kind === 'parameters') {
        const year = next.parameters?.year ?? null;

        // A value is proposed for the active year: name it and its figure, and link out to approve it.
        if (next.parameters?.state === 'proposed' && next.parameters.value !== null) {
            const named = s.need.parameters.proposed
                .replace('{year}', year === null ? '' : String(year))
                .replace('{value}', describeFiscalValue(next.parameters.value, t, locale));

            return (
                <>
                    <Chip tone="warn">{s.need.parameters.pending}</Chip>
                    <span className="text-foreground/80 text-[0.78rem] font-medium">{named}</span>
                    {canRun ? (
                        <Link
                            href={TAX_RULES}
                            className="text-info inline-flex items-center gap-1 text-[0.78rem] font-semibold hover:underline"
                        >
                            {s.need.parameters.approve}
                            <ArrowRight className="size-3.5" aria-hidden="true" />
                        </Link>
                    ) : null}
                    <span className="text-muted-foreground/80 text-[0.74rem]">{s.need.parameters.approveAt}</span>
                </>
            );
        }

        // Nothing proposed for the active year yet — the decision has not produced its value. Offer to
        // (re-)read it by running the requirement's watch; the step waits rather than asking to approve.
        const watchNeed = requirement.needs.find((need) => need.kind === 'watch') ?? null;

        return (
            <>
                <Chip tone="warn">{s.need.parameters.pending}</Chip>
                <span className="text-muted-foreground/80 text-[0.74rem]">
                    {s.need.parameters.waiting.replace('{year}', year === null ? '' : String(year))}
                </span>
                {canRun && watchNeed !== null ? (
                    <button
                        type="button"
                        onClick={() => {
                            onImport(watchNeed);
                        }}
                        className={importClass}
                    >
                        {s.need.watch.import}
                        <Download className="size-3.5" aria-hidden="true" />
                    </button>
                ) : null}
            </>
        );
    }

    const importLabel = next.kind === 'watch' ? s.need.watch.import : s.need.law.import;

    return (
        <>
            <Chip tone="warn">{next.kind === 'watch' ? s.need.watch.pending : s.need.law.pending}</Chip>
            {canRun ? (
                <button
                    type="button"
                    onClick={() => {
                        onImport(next);
                    }}
                    className={importClass}
                >
                    {importLabel}
                    <Download className="size-3.5" aria-hidden="true" />
                </button>
            ) : null}
        </>
    );
}

type ChipTone = 'ok' | 'warn' | 'busy' | 'idle';

function Chip({ tone, children }: { tone: ChipTone; children: JSX.Element | string }): JSX.Element {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[0.75rem] font-semibold whitespace-nowrap',
                tone === 'ok' && 'bg-success/10 text-success',
                tone === 'warn' && 'bg-warning/10 text-warning',
                tone === 'busy' && 'bg-info/10 text-info',
                tone === 'idle' && 'bg-muted text-muted-foreground',
            )}
        >
            <span className="size-1.5 rounded-full bg-current" aria-hidden="true" />
            {children}
        </span>
    );
}
