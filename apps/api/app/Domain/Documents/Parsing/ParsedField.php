<?php

declare(strict_types=1);

namespace App\Domain\Documents\Parsing;

/**
 * One field a parser read out of a document, with how sure it is and the text it read it from.
 *
 * `evidence` is the short snippet the value came from. It is what makes a proposal reviewable
 * rather than merely visible: a person confirming "total: 45,50" can see the line it was lifted
 * from without opening the document, and a wrong reading is obvious instead of plausible.
 *
 * `confidence` is a parser's own estimate — a value found by an unambiguous label deserves more
 * trust than one inferred from position. It orders a reviewer's attention; it decides nothing,
 * because nothing here is ever auto-confirmed.
 */
final readonly class ParsedField
{
    public function __construct(
        public string $name,
        public string $value,
        public float $confidence,
        public string $evidence = '',
    ) {
    }
}
