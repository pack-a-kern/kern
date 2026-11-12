<?php

declare(strict_types=1);

namespace Kern\Legislation\Provisions;

use Kern\Legislation\Enums\SectionType;

/**
 * A provision of a law as stored, with what stands under it, read by {@see StoredProvisions}: what a
 * reader of the law's words needs — another package reading a Ministry of Finance order for the bonds
 * it issues — named as {@see \Kern\Legislation\Parsing\ParsedSection} names it, so a reader takes
 * either: a law as stored, or one just read from its page.
 */
final readonly class StoredProvision
{
    /**
     * @param  list<list<list<string>>>  $tables  each table's rows of cells, as the markers in `text` number them
     * @param  list<StoredProvision>  $children  what stands under it, in reading order
     */
    public function __construct(
        public SectionType $sectionType,
        /** "Art. 2 alin. (1)", "ANEXĂ Art. 8" — the law's own way of naming it. */
        public string $citationLabel,
        /** Its own words only; each table stands in it as a marker line — see {@see \Kern\Legislation\Parsing\SectionText}. */
        public string $text,
        public array $tables,
        public array $children,
    ) {
    }
}
