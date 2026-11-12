<?php

declare(strict_types=1);

namespace Kern\Investments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Instruments about to be deleted with the law their terms were read from, nobody holding any: whatever
 * another package keeps about them — a notice that an issue is on offer — goes with them. Fired inside
 * the transaction that deletes them, so a listener that throws undoes it all.
 */
final readonly class InstrumentsWithdrawn
{
    use Dispatchable;

    /** @param  list<string>  $instrumentIds */
    public function __construct(public array $instrumentIds)
    {
    }
}
