<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\DataTransferObjects;

final readonly class RegisterFormDefinitionInput
{
    public function __construct(
        public string $code,
        public string $issuer,
    ) {
    }
}
