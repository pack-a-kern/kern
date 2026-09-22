<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Legislatie;

/** An act's consolidations, and the page they are listed on — the act's own, or its code's. */
final readonly class ActConsolidations
{
    /** @param list<Consolidation> $consolidations oldest first */
    public function __construct(
        public string $listedOn,
        public array $consolidations,
    ) {
    }
}
