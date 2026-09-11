<?php

declare(strict_types=1);

namespace App\Domain\Documents\Scanning;

/**
 * Which scanner produced a verdict, and what it was running at the time.
 *
 * `name` always has a value — it is a property of the implementation, not something queried at
 * runtime — while the two versions are nullable, because a scanner that answers a scan but not a
 * version query must still be allowed to scan. Absent means "not recorded", never "none".
 *
 * These are what make a stored verdict reproducible: re-running the same engine with the same
 * signature set is the only way to ask whether a past `CLEAN` still holds.
 */
final readonly class ScannerDescription
{
    public function __construct(
        public string $name,
        public ?string $engineVersion = null,
        public ?string $definitionsVersion = null,
    ) {
    }
}
