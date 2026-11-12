<?php

declare(strict_types=1);

namespace Kern\Legislation\DataTransferObjects;

/**
 * Which laws the laws screen asks for — of one type, of one year, read in by one watch, each or any — and
 * which laws a search of their text looks in, the same filter on the same screen.
 */
final readonly class LawFilter
{
    public function __construct(
        public ?string $type,
        public ?int $year,
        public ?string $watchId,
    ) {
    }

    /** Whether it narrows the laws at all. */
    public function narrows(): bool
    {
        return $this->type !== null || $this->year !== null || $this->watchId !== null;
    }
}
