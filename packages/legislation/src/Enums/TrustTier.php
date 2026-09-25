<?php

declare(strict_types=1);

namespace Kern\Legislation\Enums;

/**
 * `BLUEPRINT.md` §8.1's four-tier source hierarchy. Tier A (`legislatie.just.ro`) outranks tier D
 * (`bnr.ro`) for legal authority, but this milestone does not yet build anything that arbitrates
 * between conflicting sources — the ordering exists so that future consumer can, without
 * redefining the tiers.
 */
enum TrustTier: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
}
