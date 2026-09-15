<?php

declare(strict_types=1);

namespace App\Domain\Fx\Enums;

/**
 * `ext_fx_period_averages.verification_status` (`BLUEPRINT.md` §7.4). `PENDING` until
 * `App\Domain\Fx\Services\FxPeriodAverageCalculator::recordOfficialPublishedValue()` supplies
 * BNR's own published value to compare against — a period average is never assumed correct
 * merely because it was computed; it is `VERIFIED` only once checked against the official figure.
 */
enum VerificationStatus: string
{
    case PENDING = 'PENDING';
    case VERIFIED = 'VERIFIED';
    case MISMATCH = 'MISMATCH';
}
