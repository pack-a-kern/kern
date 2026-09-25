<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rental\Actions;

use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\TaxRomania\Rental\DataTransferObjects\TerminateRentalContractInput;
use Kern\TaxRomania\Rental\Enums\RentalAuditEvent;
use Kern\TaxRomania\Rental\Models\RentalContract;
use Kern\TaxRomania\Rental\Services\RentalAccrualHorizon;

/**
 * Ends a rental on a date. Rent stops being owed from that day, and periods after it are removed.
 */
final readonly class EndRental
{
    public function __construct(
        private TerminateRentalContract $terminate,
        private GenerateRentalAccruals $generateAccruals,
        private AuditChainService $audit,
    ) {
    }

    public function __invoke(RentalContract $contract, string $endDate, ?string $actorId): void
    {
        DB::transaction(function () use ($contract, $endDate): void {
            $ended = ($this->terminate)(new TerminateRentalContractInput(
                rentalContractId: $contract->id,
                taxSubjectId: $contract->tax_subject_id,
                endDate: $endDate,
            ));

            ($this->generateAccruals)($ended, RentalAccrualHorizon::for($ended));
        });

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $contract->tax_subject_id,
            actorId: $actorId,
            eventType: RentalAuditEvent::ENDED->value,
            resourceType: RentalAuditEvent::RESOURCE_TYPE,
            resourceId: $contract->id,
            payloadHash: $this->audit->hashPayload(['end_date' => $endDate]),
        ));
    }
}
