<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Actions;

use Kern\TaxRomania\Declarations\DataTransferObjects\RegisterFormDefinitionInput;
use Kern\TaxRomania\Declarations\Models\FormDefinition;

final readonly class RegisterFormDefinition
{
    public function __invoke(RegisterFormDefinitionInput $input): FormDefinition
    {
        return FormDefinition::create([
            'code' => $input->code,
            'issuer' => $input->issuer,
        ]);
    }
}
