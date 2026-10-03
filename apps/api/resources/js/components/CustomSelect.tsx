import { useEffect, useId, useMemo, useRef, useState, type JSX, type KeyboardEvent, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { Check, ChevronDown, Search, X } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * A searchable select, owning its own menu rather than wrapping a vendor primitive.
 *
 * Replaces the Radix `Select` behind `PanelSelect`, which could show a label and nothing else. The
 * filter rows this app is full of need more than that: a document type wants its explanation
 * underneath it ("Receipt / Expense proof"), a bank account wants its currency, and a list of tax
 * subjects long enough to scroll wants a search box.
 *
 * ## Positioned through a portal
 *
 * The menu renders into `document.body` against a measured trigger rectangle, not as an absolutely
 * positioned child. Several of these sit inside panels that scroll horizontally (`.table-wrap`) or
 * clip their content, and a menu parented to the trigger is cut off by the first such ancestor —
 * visible as a dropdown with its bottom half missing. A portal cannot be clipped by anything.
 *
 * The trade-off is that the rectangle has to be re-measured while the menu is open, which is what
 * the scroll and resize listeners below are for.
 */

export type SelectValue = string | number;

export interface SelectOption<TMeta = unknown> {
    value: SelectValue;
    label: string;
    /** A second line under the label — what this option means, not a repeat of its name. */
    description?: string;
    /** Extra words the search should match but that are not shown. */
    keywords?: string[];
    icon?: ReactNode;
    disabled?: boolean;
    meta?: TMeta;
}

interface Rect {
    top: number;
    left: number;
    width: number;
    /** True when there is more room above the trigger than below, so the menu opens upward. */
    above: boolean;
    /** What the menu may grow to on the side it opened, so it always ends inside the window. */
    maxHeight: number;
}

/** The gap between the trigger and the menu, matched by the offsets applied when it is positioned. */
const MENU_GAP = 4;

/** Breathing room at the window edge, so a menu never ends flush against it. */
const MENU_EDGE = 8;

/**
 * As tall as the menu wants to be: a search box, and about eight options under it.
 *
 * A cap rather than a height. A menu with room to spare stops here instead of running the length of
 * the window, which is what the list's own `max-height` used to do and is the size this is set to
 * preserve.
 */
const MENU_PREFERRED = 328;

/** Below this a menu is not worth opening in the space; it opens anyway and scrolls inside. */
const MENU_MIN = 152;

/**
 * `.select-menu`'s own `min-width: 14rem`, and the two must agree.
 *
 * The menu is deliberately roomier than the control that opens it — a status label needs more width
 * than a filter button gives — which means its width is not the trigger's, and placing it at the
 * trigger's left edge is not enough to keep it on the screen.
 */
const MENU_MIN_WIDTH = 224;

/**
 * Where the menu goes, and how tall it may be there.
 *
 * ## Why the height is measured rather than set
 *
 * It used to open downward unless there was less than 280px beneath the trigger, and the list was
 * then capped at a flat `max-h-72` — 288px, plus a search box and padding, so a menu was around 350
 * tall. Between those two numbers is a gap: a trigger with 300px beneath it refused to flip, because
 * 300 is more than 280, and then did not fit, because 300 is less than 350. The category select in
 * the transaction dialog landed in that gap exactly, and its list ran off the bottom of the screen.
 *
 * Widening the flip threshold alone would not fix it either: flipping only helps if the other side
 * has the room, and a trigger in the middle of a short window has neither. So the side is chosen by
 * comparing the two, and whichever is chosen also decides the height. The menu then always ends
 * inside the window, and scrolls within itself when the space is tight.
 */
function measure(trigger: HTMLElement): Rect {
    const rect = trigger.getBoundingClientRect();
    const roomBelow = window.innerHeight - rect.bottom - MENU_GAP - MENU_EDGE;
    const roomAbove = rect.top - MENU_GAP - MENU_EDGE;

    // Downward unless it does not fit and the other side is roomier: opening upward when there was
    // no need to is its own surprise, so it takes both conditions.
    const above = roomBelow < MENU_PREFERRED && roomAbove > roomBelow;
    const room = above ? roomAbove : roomBelow;

    /*
     * Aligned to the trigger, on whichever edge keeps it on the screen.
     *
     * The menu is portalled and fixed, so `left: rect.left` put it wherever the trigger was — and
     * because the menu is at least 224px wide whatever the trigger is, a narrow trigger near the
     * right edge pushed it off the screen. Measured on the transactions filter row: 31px of the tag
     * menu was past the edge at 1280, 1000 and 900 pixels wide.
     *
     * The left edge is preferred; where that does not fit, the menu's right edge lines up with the
     * trigger's right edge. Pinning it to the window instead keeps it on screen and looks detached —
     * a menu ending at the browser frame rather than under the control that opened it. The window
     * clamp remains for the case where neither alignment fits.
     */
    const width = Math.max(rect.width, MENU_MIN_WIDTH);
    const fitsLeftAligned = rect.left + width <= window.innerWidth - MENU_EDGE;

    return {
        top: above ? rect.top : rect.bottom,
        left: Math.max(
            MENU_EDGE,
            Math.min(fitsLeftAligned ? rect.left : rect.right - width, window.innerWidth - width - MENU_EDGE),
        ),
        width: rect.width,
        above,
        maxHeight: Math.min(MENU_PREFERRED, Math.max(MENU_MIN, room)),
    };
}

export default function CustomSelect<TMeta = unknown>({
    options,
    value = null,
    onChange,
    placeholder,
    searchPlaceholder,
    emptyText,
    searchable = false,
    optionVisual = 'bare',
    icon,
    clearable = false,
    disabled = false,
    name,
    id,
    className,
    menuClassName,
    renderOption,
    renderTrigger,
    closeOnSelect = true,
    hideChevron = false,
    'aria-label': ariaLabel,
}: {
    options: SelectOption<TMeta>[];
    value?: SelectValue | null;
    onChange: (value: SelectValue | null, option?: SelectOption<TMeta>) => void;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    /** Off by default: a search box above four options is furniture, not help. */
    searchable?: boolean;
    /** `tile` sets each option's icon in a rounded square; `bare` draws it as-is, for a dot. */
    optionVisual?: 'tile' | 'bare';
    /**
     * One mark for the control itself, whatever is chosen — a calendar on a period, a bank on a bank.
     *
     * Distinct from `options[].icon`, which marks the options apart from each other and is what the
     * trigger shows otherwise. Saying "this control is about dates" through the options meant giving
     * every one of them the same calendar, which then appeared four times down a menu where it
     * distinguished nothing. This says it once, in the only place it means anything.
     *
     * Wins over the selected option's own icon, because a control that says what it is should not
     * stop saying it depending on the answer.
     */
    icon?: ReactNode;
    clearable?: boolean;
    disabled?: boolean;
    /** Renders a hidden input, for the rare case a plain HTML form posts this. */
    name?: string;
    id?: string;
    className?: string;
    menuClassName?: string;
    renderOption?: (option: SelectOption<TMeta>, state: { selected: boolean; active: boolean }) => ReactNode;
    /**
     * What the trigger shows, in place of "icon, one label, chevron".
     *
     * For a control whose answer is not one option — several columns held as chips, say. Everything
     * else about this component is unchanged by it: the menu, its search, the keyboard and the ARIA
     * wiring are the same ones a single select gets, which is the point of putting it here rather
     * than writing a second dropdown next to this one.
     */
    renderTrigger?: () => ReactNode;
    /**
     * Drops the chevron from the trigger.
     *
     * For a trigger that already carries its own affordance on the right — the multiple-choice
     * control's "add" button, which says what clicking does more plainly than an arrow would. Opt-in,
     * so an ordinary select keeps the mark that tells a person it opens.
     */
    hideChevron?: boolean;
    /**
     * Whether picking an option closes the menu. True for a single choice, where the menu has done
     * its job; false where a caller is collecting several and closing after each would mean
     * reopening for the next.
     */
    closeOnSelect?: boolean;
    'aria-label'?: string;
}): JSX.Element {
    const generatedId = useId();
    const fieldId = id ?? generatedId;
    const triggerRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);
    const searchRef = useRef<HTMLInputElement>(null);

    const [rect, setRect] = useState<Rect | null>(null);
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);

    const open = rect !== null;

    const selected = useMemo(() => options.find((option) => option.value === value) ?? null, [options, value]);

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (needle === '') {
            return options;
        }

        return options.filter((option) =>
            [option.label, option.description, ...(option.keywords ?? [])]
                .filter((part): part is string => part !== undefined && part !== '')
                .join(' ')
                .toLowerCase()
                .includes(needle),
        );
    }, [options, query]);

    function openMenu(): void {
        if (disabled || triggerRef.current === null) {
            return;
        }

        setRect(measure(triggerRef.current));
        setQuery('');
        setActiveIndex(
            Math.max(
                0,
                filtered.findIndex((option) => option.value === value),
            ),
        );
    }

    function closeMenu(): void {
        setRect(null);
        setQuery('');
    }

    function commit(option: SelectOption<TMeta>): void {
        if (option.disabled === true) {
            return;
        }

        onChange(option.value, option);

        if (!closeOnSelect) {
            return;
        }

        closeMenu();
        triggerRef.current?.focus();
    }

    // Dismissal and re-measurement, only while the menu is actually open. `capture` on scroll so a
    // scroll inside any ancestor is caught, not just the window's.
    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (event: MouseEvent): void => {
            const target = event.target as Node;

            if (triggerRef.current?.contains(target) !== true && menuRef.current?.contains(target) !== true) {
                closeMenu();
            }
        };
        const reposition = (): void => {
            if (triggerRef.current !== null) {
                setRect(measure(triggerRef.current));
            }
        };

        // A dialog traps focus: Radix listens on the document and pulls focus back into the dialog the
        // moment it lands anywhere else — and this menu is portalled to the body, outside it. Inside a
        // dialog the search box took focus and lost it in the same tick, so typing went nowhere.
        //
        // Focus events are stopped on their way up instead: every one from the menu, and the trigger's
        // own `focusout` when focus is moving into the menu. The same shape as `onWheel` below, which
        // keeps a dialog's scroll lock from reaching this list. Outside a dialog nothing listens, so
        // nothing changes.
        const menu = menuRef.current;
        const trigger = triggerRef.current;
        const stayInMenu = (event: FocusEvent): void => {
            event.stopPropagation();
        };
        const leaveForMenu = (event: FocusEvent): void => {
            if (menu !== null && event.relatedTarget instanceof Node && menu.contains(event.relatedTarget)) {
                event.stopPropagation();
            }
        };

        document.addEventListener('mousedown', onPointerDown);
        window.addEventListener('scroll', reposition, true);
        window.addEventListener('resize', reposition);
        menu?.addEventListener('focusin', stayInMenu);
        menu?.addEventListener('focusout', stayInMenu);
        trigger?.addEventListener('focusout', leaveForMenu);

        // Focused here rather than through `autoFocus`: that fires while React commits, before this
        // effect has attached the listeners above, so the dialog would already have taken it back.
        searchRef.current?.focus();

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            window.removeEventListener('scroll', reposition, true);
            window.removeEventListener('resize', reposition);
            menu?.removeEventListener('focusin', stayInMenu);
            menu?.removeEventListener('focusout', stayInMenu);
            trigger?.removeEventListener('focusout', leaveForMenu);
        };
    }, [open]);

    function step(direction: 1 | -1): void {
        if (filtered.length === 0) {
            return;
        }

        let next = activeIndex;

        for (let attempt = 0; attempt < filtered.length; attempt += 1) {
            next = (next + direction + filtered.length) % filtered.length;

            if (filtered[next]?.disabled !== true) {
                setActiveIndex(next);

                return;
            }
        }
    }

    function onKeyDown(event: KeyboardEvent): void {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeMenu();
            triggerRef.current?.focus();

            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();

            if (!open) {
                openMenu();
            } else {
                step(event.key === 'ArrowDown' ? 1 : -1);
            }

            return;
        }

        if (event.key === 'Enter' || (event.key === ' ' && !open)) {
            event.preventDefault();

            if (!open) {
                openMenu();

                return;
            }

            const option = filtered[activeIndex];

            if (option !== undefined) {
                commit(option);
            }
        }
    }

    const menu =
        rect === null ? null : (
            <div
                ref={menuRef}
                style={{
                    position: 'fixed',
                    left: rect.left,
                    width: rect.width,
                    maxHeight: rect.maxHeight,
                    ...(rect.above
                        ? { bottom: window.innerHeight - rect.top + MENU_GAP }
                        : { top: rect.top + MENU_GAP }),
                }}
                className={cn('select-menu', menuClassName)}
            >
                {searchable && (
                    <div className="border-border border-b p-2">
                        <div className="bg-secondary flex h-8 items-center gap-2 rounded-lg px-2.5">
                            <Search className="text-muted-foreground size-3.5 shrink-0" aria-hidden="true" />
                            <input
                                ref={searchRef}
                                value={query}
                                onChange={(event) => {
                                    setQuery(event.target.value);
                                    setActiveIndex(0);
                                }}
                                onKeyDown={onKeyDown}
                                placeholder={searchPlaceholder}
                                className="placeholder:text-muted-foreground min-w-0 flex-1 bg-transparent text-xs outline-none"
                            />
                        </div>
                    </div>
                )}

                <div
                    id={`${fieldId}-listbox`}
                    role="listbox"
                    className="scroll-hairline min-h-0 flex-1 overflow-y-auto p-1.5"
                    onWheel={(event) => {
                        // A dialog locks scrolling everywhere except its own content (Radix hands that
                        // node to `react-remove-scroll` as the single exception), and this menu is
                        // portalled to the body — so inside a dialog the wheel was cancelled on its way
                        // up and a 42-county list could not be scrolled at all.
                        //
                        // Stopping it here keeps it from reaching that document-level listener, and the
                        // browser scrolls this list natively, exactly as it does outside a dialog.
                        // Nothing is scrolled by hand, so there is no double-speed scrolling elsewhere.
                        event.stopPropagation();
                    }}
                >
                    {filtered.length === 0 ? (
                        <p className="text-muted-foreground px-3 py-8 text-center text-xs">{emptyText}</p>
                    ) : (
                        filtered.map((option, index) => {
                            const isSelected = option.value === value;

                            return (
                                <button
                                    key={String(option.value)}
                                    type="button"
                                    role="option"
                                    aria-selected={isSelected}
                                    disabled={option.disabled === true}
                                    onMouseEnter={() => {
                                        if (option.disabled !== true) {
                                            setActiveIndex(index);
                                        }
                                    }}
                                    onClick={() => {
                                        commit(option);
                                    }}
                                    className={cn('select-option', index === activeIndex && 'select-option-active')}
                                >
                                    {renderOption === undefined ? (
                                        <DefaultOption option={option} selected={isSelected} visual={optionVisual} />
                                    ) : (
                                        renderOption(option, { selected: isSelected, active: index === activeIndex })
                                    )}
                                </button>
                            );
                        })
                    )}
                </div>
            </div>
        );

    return (
        <>
            {name !== undefined && <input type="hidden" name={name} value={value === null ? '' : String(value)} />}

            <button
                ref={triggerRef}
                id={fieldId}
                type="button"
                disabled={disabled}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls={open ? `${fieldId}-listbox` : undefined}
                aria-label={ariaLabel}
                onClick={() => {
                    if (open) {
                        closeMenu();
                    } else {
                        openMenu();
                    }
                }}
                onKeyDown={onKeyDown}
                className={cn('ctl select-trigger', className)}
            >
                {renderTrigger === undefined ? (
                    <>
                        {(icon ?? selected?.icon) !== undefined && (
                            <span className="flex shrink-0 items-center">{icon ?? selected?.icon}</span>
                        )}

                        <span className="min-w-0 flex-1 truncate text-left">
                            {selected === null ? (
                                <span className="text-muted-foreground">{placeholder}</span>
                            ) : (
                                selected.label
                            )}
                        </span>
                    </>
                ) : (
                    <span className="min-w-0 flex-1 text-left">{renderTrigger()}</span>
                )}

                {clearable && selected !== null && !disabled && (
                    <span
                        role="button"
                        tabIndex={-1}
                        aria-label={placeholder}
                        onMouseDown={(event) => {
                            event.preventDefault();
                            event.stopPropagation();
                        }}
                        onClick={(event) => {
                            event.stopPropagation();
                            onChange(null);
                        }}
                        className="text-muted-foreground hover:text-foreground hover:bg-secondary grid size-5 shrink-0 place-items-center rounded-md"
                    >
                        <X className="size-3.5" />
                    </span>
                )}

                {hideChevron ? null : (
                    <ChevronDown
                        className={cn(
                            'text-muted-foreground size-3.5 shrink-0 transition-transform',
                            open && 'rotate-180',
                        )}
                        aria-hidden="true"
                    />
                )}
            </button>

            {menu !== null && createPortal(menu, document.body)}
        </>
    );
}

function DefaultOption({
    option,
    selected,
    visual,
}: {
    option: SelectOption;
    selected: boolean;
    visual: 'tile' | 'bare';
}): JSX.Element {
    return (
        <>
            {option.icon !== undefined &&
                (visual === 'tile' ? (
                    <span className="bg-secondary text-muted-foreground grid size-7 shrink-0 place-items-center rounded-lg">
                        {option.icon}
                    </span>
                ) : (
                    <span className="flex shrink-0 items-center">{option.icon}</span>
                ))}

            <span className="min-w-0 flex-1">
                <span className="block truncate font-medium">{option.label}</span>
                {option.description !== undefined && (
                    <span className="text-muted-foreground mt-0.5 block truncate text-[10px]">
                        {option.description}
                    </span>
                )}
            </span>

            {selected && <Check className="text-accent-600 size-3.5 shrink-0" aria-hidden="true" />}
        </>
    );
}
