<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Rental\DataTransferObjects\AmendRentalContractInput;
use App\Domain\Rental\Enums\RentalAuditEvent;
use App\Domain\Rental\Enums\RentalPaymentFrequency;
use App\Domain\Rental\Models\RentalContract;
use App\Domain\Rental\Services\RentalAccrualHorizon;
use Illuminate\Support\Facades\DB;

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
