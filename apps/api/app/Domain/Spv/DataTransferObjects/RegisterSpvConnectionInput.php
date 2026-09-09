<?php

declare(strict_types=1);

namespace App\Domain\Spv\DataTransferObjects;

use App\Domain\Spv\Enums\SpvAuthMethod;

final readonly class RegisterSpvConnectionInput
{
    public function __construct(
        public string $taxSubjectId,
        public SpvAuthMethod $authMethod,
        public ?string $username = null,
    ) {
    }
}
