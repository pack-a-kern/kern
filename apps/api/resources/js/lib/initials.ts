/**
 * Up to two letters standing in for a taxpayer, where there is no picture or no room for a name.
 *
 * Taken from word starts rather than the first two characters — "Popescu Consulting SRL" is PC, not
 * PO — because the first two letters of a company's name are usually the first two letters of a
 * first name, and three taxpayers belonging to the same family then wear the same disc.
 *
 * One shared copy because there were three, and they disagreed: the switcher's returned `?` for an
 * empty name while the list's returned an empty string, so the same taxpayer could show a mark in the
 * sidebar and a blank circle in the table.
 *
 * Not for an email address. That has its own shape — `ana.popescu@` is AP, and the separator is a dot
 * rather than a space — and merging the two would mean a function that guesses which it was given.
 */
export function initialsFor(displayName: string): string {
    const words = displayName.split(/\s+/).filter((word) => word !== '');

    if (words.length === 0) {
        return '?';
    }

    // One word gives its first two letters — "ddsfsfsd" is DS. Only a real multi-word name takes one
    // letter from each.
    if (words.length === 1) {
        return (words[0] ?? '').slice(0, 2).toUpperCase();
    }

    return words
        .slice(0, 2)
        .map((word) => word.charAt(0).toUpperCase())
        .join('');
}
