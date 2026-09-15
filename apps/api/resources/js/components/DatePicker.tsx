import React, { type ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { CalendarDays, ChevronDown, ChevronLeft, ChevronRight } from 'lucide-react';

export type DatePickerMode = 'single' | 'range';
/**
 * An ISO date, or the literal `today`.
 *
 * Typed as a plain string because `string | 'today'` collapses to `string` anyway; the two
 * `resolve*` helpers below are what actually give `today` its meaning.
 */
export type DateLimit = string;

export type DateRangeValue = {
    from: string | null;
    to: string | null;
};

export type DatePickerPreset = {
    id: string;
    label: string;
    getValue: (today: string) => DateRangeValue;
    description?: string | ((today: string) => string);
};

type CommonProps = {
    compact?: boolean;
    title?: string;
    locale?: string;
    weekStartsOn?: 0 | 1;

    /** YYYY-MM-DD or "today" */
    minDate?: DateLimit;
    /** YYYY-MM-DD or "today" */
    maxDate?: DateLimit;

    /** Convenience shortcut. Equivalent to constraining maxDate to today. */
    disableFuture?: boolean;
    /** Convenience shortcut. Equivalent to constraining minDate to today. */
    disablePast?: boolean;

    disabledDates?: string[] | ((date: string) => boolean);

    /**
     * Enables Calendar -> Decades -> Years -> Months navigation
     * from the month/year title. Set false to keep the title static.
     */
    monthYearNavigation?: boolean;

    showClear?: boolean;
    showApply?: boolean;
    className?: string;
    popoverClassName?: string;
    triggerClassName?: string;
    disabled?: boolean;

    trigger?: (args: { open: boolean; formattedValue: string; value: string | null | DateRangeValue }) => ReactNode;

    onOpenChange?: (open: boolean) => void;
};

type SingleProps = CommonProps & {
    mode: 'single';
    value: string | null;
    onChange: (value: string | null) => void;
    presets?: never;
    onApply?: (value: string | null) => void;
};

type RangeProps = CommonProps & {
    mode: 'range';
    value: DateRangeValue;
    onChange: (value: DateRangeValue) => void;
    presets?: boolean | DatePickerPreset[];
    onApply?: (value: DateRangeValue) => void;
};

export type DatePickerProps = SingleProps | RangeProps;

type NavLevel = 'calendar' | 'decade' | 'year' | 'month';

const DEFAULT_RANGE_PRESETS: DatePickerPreset[] = [
    { id: 'last7', label: 'Last 7 days', getValue: (today) => ({ from: addDays(today, -6), to: today }) },
    { id: 'last30', label: 'Last 30 days', getValue: (today) => ({ from: addDays(today, -29), to: today }) },
    {
        id: 'thisMonth',
        label: 'This month',
        getValue: (today) => ({ from: startOfMonth(today), to: endOfMonth(today) }),
    },
    {
        id: 'lastMonth',
        label: 'Last month',
        getValue: (today) => {
            const previous = addMonths(startOfMonth(today), -1);
            return { from: startOfMonth(previous), to: endOfMonth(previous) };
        },
    },
    {
        id: 'thisQuarter',
        label: 'This quarter',
        getValue: (today) => ({ from: startOfQuarter(today), to: endOfQuarter(today) }),
    },
    {
        id: 'thisYear',
        label: 'This year',
        getValue: (today) => {
            const { year } = parseISO(today);
            return { from: `${String(year)}-01-01`, to: `${String(year)}-12-31` };
        },
    },
];

export function DatePicker(props: DatePickerProps) {
    const {
        compact = false,
        title = 'Choose date',
        locale = 'en-GB',
        weekStartsOn = 1,
        minDate,
        maxDate,
        disableFuture = false,
        disablePast = false,
        disabledDates,
        monthYearNavigation = true,
        showClear = true,
        showApply = true,
        className = '',
        popoverClassName = '',
        triggerClassName = '',
        disabled = false,
        trigger,
        onOpenChange,
    } = props;

    const rootRef = useRef<HTMLDivElement>(null);
    const popoverRef = useRef<HTMLDivElement>(null);
    const today = toISODate(new Date());

    /*
     * Where the popover goes, in viewport coordinates.
     *
     * It renders into `document.body` rather than beside the trigger: every card in this application
     * sets `overflow: hidden` (rounded corners over a table), and an absolutely positioned panel is
     * clipped by the first such ancestor. Measuring and fixing it to the viewport is what lets a
     * calendar open out of a card, and out of a dialog.
     */
    const [rect, setRect] = useState<{ top: number; left: number; above: boolean } | null>(null);

    const measure = (): void => {
        const node = rootRef.current;

        if (node === null) return;

        const box = node.getBoundingClientRect();
        const below = window.innerHeight - box.bottom;
        // Flip above only when there is genuinely less room below than the panel needs *and* more
        // room above — otherwise a picker near the bottom of a short page opens off-screen upward.
        const above = below < 420 && box.top > below;

        setRect({ top: above ? box.top - 8 : box.bottom + 8, left: box.left, above });
    };

    const resolvedMinDate = useMemo(() => resolveMinDate(minDate, disablePast, today), [minDate, disablePast, today]);
    const resolvedMaxDate = useMemo(
        () => resolveMaxDate(maxDate, disableFuture, today),
        [maxDate, disableFuture, today],
    );

    const initialView = getInitialView(props.mode, props.value, resolvedMinDate, resolvedMaxDate);

    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState<string | null | DateRangeValue>(() => cloneValue(props.mode, props.value));
    const [activeField, setActiveField] = useState<'from' | 'to'>('from');
    const [presetMenuOpen, setPresetMenuOpen] = useState(false);

    const [navLevel, setNavLevel] = useState<NavLevel>('calendar');
    const [viewYear, setViewYear] = useState(initialView.year);
    const [viewMonth, setViewMonth] = useState(initialView.month);

    // Range only, by type: `presets` does not exist on the single-mode props, so it is read once
    // through the narrowed union rather than asked for again inside the memo — which is what lets
    // the dependency be a plain value the linter can check.
    const configuredPresets = props.mode === 'range' ? props.presets : undefined;

    const presets = useMemo(() => {
        if (configuredPresets === undefined || configuredPresets === false) return [];
        return Array.isArray(configuredPresets) ? configuredPresets : DEFAULT_RANGE_PRESETS;
    }, [configuredPresets]);

    // Re-measured while open, with `capture` on scroll so a scroll in any ancestor is caught rather
    // than only the window's.
    useEffect(() => {
        if (!open) return;

        const reposition = (): void => {
            measure();
        };

        window.addEventListener('scroll', reposition, true);
        window.addEventListener('resize', reposition);

        return () => {
            window.removeEventListener('scroll', reposition, true);
            window.removeEventListener('resize', reposition);
        };
    }, [open]);

    /*
     * The outside-press listener is registered once and reaches the current close through a ref.
     *
     * Closing over `setOpenSafe` directly would pin the first render's `disabled` and
     * `onOpenChange` for the life of the component: a picker that mounted disabled would never
     * close again, and a caller that passes a fresh `onOpenChange` each render would stop hearing
     * about it. Re-subscribing instead is not an option — the function is rebuilt every render.
     */
    const closeRef = useRef<() => void>(() => undefined);

    useEffect(() => {
        closeRef.current = () => {
            setOpenSafe(false);
        };
    });

    useEffect(() => {
        const onPointerDown = (event: MouseEvent) => {
            const target = event.target as Node;

            // The panel lives in `document.body`, so it is not inside the root: both have to be checked
            // or clicking a day would close the picker before the click landed.
            if (!rootRef.current?.contains(target) && !popoverRef.current?.contains(target)) {
                closeRef.current();
            }
        };
        document.addEventListener('mousedown', onPointerDown);
        return () => {
            document.removeEventListener('mousedown', onPointerDown);
        };
    }, []);

    function setOpenSafe(next: boolean) {
        if (disabled) return;

        /*
         * The draft is synced to the caller's value on *open*, not by an effect while shut.
         *
         * It used to be an effect that re-copied the value on every render the popover was closed
         * for — several state writes per render of the parent, for a value nobody could see.
         * Opening is the only moment the draft has to match the outside world.
         */
        if (next) {
            measure();
            setDraft(cloneValue(props.mode, props.value));
            const view = getInitialView(props.mode, props.value, resolvedMinDate, resolvedMaxDate);
            setViewYear(view.year);
            setViewMonth(view.month);
            setActiveField('from');
        }

        setOpen(next);
        onOpenChange?.(next);
        if (!next) {
            setRect(null);
            setPresetMenuOpen(false);
            setNavLevel('calendar');
        }
    }

    const currentRange =
        props.mode === 'range' ? (draft as DateRangeValue) : { from: draft as string | null, to: null };

    const formattedValue = props.mode === 'single' ? formatDate(props.value, locale) : formatRange(props.value, locale);

    const draftFormatted =
        props.mode === 'single'
            ? formatDate(draft as string | null, locale)
            : formatRange(draft as DateRangeValue, locale);

    const commit = () => {
        if (props.mode === 'single') {
            const value = draft as string | null;
            props.onChange(value);
            props.onApply?.(value);
        } else {
            const value = draft as DateRangeValue;
            props.onChange(value);
            props.onApply?.(value);
        }
        setOpenSafe(false);
    };

    const clear = () => {
        if (props.mode === 'single') {
            setDraft(null);
            if (!showApply) props.onChange(null);
        } else {
            const empty = { from: null, to: null };
            setDraft(empty);
            if (!showApply) props.onChange(empty);
        }
        setActiveField('from');
        setPresetMenuOpen(false);
        setNavLevel('calendar');
    };

    const handleDateClick = (iso: string) => {
        if (isDateDisabled(iso, resolvedMinDate, resolvedMaxDate, disabledDates)) return;

        if (props.mode === 'single') {
            setDraft(iso);
            if (!showApply) {
                props.onChange(iso);
                setOpenSafe(false);
            }
            return;
        }

        const range = draft as DateRangeValue;

        if (activeField === 'from' || (range.from && range.to)) {
            const next = { from: iso, to: null };
            setDraft(next);
            setActiveField('to');
            if (!showApply) props.onChange(next);
            return;
        }

        const next = range.from && iso < range.from ? { from: iso, to: range.from } : { from: range.from, to: iso };

        setDraft(next);
        setActiveField('from');

        if (!showApply) {
            props.onChange(next);
            if (next.from && next.to) setOpenSafe(false);
        }
    };

    const applyPreset = (preset: DatePickerPreset) => {
        if (props.mode !== 'range') return;

        const raw = preset.getValue(today);
        const value = clampRange(raw, resolvedMinDate, resolvedMaxDate);

        setDraft(value);
        setActiveField('from');
        setPresetMenuOpen(false);

        if (value.from) {
            const parsed = parseISO(value.from);
            setViewYear(parsed.year);
            setViewMonth(parsed.month - 1);
        }

        if (!showApply) {
            props.onChange(value);
            setOpenSafe(false);
        }
    };

    const days = buildCalendarGrid(viewYear, viewMonth, weekStartsOn);
    const navigateCalendarMonth = (amount: number) => {
        if (!canNavigateMonth(viewYear, viewMonth, amount, resolvedMinDate, resolvedMaxDate)) return;
        const next = shiftMonth(viewYear, viewMonth, amount);
        setViewYear(next.year);
        setViewMonth(next.month);
    };

    const openLongRangeNavigation = () => {
        if (!monthYearNavigation) return;
        setPresetMenuOpen(false);
        setNavLevel('decade');
    };

    const navTitle = getNavigationTitle(navLevel, viewYear, viewMonth, locale);
    const navBackDisabled = isNavShiftDisabled(navLevel, -1, viewYear, viewMonth, resolvedMinDate, resolvedMaxDate);
    const navNextDisabled = isNavShiftDisabled(navLevel, 1, viewYear, viewMonth, resolvedMinDate, resolvedMaxDate);

    const shiftNavigation = (direction: -1 | 1) => {
        if (isNavShiftDisabled(navLevel, direction, viewYear, viewMonth, resolvedMinDate, resolvedMaxDate)) return;

        if (navLevel === 'calendar') {
            navigateCalendarMonth(direction);
            return;
        }

        if (navLevel === 'decade') {
            setViewYear((y) => y + direction * 120);
            return;
        }

        if (navLevel === 'year') {
            setViewYear((y) => y + direction * 10);
            return;
        }

        // The remaining level, so no further check: `decade` is handled above and `calendar` first.
        setViewYear((y) => y + direction);
    };

    return (
        <div ref={rootRef} className={`relative inline-block ${className}`}>
            {trigger ? (
                <button
                    type="button"
                    disabled={disabled}
                    onClick={() => {
                        setOpenSafe(!open);
                    }}
                    className={triggerClassName}
                >
                    {trigger({ open, formattedValue, value: props.value })}
                </button>
            ) : (
                <button
                    type="button"
                    disabled={disabled}
                    onClick={() => {
                        setOpenSafe(!open);
                    }}
                    className={[
                        'inline-flex h-8 items-center justify-center gap-1.5 rounded-xl',
                        'border border-border bg-popover px-2.5 text-xs font-medium text-ink-500',
                        'transition-colors hover:border-ring disabled:cursor-not-allowed disabled:opacity-50',
                        '',
                        triggerClassName,
                    ].join(' ')}
                >
                    <CalendarDays className="size-4" />
                    <span>{formattedValue || 'Select date'}</span>
                </button>
            )}

            {open &&
                rect !== null &&
                createPortal(
                    <div
                        ref={popoverRef}
                        style={{
                            position: 'fixed',
                            left: rect.left,
                            ...(rect.above ? { bottom: window.innerHeight - rect.top } : { top: rect.top }),
                        }}
                        onWheel={(event) => {
                            // A dialog locks scrolling to its own content; this panel is portalled to the body, so
                            // the event is stopped before that lock sees it.
                            event.stopPropagation();
                        }}
                        className={[
                            // `pointer-events-auto` is load-bearing inside a modal: a dialog sets
                            // `pointer-events: none` on `document.body` while it is open, and this panel lives
                            // there — without it the calendar renders and every click passes straight through.
                            'pointer-events-auto z-50 overflow-visible rounded-2xl',
                            'border border-border bg-popover shadow-[0_18px_45px_rgba(16,24,40,.12),0_3px_10px_rgba(16,24,40,.06)]',
                            '',
                            'w-[332px]',
                            popoverClassName,
                        ].join(' ')}
                    >
                        <div className={['relative border-b border-border', 'p-3'].join(' ')}>
                            <div className="flex items-center justify-between gap-3">
                                {props.mode === 'range' && presets.length ? (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setNavLevel('calendar');
                                            setPresetMenuOpen((v) => !v);
                                        }}
                                        className={[
                                            'flex items-center gap-1 rounded-lg px-1 py-1 font-semibold hover:bg-secondary',
                                            'text-xs',
                                        ].join(' ')}
                                    >
                                        <span>{title}</span>
                                        <ChevronDown
                                            className={`size-3 text-ink-400 transition-transform ${presetMenuOpen ? 'rotate-180' : ''}`}
                                        />
                                    </button>
                                ) : (
                                    <span className={'text-xs font-semibold'}>{title}</span>
                                )}

                                {showClear && (
                                    <button
                                        type="button"
                                        onClick={clear}
                                        className={[
                                            'font-semibold text-accent-600 hover:text-accent-700',
                                            'text-[10px]',
                                        ].join(' ')}
                                    >
                                        Clear
                                    </button>
                                )}
                            </div>

                            {presetMenuOpen && props.mode === 'range' && presets.length > 0 && (
                                <div className="absolute left-3 right-3 top-10 z-20 rounded-xl border border-border bg-popover p-1.5 shadow-[0_10px_28px_rgba(16,24,40,.10)]">
                                    {presets.map((preset) => (
                                        <button
                                            key={preset.id}
                                            type="button"
                                            onClick={() => {
                                                applyPreset(preset);
                                            }}
                                            className={[
                                                'flex w-full items-center justify-between gap-3 rounded-lg text-left font-semibold',
                                                'text-ink-700 hover:bg-secondary',
                                                'px-2.5 py-2 text-[10px]',
                                            ].join(' ')}
                                        >
                                            <span>{preset.label}</span>
                                            <span className="text-[9px] font-medium text-ink-400">
                                                {typeof preset.description === 'function'
                                                    ? preset.description(today)
                                                    : (preset.description ?? presetDescription(preset, today, locale))}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            )}

                            {!compact && (
                                <div className={'mt-3 flex items-center gap-2'}>
                                    <DateField
                                        label={props.mode === 'range' ? 'From' : 'Date'}
                                        value={currentRange.from}
                                        active={props.mode === 'single' || activeField === 'from'}
                                        locale={locale}
                                        onClick={() => {
                                            setPresetMenuOpen(false);
                                            setNavLevel('calendar');
                                            setActiveField('from');
                                        }}
                                    />

                                    {props.mode === 'range' && (
                                        <>
                                            <span className="text-ink-400">→</span>
                                            <DateField
                                                label="To"
                                                value={currentRange.to}
                                                active={activeField === 'to'}
                                                locale={locale}
                                                onClick={() => {
                                                    setPresetMenuOpen(false);
                                                    setNavLevel('calendar');
                                                    setActiveField('to');
                                                }}
                                            />
                                        </>
                                    )}
                                </div>
                            )}
                        </div>

                        <div className={'relative p-3'}>
                            <div className={'mb-2 flex items-center justify-between'}>
                                <NavButton
                                    disabled={navBackDisabled}
                                    onClick={() => {
                                        shiftNavigation(-1);
                                    }}
                                    direction="left"
                                />

                                <button
                                    type="button"
                                    disabled={!monthYearNavigation && navLevel === 'calendar'}
                                    onClick={() => {
                                        if (!monthYearNavigation) return;
                                        if (navLevel === 'calendar') openLongRangeNavigation();
                                    }}
                                    className={[
                                        'inline-flex items-center gap-1 rounded-lg px-2 py-1 font-semibold',
                                        monthYearNavigation && navLevel === 'calendar'
                                            ? 'hover:bg-secondary'
                                            : 'cursor-default',
                                        'text-xs',
                                    ].join(' ')}
                                >
                                    <span>{navTitle}</span>
                                    {monthYearNavigation && navLevel === 'calendar' && (
                                        <ChevronDown className="size-3 text-ink-400" />
                                    )}
                                </button>

                                <NavButton
                                    disabled={navNextDisabled}
                                    onClick={() => {
                                        shiftNavigation(1);
                                    }}
                                    direction="right"
                                />
                            </div>

                            {navLevel === 'calendar' && (
                                <>
                                    <div className="grid grid-cols-7 gap-0.5">
                                        {weekdayLabels(locale, weekStartsOn).map((day) => (
                                            <div
                                                key={day}
                                                className={[
                                                    'grid place-items-center font-bold uppercase text-ink-400',
                                                    'h-6 text-[9px]',
                                                ].join(' ')}
                                            >
                                                {day}
                                            </div>
                                        ))}
                                    </div>

                                    <div className="grid grid-cols-7 gap-0.5">
                                        {days.map((day) => {
                                            const iso = day.iso;
                                            const selectedStart = currentRange.from === iso;
                                            const selectedEnd = props.mode === 'range' && currentRange.to === iso;
                                            const inRange =
                                                props.mode === 'range' &&
                                                currentRange.from &&
                                                currentRange.to &&
                                                iso > currentRange.from &&
                                                iso < currentRange.to;

                                            // Either end of a range, or the one date in single mode.
                                            const isEdge = selectedStart || selectedEnd;
                                            const disabledDay = isDateDisabled(
                                                iso,
                                                resolvedMinDate,
                                                resolvedMaxDate,
                                                disabledDates,
                                            );

                                            return (
                                                <button
                                                    key={iso}
                                                    type="button"
                                                    disabled={disabledDay}
                                                    onClick={() => {
                                                        handleDateClick(iso);
                                                    }}
                                                    className={[
                                                        'relative grid place-items-center font-medium transition-colors',
                                                        'h-[34px] rounded-[9px] text-[11px]',
                                                        day.inCurrentMonth ? 'text-ink-700' : 'text-ink-400',
                                                        /*
                                                         * An end of the range is *also* inside it, so it used to
                                                         * receive both sets of classes — and two conflicting
                                                         * utilities on one element are not settled by the order
                                                         * they are written in, but by their order in the
                                                         * stylesheet. That is how a selected day ended up square
                                                         * (`!rounded-none` beating `!rounded-[9px]`) and grey on
                                                         * hover (`hover:bg-secondary` beating the brand).
                                                         *
                                                         * So the conflicting class is never emitted: an edge is
                                                         * drawn as an edge, and nothing else claims it.
                                                         */
                                                        !disabledDay && !isEdge ? 'hover:bg-secondary' : '',
                                                        disabledDay ? 'cursor-not-allowed opacity-35' : '',
                                                        inRange && !isEdge
                                                            ? '!rounded-none bg-[rgb(var(--brand-rgb)/11%)]'
                                                            : '',
                                                        isEdge
                                                            ? '!rounded-[9px] bg-accent-500 font-bold text-primary-foreground hover:bg-accent-600'
                                                            : '',
                                                    ].join(' ')}
                                                >
                                                    {day.day}
                                                    {iso === today && !isEdge && (
                                                        <span className="absolute bottom-1 size-[3px] rounded-full bg-accent-500" />
                                                    )}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </>
                            )}

                            {navLevel === 'decade' && (
                                <DecadeGrid
                                    year={viewYear}
                                    minDate={resolvedMinDate}
                                    maxDate={resolvedMaxDate}
                                    onSelect={(year) => {
                                        setViewYear(year);
                                        setNavLevel('year');
                                    }}
                                />
                            )}

                            {navLevel === 'year' && (
                                <YearGrid
                                    year={viewYear}
                                    minDate={resolvedMinDate}
                                    maxDate={resolvedMaxDate}
                                    onSelect={(year) => {
                                        setViewYear(year);
                                        setNavLevel('month');
                                    }}
                                />
                            )}

                            {navLevel === 'month' && (
                                <MonthGrid
                                    year={viewYear}
                                    month={viewMonth}
                                    locale={locale}
                                    minDate={resolvedMinDate}
                                    maxDate={resolvedMaxDate}
                                    onSelect={(month) => {
                                        setViewMonth(month);
                                        setNavLevel('calendar');
                                    }}
                                />
                            )}
                        </div>

                        <div
                            className={[
                                'flex items-center justify-between gap-3 border-t border-border bg-secondary',
                                'px-3 py-2.5',
                            ].join(' ')}
                        >
                            <span className={'text-[10px] text-ink-500'}>
                                {draftFormatted ||
                                    (props.mode === 'range' ? 'No date range selected' : 'No date selected')}
                            </span>

                            {showApply && (
                                <button
                                    type="button"
                                    onClick={commit}
                                    className={[
                                        'rounded-xl bg-accent-800 font-semibold text-primary-foreground hover:bg-accent-700',
                                        'h-8 px-3 text-xs',
                                    ].join(' ')}
                                >
                                    Apply
                                </button>
                            )}
                        </div>
                    </div>,
                    document.body,
                )}
        </div>
    );
}

function NavButton({
    disabled,
    onClick,
    direction,
}: {
    disabled: boolean;
    onClick: () => void;
    direction: 'left' | 'right';
}) {
    const Icon = direction === 'left' ? ChevronLeft : ChevronRight;
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            className={[
                'grid size-8 place-items-center rounded-lg text-ink-400',
                // An arrow that cannot move must not light up under the pointer: `:hover` still
                // matches a disabled button, so the class is withheld rather than overridden.
                disabled ? 'cursor-not-allowed opacity-25' : 'hover:bg-secondary hover:text-foreground',
            ].join(' ')}
        >
            <Icon className="size-4" />
        </button>
    );
}

function DecadeGrid({
    year,
    minDate,
    maxDate,
    onSelect,
}: {
    year: number;
    minDate?: string | undefined;
    maxDate?: string | undefined;
    onSelect: (year: number) => void;
}) {
    const start = decadeBlockStart(year);

    return (
        <div className="grid grid-cols-3 gap-1.5 py-1">
            {Array.from({ length: 12 }, (_, i) => {
                const decade = start + i * 10;
                const allowed = decadeIntersectsBounds(decade, minDate, maxDate);
                const active = year >= decade && year <= decade + 9;

                return (
                    <button
                        key={decade}
                        type="button"
                        disabled={!allowed}
                        onClick={() => {
                            onSelect(decade);
                        }}
                        className={selectorClass(active, allowed)}
                    >
                        {decade}s
                    </button>
                );
            })}
        </div>
    );
}

function YearGrid({
    year,
    minDate,
    maxDate,
    onSelect,
}: {
    year: number;
    minDate?: string | undefined;
    maxDate?: string | undefined;
    onSelect: (year: number) => void;
}) {
    const start = Math.floor(year / 10) * 10;

    return (
        <div className="grid grid-cols-3 gap-1.5 py-1">
            {Array.from({ length: 12 }, (_, i) => {
                const itemYear = start - 1 + i;
                const allowed = yearIntersectsBounds(itemYear, minDate, maxDate);
                const active = itemYear === year;
                const edge = i === 0 || i === 11;

                return (
                    <button
                        key={itemYear}
                        type="button"
                        disabled={!allowed}
                        onClick={() => {
                            onSelect(itemYear);
                        }}
                        className={`${selectorClass(active, allowed)} ${edge ? 'opacity-60' : ''}`}
                    >
                        {itemYear}
                    </button>
                );
            })}
        </div>
    );
}

function MonthGrid({
    year,
    month,
    locale,
    minDate,
    maxDate,
    onSelect,
}: {
    year: number;
    month: number;
    locale: string;
    minDate?: string | undefined;
    maxDate?: string | undefined;
    onSelect: (month: number) => void;
}) {
    return (
        <div className="grid grid-cols-3 gap-1.5 py-1">
            {Array.from({ length: 12 }, (_, i) => {
                const allowed = monthIntersectsBounds(year, i, minDate, maxDate);

                return (
                    <button
                        key={i}
                        type="button"
                        disabled={!allowed}
                        onClick={() => {
                            onSelect(i);
                        }}
                        className={selectorClass(i === month, allowed)}
                    >
                        {monthName(i, locale).slice(0, 3)}
                    </button>
                );
            })}
        </div>
    );
}

function selectorClass(active: boolean, allowed: boolean) {
    return [
        'rounded-[10px] font-semibold transition-colors',
        'h-[38px] text-[11px]',
        active ? 'bg-secondary text-accent-700' : 'text-ink-500',
        !active && allowed ? 'hover:bg-secondary hover:text-foreground' : '',
        !allowed ? 'cursor-not-allowed opacity-25' : '',
    ].join(' ');
}

function DateField({
    label,
    value,
    active,
    locale,
    onClick,
}: {
    label: string;
    value: string | null;
    active: boolean;
    locale: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={[
                'flex min-w-0 flex-1 items-center justify-between gap-2 text-left border bg-popover',
                'hover:border-ring',
                active ? 'border-accent-500 bg-secondary' : 'border-border',
                'h-[38px] rounded-[11px] px-2.5',
            ].join(' ')}
        >
            <span className="min-w-0">
                <span className={'block text-[9px] uppercase tracking-[.08em] text-ink-400'}>{label}</span>
                <span className={'mt-0.5 block truncate text-[11px] font-semibold'}>
                    {formatDate(value, locale) || 'Select date'}
                </span>
            </span>
            <CalendarDays className="size-3.5 shrink-0 text-ink-400" />
        </button>
    );
}

function resolveDateLimit(limit: DateLimit | undefined, today: string) {
    if (!limit) return undefined;
    return limit === 'today' ? today : limit;
}

function resolveMinDate(limit: DateLimit | undefined, disablePast: boolean, today: string) {
    const explicit = resolveDateLimit(limit, today);
    if (!disablePast) return explicit;
    if (!explicit) return today;
    return explicit > today ? explicit : today;
}

function resolveMaxDate(limit: DateLimit | undefined, disableFuture: boolean, today: string) {
    const explicit = resolveDateLimit(limit, today);
    if (!disableFuture) return explicit;
    if (!explicit) return today;
    return explicit < today ? explicit : today;
}

function isDateDisabled(
    date: string,
    minDate?: string,
    maxDate?: string,
    disabledDates?: string[] | ((date: string) => boolean),
) {
    if (minDate && date < minDate) return true;
    if (maxDate && date > maxDate) return true;
    if (Array.isArray(disabledDates) && disabledDates.includes(date)) return true;
    if (typeof disabledDates === 'function' && disabledDates(date)) return true;
    return false;
}

function canNavigateMonth(year: number, month: number, amount: number, minDate?: string, maxDate?: string) {
    const next = shiftMonth(year, month, amount);
    return monthIntersectsBounds(next.year, next.month, minDate, maxDate);
}

function monthIntersectsBounds(year: number, month: number, minDate?: string, maxDate?: string) {
    const start = `${String(year)}-${String(month + 1).padStart(2, '0')}-01`;
    const end = toISODate(new Date(year, month + 1, 0));
    if (minDate && end < minDate) return false;
    if (maxDate && start > maxDate) return false;
    return true;
}

function yearIntersectsBounds(year: number, minDate?: string, maxDate?: string) {
    const start = `${String(year)}-01-01`;
    const end = `${String(year)}-12-31`;
    if (minDate && end < minDate) return false;
    if (maxDate && start > maxDate) return false;
    return true;
}

function decadeIntersectsBounds(decade: number, minDate?: string, maxDate?: string) {
    const start = `${String(decade)}-01-01`;
    const end = `${String(decade + 9)}-12-31`;
    if (minDate && end < minDate) return false;
    if (maxDate && start > maxDate) return false;
    return true;
}

function isNavShiftDisabled(
    level: NavLevel,
    direction: -1 | 1,
    year: number,
    month: number,
    minDate?: string,
    maxDate?: string,
) {
    if (level === 'calendar') return !canNavigateMonth(year, month, direction, minDate, maxDate);

    if (level === 'month') {
        const y = year + direction;
        return !yearIntersectsBounds(y, minDate, maxDate);
    }

    if (level === 'year') {
        const y = year + direction * 10;
        return !yearIntersectsBounds(y, minDate, maxDate);
    }

    const y = year + direction * 120;
    const start = decadeBlockStart(y);
    const end = start + 119;

    if (minDate) {
        const minYear = parseISO(minDate).year;
        if (end < minYear) return true;
    }
    if (maxDate) {
        const maxYear = parseISO(maxDate).year;
        if (start > maxYear) return true;
    }
    return false;
}

function getNavigationTitle(level: NavLevel, year: number, month: number, locale: string) {
    if (level === 'calendar') return `${monthName(month, locale)} ${String(year)}`;
    if (level === 'month') return String(year);
    if (level === 'year') {
        const start = Math.floor(year / 10) * 10;
        return `${String(start)}–${String(start + 9)}`;
    }
    const start = decadeBlockStart(year);
    return `${String(start)}s–${String(start + 110)}s`;
}

function decadeBlockStart(year: number) {
    return Math.floor(year / 100) * 100 - 20;
}

function cloneValue(mode: DatePickerMode, value: string | null | DateRangeValue) {
    if (mode === 'single') return value as string | null;
    const range = value as DateRangeValue;
    return { from: range.from ?? null, to: range.to ?? null };
}

function getInitialView(
    mode: DatePickerMode,
    value: string | null | DateRangeValue,
    minDate?: string,
    maxDate?: string,
) {
    let iso = mode === 'single' ? (value as string | null) : (value as DateRangeValue).from;

    if (!iso) iso = toISODate(new Date());
    if (minDate && iso < minDate) iso = minDate;
    if (maxDate && iso > maxDate) iso = maxDate;

    const parsed = parseISO(iso);
    return { year: parsed.year, month: parsed.month - 1 };
}

function buildCalendarGrid(year: number, month: number, weekStartsOn: 0 | 1) {
    const first = new Date(year, month, 1);
    const jsDay = first.getDay();
    const offset = weekStartsOn === 1 ? (jsDay + 6) % 7 : jsDay;
    const start = new Date(year, month, 1 - offset);

    return Array.from({ length: 42 }, (_, i) => {
        const date = new Date(start);
        date.setDate(start.getDate() + i);
        return {
            iso: toISODate(date),
            day: date.getDate(),
            inCurrentMonth: date.getMonth() === month,
        };
    });
}

function shiftMonth(year: number, month: number, amount: number) {
    const date = new Date(year, month + amount, 1);
    return { year: date.getFullYear(), month: date.getMonth() };
}

function monthName(month: number, locale: string) {
    return new Intl.DateTimeFormat(locale, { month: 'long' }).format(new Date(2026, month, 1));
}

function weekdayLabels(locale: string, weekStartsOn: 0 | 1) {
    const base = new Date(2026, 0, 4);
    const days = Array.from({ length: 7 }, (_, i) => {
        const d = new Date(base);
        d.setDate(base.getDate() + i);
        return new Intl.DateTimeFormat(locale, { weekday: 'short' }).format(d).slice(0, 2);
    });
    return weekStartsOn === 1 ? [...days.slice(1), days[0]] : days;
}

function formatDate(value: string | null, locale: string) {
    if (!value) return '';
    const { year, month, day } = parseISO(value);
    return new Intl.DateTimeFormat(locale, { day: '2-digit', month: 'short', year: 'numeric' }).format(
        new Date(year, month - 1, day),
    );
}

function formatRange(value: DateRangeValue, locale: string) {
    if (!value.from && !value.to) return '';
    if (value.from && value.to) return `${formatDate(value.from, locale)} – ${formatDate(value.to, locale)}`;
    if (value.from) return `From ${formatDate(value.from, locale)}`;
    return `Until ${formatDate(value.to, locale)}`;
}

/**
 * The three parts of an ISO date.
 *
 * Destructured with defaults and an explicit return type because `noUncheckedIndexedAccess` makes
 * every element of the split `number | undefined`, and every caller does arithmetic on them.
 */
function parseISO(value: string): { year: number; month: number; day: number } {
    const [year = 0, month = 1, day = 1] = value.split('-').map(Number);
    return { year, month, day };
}

function toISODate(date: Date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${String(y)}-${m}-${d}`;
}

function addDays(value: string, amount: number) {
    const { year, month, day } = parseISO(value);
    const date = new Date(year, month - 1, day);
    date.setDate(date.getDate() + amount);
    return toISODate(date);
}

function addMonths(value: string, amount: number) {
    const { year, month, day } = parseISO(value);
    const date = new Date(year, month - 1 + amount, day);
    return toISODate(date);
}

function startOfMonth(value: string) {
    const { year, month } = parseISO(value);
    return `${String(year)}-${String(month).padStart(2, '0')}-01`;
}

function endOfMonth(value: string) {
    const { year, month } = parseISO(value);
    return toISODate(new Date(year, month, 0));
}

function startOfQuarter(value: string) {
    const { year, month } = parseISO(value);
    const startMonth = Math.floor((month - 1) / 3) * 3 + 1;
    return `${String(year)}-${String(startMonth).padStart(2, '0')}-01`;
}

function endOfQuarter(value: string) {
    return addDays(addMonths(startOfQuarter(value), 3), -1);
}

function clampRange(value: DateRangeValue, minDate?: string, maxDate?: string): DateRangeValue {
    let from = value.from;
    let to = value.to;

    if (from && minDate && from < minDate) from = minDate;
    if (from && maxDate && from > maxDate) from = maxDate;
    if (to && minDate && to < minDate) to = minDate;
    if (to && maxDate && to > maxDate) to = maxDate;

    if (from && to && from > to) return { from: to, to: from };
    return { from, to };
}

function presetDescription(preset: DatePickerPreset, today: string, locale: string) {
    const value = preset.getValue(today);
    if (!value.from && !value.to) return '';
    if (value.from && value.to) return `${shortDate(value.from, locale)}–${shortDate(value.to, locale)}`;
    return shortDate(value.from ?? value.to ?? '', locale);
}

function shortDate(value: string, locale: string) {
    const { year, month, day } = parseISO(value);
    return new Intl.DateTimeFormat(locale, { day: '2-digit', month: 'short' }).format(new Date(year, month - 1, day));
}
