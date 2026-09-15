<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Rental\DataTransferObjects\TerminateRentalContractInput;
use App\Domain\Rental\Enums\RentalAuditEvent;
use App\Domain\Rental\Models\RentalContract;
use App\Domain\Rental\Services\RentalAccrualHorizon;
use Illuminate\Support\Facades\DB;

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
