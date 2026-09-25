<?php

declare(strict_types=1);

namespace App\Domain\FiscalParameters\Matching;

/** One paragraph of the law as it stood on a day: its words, where they are, and where they are read from. */
final readonly class Provision
{
    public function __construct(
        public string $sectionId,
        public string $citation,
        /** The citation of the article it belongs to — its own, for an article. */
        public string $article,
        /** The heading path its article stands under, as a search reads it. */
        public string $path,
        public string $text,
        /** Everything that stands under it — its letters and points — as one text. */
        public string $under,
        /** The marginal title of the article it belongs to — "Cota de contribuție de asigurări sociale de sănătate". */
        public string $title,
        public string $documentVersionId,
        /** The day the portal's note dates this wording from, when it is read in a later consolidation. */
        public ?string $datedByNote = null,
    ) {
    }
}
