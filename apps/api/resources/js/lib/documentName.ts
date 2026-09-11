/**
 * Composing a name for a document out of what was actually read from it.
 *
 * A document is shown under its content hash until someone names it. Once extraction has read a
 * merchant and a date, a far better name is available — but it is offered, never applied: the
 * person decides what their own document is called, exactly as they decide whether an extracted
 * figure is right (`BLUEPRINT.md` §14.6).
 */

/** Fields worth putting in a name, most identifying first. Whichever exists is used. */
const NAME_FIELDS = ['merchant', 'counterparty', 'issuer', 'document_number'] as const;

const DATE_FIELDS = ['date', 'issue_date', 'due_date'] as const;

const MAX_LENGTH = 80;

/**
 * A filesystem-safe, diacritic-free slug.
 *
 * Romanian text is the normal case here, so `ă î ș ț â` have to survive as `a i s t a` rather than
 * being stripped to nothing — decomposing to NFD and dropping the combining marks does that
 * without a transliteration table.
 */
function slugify(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function firstString(fields: Record<string, unknown>, keys: readonly string[]): string | null {
    for (const key of keys) {
        const value = fields[key];

        if (typeof value === 'string' && value.trim() !== '') {
            return value.trim();
        }
    }

    return null;
}

/**
 * A suggested name, or `null` when nothing usable was extracted.
 *
 * `null` rather than a half-built name: "bon-fiscal.png" for every receipt is no more distinguishing
 * than the hash it would replace, and a suggestion nobody would accept is worse than none.
 *
 * `typeLabel` comes from the caller's dictionary — this file holds no display text of its own, in
 * either language.
 */
export function suggestDocumentName(
    typeLabel: string,
    fields: Record<string, unknown>,
    currentName: string,
): string | null {
    const subject = firstString(fields, NAME_FIELDS);
    const date = firstString(fields, DATE_FIELDS);

    if (subject === null && date === null) {
        return null;
    }

    const extension = currentName.includes('.') ? currentName.slice(currentName.lastIndexOf('.') + 1) : '';

    const stem = [typeLabel, subject, date]
        .filter((part): part is string => part !== null && part !== '')
        .map(slugify)
        .filter((part) => part !== '')
        .join('-')
        .slice(0, MAX_LENGTH)
        .replace(/-+$/, '');

    if (stem === '') {
        return null;
    }

    return extension === '' ? stem : `${stem}.${extension}`;
}
