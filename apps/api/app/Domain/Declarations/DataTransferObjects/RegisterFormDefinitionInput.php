<?php

declare(strict_types=1);

namespace App\Domain\Declarations\DataTransferObjects;

final readonly class RegisterFormDefinitionInput
{
    public function __construct(
        public string $code,
        public string $issuer,
    ) {
    }
}
