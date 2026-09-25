<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Rental\Enums\RentalAuditEvent;
use App\Domain\Rental\Models\Property;
use App\Domain\Rental\Models\RentalAccrual;
use App\Domain\Rental\Models\RentalContract;
use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;

/**
 * Deletes a rental — a contract entered by mistake — and its property when nothing else is let on it.
 *
 * A rental that really ended is ended, not deleted: ending keeps the years it was owed for, while
 * deleting removes them. The audit chain keeps the fact either way.
 */
final readonly class DeleteRental
{
    public function __construct(private AuditChainService $audit)
    {
    }

    public function __invoke(RentalContract $contract, ?string $actorId): void
    {
        $subjectId = $contract->tax_subject_id;
        $contractId = $contract->id;
        $propertyId = $contract->property_id;

        DB::transaction(function () use ($contract, $contractId, $propertyId): void {
            // Accruals first: they reference the contract's versions with `restrictOnDelete`, and the
            // contract's own cascade would reach the versions before them.
            RentalAccrual::query()->where('rental_contract_id', $contractId)->delete();
            $contract->delete();

            if (! RentalContract::query()->where('property_id', $propertyId)->exists()) {
                Property::query()->whereKey($propertyId)->delete();
            }
        });

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $subjectId,
            actorId: $actorId,
            eventType: RentalAuditEvent::DELETED->value,
            resourceType: RentalAuditEvent::RESOURCE_TYPE,
            resourceId: $contractId,
            payloadHash: $this->audit->hashPayload(['property_id' => $propertyId]),
        ));
    }
}
