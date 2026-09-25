<?php

declare(strict_types=1);

namespace Kern\Legislation\Parsing;

use Kern\Legislation\Enums\OutlineLevel;

/** One heading of a parsed law — "Capitolul IV" "Venituri din cedarea folosinței bunurilor". */
final readonly class ParsedOutlineNode
{
    public function __construct(
        public OutlineLevel $level,
        public string $label,
        public string $name,
        /** The labels from the top, "Titlul IV Capitolul IV": the same in every consolidation. */
        public string $path,
        public int $ordinal,
        /** Index of the enclosing heading in the document's outline, null for a title. */
        public ?int $parentIndex,
    ) {
    }
}
