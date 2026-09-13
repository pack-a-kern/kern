import type { JSX } from 'react';
import { DatePicker, type DateRangeValue } from './DatePicker';
import { useTranslation } from '../i18n';

/**
 * The date picker, with this application's defaults already applied.
 *
 * Screens ask for a date, not for a size and a week-start and a locale. Those answers are the same
 * everywhere in TaxOS, so they live here: repeating them at every call site is how two filters end
 * up looking different, and how changing the house style becomes a sweep instead of an edit.
 *
 * Defaults come from the build environment so a deployment can differ without a code change —
 * `VITE_DATEPICKER_SIZE` and `VITE_DATEPICKER_COMPACT`, the same shape `lib/palette.ts` uses for
 * the default palette. An unrecognised value falls back rather than leaving a control unstyled.
 */
const SIZE = import.meta.env.VITE_DATEPICKER_SIZE === 'md' ? 'md' : 'sm';

const COMPACT = import.meta.env.VITE_DATEPICKER_COMPACT !== 'false';

/**
 * Monday, not Sunday.
 *
 * Romanian calendars start the week on Monday, and a fiscal week that begins on Sunday is a week
 * this product's users do not have. Not derived from the locale: `Intl` still reports Sunday for
 * several locales this app can be read in.
 */
const WEEK_STARTS_ON = 1;

export function PanelDate(props: {
    value: string | null;
    onChange: (value: string | null) => void;
    minDate?: string;
    maxDate?: string;
    /** Refuses anything after today — a booking date, a payment received. */
    disableFuture?: boolean;
    /** Refuses anything before today — a maturity, a deadline. */
    disablePast?: boolean;
    /** Full width, for a field in a form rather than a filter in a toolbar. */
    block?: boolean;
    disabled?: boolean;
    className?: string;
    title?: string;
}): JSX.Element {
    const { locale } = useTranslation();

    return (
        <DatePicker
            mode="single"
            size={SIZE}
            compact={COMPACT}
            weekStartsOn={WEEK_STARTS_ON}
            locale={locale}
            showApply={false}
            value={props.value}
            onChange={props.onChange}
            {...(props.minDate === undefined ? {} : { minDate: props.minDate })}
            {...(props.maxDate === undefined ? {} : { maxDate: props.maxDate })}
            {...(props.disableFuture === undefined ? {} : { disableFuture: props.disableFuture })}
            {...(props.disablePast === undefined ? {} : { disablePast: props.disablePast })}
            {...(props.disabled === undefined ? {} : { disabled: props.disabled })}
            className={[props.block === true ? 'w-full' : '', props.className ?? ''].join(' ').trim()}
            triggerClassName={props.block === true ? 'w-full justify-between' : ''}
            {...(props.title === undefined ? {} : { title: props.title })}
        />
    );
}

export function PanelDateRange(props: {
    value: DateRangeValue;
    onChange: (value: DateRangeValue) => void;
    minDate?: string;
    maxDate?: string;
    /** Refuses anything after today — a booking date, a payment received. */
    disableFuture?: boolean;
    /** Refuses anything before today — a maturity, a deadline. */
    disablePast?: boolean;
    /** Full width, for a field in a form rather than a filter in a toolbar. */
    block?: boolean;
    disabled?: boolean;
    className?: string;
    title?: string;
}): JSX.Element {
    const { locale } = useTranslation();

    return (
        <DatePicker
            mode="range"
            size={SIZE}
            compact={COMPACT}
            presets
            weekStartsOn={WEEK_STARTS_ON}
            locale={locale}
            value={props.value}
            onChange={props.onChange}
            {...(props.minDate === undefined ? {} : { minDate: props.minDate })}
            {...(props.maxDate === undefined ? {} : { maxDate: props.maxDate })}
            {...(props.disableFuture === undefined ? {} : { disableFuture: props.disableFuture })}
            {...(props.disablePast === undefined ? {} : { disablePast: props.disablePast })}
            {...(props.disabled === undefined ? {} : { disabled: props.disabled })}
            className={[props.block === true ? 'w-full' : '', props.className ?? ''].join(' ').trim()}
            triggerClassName={props.block === true ? 'w-full justify-between' : ''}
            {...(props.title === undefined ? {} : { title: props.title })}
        />
    );
}
