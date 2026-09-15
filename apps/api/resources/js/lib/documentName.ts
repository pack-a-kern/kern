/**
 * Composing a name for a document out of what was actually read from it.
 *
 * A document is shown under its content hash until someone names it. Once extraction has read an
 * issuer and a date, a far better name is available — but it is offered, never applied: the person
 * decides what their own document is called, exactly as they decide whether an extracted figure is
 * right (`BLUEPRINT.md` §14.6).
 *
 * The model is now asked for a name outright (`suggested_filename`), and that wins when it is
 * there: it saw the document and this file only sees the fields that survived extraction. The
 * composition below stays as the fallback, for readings made before the field existed and for a
 * model that leaves it out.
 */

/** What the model itself proposed the document be called. Preferred over anything composed here. */
const SUGGESTED_NAME_FIELD = 'suggested_filename';

/** Fields worth putting in a name, most identifying first. Whichever exists is used. */
// `issuer` stays, ahead of the group search: a correction typed by a person may still put a plain
// name there, and `pick` ignores it when a reading made it a group instead.
// No `document_number`: a trade register number is the most identifying string on a company
// document and the least readable, which is how a resolution came to be called
// "company-document-j40-3960-2017". A name nobody can read is worse than no suggestion.
const NAME_FIELDS = ['counterparty', 'issuer', 'name', 'institution_name'] as const;

const DATE_FIELDS = ['date', 'issue_date', 'period_start', 'due_date'] as const;

/**
 * Groups worth looking inside, in the order they are worth looking.
 *
 * A reading is no longer flat: a statement's bank is `statement.institution_name` and an invoice's
 * seller is `issuer.name`, so a search that only looked at the top level found nothing and every
 * statement went unnamed. Named rather than recursive — one level, into groups that exist, so a search cannot
 * wander into a list of eighty-four rows and name a document after one of them.
 */
const GROUPS = ['statement', 'issuer', 'recipient'] as const;

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
    const direct = pick(fields, keys);

    if (direct !== null) {
        return direct;
    }

    for (const group of GROUPS) {
        const nested = fields[group];

        if (typeof nested === 'object' && nested !== null && !Array.isArray(nested)) {
            const found = pick(nested as Record<string, unknown>, keys);

            if (found !== null) {
                return found;
            }
        }
    }

    return null;
}

function pick(fields: Record<string, unknown>, keys: readonly string[]): string | null {
    for (const key of keys) {
        const value = fields[key];

        if (typeof value === 'string' && value.trim() !== '') {
            return value.trim();
        }
    }

    return null;
}

function extensionOf(name: string): string {
    return name.includes('.') ? name.slice(name.lastIndexOf('.') + 1) : '';
}

/**
 * The model's proposed name as a file name: slugified, cut to length, with the extension the document
 * already has. `null` when nothing is left of it.
 *
 * The model was asked for a filename, not for a safe one, and a name with a slash or a diacritic in it
 * is a name that breaks somewhere later.
 */
export function formatProposedName(proposed: string, currentName: string): string | null {
    const stem = slugify(proposed).slice(0, MAX_LENGTH).replace(/-+$/, '');
    const extension = extensionOf(currentName);

    if (stem === '') {
        return null;
    }

    return extension === '' ? stem : `${stem}.${extension}`;
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
    const extension = extensionOf(currentName);
    const proposed = pick(fields, [SUGGESTED_NAME_FIELD]);
    const formatted = proposed === null ? null : formatProposedName(proposed, currentName);

    if (formatted !== null) {
        return formatted;
    }

    const subject = firstString(fields, NAME_FIELDS);
    const date = firstString(fields, DATE_FIELDS);

    if (subject === null && date === null) {
        return null;
    }

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
