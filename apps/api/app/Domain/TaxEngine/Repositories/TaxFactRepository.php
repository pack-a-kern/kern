<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Repositories;

use App\Domain\TaxEngine\DataTransferObjects\TaxFactSnapshot;
use App\Domain\TaxEngine\Models\TaxFact;
use App\Domain\TaxEngine\ValueObjects\Money;
use Illuminate\Support\Collection;
use Kern\Platform\Tenancy\TenantScope;

/**
 * The sanctioned way `App\Domain\TaxEngine\TaxContext::withFacts()` is populated from persisted
 * `tax_facts` rows — never the `TaxFact` Eloquent model itself past this class
 * (`docs/AGENTS.md` §11.2/§6).
 *
 * Filters explicitly by `$taxSubjectId` (via `withoutGlobalScope(TenantScope::class)`) rather
 * than relying on `Kern\Platform\Tenancy\TenantContext` being bound: whoever calls
 * `TaxContext::forSubject($id)` already knows and has authorized that subject id (an HTTP
 * controller with a bound `TenantContext`, or a background recompute job with no HTTP request at
 * all) — this repository does not assume which. The explicit `WHERE tax_subject_id = $id` is
 * correct in both cases and does not throw `NoTenantContextException` in the job case. See
 * `App\Domain\TaxEngine\Models\TaxFact`'s own docblock — `ScopedToTenant` still guards any
 * *other*, less careful caller that queries `TaxFact` directly.
 */
final readonly class TaxFactRepository
{
    /**
     * @param list<string> $factIds
     *
     * @return list<TaxFactSnapshot>
     */
    public function snapshotsFor(string $taxSubjectId, array $factIds): array
    {
        /** @var Collection<int, TaxFact> $facts */
        $facts = TaxFact::withoutGlobalScope(TenantScope::class)
            ->where('tax_subject_id', $taxSubjectId)
            ->whereIn('id', $factIds)
            ->get();

        return array_values($facts->map(static fn (TaxFact $fact): TaxFactSnapshot => self::toSnapshot($fact))->all());
    }

    private static function toSnapshot(TaxFact $fact): TaxFactSnapshot
    {
        return new TaxFactSnapshot(
            id: $fact->id,
            categoryCode: $fact->category_code,
            subtypeCode: $fact->subtype_code,
            recognitionDate: $fact->recognition_date->toDateTimeImmutable(),
            cashDate: $fact->cash_date?->toDateTimeImmutable(),
            earnedAt: $fact->earned_at?->toDateTimeImmutable(),
            receivedAt: $fact->received_at?->toDateTimeImmutable(),
            paidAt: $fact->paid_at?->toDateTimeImmutable(),
            distributedAt: $fact->distributed_at?->toDateTimeImmutable(),
            contractDate: $fact->contract_date?->toDateTimeImmutable(),
            transactionDate: $fact->transaction_date?->toDateTimeImmutable(),
            grossAmountRon: Money::ofMinorUnits($fact->ron_amount_minor, 'RON'),
            deductibleAmountRon: $fact->deductible_amount_ron_minor !== null
                ? Money::ofMinorUnits($fact->deductible_amount_ron_minor, 'RON')
                : null,
            withheldTaxRon: $fact->withheld_tax_ron_minor !== null
                ? Money::ofMinorUnits($fact->withheld_tax_ron_minor, 'RON')
                : null,
            status: $fact->status,
        );
    }
}
