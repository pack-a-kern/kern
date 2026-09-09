<?php

declare(strict_types=1);

namespace App\Domain\Declarations\Actions;

use App\Domain\Declarations\DataTransferObjects\SubmitDeclarationInput;
use App\Domain\Declarations\Enums\DeclarationStatus;
use App\Domain\Declarations\Exceptions\DeclarationNotValidException;
use App\Domain\Declarations\Models\Declaration;
use App\Domain\Declarations\Models\DeclarationSubmission;
use App\Domain\Declarations\Services\DeclarationLifecycle;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records the fact that a `VALID` declaration was submitted, and locks it
 * (`declarations.locked_at`, `docs/AGENTS.md` rule 18). This module builds no actual transport —
 * `App\Domain\Declarations\Enums\SubmissionTransport` names the channel a caller (a future M10
 * SPV action, or a human recording a manual filing) already used; nothing here talks to ANAF.
 */
final readonly class SubmitDeclaration
{
    public function __construct(
        private DeclarationLifecycle $lifecycle,
    ) {
    }

    public function __invoke(SubmitDeclarationInput $input): DeclarationSubmission
    {
        return DB::transaction(function () use ($input): DeclarationSubmission {
            /** @var Declaration $declaration */
            $declaration = Declaration::query()->findOrFail($input->declarationId);

            if ($declaration->status !== DeclarationStatus::VALID) {
                throw DeclarationNotValidException::forDeclaration($declaration->id);
            }

            $submittedAt = new DateTimeImmutable($input->submittedAt);

            $this->lifecycle->transition($declaration, DeclarationStatus::SUBMITTED, [
                'locked_at' => $submittedAt,
            ]);

            return DeclarationSubmission::record(
                $declaration,
                $input->transport,
                $submittedAt,
                $input->externalReference,
            );
        });
    }
}
