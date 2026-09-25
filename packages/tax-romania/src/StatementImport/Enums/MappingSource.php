<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Enums;

/**
 * Who decided which column was which.
 *
 * Recorded because the two are not equally trustworthy after the fact: a mapping a person typed is
 * what they meant, and one a model proposed is what they accepted — and when an imported figure
 * turns out wrong months later, which of those it was is the first question.
 */
enum MappingSource: string
{
    case PERSON = 'PERSON';
    case AI = 'AI';
}
