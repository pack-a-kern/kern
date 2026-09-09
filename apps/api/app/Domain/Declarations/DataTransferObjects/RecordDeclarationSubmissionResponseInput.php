<?php

declare(strict_types=1);

namespace App\Domain\Declarations\DataTransferObjects;

use App\Domain\Declarations\Enums\DeclarationStatus;

/**
 * `App\Domain\Declarations\Actions\RecordDeclarationSubmissionResponse`'s input. `outcome` must be
 * `DeclarationStatus::ACCEPTED` or `DeclarationStatus::REJECTED` — any other value is
 * `App\Domain\Declarations\Exceptions\InvalidSubmissionOutcomeException`, enforced by the action,
 * not by narrowing the type here (a narrower dedicated enum with only two cases would duplicate
 * `DeclarationStatus`'s own case names for no reader benefit — `docs/CODE-QUALITY.md` §1).
 */
final readonly class RecordDeclarationSubmissionResponseInput
{
    public function __construct(
        public string $submissionId,
        public DeclarationStatus $outcome,
        public ?string $responsePayload = null,
        public ?string $receiptDocumentId = null,
    ) {
    }
}
