import { X } from 'lucide-react';
import { useId, useState, type JSX, type KeyboardEvent } from 'react';

import { useTranslation } from '../../i18n';
import { cn } from '../../lib/utils';
import type { Tag } from '../../types/wallet';

/** One spelling for a tag, matching `TagDirectory::normalise()` on the server. */
function normalise(name: string): string {
    return name.trim().replace(/\s+/g, ' ').toLowerCase().slice(0, 40);
}

/**
 * The tags on one transaction: chips you can remove, and a box that adds one.
 *
 * ## Why names and not ids
 *
 * A person tagging a payment is not picking from a list they curate first — they type "fuel" and
 * expect it to exist afterwards. So this works in names, the server resolves each to a tag and
 * creates the ones that are new, and the same normalising happens on both sides so "Fuel " and
 * "fuel" never become two tags.
 *
 * ## Why a datalist rather than a dropdown
 *
 * The existing tags are a hint, not a constraint: the browser offers them while the person types and
 * stays out of the way when what they want is a new one. It also costs no focus handling inside a
 * dialog, which is where this lives.
 */
export default function TagsInput({
    value,
    onChange,
    suggestions,
    disabled = false,
    id,
}: {
    /** Tag names, normalised. */
    value: string[];
    onChange: (next: string[]) => void;
    /** The taxpayer's existing tags, offered while typing. */
    suggestions: Tag[];
    disabled?: boolean;
    id?: string;
}): JSX.Element {
    const { t } = useTranslation();
    const [draft, setDraft] = useState('');
    const listId = useId();

    const add = (name: string): void => {
        const tag = normalise(name);

        if (tag !== '' && !value.includes(tag)) {
            onChange([...value, tag]);
        }

        setDraft('');
    };

    const keyDown = (event: KeyboardEvent<HTMLInputElement>): void => {
        // Enter and comma both finish a tag, because both are what people press. Enter never
        // submits the dialog from here: a half-typed tag would be lost to a save.
        if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault();
            add(draft);

            return;
        }

        // Backspace on an empty box removes the last chip, the behaviour every chips field has.
        if (event.key === 'Backspace' && draft === '' && value.length > 0) {
            onChange(value.slice(0, -1));
        }
    };

    return (
        <div
            className={cn(
                'border-input bg-card flex min-h-9 flex-wrap items-center gap-1.5 rounded-lg border px-2 py-1.5',
                disabled && 'opacity-60',
            )}
        >
            {value.map((tag) => (
                <span
                    key={tag}
                    className="bg-muted text-muted-foreground flex items-center gap-1 rounded-md px-2 py-0.5 text-xs"
                >
                    {tag}
                    {disabled ? null : (
                        <button
                            type="button"
                            className="hover:text-foreground"
                            aria-label={tag}
                            onClick={() => {
                                onChange(value.filter((current) => current !== tag));
                            }}
                        >
                            <X className="size-3" />
                        </button>
                    )}
                </span>
            ))}

            <input
                id={id}
                list={listId}
                disabled={disabled}
                value={draft}
                placeholder={value.length === 0 ? t((d) => d.pages.walletTransactions.tags.placeholder) : ''}
                className="min-w-24 flex-1 bg-transparent text-xs outline-none"
                onChange={(event) => {
                    setDraft(event.target.value);
                }}
                onKeyDown={keyDown}
                // Typed and then clicked away: the tag a person meant is kept rather than dropped.
                onBlur={() => {
                    add(draft);
                }}
            />

            <datalist id={listId}>
                {suggestions
                    .filter((tag) => !value.includes(tag.name))
                    .map((tag) => (
                        <option key={tag.id} value={tag.name} />
                    ))}
            </datalist>
        </div>
    );
}
