<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\DataTransferObjects;

use Kern\TaxRomania\Spv\Enums\SpvAuthMethod;

final readonly class RegisterSpvConnectionInput
{
    public function __construct(
        public string $taxSubjectId,
        public SpvAuthMethod $authMethod,
        public ?string $username = null,
    ) {
    }
}
