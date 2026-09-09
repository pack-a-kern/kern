<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Exceptions\InvalidWithholdingAmountException;
use App\Domain\Wallet\Models\DepositInterestEvent;

/**
 * Records the withholding actually applied to one `deposit_interest_events` row, and recomputes
 * `net_interest_minor` from it. Deliberately separate from
 * `App\Domain\Wallet\Actions\GenerateDepositInterestSchedule`: this module computes only **gross**
 * interest (`App\Domain\Wallet\Services\Interest\DepositInterestCalculator`'s docblock explains
 * why no withholding rate is hard-coded anywhere here), so the actual withheld amount — a
 * fiscal figure `Domain/TaxEngine` is the authority on, once it exists, or a value transcribed
 * from a real bank statement in the meantime — is recorded through this explicit, narrow action
 * rather than guessed at generation time.
 */
final class RecordDepositInterestWithholding
{
    public function __invoke(DepositInterestEvent $event, int $withholdingMinor): DepositInterestEvent
    {
        if ($withholdingMinor < 0 || $withholdingMinor > $event->gross_interest_minor) {
            throw InvalidWithholdingAmountException::outOfRange($withholdingMinor, $event->gross_interest_minor);
        }

        $event->withholding_minor = $withholdingMinor;
        $event->net_interest_minor = $event->gross_interest_minor - $withholdingMinor;
        $event->save();

        return $event;
    }
}
