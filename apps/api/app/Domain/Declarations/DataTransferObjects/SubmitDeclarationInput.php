<?php

declare(strict_types=1);

namespace App\Domain\Declarations\DataTransferObjects;

use App\Domain\Declarations\Enums\SubmissionTransport;

final readonly class SubmitDeclarationInput
{
    public function __construct(
        public string $declarationId,
        public SubmissionTransport $transport,
        public string $submittedAt,
        public ?string $externalReference = null,
    ) {
    }
}
