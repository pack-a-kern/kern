<?php

declare(strict_types=1);

namespace Kern\Legislation\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A law is being deleted, inside the transaction that deletes it: whatever was read from it and is
 * nobody's to keep goes with it here — a bond read from the order that issued it, for one — so nothing is
 * left pointing at a law that is gone. Fired only once nothing declared in `DocumentDependents` holds the
 * law; a listener that throws undoes the whole delete.
 */
final readonly class LawDeleting
{
    use Dispatchable;

    public function __construct(public string $sourceDocumentId)
    {
    }
}
