<?php

declare(strict_types=1);

namespace App\Domain\Declarations\Actions;

use App\Domain\Declarations\DataTransferObjects\RecordDeclarationSubmissionResponseInput;
use App\Domain\Declarations\Enums\DeclarationStatus;
use App\Domain\Declarations\Exceptions\DeclarationNotSubmittedException;
use App\Domain\Declarations\Exceptions\InvalidSubmissionOutcomeException;
use App\Domain\Declarations\Models\Declaration;
use App\Domain\Declarations\Models\DeclarationSubmission;
use App\Domain\Declarations\Services\DeclarationLifecycle;
use Illuminate\Support\Facades\DB;

/**
 * Records ANAF's outcome for a submission and flips the owning declaration to its terminal
 * `ACCEPTED`/`REJECTED` status — the one mutation `App\Domain\Declarations\Models\Declaration`'s
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
