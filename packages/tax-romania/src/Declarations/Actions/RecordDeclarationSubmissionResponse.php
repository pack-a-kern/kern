<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Actions;

use Illuminate\Support\Facades\DB;
use Kern\TaxRomania\Declarations\DataTransferObjects\RecordDeclarationSubmissionResponseInput;
use Kern\TaxRomania\Declarations\Enums\DeclarationStatus;
use Kern\TaxRomania\Declarations\Exceptions\DeclarationNotSubmittedException;
use Kern\TaxRomania\Declarations\Exceptions\InvalidSubmissionOutcomeException;
use Kern\TaxRomania\Declarations\Models\Declaration;
use Kern\TaxRomania\Declarations\Models\DeclarationSubmission;
use Kern\TaxRomania\Declarations\Services\DeclarationLifecycle;

/**
 * Records ANAF's outcome for a submission and flips the owning declaration to its terminal
 * `ACCEPTED`/`REJECTED` status — the one mutation `Kern\TaxRomania\Declarations\Models\Declaration`'s
 * immutability guard permits on an already-locked row.
 */
final readonly class RecordDeclarationSubmissionResponse
{
    public function __construct(
        private DeclarationLifecycle $lifecycle,
    ) {
    }

    public function __invoke(RecordDeclarationSubmissionResponseInput $input): DeclarationSubmission
    {
        if (! in_array($input->outcome, [DeclarationStatus::ACCEPTED, DeclarationStatus::REJECTED], true)) {
            throw InvalidSubmissionOutcomeException::forStatus($input->outcome);
        }

        return DB::transaction(function () use ($input): DeclarationSubmission {
            /** @var DeclarationSubmission $submission */
            $submission = DeclarationSubmission::query()->findOrFail($input->submissionId);

            /** @var Declaration $declaration */
            $declaration = $submission->declaration()->firstOrFail();

            if ($declaration->status !== DeclarationStatus::SUBMITTED) {
                throw DeclarationNotSubmittedException::forDeclaration($declaration->id);
            }

            $submission->recordResponse($input->responsePayload, $input->receiptDocumentId);

            $this->lifecycle->transition($declaration, $input->outcome);

            return $submission;
        });
    }
}
