<?php

declare(strict_types=1);

namespace Kern\Documents\Extraction;

/**
 * How many pages a PDF has.
 *
 * Its own contract, separate from reading one, because it is asked at a different moment for a
 * different reason: a document is refused for being too long *before* anything expensive has looked
 * at it, and counting costs milliseconds where reading costs seconds and a provider costs minutes.
 *
 * Implementations must reject rather than guess. A count that cannot be trusted is worse than none,
 * because a limit that silently passes everything looks like a limit.
 */
interface PdfPageCounter
{
    /**
     * @param  resource  $pdfStream
     *
     * @throws Exceptions\DocumentRejectedByExtractionException  the bytes are not an acceptable PDF
     * @throws Exceptions\TextExtractionUnavailableException  the service could not answer
     */
    public function countPages($pdfStream): int;
}
