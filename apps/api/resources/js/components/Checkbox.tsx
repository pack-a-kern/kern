import { useEffect, useRef, type JSX } from 'react';
import { cn } from '@/lib/utils';

/**
 * A checkbox that can be half-checked.
 *
 * `indeterminate` is not an attribute — it exists only as a DOM property, so React cannot set it
 * declaratively and it has to be written to the node after every render. That is the whole reason
 * this wrapper exists: a select-all needs three states ("none", "some", "all"), and without the
 * third one a partly-selected page shows an empty box and claims nothing is selected.
 *
 * Deliberately a real `<input type="checkbox">` rather than the vendor component, which renders a
 * styled button and drops the third state along with the native keyboard behaviour.
 */
export default function Checkbox({
    checked,
    indeterminate = false,
    onCheckedChange,
    className,
    ...rest
}: {
    checked: boolean;
    /** "Some but not all" — takes visual precedence over `checked`, as the DOM does. */
    indeterminate?: boolean;
    onCheckedChange: (checked: boolean) => void;
    className?: string;
    'aria-label': string;
    disabled?: boolean;
}): JSX.Element {
    const ref = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (ref.current !== null) {
            ref.current.indeterminate = indeterminate;
        }
    }, [indeterminate]);

    return (
        <input
            ref={ref}
            type="checkbox"
            className={cn('checkbox', className)}
            checked={checked}
            onChange={(event) => {
                onCheckedChange(event.target.checked);
            }}
            {...rest}
        />
    );
}
