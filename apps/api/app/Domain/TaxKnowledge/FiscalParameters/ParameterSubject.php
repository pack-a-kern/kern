<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters;

/** Who a value is about: what a person owes, what a company owes, or both. */
enum ParameterSubject: string
{
    case PERSON = 'PERSON';
    case COMPANY = 'COMPANY';
    case BOTH = 'BOTH';
}
