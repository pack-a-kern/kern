<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters;

/** Who a value is about: what a person owes, what a company owes, or both. */
enum ParameterSubject: string
{
    case PERSON = 'PERSON';
    case COMPANY = 'COMPANY';
    case BOTH = 'BOTH';
}
