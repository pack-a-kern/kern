/**
 * What a bank's mark shows when it has no logo: its initials, on a hue of its own.
 *
 * Pure, so the same bank reads the same on every screen and in every session.
 */

/** A first word this short, written in capitals, is the bank's own acronym: BRD, CEC, ING, TBI, PKO. */
const ACRONYM_MAX_LETTERS = 4;

/** Words that make up a name rather than naming the bank: "de", "si", "of". */
const MIN_WORD_LETTERS = 3;

/**
 * "Banca Transilvania" is BT, "Banca Comerciala Romana" BCR, "BRD - Groupe Societe Generale" BRD,
 * "Exim Banca Romaneasca" EBR: the acronym a name starts with, or else the first letters of its
 * first three words — joining words left out.
 */
export function bankInitials(name: string): string {
    const words = name.split(/[\s\-–—·,()]+/u).filter((word) => /\p{L}/u.test(word));
    const first = words[0] ?? '';
    const letters = first.replace(/[^\p{L}]/gu, '');

    if (letters.length > 1 && letters.length <= ACRONYM_MAX_LETTERS && letters === letters.toUpperCase()) {
        return letters;
    }

    const initials = words
        .filter((word) => word.replace(/[^\p{L}]/gu, '').length >= MIN_WORD_LETTERS)
        .slice(0, 3)
        .map((word) => (word.match(/\p{L}/u)?.[0] ?? '').toUpperCase())
        .join('');

    return initials === '' ? '?' : initials;
}

/** A hue from 0 to 359, the same for the same key — the bank's code, or its name where it has none. */
export function bankHue(key: string): number {
    let hash = 0;

    for (const character of key) {
        hash = (hash * 31 + (character.codePointAt(0) ?? 0)) % 360;
    }

    return hash;
}
