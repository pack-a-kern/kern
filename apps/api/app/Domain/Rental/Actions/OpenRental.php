<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Rental\DataTransferObjects\CreateRentalContractInput;
use App\Domain\Rental\DataTransferObjects\OpenRentalInput;
use App\Domain\Rental\DataTransferObjects\RegisterPropertyInput;
use App\Domain\Rental\Enums\RentalAuditEvent;
use App\Domain\Rental\Models\RentalContract;
use App\Domain\Rental\Services\RentalAccrualHorizon;
use Illuminate\Support\Facades\DB;

/**
 * Lets a property: registers it, opens the contract on it, and works out what the contract owes.
 *
 * One transaction for all three, so a failure on the contract does not leave a property nobody
 * rents out. The owed rent is computed now rather than later because it is the whole point of
 * recording a rental — the income a tax return counts.
 */
final readonly class OpenRental
{
    public function __construct(
        private RegisterProperty $registerProperty,
        private CreateRentalContract $createContract,
        private GenerateRentalAccruals $generateAccruals,
        private AuditChainService $audit,
    ) {
    }

    public function __invoke(OpenRentalInput $input): RentalContract
    {
        $contract = DB::transaction(function () use ($input): RentalContract {
            $property = ($this->registerProperty)(new RegisterPropertyInput(
                taxSubjectId: $input->taxSubjectId,
                type: $input->propertyType,
                address: $input->address,
                ownershipPercentDecimal: $input->ownershipPercentDecimal,
            ));

            $contract = ($this->createContract)(new CreateRentalContractInput(
                taxSubjectId: $input->taxSubjectId,
                propertyId: $property->id,
                tenantType: $input->tenantType,
                tenantIdentity: $input->tenantName,
                startDate: $input->startDate,
                currencyCode: $input->currencyCode,
                rentMinor: $input->rentMinor,
                paymentFrequency: $input->paymentFrequency,
                endDate: $input->endDate,
                paymentDay: $input->paymentDay,
                sourceDocumentId: $input->sourceDocumentId,
                extractionId: $input->extractionId,
            ));

            ($this->generateAccruals)($contract, RentalAccrualHorizon::for($contract));

            return $contract;
        });

        // The terms and where they came from — never the address or the tenant, which are encrypted
        // at rest and are not what a question about this entry would be about.
        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $input->taxSubjectId,
            actorId: $input->actorId,
            eventType: RentalAuditEvent::OPENED->value,
            resourceType: RentalAuditEvent::RESOURCE_TYPE,
            resourceId: $contract->id,
            payloadHash: $this->audit->hashPayload([
                'rent_minor' => $input->rentMinor,
                'currency_code' => $input->currencyCode,
                'payment_frequency' => $input->paymentFrequency->value,
                'start_date' => $input->startDate,
                'end_date' => $input->endDate,
                'ownership_percent' => $input->ownershipPercentDecimal,
                'source_document_id' => $input->sourceDocumentId,
            ]),
        ));

        return $contract;
    }
}
