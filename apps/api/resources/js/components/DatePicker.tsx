import React, { type ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { CalendarDays, ChevronDown, ChevronLeft, ChevronRight } from 'lucide-react';

export type DatePickerMode = 'single' | 'range';
export type DatePickerSize = 'sm' | 'md';

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
    size?: DatePickerSize;
    compact?: boolean;
    title?: string;
    locale?: string;
    weekStartsOn?: 0 | 1;
    minDate?: string;
    maxDate?: string;
    disabledDates?: string[] | ((date: string) => boolean);
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

const MONTHS_EN = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

const DEFAULT_RANGE_PRESETS: DatePickerPreset[] = [
    {
        id: 'last7',
        label: 'Last 7 days',
        getValue: (today) => ({ from: addDays(today, -6), to: today }),
    },
    {
        id: 'last30',
        label: 'Last 30 days',
        getValue: (today) => ({ from: addDays(today, -29), to: today }),
    },
    {
        id: 'thisMonth',
        label: 'This month',
        getValue: (today) => ({
            from: startOfMonth(today),
            to: endOfMonth(today),
        }),
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
        getValue: (today) => ({
            from: startOfQuarter(today),
            to: endOfQuarter(today),
        }),
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
        size = 'md',
        compact = false,
        title = 'Choose date',
        locale = 'en-GB',
        weekStartsOn = 1,
        minDate,
        maxDate,
        disabledDates,
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
    // The portalled panel, so an outside press can tell "inside the popover" from "outside
    // everything". The trigger's box comes from `rootRef`, which wraps it.
    const popoverRef = useRef<HTMLDivElement>(null);
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState<string | null | DateRangeValue>(() => cloneValue(props.mode, props.value));
    const [activeField, setActiveField] = useState<'from' | 'to'>('from');
    const [monthPanelOpen, setMonthPanelOpen] = useState(false);
    const [presetMenuOpen, setPresetMenuOpen] = useState(false);

    const initialView = getInitialView(props.mode, props.value);
    const [viewYear, setViewYear] = useState(initialView.year);
    const [viewMonth, setViewMonth] = useState(initialView.month);

    const today = toISODate(new Date());

    // Range only, by type: `presets` does not exist on the single-mode props, so this reads it once
    // through the narrowed union rather than asking again inside the memo.
    const configuredPresets = props.mode === 'range' ? props.presets : undefined;

    const presets = useMemo(() => {
        if (configuredPresets === undefined || configuredPresets === false) {
            return [];
        }

        return Array.isArray(configuredPresets) ? configuredPresets : DEFAULT_RANGE_PRESETS;
    }, [configuredPresets]);

    /*
     * The draft is seeded when the popover opens, not kept in step by an effect.
     *
     * It used to be an effect that re-copied the caller's value on every render while the popover
     * was shut — four state writes per render of the parent, for a value nobody could see. Opening
     * is the only moment the draft has to match the outside world, so that is where it is done.
     */
    const setOpenSafe = (next: boolean) => {
        if (disabled) return;

        if (next) {
            measure();
            setDraft(cloneValue(props.mode, props.value));
            const view = getInitialView(props.mode, props.value);
            setViewYear(view.year);
            setViewMonth(view.month);
            setActiveField('from');
        }

        setOpen(next);
        onOpenChange?.(next);

        if (!next) {
            setRect(null);
            setMonthPanelOpen(false);
            setPresetMenuOpen(false);
        }
    };

    // Closing on an outside press. The handler is registered once, so it reaches the current
    // `onOpenChange` through a ref rather than by re-subscribing whenever the caller re-renders.
    /*
     * Where the popover goes, in viewport coordinates.
     *
     * It renders into `document.body` rather than beside the trigger: every card in this
     * application sets `overflow: hidden` (rounded corners over a table), and an absolutely
     * positioned panel inside one is cut off at the card's edge — visible as a calendar with its
     * last two weeks missing. A portal cannot be clipped by an ancestor.
     *
     * The cost is re-measuring while open, which is what the scroll and resize listeners below do.
     * `CustomSelect` solves the same problem the same way.
     */
    const [rect, setRect] = useState<{ top: number; left: number; above: boolean } | null>(null);

    const measure = (): void => {
        const node = rootRef.current;

        if (node === null) {
            return;
        }

        const box = node.getBoundingClientRect();
        const below = window.innerHeight - box.bottom;
        const above = below < 420 && box.top > below;

        setRect({ top: above ? box.top - 8 : box.bottom + 8, left: box.left, above });
    };

    // Only re-measurement lives in an effect; the first measurement happens when the popover is
    // opened, which is a user action rather than a render. `capture` on scroll so a scroll in any
    // ancestor is caught, not just the window's.
    useEffect(() => {
        if (!open) {
            return;
        }

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

    const openChangeRef = useRef(onOpenChange);

    useEffect(() => {
        openChangeRef.current = onOpenChange;
    }, [onOpenChange]);

    useEffect(() => {
        const onPointerDown = (event: MouseEvent) => {
            const target = event.target as Node;

            if (rootRef.current?.contains(target) !== true && popoverRef.current?.contains(target) !== true) {
                setOpen(false);
                setRect(null);
                setMonthPanelOpen(false);
                setPresetMenuOpen(false);
                openChangeRef.current?.(false);
            }
        };

        document.addEventListener('mousedown', onPointerDown);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
        };
    }, []);

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
        setMonthPanelOpen(false);
        setPresetMenuOpen(false);
    };

    const handleDateClick = (iso: string) => {
        if (isDisabled(iso, minDate, maxDate, disabledDates)) return;

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

        let next: DateRangeValue;
        if (range.from && iso < range.from) {
            next = { from: iso, to: range.from };
        } else {
            next = { from: range.from, to: iso };
        }

        setDraft(next);
        setActiveField('from');
        if (!showApply) {
            props.onChange(next);
            if (next.from && next.to) setOpenSafe(false);
        }
    };

    const applyPreset = (preset: DatePickerPreset) => {
        if (props.mode !== 'range') return;
        const value = preset.getValue(today);
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
                            // A dialog locks scrolling to its own content; this panel is portalled
                            // to the body, so the event is stopped before that lock sees it.
                            event.stopPropagation();
                        }}
                        className={[
                            // `pointer-events-auto` is load-bearing inside a modal: a dialog sets
                            // `pointer-events: none` on `document.body` while it is open, and this
                            // panel lives there — without it the calendar renders and every click
                            // passes straight through. Visible, and completely inert.
                            'pointer-events-auto z-50 overflow-visible rounded-2xl',
                            'border border-border bg-popover shadow-[0_18px_45px_rgba(16,24,40,.12),0_3px_10px_rgba(16,24,40,.06)]',
                            '',
                            size === 'sm' ? 'w-[304px]' : 'w-[332px]',
                            popoverClassName,
                        ].join(' ')}
                    >
                        <div
                            className={['relative border-b border-border', size === 'sm' ? 'px-2.5 py-2' : 'p-3'].join(
                                ' ',
                            )}
                        >
                            <div className="flex items-center justify-between gap-3">
                                {props.mode === 'range' && presets.length ? (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setMonthPanelOpen(false);
                                            setPresetMenuOpen((v) => !v);
                                        }}
                                        className={[
                                            'flex items-center gap-1 rounded-lg px-1 py-1 font-semibold',
                                            'hover:bg-secondary',
                                            size === 'sm' ? 'text-[11px]' : 'text-xs',
                                        ].join(' ')}
                                    >
                                        <span>{title}</span>
                                        <ChevronDown
                                            className={[
                                                'size-3 text-ink-400 transition-transform',
                                                presetMenuOpen ? 'rotate-180' : '',
                                            ].join(' ')}
                                        />
                                    </button>
                                ) : (
                                    <span
                                        className={
                                            size === 'sm' ? 'text-[11px] font-semibold' : 'text-xs font-semibold'
                                        }
                                    >
                                        {title}
                                    </span>
                                )}

                                {showClear && (
                                    <button
                                        type="button"
                                        onClick={clear}
                                        className={[
                                            'font-semibold text-accent-500 hover:text-accent-700',
                                            size === 'sm' ? 'text-[9px]' : 'text-[10px]',
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
                                                size === 'sm' ? 'px-2 py-1.5 text-[9px]' : 'px-2.5 py-2 text-[10px]',
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
                                <div
                                    className={
                                        size === 'sm'
                                            ? 'mt-2 flex items-center gap-1.5'
                                            : 'mt-3 flex items-center gap-2'
                                    }
                                >
                                    <DateField
                                        label={props.mode === 'range' ? 'From' : 'Date'}
                                        value={currentRange.from}
                                        active={props.mode === 'single' || activeField === 'from'}
                                        size={size}
                                        locale={locale}
                                        onClick={() => {
                                            setPresetMenuOpen(false);
                                            setMonthPanelOpen(false);
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
                                                size={size}
                                                locale={locale}
                                                onClick={() => {
                                                    setPresetMenuOpen(false);
                                                    setMonthPanelOpen(false);
                                                    setActiveField('to');
                                                }}
                                            />
                                        </>
                                    )}
                                </div>
                            )}
                        </div>

                        <div className={size === 'sm' ? 'relative p-2.5' : 'relative p-3'}>
                            <div
                                className={
                                    size === 'sm'
                                        ? 'mb-1.5 flex items-center justify-between'
                                        : 'mb-2 flex items-center justify-between'
                                }
                            >
                                <button
                                    type="button"
                                    onClick={() => {
                                        setMonthPanelOpen(false);
                                        setPresetMenuOpen(false);
                                        const next = shiftMonth(viewYear, viewMonth, -1);
                                        setViewYear(next.year);
                                        setViewMonth(next.month);
                                    }}
                                    className="grid size-8 place-items-center rounded-lg text-ink-400 hover:bg-secondary hover:text-ink-950"
                                >
                                    <ChevronLeft className="size-4" />
                                </button>

                                <button
                                    type="button"
                                    onClick={() => {
                                        setPresetMenuOpen(false);
                                        setMonthPanelOpen((v) => !v);
                                    }}
                                    className={[
                                        'inline-flex items-center gap-1 rounded-lg px-2 py-1 font-semibold hover:bg-secondary',
                                        size === 'sm' ? 'text-[11px]' : 'text-xs',
                                    ].join(' ')}
                                >
                                    <span>
                                        {monthName(viewMonth, locale)} {viewYear}
                                    </span>
                                    <ChevronDown
                                        className={`size-3 text-ink-400 transition-transform ${monthPanelOpen ? 'rotate-180' : ''}`}
                                    />
                                </button>

                                <button
                                    type="button"
                                    onClick={() => {
                                        setMonthPanelOpen(false);
                                        setPresetMenuOpen(false);
                                        const next = shiftMonth(viewYear, viewMonth, 1);
                                        setViewYear(next.year);
                                        setViewMonth(next.month);
                                    }}
                                    className="grid size-8 place-items-center rounded-lg text-ink-400 hover:bg-secondary hover:text-ink-950"
                                >
                                    <ChevronRight className="size-4" />
                                </button>
                            </div>

                            {monthPanelOpen && (
                                <MonthYearPanel
                                    year={viewYear}
                                    month={viewMonth}
                                    locale={locale}
                                    size={size}
                                    onYearChange={setViewYear}
                                    onMonthChange={(month) => {
                                        setViewMonth(month);
                                        setMonthPanelOpen(false);
                                    }}
                                />
                            )}

                            <div className="grid grid-cols-7 gap-0.5">
                                {weekdayLabels(locale, weekStartsOn).map((day) => (
                                    <div
                                        key={day}
                                        className={[
                                            'grid place-items-center font-bold uppercase text-ink-400',
                                            size === 'sm' ? 'h-5 text-[8px]' : 'h-6 text-[9px]',
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

                                    // Either end of the range, or the one date in single mode.
                                    const isEdge = selectedStart || selectedEnd;
                                    const disabledDay = isDisabled(iso, minDate, maxDate, disabledDates);

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
                                                size === 'sm'
                                                    ? 'h-[30px] rounded-lg text-[10px]'
                                                    : 'h-[34px] rounded-[9px] text-[11px]',
                                                day.inCurrentMonth ? 'text-ink-700' : 'text-ink-400',
                                                /*
                                                 * An end of the range is *also* inside it, so it
                                                 * used to receive both sets of classes — and two
                                                 * conflicting utilities on one element are not
                                                 * settled by the order they are written in, but by
                                                 * their order in the stylesheet. That is how a
                                                 * selected day ended up square (`!rounded-none`
                                                 * beating `!rounded-[9px]`) and grey on hover
                                                 * (`hover:bg-secondary` beating the brand).
                                                 *
                                                 * So the conflicting class is not emitted at all:
                                                 * an edge is drawn as an edge, and nothing else
                                                 * claims it.
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
                        </div>

                        <div
                            className={[
                                'flex items-center justify-between gap-3 border-t border-border bg-secondary',
                                '',
                                size === 'sm' ? 'px-2.5 py-2' : 'px-3 py-2.5',
                            ].join(' ')}
                        >
                            <span className={size === 'sm' ? 'text-[9px] text-ink-500' : 'text-[10px] text-ink-500'}>
                                {draftFormatted ||
                                    (props.mode === 'range' ? 'No date range selected' : 'No date selected')}
                            </span>

                            {showApply && (
                                <button
                                    type="button"
                                    onClick={commit}
                                    className={[
                                        'rounded-xl bg-accent-800 font-semibold text-primary-foreground hover:bg-accent-700',
                                        '',
                                        size === 'sm' ? 'h-7 px-2.5 text-[10px]' : 'h-8 px-3 text-xs',
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

function DateField({
    label,
    value,
    active,
    size,
    locale,
    onClick,
}: {
    label: string;
    value: string | null;
    active: boolean;
    size: DatePickerSize;
    locale: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={[
                'flex min-w-0 flex-1 items-center justify-between gap-2 text-left',
                'border bg-popover hover:border-border',
                active ? 'border-accent-500 bg-secondary' : 'border-border',
                size === 'sm' ? 'h-[34px] rounded-[10px] px-2.5' : 'h-[38px] rounded-[11px] px-2.5',
            ].join(' ')}
        >
            <span className="min-w-0">
                <span
                    className={
                        size === 'sm'
                            ? 'block text-[8px] uppercase tracking-[.08em] text-ink-400'
                            : 'block text-[9px] uppercase tracking-[.08em] text-ink-400'
                    }
                >
                    {label}
                </span>
                <span
                    className={
                        size === 'sm'
                            ? 'mt-0.5 block truncate text-[10px] font-semibold'
                            : 'mt-0.5 block truncate text-[11px] font-semibold'
                    }
                >
                    {formatDate(value, locale) || 'Select date'}
                </span>
            </span>
            <CalendarDays className="size-3.5 shrink-0 text-ink-400" />
        </button>
    );
}

function MonthYearPanel({
    year,
    month,
    locale,
    size,
    onYearChange,
    onMonthChange,
}: {
    year: number;
    month: number;
    locale: string;
    size: DatePickerSize;
    onYearChange: (year: number) => void;
    onMonthChange: (month: number) => void;
}) {
    return (
        <div className="absolute left-3 right-3 top-11 z-20 rounded-xl border border-border bg-popover p-2 shadow-[0_10px_28px_rgba(16,24,40,.10)]">
            <div className="mb-2 flex items-center justify-between">
                <button
                    type="button"
                    onClick={() => {
                        onYearChange(year - 1);
                    }}
                    className="grid size-7 place-items-center rounded-lg text-ink-400 hover:bg-secondary"
                >
                    <ChevronLeft className="size-3.5" />
                </button>
                <span className="text-[10px] font-semibold">{year}</span>
                <button
                    type="button"
                    onClick={() => {
                        onYearChange(year + 1);
                    }}
                    className="grid size-7 place-items-center rounded-lg text-ink-400 hover:bg-secondary"
                >
                    <ChevronRight className="size-3.5" />
                </button>
            </div>

            <div className="grid grid-cols-3 gap-1">
                {Array.from({ length: 12 }, (_, index) => (
                    <button
                        key={index}
                        type="button"
                        onClick={() => {
                            onMonthChange(index);
                        }}
                        className={[
                            'rounded-lg font-semibold',
                            size === 'sm' ? 'h-7 text-[9px]' : 'h-[30px] text-[10px]',
                            index === month
                                ? 'bg-[rgb(var(--brand-rgb)/12%)] text-accent-700'
                                : 'text-ink-400 hover:bg-secondary hover:text-ink-950',
                        ].join(' ')}
                    >
                        {monthName(index, locale).slice(0, 3)}
                    </button>
                ))}
            </div>
        </div>
    );
}

function cloneValue(mode: DatePickerMode, value: string | null | DateRangeValue) {
    if (mode === 'single') {
        return typeof value === 'string' ? value : null;
    }

    const range = typeof value === 'object' && value !== null ? value : { from: null, to: null };

    return { from: range.from, to: range.to };
}

function getInitialView(mode: DatePickerMode, value: string | null | DateRangeValue) {
    const iso = typeof value === 'string' ? value : (value?.from ?? null);

    if (iso) {
        const parsed = parseISO(iso);
        return { year: parsed.year, month: parsed.month - 1 };
    }

    const now = new Date();
    return { year: now.getFullYear(), month: now.getMonth() };
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

function isDisabled(
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

function shiftMonth(year: number, month: number, amount: number) {
    const date = new Date(year, month + amount, 1);
    return { year: date.getFullYear(), month: date.getMonth() };
}

function monthName(month: number, locale: string): string {
    try {
        return new Intl.DateTimeFormat(locale, { month: 'long' }).format(new Date(2026, month, 1));
    } catch {
        // Only reachable with a locale `Intl` rejects; the English name is a last resort, and an index
        // outside the year falls back to the number so the button still says something.
        return MONTHS_EN[month] ?? String(month + 1);
    }
}

function weekdayLabels(locale: string, weekStartsOn: 0 | 1) {
    const base = new Date(2026, 0, 4); // Sunday
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
    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(year, month - 1, day));
}

function formatRange(value: DateRangeValue, locale: string) {
    if (value.from === null && value.to === null) return '';
    if (value.from && value.to) {
        return `${formatDate(value.from, locale)} – ${formatDate(value.to, locale)}`;
    }
    if (value.from) return `From ${formatDate(value.from, locale)}`;
    return `Until ${formatDate(value.to, locale)}`;
}

/**
 * `YYYY-MM-DD` split into its three numbers.
 *
 * The return type is declared rather than inferred: destructuring an array gives
 * `number | undefined` per element under `noUncheckedIndexedAccess`, which then spreads
 * `undefined` through every caller that does arithmetic on a month. A malformed string is not a
 * case worth threading nullability through 800 lines for — it cannot reach here from the calendar,
 * and a caller that invents one gets a date in year 0 rather than a crash.
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
    const date = new Date(year, month, 0);
    return toISODate(date);
}

function startOfQuarter(value: string) {
    const { year, month } = parseISO(value);
    const startMonth = Math.floor((month - 1) / 3) * 3 + 1;
    return `${String(year)}-${String(startMonth).padStart(2, '0')}-01`;
}

function endOfQuarter(value: string) {
    const start = startOfQuarter(value);
    const nextQuarter = addMonths(start, 3);
    return addDays(nextQuarter, -1);
}

function presetDescription(preset: DatePickerPreset, today: string, locale: string) {
    const value = preset.getValue(today);
    if (!value.from && !value.to) return '';
    if (value.from && value.to) {
        const from = shortDate(value.from, locale);
        const to = shortDate(value.to, locale);
        return `${from}–${to}`;
    }
    // One end or the other — the empty case returned above, so this cannot be null.
    const single = value.from ?? value.to;

    return single === null ? '' : shortDate(single, locale);
}

function shortDate(value: string, locale: string) {
    const { year, month, day } = parseISO(value);
    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: 'short',
    }).format(new Date(year, month - 1, day));
}
