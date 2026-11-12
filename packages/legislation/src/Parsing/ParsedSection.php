<?php

declare(strict_types=1);

namespace Kern\Legislation\Parsing;

use Kern\Legislation\Enums\SectionType;

/**
 * One node of a {@see LegislatieJustRoHtmlParser} result, before persistence. `text` is this
 * node's own content only — it deliberately excludes the text of any nested `children`, so a
 * parent's row never duplicates what its children already carry (an `ARTICOL` with alineate holds
 * only its heading/marginal note; the substantive text lives on the `ALINEAT` rows).
 *
 * `text` keeps the breaks between the source's own blocks, and each table it holds stands in it as a
 * marker line, its rows and cells in `tables` — see {@see SectionText} — and the cells it merges in
 * `tableSpans`, table for table.
 *
 * `rawLabel` is the label exactly as rendered (`"Articolul 27"`, `"(1)"`, `"a)"`) — kept alongside
 * the fully-qualified `citationLabel` (`"Art. 27 alin. (1) lit. a)"`) so a caller can reconstruct
 * either without re-parsing.
 */
final readonly class ParsedSection
{
    /**
     * @param list<ParsedSection> $children
     * @param list<list<list<string>>> $tables
     * @param list<string> $notes
     * @param list<list<array{row: int, cell: int, colspan: int, rowspan: int}>> $tableSpans
     */
    public function __construct(
        public SectionType $sectionType,
        public string $rawLabel,
        public string $citationLabel,
        public int $ordinal,
        public string $text,
        public array $children,
        public array $tables = [],
        /** For an article or annex: the heading it stands under, as an index into the document's outline. */
        public ?int $outlineIndex = null,
        /** The portal's notes on this provision, each as its text — never part of `text`. */
        public array $notes = [],
        /** For each of `tables`, the cells spanning more than one column or row: the cell's place, as `tables` lists it, and its spans. */
        public array $tableSpans = [],
    ) {
    }

    /** The same section, numbered and placed where it stands among the act's own. */
    public function at(int $ordinal, ?int $outlineIndex): self
    {
        return new self($this->sectionType, $this->rawLabel, $this->citationLabel, $ordinal, $this->text, $this->children, $this->tables, $outlineIndex, $this->notes, $this->tableSpans);
    }

    /** @param list<ParsedSection> $children */
    public function withChildren(array $children): self
    {
        return new self($this->sectionType, $this->rawLabel, $this->citationLabel, $this->ordinal, $this->text, $children, $this->tables, $this->outlineIndex, $this->notes, $this->tableSpans);
    }

    /** The text with its tables written out — what is searched, quoted and fingerprinted. */
    public function fullText(): string
    {
        return SectionText::withTables($this->text, $this->tables);
    }

    public function textHash(): string
    {
        return hash('sha256', $this->fullText());
    }
}
