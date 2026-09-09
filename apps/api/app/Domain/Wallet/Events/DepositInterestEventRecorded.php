<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Events;

use DateTimeImmutable;

/**
 * Dispatched (via Laravel's own event dispatcher, `event(new self(...))`) after a
 * `deposit_interest_events` row is committed to the database — a deposit interest accrual is
 * fiscally relevant (`BLUEPRINT.md` §2.4's "Financial Event → Normalized Tax Fact" pipeline) but this
 * module must not construct a `TaxFact` itself: that model belongs to `Domain/TaxEngine`
 * (Milestone 5), built by a different, concurrently-working agent, and `docs/AGENTS.md` §6
 * forbids importing another module's Eloquent models.
 *
 * **This is the contract Milestone 5 consumes.** A listener there (e.g.
 * `App\Domain\TaxEngine\Listeners\CreateTaxFactFromDepositInterest`, not built here) should:
 *  1. Register a listener for this event class (`EventServiceProvider`/`Event::listen()`).
 *  2. Create a `tax_facts` row with `status = PROPOSED` (never `CONFIRMED` automatically —
 *     `BLUEPRINT.md` §14.6 applies here exactly as it does to AI-extracted values: an automatically
 *     created tax fact from a deterministic wallet calculation is still not the same as a human
 *     or a `TaxRule` confirming it belongs in an assessment).
 *  3. Independently choose and apply its own FX conversion method (`BLUEPRINT.md` §7.4) using
 *     `$grossInterestMinor`/`$currencyCode`/`$accrualTo` — **not** `$ronEquivalentMinor`/
 *     `$fxRateDecimal`, which are `App\Domain\Wallet\Services\Fx\DepositInterestFxAnnotator`'s own
 *     wallet-bookkeeping spot conversion, not an authoritative fiscal one (see that class's
 *     docblock).
 *
 * This event is dispatched **after** the enclosing DB transaction commits (from
 * `App\Domain\Wallet\Actions\GenerateDepositInterestSchedule`, once its `DB::transaction()` call
 * returns) — a listener querying `deposit_interest_events`/`deposits` by the ids carried here will
 * always find the committed row.
 */
final readonly class DepositInterestEventRecorded
{
    public function __construct(
        public string $depositInterestEventId,
        public string $taxSubjectId,
        public string $depositId,
        public DateTimeImmutable $accrualFrom,
        public DateTimeImmutable $accrualTo,
        public string $currencyCode,
        public int $grossInterestMinor,
        public ?int $withholdingMinor,
        public int $netInterestMinor,
        public ?int $ronEquivalentMinor,
        public ?string $fxRateDecimal,
        public ?string $fxSourceCode,
    ) {
    }
}
