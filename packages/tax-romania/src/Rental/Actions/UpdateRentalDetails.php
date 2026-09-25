<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rental\Actions;

use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\TaxRomania\Rental\DataTransferObjects\UpdateRentalDetailsInput;
use Kern\TaxRomania\Rental\Enums\RentalAuditEvent;
use Kern\TaxRomania\Rental\Models\RentalContract;
use Kern\TaxRomania\Rental\Services\RentalAccrualHorizon;

/**
 * Corrects what a rental is described as: the property, its share, and the tenant.
 *
 * The ownership share is the one of these that changes a figure — only that share of the rent is
 * this taxpayer's income — so changing it recomputes what every period owes.
 */
final readonly class UpdateRentalDetails
{
    public function __construct(
        private GenerateRentalAccruals $generateAccruals,
        private AuditChainService $audit,
    ) {
    }

    public function __invoke(RentalContract $contract, UpdateRentalDetailsInput $input): void
    {
        if ($input->ownershipPercentDecimal !== null) {
            RegisterProperty::assertValidOwnershipPercent($input->ownershipPercentDecimal);
        }

        $changed = DB::transaction(function () use ($contract, $input): array {
            $property = $contract->property()->firstOrFail();
            $changed = [];

            if ($input->propertyType !== null) {
                $property->type = $input->propertyType;
                $changed[] = 'property_type';
            }

            if ($input->address !== null) {
                $property->address_ciphertext = $input->address;
                $changed[] = 'address';
            }

            $shareChanged = $input->ownershipPercentDecimal !== null
                && $input->ownershipPercentDecimal !== $property->ownership_percent_decimal;

            if ($input->ownershipPercentDecimal !== null) {
                $property->ownership_percent_decimal = $input->ownershipPercentDecimal;
                $changed[] = 'ownership_percent';
            }

            $property->save();

            if ($input->tenantType !== null) {
                $contract->tenant_type = $input->tenantType;
                $changed[] = 'tenant_type';
            }

            if ($input->tenantName !== null) {
                $contract->tenant_identity_ciphertext = $input->tenantName;
                $changed[] = 'tenant_name';
            }

            $contract->save();

            if ($shareChanged) {
                ($this->generateAccruals)($contract, RentalAccrualHorizon::for($contract));
            }

            return $changed;
        });

        // Which fields moved, and the share when it did — the address and tenant are encrypted, so
        // only the fact that they changed is recorded.
        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $contract->tax_subject_id,
            actorId: $input->actorId,
            eventType: RentalAuditEvent::UPDATED->value,
            resourceType: RentalAuditEvent::RESOURCE_TYPE,
            resourceId: $contract->id,
            payloadHash: $this->audit->hashPayload([
                'fields' => implode(',', $changed),
                'ownership_percent' => $input->ownershipPercentDecimal,
            ]),
        ));
    }
}
