import type { LawTable } from '../types/legislation';

/**
 * How a provision's stored text reads, mirroring `App\Domain\TaxKnowledge\Parsing\SectionText`.
 *
 * The text is lines; a line `[[table:N]]` stands where its Nth table was, and a line opening
 * "(la 01-01-2025, …" is the portal's note on when and by what the provision was last changed —
 * commentary, shown quieter than the law's own words.
 */

const TABLE_MARKER = /^\[\[table:(\d+)\]\]$/;

const AMENDMENT_NOTE = /^\(la \d{2}-\d{2}-\d{4},/;

export type TextLine = { kind: 'text' | 'note'; text: string } | { kind: 'table'; table: LawTable };

export function lines(text: string, tables: LawTable[]): TextLine[] {
    return text
        .split('\n')
        .filter((line) => line !== '')
        .map((line): TextLine => {
            const marker = TABLE_MARKER.exec(line);

            if (marker !== null) {
                return { kind: 'table', table: tables[Number(marker[1]) - 1] ?? [] };
            }

            return { kind: AMENDMENT_NOTE.test(line) ? 'note' : 'text', text: line };
        });
}

/** The text with each table written out row by row — what two versions are compared on. */
export function withTables(text: string, tables: LawTable[]): string {
    return lines(text, tables)
        .map((line) => (line.kind === 'table' ? line.table.map((row) => row.join(' | ')).join('\n') : line.text))
        .join('\n');
}

/** Where a provision is on the page: an id a link can carry after `#`. */
export function anchorOf(section: { id: string }): string {
    return `p-${section.id}`;
}

/** Words as a find compares them: lower case, without diacritics — "tara" finds "țară". */
export function foldForFind(text: string): string {
    return text.normalize('NFD').replace(/\p{M}/gu, '').toLocaleLowerCase();
}
