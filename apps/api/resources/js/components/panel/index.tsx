import CustomSelect from '../CustomSelect';
import { useTranslation } from '../../i18n';
import { Link } from '@inertiajs/react';
import { setPageHeader } from '../../lib/pageHeader';
import { iconForInputType } from '../../lib/inputIcons';
import { cloneElement, isValidElement, type MouseEvent, useEffect } from 'react';
import type { ComponentProps, JSX, ReactElement, ReactNode } from 'react';
import { CheckIcon, CircleHelp, Plus, SearchIcon, X } from 'lucide-react';

import { Button } from '../ui/button';
import { Card } from '../ui/card';
import { Input } from '../ui/input';
import { Label } from '../ui/label';
import { Tooltip, TooltipContent, TooltipTrigger } from '../ui/tooltip';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '../ui/dialog';
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
    /** An icon for the head's leading tile. A card without one still aligns, it just has no mark. */
    icon?: ReactNode;
    /**
     * A caveat about the card's contents, on a mark beside its title.
     *
     * For what a reader needs only if they ask — a retention window, a source's limits. `description`
     * is for what they need before reading the card at all; this is for what would otherwise become a
     * paragraph underneath it, where it is read last by the people it was written for and read every
     * time by everyone else.
     */
    help?: string;
    /** The body reaches the card's edges — for a table or a toolbar, which draw their own rules. */
    flush?: boolean;
    /** Right-hand side of the card head — an action, a switch, a status. */
    aside?: ReactNode;
    children: ReactNode;
    /** Left side of the foot: a short explanatory note or a count. */
    footNote?: ReactNode;
    /** Right side of the foot: the card's actions. */
    footActions?: ReactNode;
    /**
     * The whole foot, for something that lays itself out across the full width — a pager, which is
     * a summary at one end and controls at the other and has to own the space between them. Its
     * children become direct children of the foot's flex row, so passing it through `footNote`
     * instead nests them in a block and they stack.
     */
    foot?: ReactNode;
    className?: string;
}): JSX.Element {
    const hasFoot = props.footNote !== undefined || props.footActions !== undefined || props.foot !== undefined;

    return (
        <Card className={cn('panel gap-0 break-inside-avoid', props.flush === true && 'panel-flush', props.className)}>
            <div className="panel-head">
                <div className="flex min-w-0 items-center gap-2.5">
                    {props.icon !== undefined && <div className="panel-head-icon">{props.icon}</div>}
                    <div className="min-w-0">
                        <span className="flex min-w-0 items-center gap-1.5">
                            <h2 className="panel-title truncate">{props.title}</h2>
                            {props.help === undefined || props.help === '' ? null : (
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <span className="text-muted-foreground hover:text-foreground inline-flex shrink-0">
                                            <CircleHelp className="size-3.5" />
                                            <span className="sr-only">{props.help}</span>
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent side="bottom" className="max-w-80">
                                        {props.help}
                                    </TooltipContent>
                                </Tooltip>
                            )}
                        </span>
                        {props.description !== undefined && <p className="panel-subtitle">{props.description}</p>}
                    </div>
                </div>
                {/* A row with air in it: the aside usually holds two or three buttons, and with no gap
                    they met edge to edge and read as one segmented control rather than as separate
                    actions. */}
                {props.aside !== undefined && <div className="flex shrink-0 items-center gap-2">{props.aside}</div>}
            </div>

            {/* No body when there is nothing in it. A card whose children render to nothing — a hint shown
                only before an action, a list that is empty — otherwise draws an empty padded strip under its
                head, which reads as something that failed to load. */}
            {props.children === null || props.children === undefined || props.children === false ? null : (
                <div className="panel-body">{props.children}</div>
            )}

            {hasFoot && (
                <div className="panel-foot">
                    {props.foot ?? (
                        <>
                            <div className="text-muted-foreground min-w-0 text-[10px]">{props.footNote}</div>
                            <div className="flex shrink-0 items-center gap-2">{props.footActions}</div>
                        </>
                    )}
                </div>
            )}
        </Card>
    );
}

/**
 * What a card shows when it has nothing to show.
 *
 * A sentence of grey text was doing this job, and a sentence reads as "something failed" rather
 * than "there is nothing here yet". An empty state is a small composition: a mark, what is missing,
 * one line explaining how it gets filled, and the action that fills it — so the screen stays a
 * place you can start from instead of a dead end.
 *
 * Shared rather than per-page: every list in this app will need one, and they should be the same
 * shape so an empty documents list and an empty accounts list do not look like different products.
 */
export function EmptyState(props: {
    icon: ReactNode;
    title: string;
    description?: string;
    action?: ReactNode;
}): JSX.Element {
    return (
        <div className="flex flex-col items-center px-6 py-12 text-center">
            <div className="bg-secondary text-muted-foreground grid size-11 place-items-center rounded-2xl">
                {props.icon}
            </div>
            <p className="mt-3 text-sm font-semibold">{props.title}</p>
            {props.description !== undefined && (
                <p className="text-muted-foreground mt-1 max-w-sm text-xs leading-5">{props.description}</p>
            )}
            {props.action !== undefined && <div className="mt-4">{props.action}</div>}
        </div>
    );
}

/**
 * A row of related actions, joined into one control.
 *
 * Distinct from {@link ButtonGroup} above, which is a segmented *toggle*: that one picks a value
 * and reports `aria-pressed`. This one runs commands — review or delete a row, rename or file a
 * document — and nothing stays pressed afterwards.
 *
 * They share `.btn-group`'s joining, so a group of actions and a group of choices sit on the same
 * grid and round the same way. Each segment is a real `PanelButton`, so hover, focus, disabled and
 * the control height come from the same place as every other button in the app rather than from a
 * parallel set of rules.
 *
 * Drawn at `.ctl-action`'s size rather than a control's: a row action is not something you fill in,
 * and at full control height the pair at the end of every row carried the same weight as the data it
 * acts on. The group's own rounding still wins — `.btn-group > *` zeroes each member's — so only the
 * height and the label size come from here.
 *
 * An action may navigate instead of firing a handler — a link and a button look identical here and
 * must, because "open this" and "delete this" sit side by side and read as one set.
 */
export function ActionGroup(props: {
    actions: Array<{
        key: string;
        label: ReactNode;
        icon?: ReactNode;
        /** Colours the icon only. Whole-button colour would break the group into separate objects. */
        tone?: 'primary' | 'destructive';
        /** Shown instead of the label when the label is an icon alone. */
        title?: string;
        onClick?: () => void;
        href?: string;
        disabled?: boolean;
    }>;
    className?: string;
}): JSX.Element {
    return (
        <div className={cn('btn-group', props.className)} role="group">
            {props.actions.map((action) => {
                const content = (
                    <>
                        {action.icon}
                        {action.label}
                    </>
                );

                return action.href === undefined ? (
                    <Button
                        key={action.key}
                        type="button"
                        variant="ghost"
                        className="ctl-action"
                        data-tone={action.tone}
                        aria-label={action.title}
                        disabled={action.disabled ?? false}
                        onClick={action.onClick}
                    >
                        {content}
                    </Button>
                ) : (
                    <Button key={action.key} asChild variant="ghost" className="ctl-action" data-tone={action.tone}>
                        <Link href={action.href} aria-label={action.title}>
                            {content}
                        </Link>
                    </Button>
                );
            })}
        </div>
    );
}

/** The outer wrapper every screen uses: a vertical rhythm plus the page heading. */
export function Page(props: {
    title: string;
    lede?: string;
    /**
     * What narrows the view — a year, a period, a search. Left of the row, because a reader chooses what they
     * are looking at before they do anything to it.
     */
    filters?: ReactNode;
    /** What acts on it. Right of the row, where every screen in the app puts its verbs. */
    actions?: ReactNode;
    children: ReactNode;
}): JSX.Element {
    // The title and lede are published to the shell's topbar rather than drawn here — one heading
    // per screen, in the same place on every screen. `actions` stay with the content, where they
    // act on what is below them.
    useEffect(() => {
        setPageHeader({ title: props.title, description: props.lede });

        return () => {
            setPageHeader(null);
        };
    }, [props.title, props.lede]);

    return (
        <div className="page-stack">
            {/*
                One toolbar, filters left and actions right, so a reader finds the same two things in the same
                two places on every screen. Each side is its own flex row: without that the children are
                block-level and a select stretches to the page's full width with the button stranded below it.
            */}
            {(props.filters !== undefined || props.actions !== undefined) && (
                <header className="page-head">
                    <div className="flex flex-wrap items-center gap-2">{props.filters}</div>
                    <div className="flex flex-wrap items-center gap-2">{props.actions}</div>
                </header>
            )}

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
 * Label, optional leading mark, and hint, arranged identically for every control.
 *
 * The mark goes *before* the label, never after it: at the row's right edge the marks land at
 * ragged positions set by each label's length, while a leading column of them lines up down the
 * card and can be scanned without reading a single label.
 */
export function Field(props: {
    label: string;
    /** A validation message for this field. Replaces the hint and marks the control invalid. */
    error?: string | undefined;
    /** A small state indicator shown *before* the label — provenance, a lock. */
    mark?: ReactNode;
    /**
     * An explanation, behind a mark beside the label rather than set under the control.
     *
     * A sentence under every field turns a form of eight into a wall of prose, and the one field a
     * person is actually unsure about is no easier to find. Use `hint` where the text must be read
     * before answering, and `help` where it answers a question a few people will have.
     */
    help?: string;
    /** Appended to the label in parentheses, e.g. `MB`. Never rendered inside the input: the unit
     *  would collide with a number input's spinner arrows. */
    unit?: string | undefined;
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
                {/* The mark sits beside the label's own text, not out at the field's right edge: it
                    explains this label, and a question mark a column away belongs to nothing. */}
                <span className="flex min-w-0 flex-1 items-center gap-1.5">
                    <Label htmlFor={props.htmlFor} className="field-label truncate">
                        {props.unit !== undefined && props.unit !== '' ? `${props.label} (${props.unit})` : props.label}
                    </Label>
                    {props.help === undefined || props.help === '' ? null : (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <span className="text-muted-foreground hover:text-foreground inline-flex shrink-0">
                                    <CircleHelp className="size-3.5" />
                                    <span className="sr-only">{props.help}</span>
                                </span>
                            </TooltipTrigger>
                            <TooltipContent side="top" className="max-w-72">
                                {props.help}
                            </TooltipContent>
                        </Tooltip>
                    )}
                </span>
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

export function Metric(props: {
    label: string;
    value: string;
    /** A small state indicator shown *before* the label — provenance, a lock. As in {@link Field},
     *  so a tile and a field carry the same mark in the same place. */
    mark?: ReactNode;
}): JSX.Element {
    return (
        <div className="metric bg-muted/30 border">
            <div className="flex items-center gap-1.5">
                {props.mark}
                <span className="metric-label truncate">{props.label}</span>
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
    icon: passedIcon,
    iconPosition = 'leading',
    affix,
    addonStart,
    addonEnd,
    actionStart,
    actionEnd,
    ...props
}: ComponentProps<typeof Input> & {
    /** Omit to take the icon the field's `type` implies; `null` for none at all. */
    icon?: ReactNode | null;
    iconPosition?: 'leading' | 'trailing';
    affix?: ReactNode;
    /** A static cell fused to the field — a scheme, a currency, a unit. */
    addonStart?: ReactNode;
    addonEnd?: ReactNode;
    /** An interactive cell fused to the field — a button, a select. Pass the control itself. */
    actionStart?: ReactNode;
    actionEnd?: ReactNode;
}): JSX.Element {
    // The type's own icon unless the caller named one. `icon={null}` is the opt-out, for a field
    // whose type would suggest something misleading.
    const icon = passedIcon === undefined ? iconForInputType(props.type) : (passedIcon ?? undefined);

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
    /** Fills its field and splits evenly — a choice in a form, not a control in a toolbar. */
    stretch?: boolean;
    disabled?: boolean;
    className?: string;
}): JSX.Element {
    return (
        <div className={cn('btn-group', props.stretch === true && 'w-full', props.className)} role="group">
            {props.options.map((option) => {
                const pressed = option.value === props.value;

                return (
                    <Button
                        key={option.value}
                        type="button"
                        variant={pressed ? 'secondary' : 'ghost'}
                        aria-pressed={pressed}
                        disabled={props.disabled}
                        title={option.title}
                        className={cn('ctl px-2.5 font-semibold', props.stretch === true && 'flex-1')}
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
    /**
     * The clearing row's own mark and hint.
     *
     * Without them that row is the one option in the list with no icon, which leaves a ragged
     * column and — since the trigger shows the chosen option's mark — a control that loses its mark
     * whenever the filter is off. "No filter" is a choice like any other and looks like one.
     */
    emptyIcon?: ReactNode;
    emptyDescription?: string;
    /**
     * `label` is what the menu shows; `shortLabel`, where given, is what a chip shows.
     *
     * The two answer different questions. Choosing a column wants whatever tells it from its
     * neighbour — "Amount (RON) · number -1234.56" against "Balance (RON) · number 1234.56" — and a
     * chip only has to say which was chosen.
     */
    options: Array<{ value: string; label: string; shortLabel?: string; description?: string; icon?: ReactNode }>;
    /** How many characters a chip shows before it is cut. The full text stays on hover. */
    chipMaxChars?: number;
    /** Shows a search box above the list. Worth it past roughly a dozen options, not before. */
    searchable?: boolean;
    /** `tile` sets each option's icon in a rounded square; `bare` draws it as-is, for a dot. */
    optionVisual?: 'tile' | 'bare';
    /** One mark for the control itself, whatever is chosen — see `CustomSelect`. */
    icon?: ReactNode;
    id?: string;
    /** A select the viewer may read but not change — the same meaning `disabled` has on an input. */
    disabled?: boolean;
    className?: string;
    'aria-label'?: string;
}): JSX.Element {
    const { t } = useTranslation();

    // The clear-selection row is an option like any other, so it can be searched past and arrowed
    // through rather than being a special case the keyboard has to know about.
    const options =
        props.emptyLabel === undefined
            ? props.options
            : [
                  {
                      value: NO_SELECTION,
                      label: props.emptyLabel,
                      ...(props.emptyIcon === undefined ? {} : { icon: props.emptyIcon }),
                      ...(props.emptyDescription === undefined ? {} : { description: props.emptyDescription }),
                  },
                  ...props.options,
              ];

    return (
        <CustomSelect
            options={options}
            value={props.value === undefined || props.value === '' ? NO_SELECTION : props.value}
            onChange={(value) => {
                props.onValueChange(value === null || value === NO_SELECTION ? '' : String(value));
            }}
            {...(props.placeholder === undefined ? {} : { placeholder: props.placeholder })}
            {...(props.id === undefined ? {} : { id: props.id })}
            {...(props.className === undefined ? {} : { className: props.className })}
            {...(props['aria-label'] === undefined ? {} : { 'aria-label': props['aria-label'] })}
            disabled={props.disabled ?? false}
            searchable={props.searchable ?? false}
            optionVisual={props.optionVisual ?? 'bare'}
            {...(props.icon === undefined ? {} : { icon: props.icon })}
            searchPlaceholder={t((d) => d.common.search)}
            emptyText={t((d) => d.common.noResults)}
        />
    );
}

/**
 * Several options in one control, held as removable chips with the picker at the end of the row.
 *
 * ## Why not a column of selects
 *
 * Because that is what it replaced. A field that may name three places drew three full-width selects
 * stacked downward, each nearly identical to the last, pushing everything below it out of alignment —
 * and a form with four such fields grew unpredictably as it was filled in. Here a field is one
 * control whatever it holds.
 *
 * ## `ordered`
 *
 * Off by default, because for most multiple choices the order is the option list's, not the order
 * somebody happened to click: picking A then B is the same answer as B then A.
 *
 * On where the order *is* the answer. The statement import reads a field's places in turn until one
 * has something in it, so "first this column, then that one" is a different instruction from its
 * reverse — the chips are numbered for that reason, and a new pick goes on the end. There is no
 * rearranging: with a ceiling of a few places, removing one and picking it again is the whole of it,
 * and drag handles or a pair of arrows on every chip would put back the clutter this removes.
 *
 * ## Picking toggles
 *
 * Every option stays in the menu with a tick against the chosen ones, and clicking one that is
 * already chosen removes it. That is the ordinary multi-select gesture, and it makes naming the same
 * option twice unrepresentable rather than merely discouraged.
 */
export function PanelMultiSelect(props: {
    values: string[];
    onChange: (next: string[]) => void;
    /**
     * `label` is what the menu shows and `shortLabel`, where given, is what a chip shows.
     *
     * They differ because the two places answer different questions. Choosing a column wants
     * everything that tells it from its neighbour — "Amount (RON) · number -1234.56" against
     * "Balance (RON) · number 1234.56" — and a chip only has to say which one was chosen. Putting the
     * long form on a chip means every chip is cut off at the same useless point.
     */
    options: Array<{ value: string; label: string; shortLabel?: string; description?: string; icon?: ReactNode }>;
    /** How many characters a chip shows before it is cut. The full text stays on hover. */
    chipMaxChars?: number;
    /** Numbers the chips and keeps them in the order they were picked. See the docblock. */
    ordered?: boolean;
    /** How many may be chosen. The picker goes away at the ceiling rather than refusing a click. */
    max?: number;
    /** The picker's own label, e.g. "Add column". */
    addLabel: string;
    /** Shown in the picker's place once `max` is reached, e.g. "4 of 4". */
    fullLabel?: string;
    /** Named on each chip's remove button, for a screen reader. */
    removeLabel: string;
    placeholder?: string;
    searchable?: boolean;
    disabled?: boolean;
    id?: string;
    'aria-label'?: string;
}): JSX.Element {
    const { t } = useTranslation();
    const ordered = props.ordered ?? true;
    const disabled = props.disabled ?? false;
    const full = props.max !== undefined && props.values.length >= props.max;

    const labelFor = (value: string): string => {
        const option = props.options.find((one) => one.value === value);

        return option?.shortLabel ?? option?.label ?? value;
    };

    /**
     * A chip's text, cut to a fixed number of characters.
     *
     * CSS truncation does nothing here: the chips wrap, and a wrapped flex item moves to the next
     * line at its natural width rather than shrinking, so `text-overflow` is never reached. Cutting
     * the string is what actually keeps a row of chips a row. The full text stays in `title`.
     */
    const chipText = (value: string): string => {
        const full = labelFor(value);
        const max = props.chipMaxChars ?? 12;

        return full.length <= max ? full : `${full.slice(0, max).trimEnd()}…`;
    };

    // Kept in the order they were picked where that matters, and in the options' own order where it
    // does not — so an unordered control shows the same chips however they were clicked.
    const chips = ordered
        ? props.values
        : props.options.map((option) => option.value).filter((value) => props.values.includes(value));

    const toggle = (value: string): void => {
        if (props.values.includes(value)) {
            props.onChange(props.values.filter((one) => one !== value));

            return;
        }

        // The ceiling is enforced here, not only by hiding the button. The menu opens from anywhere
        // on the control, so an affordance that merely disappears stops nobody: it was still possible
        // to click past the limit and keep adding.
        if (full) {
            return;
        }

        props.onChange([...props.values, value]);
    };

    return (
        <CustomSelect
            options={props.options}
            value={null}
            disabled={disabled || (full && props.values.length === 0)}
            searchable={props.searchable ?? false}
            searchPlaceholder={t((d) => d.common.search)}
            emptyText={t((d) => d.common.noResults)}
            // Closes on a pick, like any other select. Staying open to collect several read as the
            // control being stuck: the menu covers the chips, so the one thing a person wants to see
            // after choosing — that it landed, and where in the order — is hidden behind it.
            closeOnSelect
            {...(props.id === undefined ? {} : { id: props.id })}
            {...(props['aria-label'] === undefined ? {} : { 'aria-label': props['aria-label'] })}
            onChange={(value) => {
                if (value !== null) {
                    toggle(String(value));
                }
            }}
            renderOption={(option) => (
                <span className="flex min-w-0 flex-1 items-center gap-2">
                    {/* A tick against what is already chosen, and clicking it again removes it —
                        the ordinary multi-select gesture, which is also what makes naming the same
                        option twice unrepresentable. */}
                    <CheckIcon
                        className={cn(
                            'size-3.5 shrink-0',
                            props.values.includes(String(option.value)) ? '' : 'opacity-0',
                        )}
                        aria-hidden="true"
                    />
                    <span className="min-w-0 flex-1 truncate text-left">{option.label}</span>
                </span>
            )}
            // No chevron: the "add" button on the right says what clicking does more plainly than an
            // arrow would, and two affordances on one control is one too many.
            hideChevron
            className="ctl-multi"
            renderTrigger={() => (
                <span className="flex w-full items-start justify-between gap-2">
                    <span className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
                        {chips.length === 0 && props.placeholder !== undefined ? (
                            <span className="text-muted-foreground inline-flex h-6 items-center">
                                {props.placeholder}
                            </span>
                        ) : null}

                        {chips.map((value, index) => (
                            <span
                                key={value}
                                className="bg-secondary text-secondary-foreground border-border inline-flex h-6 min-w-0 shrink items-center gap-1.5 rounded-lg border px-2 text-xs font-semibold"
                            >
                                {/* The position, as its own mark rather than loose text: for these
                                    fields the order is the instruction, and a number that reads as
                                    part of the label is a number nobody sees. */}
                                {ordered ? (
                                    <span className="bg-background text-muted-foreground border-border rounded border px-1 text-[10px] leading-4 font-normal tabular-nums">
                                        {index + 1}
                                    </span>
                                ) : null}

                                <span className="min-w-0 flex-1" title={labelFor(value)}>
                                    {chipText(value)}
                                </span>

                                {disabled ? null : (
                                    <span
                                        role="button"
                                        tabIndex={-1}
                                        aria-label={props.removeLabel}
                                        // `mousedown` is what opens the menu, so it is stopped here
                                        // as well as the click: removing a chip should not also drop
                                        // the list open over the control.
                                        onMouseDown={(event) => {
                                            event.preventDefault();
                                            event.stopPropagation();
                                        }}
                                        onClick={(event) => {
                                            event.stopPropagation();
                                            toggle(value);
                                        }}
                                        className="text-muted-foreground hover:text-destructive grid size-4 shrink-0 cursor-pointer place-items-center"
                                    >
                                        <X className="size-3" />
                                    </span>
                                )}
                            </span>
                        ))}
                    </span>

                    {disabled ? null : full ? (
                        // Saying the limit is reached, rather than removing the control's one
                        // affordance and leaving a person to wonder where it went.
                        <span className="text-muted-foreground inline-flex h-6 shrink-0 items-center text-xs tabular-nums">
                            {props.fullLabel ?? ''}
                        </span>
                    ) : (
                        <span className="text-info border-border inline-flex h-6 shrink-0 items-center gap-1 rounded-lg border border-dashed px-2 text-xs font-semibold">
                            <Plus className="size-3" aria-hidden="true" />
                            {props.addLabel}
                        </span>
                    )}
                </span>
            )}
        />
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
    /**
     * Whether Escape may close this.
     *
     * `true` by default. Set false while work is moving that the dialog is the only view of — an
     * upload in progress, say: dismissing it silently is how a person concludes something finished
     * when it did not. The dialog still closes, through whatever control the screen provides.
     *
     * A click outside closes this only when `dismissOnOutsideClick` says so. See the handler below.
     */
    dismissable?: boolean;
    /**
     * Whether clicking the page behind closes this.
     *
     * `false` by default, and that default is the important half: most dialogs here hold a form — a
     * transaction being split, a task being run with arguments — and a stray click is not a decision to
     * throw that away.
     *
     * `true` for a dialog that only *shows* something. A history, a preview, a detail panel: there is
     * nothing to lose, and refusing to close is then just a dialog that will not go away.
     */
    dismissOnOutsideClick?: boolean;
}): JSX.Element {
    const dismissable = props.dismissable !== false;

    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            <DialogContent
                className={cn('dialog-panel', `dialog-${props.size ?? 'md'}`)}
                /*
                 * A click outside closes nothing unless the dialog asked for it.
                 *
                 * Most of these hold forms — a transaction being split, a party being merged, a task
                 * being run with arguments — and a stray click on the page behind is not a decision to
                 * throw that away. Escape and the close control are: one is a key pressed on purpose,
                 * the other is a button that says what it does. A dialog that only shows something has
                 * nothing to lose and opts in.
                 */
                onPointerDownOutside={(event) => {
                    if (props.dismissOnOutsideClick !== true) {
                        event.preventDefault();
                    }
                }}
                onEscapeKeyDown={(event) => {
                    if (!dismissable) {
                        event.preventDefault();
                    }
                }}
            >
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

export function Stat(props: {
    label: string;
    value: ReactNode;
    sub?: ReactNode;
    /** A modifier on the tile — `stat-primary` for the one figure a page is about. Not a free-for-all: the
     *  padding, radius and border stay the component's. */
    className?: string;
    /** A mark for what the figure is about, set against the label rather than beside the number. */
    icon?: ReactNode;
    /** Colours the mark only — the figure itself stays in the text colour, because a green number
     *  and a green tile would say the same thing twice and leave nothing for a bad one to say. */
    tone?: 'success' | 'destructive' | 'accent' | 'neutral';
}): JSX.Element {
    return (
        <div className={`stat${props.className === undefined ? '' : ` ${props.className}`}`}>
            {props.icon === undefined ? (
                <div className="stat-label">{props.label}</div>
            ) : (
                <div className="stat-head">
                    <div className="stat-label">{props.label}</div>
                    <span
                        className={cn(
                            'stat-mark',
                            props.tone === 'success' && 'bg-success/10 text-success',
                            props.tone === 'destructive' && 'bg-destructive/10 text-destructive',
                            props.tone === 'accent' && 'bg-info/10 text-info',
                            (props.tone === 'neutral' || props.tone === undefined) && 'bg-muted text-muted-foreground',
                        )}
                    >
                        {props.icon}
                    </span>
                </div>
            )}
            <div className="stat-value">{props.value}</div>
            {props.sub !== undefined ? <div className="stat-sub">{props.sub}</div> : null}
        </div>
    );
}

/** The filter bar that sits above a table, inside the same bordered panel. */
/**
 * Several readings of one panel, one of them current.
 *
 * Distinct from {@link ButtonGroup}, which is a joined set of *actions*, and from {@link Tabs}'
 * nearest miss — a row of plain buttons, which gives a reader nothing to tell the current one by
 * except its colour. This is the inset track with the current reading lifted out of it.
 *
 * `role="tablist"` with `aria-selected` rather than `aria-pressed`: these are views of one thing,
 * not switches that are each on or off, and a reader is told which of the set is showing.
 */
export function Tabs<T extends string>(props: {
    value: T;
    onChange: (value: T) => void;
    options: Array<{ value: T; label: ReactNode; icon?: ReactNode }>;
    className?: string;
}): JSX.Element {
    return (
        <div className={cn('tabs', props.className)} role="tablist">
            {props.options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    role="tab"
                    className="tab"
                    aria-selected={option.value === props.value}
                    onClick={() => {
                        props.onChange(option.value);
                    }}
                >
                    {option.icon}
                    {option.label}
                </button>
            ))}
        </div>
    );
}

export function Toolbar({ children }: { children: ReactNode }): JSX.Element {
    return <div className="toolbar">{children}</div>;
}

/**
 * A toolbar whose controls do more than one job, split into labelled rows.
 *
 * For the case `Toolbar` handles badly: transactions had nine controls in one wrapping line — a view
 * to choose, six filters, a reset, and a tag manager that filters nothing. They wrapped wherever
 * they fitted, so choosing a view and narrowing a list happened on whichever line the browser
 * decided, and nothing told a person which control did which.
 *
 * `Toolbar` stays as it is for the screens with one row of one kind of control, which is most of
 * them.
 */
export function ToolbarRows({ children }: { children: ReactNode }): JSX.Element {
    return <div className="toolbar-rows">{children}</div>;
}

/**
 * One row of a {@see ToolbarRows}, named by what its controls do.
 *
 * `end` is for the controls that are not what the row is about — a row of filters still has to put
 * "manage tags" and the bulk actions somewhere, and the far end is where they stop reading as
 * filters.
 */
export function ToolbarRow(props: { label?: string; children: ReactNode; end?: ReactNode }): JSX.Element {
    return (
        <div className="toolbar-row">
            {/* Only where the row needs naming. A line holding one search box explains itself, and an
                eyebrow in front of it is a word to read before the thing it labels. */}
            {props.label === undefined ? null : <span className="toolbar-label">{props.label}</span>}
            {props.children}
            {props.end === undefined ? null : <div className="toolbar-end">{props.end}</div>}
        </div>
    );
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
export function DetailList({ children, wide }: { children: ReactNode; wide?: boolean }): JSX.Element {
    return <dl className={cn('detail-grid', wide === true && 'detail-grid-wide')}>{children}</dl>;
}

export function Detail(props: { label: string; children: ReactNode; icon?: ReactNode }): JSX.Element {
    return (
        <>
            <dt>
                {props.icon}
                <span className="truncate">{props.label}</span>
            </dt>
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
    /** The event comes with it: an action inside a card that is itself clickable stops it there. */
    onClick: (event: MouseEvent<HTMLButtonElement>) => void;
    disabled?: boolean;
    primary?: boolean;
    variant?: 'ghost' | 'outline';
    /** Match a control it stands beside (32px) instead of the 30px card-foot button. */
    inline?: boolean;
    /** The hover text an icon-only button needs, since its label is a picture. */
    title?: string;
    /** For a button that is on or off, so a reader is told which rather than shown a shade. */
    'aria-pressed'?: boolean;
    'aria-expanded'?: boolean;
    className?: string;
}): JSX.Element {
    return (
        <Button
            type="button"
            variant={props.primary === true ? 'default' : (props.variant ?? 'outline')}
            {...(props.title === undefined ? {} : { title: props.title })}
            {...(props['aria-pressed'] === undefined ? {} : { 'aria-pressed': props['aria-pressed'] })}
            {...(props['aria-expanded'] === undefined ? {} : { 'aria-expanded': props['aria-expanded'] })}
            className={cn(props.inline === true ? 'ctl shrink-0 font-semibold' : 'ctl-action', props.className)}
            onClick={props.onClick}
            disabled={props.disabled ?? false}
        >
            {props.children}
        </Button>
    );
}

/**
 * The figure tiles a page leads with, and the mark that says what each is about.
 *
 * Moved out of the dashboard, which had them, when the taxes screen needed the same row. Two pages showing the
 * same kind of figure should show it the same way — the alternative was a second tile with the mark on the
 * other side of the label, which is how an application stops looking like one application.
 */
/** The colour an icon badge carries: what kind of figure sits beside it, never how good it is. */
export type Tone = 'brand' | 'success' | 'amber' | 'info';

const TONE_CLASS: Record<Tone, string> = {
    brand: 'bg-accent text-accent-foreground',
    success: 'bg-success/10 text-success',
    amber: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    info: 'bg-info/10 text-info',
};

export function IconBadge({ tone, children }: { tone: Tone; children: ReactNode }): JSX.Element {
    return (
        <span className={cn('grid size-8 shrink-0 place-items-center rounded-lg', TONE_CLASS[tone])}>{children}</span>
    );
}

/** One figure beside the headline: a coloured mark saying what kind it is, the value, and its detail. */
export function Kpi(props: {
    tone: Tone;
    icon: ReactNode;
    label: string;
    value: string;
    sub?: string | undefined;
}): JSX.Element {
    return (
        <div className="border-border bg-card shadow-hair rounded-xl border p-4">
            <div className="text-muted-foreground flex items-center gap-2.5 text-xs">
                <IconBadge tone={props.tone}>{props.icon}</IconBadge>
                {props.label}
            </div>
            <div className="mt-3 text-xl font-bold tracking-tight tabular-nums">{props.value}</div>
            {props.sub === undefined || props.sub === '' ? null : <div className="note mt-1">{props.sub}</div>}
        </div>
    );
}

/**
 * A stacked row's label: the column's name, with a mark in front of it.
 *
 * `data-label` draws the name alone, in small capitals, and a phone full of them reads as a wall of words.
 * The mark gives each row something to find it by at a glance — a clock for a duration, a pulse for a state
 * — while the name stays, because an icon on its own is not a label, it is a picture of one.
 *
 * Shows only where the table is stacked. On a wide screen the column heading already says this, and a second
 * copy in every cell is what the stacked layout exists to avoid.
 */
export function CellLabel({ icon, children }: { icon: ReactNode; children: string }): JSX.Element {
    return (
        <span className="cell-label">
            {icon}
            {children}
        </span>
    );
}
