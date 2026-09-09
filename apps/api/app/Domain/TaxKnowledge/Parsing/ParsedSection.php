<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Parsing;

use App\Domain\TaxKnowledge\Enums\SectionType;

/**
 * One node of a {@see LegislatieJustRoHtmlParser} result, before persistence. `text` is this
 * node's own content only — it deliberately excludes the text of any nested `children`, so a
 * parent's row never duplicates what its children already carry (an `ARTICOL` with alineate holds
 * only its heading/marginal note; the substantive text lives on the `ALINEAT` rows).
 *
 * `rawLabel` is the label exactly as rendered (`"Articolul 27"`, `"(1)"`, `"a)"`) — kept alongside
 * the fully-qualified `citationLabel` (`"Art. 27 alin. (1) lit. a)"`) so a caller can reconstruct
 * either without re-parsing.
 */
final readonly class ParsedSection
{
    /**
     * @param list<ParsedSection> $children
     */
    public function __construct(
        public SectionType $sectionType,
        public string $rawLabel,
        public string $citationLabel,
        public int $ordinal,
        public string $text,
        public array $children,
    ) {
    }

    public function textHash(): string
    {
        return hash('sha256', $this->text);
    }
}
