import { useEffect, useId, useRef, useState, type JSX } from 'react';
import { UserIcon } from 'lucide-react';

import { apiRequest } from '../../lib/http';
import { useTranslation } from '../../i18n';
import { PanelInput } from '../panel';

/**
 * Who the money went to or came from: type it, or take one the ledger already knows.
 *
 * ## Why a search rather than a list
 *
 * A taxpayer with a few statements imported has thousands of counterparties, so there is no list to
 * put in a dropdown and no datalist worth sending — which is how the tags field gets away with one,
 * and why this cannot. The names are also encrypted, so matching happens on the server over the
 * blind index; nothing here can filter a list it was given.
 *
 * ## Why it stays a text box
 *
 * Typing a name that does not exist yet must keep working: the first payment to anybody is a name
 * nobody has used before, and a picker that only offers what exists would make the ordinary case
 * the awkward one. So the matches are an offer beside what is typed, never a constraint on it —
 * the same stance the tags field takes, reached differently.
 *
 * Picking one matters beyond saving keystrokes: a name spelled as the ledger already spells it
 * carries the party's remembered category onto this transaction, where a near-miss spelling starts
 * a second party that has to be merged later.
 */
export default function CounterpartyInput({
    value,
    onChange,
    subjectId,
    disabled = false,
    id,
}: {
    value: string;
    onChange: (next: string) => void;
    subjectId: string | null;
    disabled?: boolean;
    id?: string;
}): JSX.Element {
    const { t } = useTranslation();
    const [matches, setMatches] = useState<string[]>([]);
    const [open, setOpen] = useState(false);
    const listId = useId();
    // What the last search asked for, so picking a match does not immediately search for it again.
    const settled = useRef<string>('');

    useEffect(() => {
        const typed = value.trim();

        if (subjectId === null || typed.length < 2 || typed === settled.current) {
            setMatches([]);

            return;
        }

        // Late enough that a name is not searched letter by letter, short enough that the matches
        // are there by the time somebody looks up from the keyboard.
        const timer = window.setTimeout(() => {
            const params = new URLSearchParams({ q: typed, per_page: '8' });

            apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/wallet/counterparties?${params.toString()}`).then(
                (text: string) => {
                    const body: unknown = JSON.parse(text);
                    const rows =
                        typeof body === 'object' && body !== null
                            ? (body as { counterparties?: unknown }).counterparties
                            : null;

                    setMatches(
                        (Array.isArray(rows) ? rows : [])
                            .map((row): string =>
                                typeof row === 'object' &&
                                row !== null &&
                                typeof (row as { name?: unknown }).name === 'string'
                                    ? (row as { name: string }).name
                                    : '',
                            )
                            .filter((name) => name !== '' && name.toLowerCase() !== typed.toLowerCase())
                            .slice(0, 8),
                    );
                },
                () => {
                    // A search that fails costs the offer and nothing else: the name typed is still
                    // the answer, and saying so would interrupt somebody mid-word.
                    setMatches([]);
                },
            );
        }, 250);

        return () => {
            window.clearTimeout(timer);
        };
    }, [value, subjectId]);

    const choose = (name: string): void => {
        settled.current = name;
        onChange(name);
        setMatches([]);
        setOpen(false);
    };

    return (
        <div className="relative">
            <PanelInput
                id={id}
                value={value}
                disabled={disabled}
                icon={<UserIcon className="size-3.5" />}
                autoComplete="off"
                role="combobox"
                aria-expanded={open && matches.length > 0}
                aria-controls={listId}
                onChange={(event) => {
                    settled.current = '';
                    onChange(event.target.value);
                    setOpen(true);
                }}
                onFocus={() => {
                    setOpen(true);
                }}
                // On a blur rather than a click-outside listener: the list is inside this element,
                // and a pointerdown on a match fires before the blur that would hide it.
                onBlur={() => {
                    window.setTimeout(() => {
                        setOpen(false);
                    }, 120);
                }}
            />

            {!open || matches.length === 0 ? null : (
                <ul id={listId} role="listbox" className="suggest-list">
                    {matches.map((name) => (
                        <li key={name}>
                            <button
                                type="button"
                                role="option"
                                aria-selected={false}
                                className="suggest-option"
                                onMouseDown={(event) => {
                                    // Before the blur, so the pick lands rather than the list closing.
                                    event.preventDefault();
                                    choose(name);
                                }}
                            >
                                {name}
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {!open || matches.length === 0 ? null : (
                <span className="sr-only" aria-live="polite">
                    {t((d) => d.pages.walletTransactions.form.counterpartyMatches).replace(
                        '{count}',
                        String(matches.length),
                    )}
                </span>
            )}
        </div>
    );
}
