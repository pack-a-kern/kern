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
}

function measure(trigger: HTMLElement): Rect {
    const rect = trigger.getBoundingClientRect();
    const below = window.innerHeight - rect.bottom;
    const above = below < 280 && rect.top > below;

    return {
        top: above ? rect.top : rect.bottom,
        left: rect.left,
        width: rect.width,
        above,
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
    clearable = false,
    disabled = false,
    name,
    id,
    className,
    menuClassName,
    renderOption,
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
    clearable?: boolean;
    disabled?: boolean;
    /** Renders a hidden input, for the rare case a plain HTML form posts this. */
    name?: string;
    id?: string;
    className?: string;
    menuClassName?: string;
    renderOption?: (option: SelectOption<TMeta>, state: { selected: boolean; active: boolean }) => ReactNode;
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
        setActiveIndex(Math.max(0, filtered.findIndex((option) => option.value === value)));
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

        document.addEventListener('mousedown', onPointerDown);
        window.addEventListener('scroll', reposition, true);
        window.addEventListener('resize', reposition);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            window.removeEventListener('scroll', reposition, true);
            window.removeEventListener('resize', reposition);
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

    const menu = rect === null ? null : (
        <div
            ref={menuRef}
            style={{
                position: 'fixed',
                left: rect.left,
                width: rect.width,
                ...(rect.above ? { bottom: window.innerHeight - rect.top + 4 } : { top: rect.top + 4 }),
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
                            autoFocus
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
                className="scroll-hairline max-h-72 overflow-y-auto p-1.5"
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
                {selected?.icon !== undefined && <span className="flex shrink-0 items-center">{selected.icon}</span>}

                <span className="min-w-0 flex-1 truncate text-left">
                    {selected === null ? (
                        <span className="text-muted-foreground">{placeholder}</span>
                    ) : (
                        selected.label
                    )}
                </span>

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

                <ChevronDown
                    className={cn('text-muted-foreground size-3.5 shrink-0 transition-transform', open && 'rotate-180')}
                    aria-hidden="true"
                />
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
