<?php

declare(strict_types=1);

namespace Kern\Legislation\Legislatie;

/** An act's consolidations, and the consolidated form that lists them. */
final readonly class ActConsolidations
{
    /** @param list<Consolidation> $consolidations oldest first */
    public function __construct(
        public string $listedOn,
        public array $consolidations,
    ) {
    }
}
