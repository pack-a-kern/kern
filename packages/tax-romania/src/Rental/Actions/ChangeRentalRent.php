<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rental\Actions;

use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\TaxRomania\Rental\DataTransferObjects\AmendRentalContractInput;
use Kern\TaxRomania\Rental\Enums\RentalAuditEvent;
use Kern\TaxRomania\Rental\Enums\RentalPaymentFrequency;
use Kern\TaxRomania\Rental\Models\RentalContract;
use Kern\TaxRomania\Rental\Services\RentalAccrualHorizon;

/**
 * A new rent from a date, with the old rent kept for every period before it.
 *
 * An amendment, never an edit: last year's income was owed at last year's rent, and overwriting the
 * figure would change a year that may already have been declared.
 */
final readonly class ChangeRentalRent
{
    public function __construct(
        private AmendRentalContract $amend,
        private GenerateRentalAccruals $generateAccruals,
        private AuditChainService $audit,
    ) {
    }

    public function __invoke(
        RentalContract $contract,
        string $effectiveFrom,
        int $rentMinor,
        RentalPaymentFrequency $paymentFrequency,
        ?int $paymentDay,
        ?string $actorId,
    ): void {
        DB::transaction(function () use ($contract, $effectiveFrom, $rentMinor, $paymentFrequency, $paymentDay): void {
            ($this->amend)(new AmendRentalContractInput(
                rentalContractId: $contract->id,
                taxSubjectId: $contract->tax_subject_id,
                effectiveFrom: $effectiveFrom,
                rentMinor: $rentMinor,
                paymentFrequency: $paymentFrequency,
                paymentDay: $paymentDay,
            ));

            ($this->generateAccruals)($contract, RentalAccrualHorizon::for($contract));
        });

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $contract->tax_subject_id,
            actorId: $actorId,
            eventType: RentalAuditEvent::RENT_CHANGED->value,
            resourceType: RentalAuditEvent::RESOURCE_TYPE,
            resourceId: $contract->id,
            payloadHash: $this->audit->hashPayload([
                'effective_from' => $effectiveFrom,
                'rent_minor' => $rentMinor,
                'payment_frequency' => $paymentFrequency->value,
            ]),
        ));
    }
}
