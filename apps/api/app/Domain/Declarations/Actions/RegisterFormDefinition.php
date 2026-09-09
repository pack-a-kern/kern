<?php

declare(strict_types=1);

namespace App\Domain\Declarations\Actions;

use App\Domain\Declarations\DataTransferObjects\RegisterFormDefinitionInput;
use App\Domain\Declarations\Models\FormDefinition;

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
