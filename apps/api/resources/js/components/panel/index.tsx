import { cloneElement, isValidElement } from 'react';
import type { ComponentProps, JSX, ReactElement, ReactNode } from 'react';
import { SearchIcon } from 'lucide-react';

import { Button } from '../ui/button';
import { Card } from '../ui/card';
import { Input } from '../ui/input';
import { Label } from '../ui/label';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '../ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../ui/select';
import { cn } from '../../lib/utils';

export { QrCode } from './QrCode';

/**
 * The shared vocabulary every admin/detail screen is built from, so a page composes these instead
 * of restating geometry. Four card bodies cover everything we have needed so far, and which one a
 * card uses is a statement about its content:
 *
 *  - {@link FieldGrid}  values the operator types or picks.
 *  - {@link RowList}    one setting per line, each with a switch, a status or a badge on the right.
 *                       Anything that is not free text reads better here than as a labelled input.
 *  - {@link MetricGrid} values that are read far more often than they are changed. Shown formatted
 *                       and read-only; editing happens in a dialog, so the display never has to
 *                       double as an input.
 *  - plain children     for anything that fits none of the above.
 *
 * Sizes are fixed here on purpose: a page that hand-rolls its own paddings drifts from every other
 * page within a release or two.
 */
export function Panel(props: {
    title: string;
    description?: string;
    /** The body reaches the card's edges — for a table or a toolbar, which draw their own rules. */
    flush?: boolean;
    /** Right-hand side of the card head — a switch, a status, a badge. */
    aside?: ReactNode;
    children: ReactNode;
    /** Left side of the foot: a short explanatory note, not a control. */
    footNote?: ReactNode;
    /** Right side of the foot: the card's actions, primary last. */
    footActions?: ReactNode;
    className?: string;
}): JSX.Element {
    const hasFoot = props.footNote !== undefined || props.footActions !== undefined;

    return (
        <Card
            className={cn(
                'panel mb-3.5 gap-0 break-inside-avoid',
                props.flush === true && 'panel-flush',
                props.className,
            )}
        >
            <div className="panel-head">
                <div className="min-w-0">
                    <h2 className="text-sm leading-tight font-semibold">{props.title}</h2>
                    {props.description !== undefined ? <p className="note mt-1">{props.description}</p> : null}
                </div>
                {props.aside !== undefined ? <div className="shrink-0">{props.aside}</div> : null}
            </div>

            {props.children}

            {hasFoot ? (
                <div className="panel-foot">
                    <span className="note">{props.footNote}</span>
                    <div className="flex shrink-0 gap-1.5">{props.footActions}</div>
                </div>
            ) : null}
        </Card>
    );
}

/** The outer wrapper every screen uses: a vertical rhythm plus the page heading. */
export function Page(props: { title: string; lede?: string; actions?: ReactNode; children: ReactNode }): JSX.Element {
    return (
        <div className="page-stack">
            <header className="page-head">
                <div>
                    <h1 className="page-title">{props.title}</h1>
                    {props.lede !== undefined ? <p className="page-lede max-w-2xl">{props.lede}</p> : null}
                </div>
                {props.actions}
            </header>

            {props.children}
        </div>
    );
}

/** Two columns of short, independent inputs. */
export function FieldGrid({ children }: { children: ReactNode }): JSX.Element {
    return <div className="field-grid">{children}</div>;
}

/** A field that should take the grid's full width — a URL, a key. */
export function FieldSpan({ children }: { children: ReactNode }): JSX.Element {
    return <div className="sm:col-span-2">{children}</div>;
}

/**
 * The one control size these screens use — see `.ctl` in `resources/css/app.css`.
 *
 * Kept as a named export only so a screen with a control these wrappers do not cover can reach for
 * the same class; normally use {@link PanelInput}, {@link PanelSelect} or {@link PanelButton}.
 */
export const CONTROL = 'ctl';

/**
 * Label, optional right-hand marker, and hint, arranged identically for every control.
 *
 * The label row is `justify-between`, so a marker sits flush against the field's right edge and
 * every marker on a card lines up in a column. Placing it right after the label text instead
 * leaves them at ragged positions, which reads as untidy across a page of cards.
 */
export function Field(props: {
    label: string;
    /** A validation message for this field. Replaces the hint and marks the control invalid. */
    error?: string | undefined;
    /** A small state indicator shown *before* the label — provenance, a lock. */
    mark?: ReactNode;
    /** Appended to the label in parentheses, e.g. `MB`. Never rendered inside the input: the unit
     *  would collide with a number input's spinner arrows. */
    unit?: string | undefined;
    marker?: ReactNode;
    hint?: ReactNode;
    htmlFor?: string;
    children: ReactNode;
    className?: string;
}): JSX.Element {
    const invalid = props.error !== undefined && props.error !== '';

    return (
        // `field-invalid` rather than styling the control directly: the border belongs to the
        // `.input-group` when a field has an addon, and to the input itself otherwise. One class on
        // the field reaches both, whatever shape the control turns out to be.
        <div className={cn('min-w-0', invalid && 'field-invalid', props.className)}>
            <div className="field-label-row">
                {props.mark}
                <Label htmlFor={props.htmlFor} className={cn('field-label', props.marker != null && 'truncate')}>
                    {props.unit !== undefined && props.unit !== '' ? `${props.label} (${props.unit})` : props.label}
                </Label>
                {props.marker}
            </div>

            {/* `aria-invalid` on the control as well, so it is announced as invalid and not only
                drawn as such. Cloned onto the child because `Field` is what knows about the error;
                a non-element child (a fragment, a string) is left alone. */}
            {isValidElement(props.children) && invalid
                ? cloneElement(props.children as ReactElement<{ 'aria-invalid'?: boolean }>, {
                      'aria-invalid': true,
                  })
                : props.children}

            {invalid ? (
                // `role="alert"` so a screen reader announces it when it appears, rather than only
                // when the field is next visited.
                <p className="field-error" role="alert">
                    {props.error}
                </p>
            ) : props.hint !== undefined && props.hint !== null && props.hint !== '' ? (
                <p className="field-hint">{props.hint}</p>
            ) : null}
        </div>
    );
}

/** One setting per line, hairline-separated, with its control or status on the right. */
export function RowList({ children }: { children: ReactNode }): JSX.Element {
    return <div className="divide-border divide-y">{children}</div>;
}

export function Row(props: { title: string; subtitle?: string; children: ReactNode }): JSX.Element {
    return (
        <div className="flex items-center justify-between gap-3 py-[9px] first:pt-0 last:pb-0">
            <div className="min-w-0">
                <div className="text-xs font-semibold">{props.title}</div>
                {props.subtitle !== undefined ? <div className="note mt-0.5">{props.subtitle}</div> : null}
            </div>
            <div className="shrink-0">{props.children}</div>
        </div>
    );
}

/** Read-mostly values, shown formatted. Editing happens elsewhere — usually a dialog. */
export function MetricGrid({ children }: { children: ReactNode }): JSX.Element {
    return <div className="grid grid-cols-2 gap-[9px]">{children}</div>;
}

export function Metric(props: { label: string; value: string; marker?: ReactNode }): JSX.Element {
    return (
        <div className="metric bg-muted/30 border">
            <div className="flex items-center justify-between gap-2">
                <span className="metric-label truncate">{props.label}</span>
                {props.marker}
            </div>
            <strong className="metric-value">{props.value}</strong>
        </div>
    );
}

/**
 * An input at the standard control size, optionally with an icon inside it.
 *
 * `icon` sits at the leading edge by default — where a reader looks first, and where it labels the
 * field rather than decorating it. `iconPosition="trailing"` moves it to the other end, for a state
 * indicator rather than a category. `affix` is different again: a short, non-interactive suffix
 * such as a unit, drawn in its own cell against the field's right edge.
 *
 * The icon is `pointer-events: none` and `aria-hidden`, so it never intercepts a click and never
 * competes with the field's real label for a screen reader.
 */
export function PanelInput({
    className,
    icon,
    iconPosition = 'leading',
    affix,
    addonStart,
    addonEnd,
    actionStart,
    actionEnd,
    ...props
}: ComponentProps<typeof Input> & {
    icon?: ReactNode;
    iconPosition?: 'leading' | 'trailing';
    affix?: ReactNode;
    /** A static cell fused to the field — a scheme, a currency, a unit. */
    addonStart?: ReactNode;
    addonEnd?: ReactNode;
    /** An interactive cell fused to the field — a button, a select. Pass the control itself. */
    actionStart?: ReactNode;
    actionEnd?: ReactNode;
}): JSX.Element {
    const grouped =
        addonStart !== undefined || addonEnd !== undefined || actionStart !== undefined || actionEnd !== undefined;

    if (grouped) {
        // The group owns the border and radius; the field inside gives its own up, so the whole
        // control reads as one shape rather than a box inside a box.
        return (
            <div className={cn('input-group', className)}>
                {addonStart !== undefined ? <span className="input-group-addon">{addonStart}</span> : null}
                {actionStart !== undefined ? <span className="input-group-action">{actionStart}</span> : null}

                <span className="input-group-field">
                    <Input className={cn(CONTROL, icon !== undefined && 'ctl-has-leading')} {...props} />
                    {icon !== undefined ? (
                        <span aria-hidden="true" className="ctl-icon ctl-icon-leading">
                            {icon}
                        </span>
                    ) : null}
                </span>

                {actionEnd !== undefined ? <span className="input-group-action">{actionEnd}</span> : null}
                {addonEnd !== undefined ? <span className="input-group-addon">{addonEnd}</span> : null}
            </div>
        );
    }

    const field = (
        <Input
            className={cn(
                CONTROL,
                icon !== undefined && (iconPosition === 'leading' ? 'ctl-has-leading' : 'ctl-has-trailing'),
                affix !== undefined && 'ctl-has-trailing',
                className,
            )}
            {...props}
        />
    );

    if (icon === undefined && affix === undefined) {
        return field;
    }

    return (
        <div className="ctl-group">
            {icon !== undefined ? (
                <span
                    aria-hidden="true"
                    className={cn('ctl-icon', iconPosition === 'leading' ? 'ctl-icon-leading' : 'ctl-icon-trailing')}
                >
                    {icon}
                </span>
            ) : null}

            {field}

            {affix !== undefined ? (
                <span aria-hidden="true" className="ctl-affix">
                    {affix}
                </span>
            ) : null}
        </div>
    );
}

/**
 * Segmented buttons — several options presented as one control.
 *
 * For a small, fixed set where the choices are worth seeing at once; a select is better past four,
 * or when the labels are long. Each segment reports `aria-pressed`, so the pressed one is announced
 * as such rather than merely looking different.
 */
export function ButtonGroup<T extends string>(props: {
    value: T;
    onChange: (value: T) => void;
    options: Array<{ value: T; label: ReactNode; title?: string }>;
    className?: string;
}): JSX.Element {
    return (
        <div className={cn('btn-group', props.className)} role="group">
            {props.options.map((option) => {
                const pressed = option.value === props.value;

                return (
                    <Button
                        key={option.value}
                        type="button"
                        variant={pressed ? 'secondary' : 'outline'}
                        aria-pressed={pressed}
                        title={option.title}
                        className={cn('ctl px-2.5 font-semibold', pressed && 'border-ring')}
                        onClick={() => {
                            props.onChange(option.value);
                        }}
                    >
                        {option.label}
                    </Button>
                );
            })}
        </div>
    );
}

/**
 * The value `PanelSelect` uses to mean "no selection".
 *
 * Radix reserves the empty string for "nothing is selected" and throws if an item carries it, so a
 * clearable select cannot simply offer `value=""`. This sentinel stands in for it inside the
 * component and is translated back to `''` before it reaches the caller — no page ever sees it.
 */
const NO_SELECTION = '__panel_select_none__';

/**
 * A select at the standard control size.
 *
 * Two defaults differ from the underlying component, both deliberately:
 *
 * `size="default"` — shadcn's `sm` trigger is 28px, four short of every input and button beside it,
 * and being a `data-[size=sm]` variant it also beats a plain `h-8` utility. The mismatch is
 * invisible in isolation and obvious in a row.
 *
 * The menu drops below the trigger rather than covering it — see the `position` default in
 * `ui/select.tsx`, changed there so every select in the app behaves the same way.
 *
 * Pass `emptyLabel` for a filter, which must be clearable; omit it for a setting, where "no value"
 * is not a legitimate choice.
 */
export function PanelSelect(props: {
    value?: string | undefined;
    onValueChange: (value: string) => void;
    placeholder?: string;
    /** Adds a first item that clears the selection, e.g. "All roles". */
    emptyLabel?: string;
    options: Array<{ value: string; label: string }>;
    id?: string;
    className?: string;
}): JSX.Element {
    const selected = props.value === undefined || props.value === '' ? undefined : props.value;

    return (
        <Select
            {...(selected === undefined ? {} : { value: selected })}
            onValueChange={(value) => {
                props.onValueChange(value === NO_SELECTION ? '' : value);
            }}
        >
            <SelectTrigger id={props.id} size="default" className={cn(CONTROL, 'w-full min-w-0', props.className)}>
                <SelectValue placeholder={props.placeholder} />
            </SelectTrigger>
            <SelectContent sideOffset={4}>
                {props.emptyLabel !== undefined ? (
                    <SelectItem value={NO_SELECTION} className="text-xs">
                        {props.emptyLabel}
                    </SelectItem>
                ) : null}
                {props.options.map((option) => (
                    <SelectItem key={option.value} value={option.value} className="text-xs">
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/**
 * A dialog at these screens' scale: 460px, 16px padding, and a plain right-aligned action row.
 *
 * `ui/dialog`'s own `DialogFooter` is a full-bleed tinted bar with a top border — correct for a
 * destructive confirmation, too heavy for a small edit form, and it is shared with other screens,
 * so it is left alone and simply not used here.
 */
export function PanelDialog(props: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: string;
    /** `md` by default; `lg` for a form with two columns of fields. */
    size?: 'sm' | 'md' | 'lg';
    children: ReactNode;
    actions: ReactNode;
}): JSX.Element {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            <DialogContent className={cn('dialog-panel', `dialog-${props.size ?? 'md'}`)}>
                <DialogHeader className="gap-1">
                    <DialogTitle className="dialog-title">{props.title}</DialogTitle>
                    {props.description !== undefined ? (
                        <DialogDescription className="text-xs leading-[1.45]">{props.description}</DialogDescription>
                    ) : null}
                </DialogHeader>

                <div className="dialog-body">{props.children}</div>

                <div className="dialog-footer">{props.actions}</div>
            </DialogContent>
        </Dialog>
    );
}

/** Summary tiles above a listing. */
export function StatGrid({ children }: { children: ReactNode }): JSX.Element {
    return <div className="stat-grid">{children}</div>;
}

export function Stat(props: { label: string; value: ReactNode; sub?: ReactNode }): JSX.Element {
    return (
        <div className="stat">
            <div className="stat-label">{props.label}</div>
            <div className="stat-value">{props.value}</div>
            {props.sub !== undefined ? <div className="stat-sub">{props.sub}</div> : null}
        </div>
    );
}

/** The filter bar that sits above a table, inside the same bordered panel. */
export function Toolbar({ children }: { children: ReactNode }): JSX.Element {
    return <div className="toolbar">{children}</div>;
}

/**
 * A listing table. Wide content scrolls inside this wrapper so the page body never scrolls
 * sideways — a table of hashes is always wider than the viewport on a laptop.
 */
export function DataTable(props: {
    head: ReactNode;
    /**
     * Below 640px, drop the header row and lay each row out as a small stacked block instead of
     * scrolling it sideways. Opt-in per table: which columns still earn their place on a phone is a
     * judgement about the content, so the cells decide it with `col-primary`, `col-hide` and
     * `col-end` (see `app.css`).
     */
    stacked?: boolean;
    children: ReactNode;
}): JSX.Element {
    return (
        <div className="table-wrap">
            <table className={cn('data-table', props.stacked === true && 'data-table-stack')}>
                <thead>
                    <tr>{props.head}</tr>
                </thead>
                <tbody>{props.children}</tbody>
            </table>
        </div>
    );
}

/** Key/value pairs in a detail drawer. */
export function DetailList({ children }: { children: ReactNode }): JSX.Element {
    return <dl className="detail-grid">{children}</dl>;
}

export function Detail(props: { label: string; children: ReactNode }): JSX.Element {
    return (
        <>
            <dt>{props.label}</dt>
            <dd>{props.children}</dd>
        </>
    );
}

/** Initials disc plus a name and a secondary line — the identity cell in a listing. */
export function Identity(props: { initials: string; name: string; sub?: string }): JSX.Element {
    return (
        <div className="identity">
            <span className="avatar" aria-hidden="true">
                {props.initials}
            </span>
            <div className="min-w-0">
                <div className="identity-name truncate">{props.name}</div>
                {props.sub !== undefined ? <div className="identity-sub truncate">{props.sub}</div> : null}
            </div>
        </div>
    );
}

/** Search field with a real magnifier inside it, rather than a CSS glyph. */
export function SearchInput(props: {
    value: string;
    placeholder: string;
    onChange: (value: string) => void;
}): JSX.Element {
    return (
        <div className="search">
            <PanelInput
                type="search"
                icon={<SearchIcon className="size-3.5" />}
                value={props.value}
                placeholder={props.placeholder}
                onChange={(event) => {
                    props.onChange(event.target.value);
                }}
            />
        </div>
    );
}

/** A flat underline tab rail. */
export function TabRail<T extends string>(props: {
    value: T;
    onChange: (value: T) => void;
    tabs: Array<{ value: T; label: string }>;
}): JSX.Element {
    return (
        <div className="tab-rail" role="tablist">
            {props.tabs.map((tab) => (
                <button
                    key={tab.value}
                    type="button"
                    role="tab"
                    aria-selected={tab.value === props.value}
                    className="tab-item"
                    onClick={() => {
                        props.onChange(tab.value);
                    }}
                >
                    {tab.label}
                </button>
            ))}
        </div>
    );
}

/**
 * A permission-matrix cell.
 *
 * Three states, not two: granted-and-enforced is filled, granted-but-not-yet-checked-anywhere is
 * outlined, and not granted is empty. Collapsing the middle state into a tick would make the
 * matrix assert a protection the application does not apply.
 */
export function Check({ granted, enforced }: { granted: boolean; enforced: boolean }): JSX.Element {
    if (!granted) {
        return <span className="check" aria-label="no" />;
    }

    return (
        <span className={cn('check', enforced ? 'check-on' : 'check-unenforced')} aria-label="yes">
            ✓
        </span>
    );
}

export type StatusTone = 'ok' | 'warn' | 'bad' | 'idle';

/** A coloured dot and a word. Used for live state, never for a stored setting's value. */
export function Status({ tone, children }: { tone: StatusTone; children: ReactNode }): JSX.Element {
    return (
        <span
            className={cn(
                'status',
                tone === 'ok' && 'text-emerald-700 dark:text-emerald-400',
                tone === 'warn' && 'text-amber-700 dark:text-amber-400',
                tone === 'bad' && 'text-destructive',
                tone === 'idle' && 'text-muted-foreground',
            )}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'size-1.5 rounded-full',
                    tone === 'ok' && 'bg-emerald-500',
                    tone === 'warn' && 'bg-amber-500',
                    tone === 'bad' && 'bg-destructive',
                    tone === 'idle' && 'bg-muted-foreground/50',
                )}
            />
            {children}
        </span>
    );
}

export type BadgeTone = 'neutral' | 'accent' | 'locked';

/** 10.5px pill, used for provenance and for short read-only values. */
export function Pill({
    children,
    tone = 'neutral',
    className,
}: {
    children: ReactNode;
    tone?: BadgeTone;
    className?: string;
}): JSX.Element {
    return (
        <span
            className={cn(
                'pill',
                // Not `primary`: that is now a near-black, which would make this pill grey and
                // indistinguishable from the neutral one beside it. The three tones have to stay
                // tellable apart — that is the whole point of the legend.
                tone === 'accent' && 'bg-info/10 text-info',
                tone === 'neutral' && 'bg-muted text-muted-foreground',
                tone === 'locked' && 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
                className,
            )}
        >
            {children}
        </span>
    );
}

/** 30px tall, 11.5px label — smaller than the default control, per these screens' scale. */
export function PanelButton(props: {
    children: ReactNode;
    onClick: () => void;
    disabled?: boolean;
    primary?: boolean;
    variant?: 'ghost' | 'outline';
    /** Match a control it stands beside (32px) instead of the 30px card-foot button. */
    inline?: boolean;
    className?: string;
}): JSX.Element {
    return (
        <Button
            type="button"
            variant={props.primary === true ? 'default' : (props.variant ?? 'outline')}
            className={cn(props.inline === true ? 'ctl shrink-0 font-semibold' : 'ctl-action', props.className)}
            onClick={props.onClick}
            disabled={props.disabled ?? false}
        >
            {props.children}
        </Button>
    );
}
