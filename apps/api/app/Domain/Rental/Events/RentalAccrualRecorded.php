<?php

declare(strict_types=1);

namespace App\Domain\Rental\Events;

use App\Domain\Rental\Enums\TenantType;
use DateTimeImmutable;

/**
 * Dispatched (via Laravel's own event dispatcher, `event(new self(...))`) after a
 * `rental_accruals` row is committed — rental income is fiscally relevant
 * (`BLUEPRINT.md` §2.4's "Financial Event → Normalized Tax Fact" pipeline) but this module must not
 * construct a `TaxFact` itself: that model belongs to `Domain/TaxEngine`, and `docs/AGENTS.md` §6
 * forbids importing another module's Eloquent models. This is the exact contract
 * `App\Domain\Wallet\Events\DepositInterestEventRecorded` already establishes for the identical
 * problem — this event follows the same shape deliberately.
 *
 * **This is the contract Milestone 5 (`Domain/TaxEngine`) consumes.** A listener there (not built
 * here) should:
 *  1. Register a listener for this event class.
 *  2. Create a `tax_facts` row with `status = PROPOSED` — never `CONFIRMED` automatically
 *     (`BLUEPRINT.md` §14.6's principle, applied here exactly as `docs/handoff/021-wallet.md` applies
 *     it to a deposit interest accrual: an automatic wallet/rental calculation is still not the
 *     same as a human or a `TaxRule` confirming it belongs in an assessment).
 *  3. Independently choose and apply its own FX conversion method (`BLUEPRINT.md` §7.4) if
 *     `$currencyCode !== 'RON'`, using `$grossMinor`/`$currencyCode`/`$periodEnd` — this event
 *     carries no FX provenance of its own, unlike `DepositInterestEventRecorded`: this module
 *     performs no currency conversion anywhere (no wallet-bookkeeping spot rate is computed for a
 *     rental accrual), so there is nothing to warn a future consumer away from misusing.
 *  4. Use `$tenantType` if the applicable tax rule's withholding treatment depends on whether the
 *     tenant is a PF, a PJ, or unknown — `BLUEPRINT.md` §6.5 names this distinction explicitly as
 *     fiscally relevant; this module only records which one applies and decides nothing itself
 *     (`docs/AGENTS.md` rule 3).
 *  5. Use `$ownershipPercentDecimal` only for its own audit trail if it wants one — `$grossMinor`
 *     is **already** the owning tax subject's share (`BLUEPRINT.md` §33); a consumer must not apply
 *     the percentage a second time.
 *
 * Dispatched **after** the enclosing DB transaction commits
 * (`App\Domain\Rental\Actions\GenerateRentalAccruals`), so a listener querying `rental_accruals`
 * by the id carried here will always find the committed row.
 */
final readonly class RentalAccrualRecorded
{
    public function __construct(
        public string $rentalAccrualId,
        public string $taxSubjectId,
        public string $propertyId,
        public string $rentalContractId,
        public TenantType $tenantType,
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
        public DateTimeImmutable $dueDate,
        public string $currencyCode,
        public int $grossMinor,
        public string $ownershipPercentDecimal,
    ) {
    }
}
