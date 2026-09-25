<?php

declare(strict_types=1);

namespace Kern\Legislation\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Every consolidation of one law has been read, dated and parsed.
 *
 * Fired instead of dispatching the jobs that care. Reading the minimum wage out of a government
 * decision is one of those, and it is a *tax* concern: the sync knows it finished a document, not
 * that some number in it is a fiscal parameter. A module that reads laws must not have to name the
 * modules that make use of them, or it can never be installed without them.
 *
 * Carries the document's type and title as well as its id, so a listener can decide whether this law
 * is one it cares about without loading the row.
 */
final readonly class LawConsolidationsRead
{
    use Dispatchable;

    public function __construct(
        public string $sourceDocumentId,
        public string $documentType,
        public string $title,
    ) {
    }
}
