<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Actions;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Kern\TaxRomania\Declarations\DataTransferObjects\SubmitDeclarationInput;
use Kern\TaxRomania\Declarations\Enums\DeclarationStatus;
use Kern\TaxRomania\Declarations\Exceptions\DeclarationNotValidException;
use Kern\TaxRomania\Declarations\Models\Declaration;
use Kern\TaxRomania\Declarations\Models\DeclarationSubmission;
use Kern\TaxRomania\Declarations\Services\DeclarationLifecycle;

/**
 * Records the fact that a `VALID` declaration was submitted, and locks it
 * (`declarations.locked_at`, `docs/AGENTS.md` rule 18). This module builds no actual transport —
 * `Kern\TaxRomania\Declarations\Enums\SubmissionTransport` names the channel a caller (a future M10
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
